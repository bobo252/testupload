<?php


// ─── Helper functions ──────────────────────────────────────────────────────
function r_fetch_all(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
  if ($types && $params) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $data = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $data;
  }
  $res = $conn->query($sql);
  return ($res && $res->num_rows > 0) ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

function r_fetch_row(mysqli $conn, string $sql, string $types, array $params): ?array
{
  $stmt = $conn->prepare($sql);
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $stmt->close();
  return $row ?: null;
}

function r_exec(mysqli $conn, string $sql, string $types, array $params): void
{
  $stmt = $conn->prepare($sql);
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $stmt->close();
}

function json_res(array $data): void
{
  header('Content-Type: application/json');
  echo json_encode($data);
  exit;
}

/**
 * Auto-derive pub_status from the latest submission log entry.
 * If no log → 'draft'. Mapping: submission log status → publication status.
 */
function update_pub_status(mysqli $conn, int $pubid): void
{
  $row = r_fetch_row(
    $conn,
    "SELECT status FROM pub_submission_log WHERE pub_id=? ORDER BY submit_date DESC, log_id DESC LIMIT 1",
    "i",
    [$pubid]
  );
  $map = [
    'submitted' => 'submitted',
    'under_review' => 'under_review',
    'revision_requested' => 'revision',
    'resubmitted' => 'submitted',
    'accepted' => 'accepted',
    'rejected' => 'rejected',
    'withdrawn' => 'withdrawn',
    'deferred' => 'deferred',
  ];
  $stat = $row ? ($map[$row['status']] ?? 'submitted') : 'draft';
  r_exec($conn, "UPDATE publication SET pub_status=? WHERE pub_id=?", "si", [$stat, $pubid]);
}

// ─── AJAX handlers ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  $action = $_POST['action'];
  $ni = fn($k) => isset($_POST[$k]) && $_POST[$k] !== '' ? (int) $_POST[$k] : null;
  $ns = fn($k) => isset($_POST[$k]) && trim($_POST[$k]) !== '' ? trim($_POST[$k]) : null;
  $nd = fn($k) => isset($_POST[$k]) && $_POST[$k] !== '' ? $_POST[$k] : null;

  /* ════ PROPOSAL ════ */
  if ($action === 'get_proposals') {
    $rows = r_fetch_all($conn, "
            SELECT pr.proposal_id, s.student_id, pr.std_id,
                CONCAT(COALESCE(p.eng_name,''),' ',COALESCE(p.eng_mname,''),' ',COALESCE(p.eng_surname,'')) AS student_name,
                pr.proposal_title,
                pr.major_advisor_id,
                CONCAT(COALESCE(lp.eng_name,''),' ',COALESCE(lp.eng_mname,''),' ',COALESCE(lp.eng_surname,'')) AS major_advisor,
                GROUP_CONCAT(DISTINCT CONCAT(COALESCE(cop.eng_name,''),' ',COALESCE(cop.eng_mname,''),' ',COALESCE(cop.eng_surname,'')) ORDER BY pc.sort_order SEPARATOR ', ') AS coadvisors,
                pr.approve_date,
                pr.file_link,
                (SELECT COUNT(*) FROM proposal_defend pd WHERE pd.proposal_id=pr.proposal_id) AS defend_count,
                (SELECT d.result FROM proposal_defend d WHERE d.proposal_id=pr.proposal_id ORDER BY d.defend_date DESC LIMIT 1) AS latest_result,
                (SELECT d.condition_days FROM proposal_defend d WHERE d.proposal_id=pr.proposal_id ORDER BY d.defend_date DESC LIMIT 1) AS latest_condition_days,
                (SELECT d.defend_date FROM proposal_defend d WHERE d.proposal_id=pr.proposal_id ORDER BY d.defend_date DESC LIMIT 1) AS latest_defend_date,
                (SELECT th.thesis_id FROM thesis th WHERE th.std_id=s.std_id LIMIT 1) AS existing_thesis_id
            FROM proposal pr
            JOIN student s ON pr.std_id=s.std_id
            JOIN personal p ON s.mpd_id=p.mpd_id
            LEFT JOIN lecturer l ON pr.major_advisor_id=l.lec_id
            LEFT JOIN personal lp ON l.mpd_id=lp.mpd_id
            LEFT JOIN proposal_coadvisor pc ON pc.proposal_id=pr.proposal_id
            LEFT JOIN lecturer col ON pc.lec_id=col.lec_id
            LEFT JOIN personal cop ON col.mpd_id=cop.mpd_id
            GROUP BY pr.proposal_id, s.student_id, pr.std_id, p.eng_name, p.eng_mname, p.eng_surname, pr.proposal_title,
                     pr.major_advisor_id, lp.eng_name, lp.eng_mname, lp.eng_surname, pr.approve_date, pr.file_link
            ORDER BY s.student_id");
    json_res(['success' => true, 'data' => $rows]);
  }

  if ($action === 'get_proposal') {
    $pid = (int) $_POST['proposal_id'];
    $row = r_fetch_row(
      $conn,
      "SELECT pr.*, CONCAT(COALESCE(p.eng_name,''),' ',COALESCE(p.eng_mname,''),' ',COALESCE(p.eng_surname,'')) AS student_name, s.student_id
             FROM proposal pr JOIN student s ON pr.std_id=s.std_id JOIN personal p ON s.mpd_id=p.mpd_id
             WHERE pr.proposal_id=?",
      "i",
      [$pid]
    );
    $cos = r_fetch_all($conn, "SELECT lec_id,sort_order FROM proposal_coadvisor WHERE proposal_id=? ORDER BY sort_order", "i", [$pid]);
    if ($row)
      $row['coadvisors'] = $cos;
    json_res(['success' => true, 'data' => $row]);
  }

  if ($action === 'save_proposal') {
    $pid = $ni('proposal_id');
    $std = (int) $_POST['std_id'];
    $title = trim($_POST['proposal_title'] ?? '');
    $major = $ni('major_advisor_id');
    $appr = $nd('approve_date');
    $flink = $ns('file_link');
    if (!$std || !$title)
      json_res(['success' => false, 'error' => 'Student and Title required']);

    // Collect co-advisors (max 6) and check duplicates
    $coIds = [];
    for ($i = 1; $i <= 6; $i++) {
      if (!empty($_POST["coadvisor_$i"])) {
        $cid = (int) $_POST["coadvisor_$i"];
        if (in_array($cid, $coIds))
          json_res(['success' => false, 'error' => "Duplicate co-advisor selected (position $i)"]);
        if ($major && $cid === $major)
          json_res(['success' => false, 'error' => "Co-advisor $i is the same as Major Advisor"]);
        $coIds[] = $cid;
      }
    }

    $conn->begin_transaction();
    try {
      if ($pid) {
        r_exec($conn, "UPDATE proposal SET std_id=?,proposal_title=?,major_advisor_id=?,approve_date=?,file_link=? WHERE proposal_id=?", "isisis", [$std, $title, $major, $appr, $flink, $pid]);
        r_exec($conn, "DELETE FROM proposal_coadvisor WHERE proposal_id=?", "i", [$pid]);
        write_audit_log($conn, 'UPDATE', 'research_proposal', $pid, "Updated proposal $pid");
      } else {
        $stmt = $conn->prepare("INSERT INTO proposal(std_id,proposal_title,major_advisor_id,approve_date,file_link) VALUES(?,?,?,?,?)");
        $stmt->bind_param("isiss", $std, $title, $major, $appr, $flink);
        $stmt->execute();
        $pid = $conn->insert_id;
        $stmt->close();
        write_audit_log($conn, 'INSERT', 'research_proposal', $pid, "Added proposal for std_id: $std - Title: $title");
      }
      $sort = 1;
      for ($i = 1; $i <= 6; $i++) {
        if (!empty($_POST["coadvisor_$i"])) {
          r_exec($conn, "INSERT INTO proposal_coadvisor(proposal_id,lec_id,sort_order) VALUES(?,?,?)", "iii", [$pid, (int) $_POST["coadvisor_$i"], $sort++]);
        }
      }
      $conn->commit();
      json_res(['success' => true, 'proposal_id' => $pid]);
    } catch (Exception $e) {
      $conn->rollback();
      json_res(['success' => false, 'error' => $e->getMessage()]);
    }
  }

  if ($action === 'delete_proposal') {
    r_exec($conn, "DELETE FROM proposal WHERE proposal_id=?", "i", [(int) $_POST['proposal_id']]);
    write_audit_log($conn, 'DELETE', 'research_proposal', (int)$_POST['proposal_id'], "Deleted proposal");
    json_res(['success' => true]);
  }

  if ($action === 'get_proposal_defends') {
    $rows = r_fetch_all($conn, "SELECT * FROM proposal_defend WHERE proposal_id=? ORDER BY defend_date", "i", [(int) $_POST['proposal_id']]);
    json_res(['success' => true, 'data' => $rows]);
  }

  if ($action === 'save_proposal_defend') {
    $did = $ni('defend_id');
    $pid = (int) $_POST['proposal_id'];
    $ddate = $_POST['defend_date'];
    $res = $_POST['result'];
    $cdays = ($res === 'pass_with_condition' && !empty($_POST['condition_days'])) ? (int) $_POST['condition_days'] : null;
    $notes = $ns('notes');
    if ($did) {
      r_exec($conn, "UPDATE proposal_defend SET defend_date=?,result=?,condition_days=?,notes=? WHERE defend_id=?", "ssisi", [$ddate, $res, $cdays, $notes, $did]);
      write_audit_log($conn, 'UPDATE', 'proposal_defend', $did, "Updated proposal defend $did");
    } else {
      r_exec($conn, "INSERT INTO proposal_defend(proposal_id,defend_date,result,condition_days,notes) VALUES(?,?,?,?,?)", "issii", [$pid, $ddate, $res, $cdays, $notes]);
      write_audit_log($conn, 'INSERT', 'proposal_defend', $conn->insert_id, "Added proposal defend for proposal $pid");
    }
    json_res(['success' => true]);
  }

  if ($action === 'delete_proposal_defend') {
    r_exec($conn, "DELETE FROM proposal_defend WHERE defend_id=?", "i", [(int) $_POST['defend_id']]);
    write_audit_log($conn, 'DELETE', 'proposal_defend', (int)$_POST['defend_id'], "Deleted proposal defend");
    json_res(['success' => true]);
  }

  /* ════ AUTO-CREATE THESIS FROM PROPOSAL ════ */
  if ($action === 'auto_create_thesis_from_proposal') {
    $pid = (int) $_POST['proposal_id'];

    // Fetch proposal + student + co-advisors
    $prop = r_fetch_row(
      $conn,
      "SELECT pr.*, s.std_id, s.student_id, s.s_yearin,
                    CONCAT(COALESCE(p.eng_name,''),' ',COALESCE(p.eng_mname,''),' ',COALESCE(p.eng_surname,'')) AS student_name
             FROM proposal pr
             JOIN student s ON pr.std_id=s.std_id
             JOIN personal p ON s.mpd_id=p.mpd_id
             WHERE pr.proposal_id=?",
      "i",
      [$pid]
    );

    if (!$prop)
      json_res(['success' => false, 'error' => 'Proposal not found']);

    // Check if thesis already exists for this student
    $exists = r_fetch_row(
      $conn,
      "SELECT thesis_id FROM thesis WHERE std_id=?",
      "i",
      [$prop['std_id']]
    );
    if ($exists) {
      json_res([
        'success' => false,
        'already_exists' => true,
        'thesis_id' => $exists['thesis_id'],
        'error' => 'This student already has a Thesis (Thesis ID: ' . $exists['thesis_id'] . ')'
      ]);
    }

    // Fetch co-advisors of this proposal
    $cos = r_fetch_all(
      $conn,
      "SELECT lec_id, sort_order FROM proposal_coadvisor WHERE proposal_id=? ORDER BY sort_order",
      "i",
      [$pid]
    );

    $conn->begin_transaction();
    try {
      $stmt = $conn->prepare(
        "INSERT INTO thesis(std_id, thesis_title, major_advisor_id, cochair_id) VALUES(?,?,?,NULL)"
      );
      $stmt->bind_param(
        "isi",
        $prop['std_id'],
        $prop['proposal_title'],
        $prop['major_advisor_id']
      );
      $stmt->execute();
      $tid = $conn->insert_id;
      $stmt->close();
      write_audit_log($conn, 'INSERT', 'research_thesis', $tid, "Auto-created thesis for std_id: {$prop['std_id']}");

      // Copy co-advisors from proposal to thesis
      $sort = 1;
      foreach ($cos as $co) {
        r_exec(
          $conn,
          "INSERT INTO thesis_coadvisor(thesis_id, lec_id, sort_order) VALUES(?,?,?)",
          "iii",
          [$tid, $co['lec_id'], $sort++]
        );
      }

      $conn->commit();
      json_res([
        'success' => true,
        'thesis_id' => $tid,
        'std_id' => $prop['std_id'],
        'student_id' => $prop['student_id'],
        'student_name' => $prop['student_name'],
        'thesis_title' => $prop['proposal_title'],
        'major_advisor_id' => $prop['major_advisor_id'],
        'coadvisors' => $cos,
        's_yearin' => $prop['s_yearin'],
      ]);
    } catch (Exception $e) {
      $conn->rollback();
      json_res(['success' => false, 'error' => $e->getMessage()]);
    }
  }

  /* ════ THESIS ════ */
  if ($action === 'get_theses') {
    $rows = r_fetch_all($conn, "
            SELECT th.thesis_id, s.student_id,
                CONCAT(COALESCE(p.eng_name,''),' ',COALESCE(p.eng_mname,''),' ',COALESCE(p.eng_surname,'')) AS student_name,
                th.thesis_title,
                CONCAT(COALESCE(lp.eng_name,''),' ',COALESCE(lp.eng_mname,''),' ',COALESCE(lp.eng_surname,'')) AS major_advisor,
                GROUP_CONCAT(DISTINCT CONCAT(COALESCE(cop.eng_name,''),' ',COALESCE(cop.eng_mname,''),' ',COALESCE(cop.eng_surname,'')) ORDER BY tc.sort_order SEPARATOR ', ') AS coadvisors,
                CONCAT(COALESCE(cp.eng_name,''),' ',COALESCE(cp.eng_mname,''),' ',COALESCE(cp.eng_surname,'')) AS cochair,
                (SELECT COUNT(*) FROM thesis_defend td WHERE td.thesis_id=th.thesis_id) AS defend_count,
                (SELECT d.result FROM thesis_defend d WHERE d.thesis_id=th.thesis_id ORDER BY d.defend_date DESC LIMIT 1) AS latest_result,
                (SELECT d.condition_days FROM thesis_defend d WHERE d.thesis_id=th.thesis_id ORDER BY d.defend_date DESC LIMIT 1) AS latest_condition_days
            FROM thesis th
            JOIN student s ON th.std_id=s.std_id
            JOIN personal p ON s.mpd_id=p.mpd_id
            LEFT JOIN lecturer l ON th.major_advisor_id=l.lec_id
            LEFT JOIN personal lp ON l.mpd_id=lp.mpd_id
            LEFT JOIN thesis_coadvisor tc ON tc.thesis_id=th.thesis_id
            LEFT JOIN lecturer col ON tc.lec_id=col.lec_id
            LEFT JOIN personal cop ON col.mpd_id=cop.mpd_id
            LEFT JOIN lecturer cl ON th.cochair_id=cl.lec_id
            LEFT JOIN personal cp ON cl.mpd_id=cp.mpd_id
            GROUP BY th.thesis_id, s.student_id, p.eng_name, p.eng_mname, p.eng_surname, th.thesis_title, lp.eng_name, lp.eng_mname, lp.eng_surname, cp.eng_name, cp.eng_mname, cp.eng_surname
            ORDER BY s.student_id");
    json_res(['success' => true, 'data' => $rows]);
  }

  if ($action === 'get_thesis') {
    $tid = (int) $_POST['thesis_id'];
    $row = r_fetch_row(
      $conn,
      "SELECT th.*, CONCAT(COALESCE(p.eng_name,''),' ',COALESCE(p.eng_mname,''),' ',COALESCE(p.eng_surname,'')) AS student_name, s.student_id, s.s_yearin
             FROM thesis th JOIN student s ON th.std_id=s.std_id JOIN personal p ON s.mpd_id=p.mpd_id
             WHERE th.thesis_id=?",
      "i",
      [$tid]
    );
    $cos = r_fetch_all($conn, "SELECT lec_id,sort_order FROM thesis_coadvisor WHERE thesis_id=? ORDER BY sort_order", "i", [$tid]);
    $exts = r_fetch_all($conn, "SELECT mpd_id,sort_order FROM thesis_external WHERE thesis_id=? ORDER BY sort_order", "i", [$tid]);
    if ($row) {
      $row['coadvisors'] = $cos;
      $row['externals'] = $exts;
    }
    json_res(['success' => true, 'data' => $row]);
  }

  if ($action === 'save_thesis') {
    $tid = $ni('thesis_id');
    $std = (int) $_POST['std_id'];
    $title = trim($_POST['thesis_title'] ?? '');
    $major = $ni('major_advisor_id');
    $cochair = $ni('cochair_id');
    $flink = $ns('file_link');
    $cnote = $ns('change_note'); // optional note for title history
    if (!$std || !$title)
      json_res(['success' => false, 'error' => 'Student and Title required']);

    // Collect co-advisors (max 6) and check duplicates
    $coIds = [];
    for ($i = 1; $i <= 6; $i++) {
      if (!empty($_POST["coadvisor_$i"])) {
        $cid = (int) $_POST["coadvisor_$i"];
        if (in_array($cid, $coIds))
          json_res(['success' => false, 'error' => "Duplicate co-advisor selected (position $i)"]);
        if ($major && $cid === $major)
          json_res(['success' => false, 'error' => "Co-advisor $i is the same as Major Advisor"]);
        if ($cochair && $cid === $cochair)
          json_res(['success' => false, 'error' => "Co-advisor $i is the same as Co-chair"]);
        $coIds[] = $cid;
      }
    }

    $conn->begin_transaction();
    try {
      if ($tid) {
        // Fetch current title to detect change
        $cur = r_fetch_row($conn, "SELECT thesis_title FROM thesis WHERE thesis_id=?", "i", [$tid]);
        if ($cur && $cur['thesis_title'] !== $title) {
          // Log title change history
          r_exec(
            $conn,
            "INSERT INTO thesis_title_history(thesis_id,old_title,new_title,change_note) VALUES(?,?,?,?)",
            "isss",
            [$tid, $cur['thesis_title'], $title, $cnote]
          );
        }
        r_exec($conn, "UPDATE thesis SET std_id=?,thesis_title=?,major_advisor_id=?,cochair_id=?,file_link=? WHERE thesis_id=?", "isiiis", [$std, $title, $major, $cochair, $flink, $tid]);
        r_exec($conn, "DELETE FROM thesis_coadvisor WHERE thesis_id=?", "i", [$tid]);
        r_exec($conn, "DELETE FROM thesis_external WHERE thesis_id=?", "i", [$tid]);
        write_audit_log($conn, 'UPDATE', 'research_thesis', $tid, "Updated thesis $tid");
      } else {
        $stmt = $conn->prepare("INSERT INTO thesis(std_id,thesis_title,major_advisor_id,cochair_id,file_link) VALUES(?,?,?,?,?)");
        $stmt->bind_param("isiis", $std, $title, $major, $cochair, $flink);
        $stmt->execute();
        $tid = $conn->insert_id;
        $stmt->close();
        write_audit_log($conn, 'INSERT', 'research_thesis', $tid, "Added thesis for std_id: $std - Title: $title");
      }
      $sort = 1;
      for ($i = 1; $i <= 6; $i++)
        if (!empty($_POST["coadvisor_$i"]))
          r_exec($conn, "INSERT INTO thesis_coadvisor(thesis_id,lec_id,sort_order) VALUES(?,?,?)", "iii", [$tid, (int) $_POST["coadvisor_$i"], $sort++]);
      for ($i = 1; $i <= 2; $i++)
        if (!empty($_POST["external_$i"]))
          r_exec($conn, "INSERT INTO thesis_external(thesis_id,mpd_id,sort_order) VALUES(?,?,?)", "iii", [$tid, (int) $_POST["external_$i"], $i]);
      $conn->commit();
      json_res(['success' => true, 'thesis_id' => $tid]);
    } catch (Exception $e) {
      $conn->rollback();
      json_res(['success' => false, 'error' => $e->getMessage()]);
    }
  }

  if ($action === 'delete_thesis') {
    r_exec($conn, "DELETE FROM thesis WHERE thesis_id=?", "i", [(int) $_POST['thesis_id']]);
    json_res(['success' => true]);
  }

  if ($action === 'get_thesis_title_history') {
    $tid = (int) $_POST['thesis_id'];
    $rows = r_fetch_all(
      $conn,
      "SELECT * FROM thesis_title_history WHERE thesis_id=? ORDER BY changed_at ASC",
      "i",
      [$tid]
    );
    json_res(['success' => true, 'data' => $rows]);
  }

  if ($action === 'get_softskill_count') {
    $std_id = (int) $_POST['std_id'];
    $row = r_fetch_row(
      $conn,
      "SELECT COUNT(*) AS passed FROM student_softskill WHERE std_id=? AND ss_pass_date IS NOT NULL",
      "i",
      [$std_id]
    );
    $total = r_fetch_row($conn, "SELECT COUNT(*) AS total FROM softskills", "", []);
    json_res(['success' => true, 'passed' => (int) ($row['passed'] ?? 0), 'total' => (int) ($total['total'] ?? 0)]);
  }

  if ($action === 'get_thesis_defends') {
    $rows = r_fetch_all($conn, "SELECT * FROM thesis_defend WHERE thesis_id=? ORDER BY defend_date", "i", [(int) $_POST['thesis_id']]);
    json_res(['success' => true, 'data' => $rows]);
  }

  if ($action === 'save_thesis_defend') {
    $did = $ni('defend_id');
    $tid = (int) $_POST['thesis_id'];
    $ddate = $_POST['defend_date'];
    $res = $_POST['result'];
    $cdays = ($res === 'pass_with_condition' && !empty($_POST['condition_days'])) ? (int) $_POST['condition_days'] : null;
    $af = $nd('actual_final_thesis_date');
    $as_ = $nd('actual_submission_date');
    $notes = $ns('notes');
    if ($did) {
      r_exec(
        $conn,
        "UPDATE thesis_defend SET defend_date=?,result=?,condition_days=?,actual_final_thesis_date=?,actual_submission_date=?,notes=? WHERE defend_id=?",
        "ssisssi",
        [$ddate, $res, $cdays, $af, $as_, $notes, $did]
      );
    } else {
      r_exec(
        $conn,
        "INSERT INTO thesis_defend(thesis_id,defend_date,result,condition_days,actual_final_thesis_date,actual_submission_date,notes) VALUES(?,?,?,?,?,?,?)",
        "issssss",
        [$tid, $ddate, $res, $cdays, $af, $as_, $notes]
      );
    }
    json_res(['success' => true]);
  }

  if ($action === 'delete_thesis_defend') {
    r_exec($conn, "DELETE FROM thesis_defend WHERE defend_id=?", "i", [(int) $_POST['defend_id']]);
    json_res(['success' => true]);
  }

  /* ════ PUBLICATIONS ════ */
  if ($action === 'get_publications') {
    $type = $_POST['pub_type'] ?? 'journal';
    $rows = r_fetch_all($conn, "
            SELECT pu.pub_id, pu.pub_type, pu.article_type, pu.pub_title, pu.pub_status,
                pu.journal_name, pu.conference_name, pu.pub_year, pu.doi,
                pu.quartile, pu.percentile,
                pu.conference_date, pu.presentation_type, pu.conference_location,
                s.student_id, s.s_yearin,
                CONCAT(COALESCE(p.eng_name,''),' ',COALESCE(p.eng_mname,''),' ',COALESCE(p.eng_surname,'')) AS student_name,
                (SELECT GROUP_CONCAT(COALESCE(pa2.author_name,CONCAT(COALESCE(per.eng_name,''),' ',COALESCE(per.eng_mname,''),' ',COALESCE(per.eng_surname,'')))
                 ORDER BY pa2.sort_order SEPARATOR '; ')
                 FROM pub_author pa2 LEFT JOIN personal per ON pa2.mpd_id=per.mpd_id WHERE pa2.pub_id=pu.pub_id) AS authors
            FROM publication pu
            LEFT JOIN student s ON pu.std_id=s.std_id
            LEFT JOIN personal p ON s.mpd_id=p.mpd_id
            WHERE pu.pub_type=? ORDER BY pu.pub_id DESC", "s", [$type]);
    json_res(['success' => true, 'data' => $rows]);
  }

  if ($action === 'get_publication') {
    $pubid = (int) $_POST['pub_id'];
    $row = r_fetch_row($conn, "SELECT * FROM publication WHERE pub_id=?", "i", [$pubid]);
    $authors = r_fetch_all(
      $conn,
      "SELECT pa.*, COALESCE(pa.author_name,CONCAT(COALESCE(p.eng_name,''),' ',COALESCE(p.eng_mname,''),' ',COALESCE(p.eng_surname,''))) AS display_name
             FROM pub_author pa LEFT JOIN personal p ON pa.mpd_id=p.mpd_id WHERE pa.pub_id=? ORDER BY pa.sort_order",
      "i",
      [$pubid]
    );
    if ($row)
      $row['authors'] = $authors;
    json_res(['success' => true, 'data' => $row]);
  }

  if ($action === 'save_publication') {
    $pubid = $ni('pub_id');
    $title = trim($_POST['pub_title'] ?? '');
    if (!$title)
      json_res(['success' => false, 'error' => 'Title required']);
    $std = $ni('std_id');

    // ── Requirement 5: student must have a proposal with approve_date ──
    if ($std) {
      $propCheck = r_fetch_row(
        $conn,
        "SELECT proposal_id FROM proposal WHERE std_id=? AND NULLIF(approve_date,'0000-00-00') IS NOT NULL LIMIT 1",
        "i",
        [$std]
      );
      if (!$propCheck) {
        json_res([
          'success' => false,
          'error' => 'no_approved_proposal',
          'message' => 'This student does not have an approved proposal topic yet. Please set the Approve Topic Date in the Proposal section before adding publications.'
        ]);
      }
    }
    $ptype = $_POST['pub_type'];
    $atype = !empty($_POST['article_type']) ? $_POST['article_type'] : null;
    // pub_status is auto-derived from submission log (not from form)
    $abst = $ns('abstract');
    $kw = $ns('keywords');
    $jname = $ns('journal_name');
    $doi = $ns('doi');
    $pmid = $ns('pubmed_id');
    $qrt = $ns('quartile');
    $pct = !empty($_POST['percentile']) ? (int) $_POST['percentile'] : null; // 1, 5, or 10
    $yr = !empty($_POST['pub_year']) ? (int) $_POST['pub_year'] : null;
    $cname = $ns('conference_name');
    $cloc = $ns('conference_location');
    $cdate = $nd('conference_date');
    $ptype2 = $ns('presentation_type');

    $conn->begin_transaction();
    try {
      if ($pubid) {
        $conn->query("UPDATE publication SET
                    std_id=" . ($std ? $std : 'NULL') . ",pub_type='" . mysqli_real_escape_string($conn, $ptype) . "',
                    article_type=" . ($atype ? "'" . mysqli_real_escape_string($conn, $atype) . "'" : 'NULL') . ",
                    pub_title='" . mysqli_real_escape_string($conn, $title) . "',
                    abstract=" . ($abst ? "'" . mysqli_real_escape_string($conn, $abst) . "'" : 'NULL') . ",
                    keywords=" . ($kw ? "'" . mysqli_real_escape_string($conn, $kw) . "'" : 'NULL') . ",
                    journal_name=" . ($jname ? "'" . mysqli_real_escape_string($conn, $jname) . "'" : 'NULL') . ",
                    doi=" . ($doi ? "'" . mysqli_real_escape_string($conn, $doi) . "'" : 'NULL') . ",
                    pubmed_id=" . ($pmid ? "'" . mysqli_real_escape_string($conn, $pmid) . "'" : 'NULL') . ",
                    quartile=" . ($qrt ? "'" . mysqli_real_escape_string($conn, $qrt) . "'" : 'NULL') . ",
                    percentile=" . ($pct !== null ? $pct : 'NULL') . ",
                    pub_year=" . ($yr ? $yr : 'NULL') . ",
                    conference_name=" . ($cname ? "'" . mysqli_real_escape_string($conn, $cname) . "'" : 'NULL') . ",
                    conference_location=" . ($cloc ? "'" . mysqli_real_escape_string($conn, $cloc) . "'" : 'NULL') . ",
                    conference_date=" . ($cdate ? "'" . mysqli_real_escape_string($conn, $cdate) . "'" : 'NULL') . ",
                    presentation_type=" . ($ptype2 ? "'" . mysqli_real_escape_string($conn, $ptype2) . "'" : 'NULL') . "
                    WHERE pub_id=$pubid");
        $conn->query("DELETE FROM pub_author WHERE pub_id=$pubid");
      } else {
        $conn->query("INSERT INTO publication(std_id,pub_type,article_type,pub_title,abstract,keywords,pub_status,journal_name,doi,pubmed_id,quartile,percentile,pub_year,conference_name,conference_location,conference_date,presentation_type) VALUES(
                    " . ($std ? $std : 'NULL') . ",
                    '" . mysqli_real_escape_string($conn, $ptype) . "',
                    " . ($atype ? "'" . mysqli_real_escape_string($conn, $atype) . "'" : 'NULL') . ",
                    '" . mysqli_real_escape_string($conn, $title) . "',
                    " . ($abst ? "'" . mysqli_real_escape_string($conn, $abst) . "'" : 'NULL') . ",
                    " . ($kw ? "'" . mysqli_real_escape_string($conn, $kw) . "'" : 'NULL') . ",
                    'draft',
                    " . ($jname ? "'" . mysqli_real_escape_string($conn, $jname) . "'" : 'NULL') . ",
                    " . ($doi ? "'" . mysqli_real_escape_string($conn, $doi) . "'" : 'NULL') . ",
                    " . ($pmid ? "'" . mysqli_real_escape_string($conn, $pmid) . "'" : 'NULL') . ",
                    " . ($qrt ? "'" . mysqli_real_escape_string($conn, $qrt) . "'" : 'NULL') . ",
                    " . ($pct !== null ? $pct : 'NULL') . ",
                    " . ($yr ? $yr : 'NULL') . ",
                    " . ($cname ? "'" . mysqli_real_escape_string($conn, $cname) . "'" : 'NULL') . ",
                    " . ($cloc ? "'" . mysqli_real_escape_string($conn, $cloc) . "'" : 'NULL') . ",
                    " . ($cdate ? "'" . mysqli_real_escape_string($conn, $cdate) . "'" : 'NULL') . ",
                    " . ($ptype2 ? "'" . mysqli_real_escape_string($conn, $ptype2) . "'" : 'NULL') . "
                )");
        $pubid = $conn->insert_id;
      }
      if (!empty($_POST['authors'])) {
        $authors = json_decode($_POST['authors'], true);
        foreach ($authors as $idx => $a) {
          $mpd = !empty($a['mpd_id']) ? (int) $a['mpd_id'] : null;
          $aname = !empty($a['name']) ? mysqli_real_escape_string($conn, trim($a['name'])) : null;
          $affi = !empty($a['affiliation']) ? mysqli_real_escape_string($conn, trim($a['affiliation'])) : null;
          $atype = mysqli_real_escape_string($conn, $a['type']);
          $sort = $idx + 1;
          $conn->query("INSERT INTO pub_author(pub_id,author_type,mpd_id,author_name,author_affiliation,sort_order) VALUES($pubid,'$atype'," . ($mpd ? $mpd : 'NULL') . "," . ($aname ? "'$aname'" : 'NULL') . "," . ($affi ? "'$affi'" : 'NULL') . ",$sort)");
        }
      }
      // Auto-derive pub_status from latest submission log
      update_pub_status($conn, $pubid);
      $conn->commit();
      json_res(['success' => true, 'pub_id' => $pubid]);
    } catch (Exception $e) {
      $conn->rollback();
      json_res(['success' => false, 'error' => $e->getMessage()]);
    }
  }

  if ($action === 'delete_publication') {
    r_exec($conn, "DELETE FROM publication WHERE pub_id=?", "i", [(int) $_POST['pub_id']]);
    json_res(['success' => true]);
  }

  if ($action === 'get_submission_log') {
    $rows = r_fetch_all($conn, "SELECT * FROM pub_submission_log WHERE pub_id=? ORDER BY submit_date,log_id", "i", [(int) $_POST['pub_id']]);
    json_res(['success' => true, 'data' => $rows]);
  }

  if ($action === 'save_submission_log') {
    $lid = $ni('log_id');
    $pubid = (int) $_POST['pub_id'];
    $jname = trim($_POST['journal_name'] ?? '');
    $round = (int) ($_POST['round'] ?? 1);
    $sdate = $nd('submit_date');
    $stat = $_POST['status'];
    $rdate = $nd('response_date');
    $rsdate = $nd('resubmit_date');
    $ddate = $nd('decision_date');
    $notes = $ns('notes');
    if ($lid) {
      // FIX: corrected type string from "sisssssi" (8) to "sissssssi" (9 params)
      r_exec(
        $conn,
        "UPDATE pub_submission_log SET journal_name=?,round=?,submit_date=?,status=?,response_date=?,resubmit_date=?,decision_date=?,notes=? WHERE log_id=?",
        "sissssssi",
        [$jname, $round, $sdate, $stat, $rdate, $rsdate, $ddate, $notes, $lid]
      );
    } else {
      r_exec(
        $conn,
        "INSERT INTO pub_submission_log(pub_id,journal_name,round,submit_date,status,response_date,resubmit_date,decision_date,notes) VALUES(?,?,?,?,?,?,?,?,?)",
        "isissssss",
        [$pubid, $jname, $round, $sdate, $stat, $rdate, $rsdate, $ddate, $notes]
      );
    }
    // Auto-derive pub_status after log change
    update_pub_status($conn, $pubid);
    json_res(['success' => true]);
  }

  if ($action === 'delete_submission_log') {
    // Get pub_id before delete so we can update pub_status
    $logRow = r_fetch_row($conn, "SELECT pub_id FROM pub_submission_log WHERE log_id=?", "i", [(int) $_POST['log_id']]);
    r_exec($conn, "DELETE FROM pub_submission_log WHERE log_id=?", "i", [(int) $_POST['log_id']]);
    if ($logRow)
      update_pub_status($conn, (int) $logRow['pub_id']);
    json_res(['success' => true]);
  }

  json_res(['success' => false, 'error' => 'Unknown action']);
}

// ─── Load dropdown data for JS ──────────────────────────────────────────────
$lec_list = r_fetch_all($conn, "
    SELECT l.lec_id, CONCAT(COALESCE(p.eng_name,''),' ',COALESCE(p.eng_mname,''),' ',COALESCE(p.eng_surname,'')) AS full_name
    FROM lecturer l JOIN personal p ON l.mpd_id=p.mpd_id
    WHERE p.mpd_status=1 ORDER BY p.eng_name,p.eng_surname");
// NOTE: mpd_id added so JS can auto-set student as First Author
$std_list = r_fetch_all($conn, "
    SELECT s.std_id, s.student_id, s.mpd_id, s.std_status, CONCAT(COALESCE(p.eng_name,''),' ',COALESCE(p.eng_mname,''),' ',COALESCE(p.eng_surname,'')) AS full_name
    FROM student s JOIN personal p ON s.mpd_id=p.mpd_id
    WHERE s.std_status IN (1, 2) ORDER BY s.student_id");
$personal_list = r_fetch_all($conn, "
    SELECT mpd_id, CONCAT(COALESCE(eng_name,''),' ',COALESCE(eng_surname,'')) AS full_name
    FROM personal ORDER BY eng_name,eng_surname");
$ext_examiner_list = r_fetch_all($conn, "
    SELECT ee.ee_id, ee.mpd_id,
        CONCAT(COALESCE(p.eng_name,''),' ',COALESCE(p.eng_surname,'')) AS full_name,
        ee.expertise
    FROM external_examiner ee
    JOIN personal p ON ee.mpd_id=p.mpd_id
    ORDER BY p.eng_name, p.eng_surname");

// Map std_id => approve_date for Publication validation (JS side)
$prop_approve_map = r_fetch_all(
  $conn,
  "SELECT std_id, MAX(NULLIF(approve_date,'0000-00-00')) AS approve_date FROM proposal WHERE NULLIF(approve_date,'0000-00-00') IS NOT NULL GROUP BY std_id"
);

$lecJson = json_encode($lec_list);
$stdJson = json_encode($std_list);
$perJson = json_encode($personal_list);
$extExJson = json_encode($ext_examiner_list);
$propApprJson = json_encode(array_column($prop_approve_map, 'approve_date', 'std_id'));
?>



<style>
  /* ============================================================
   SPMS Design System — Unified UI v1.0
   Applied: all modules | Language: English | Dates: CE
   ============================================================ */
  :root {
    --sp-blue: #2563eb;
    --sp-blue-lt: #eff6ff;
    --sp-blue-dk: #1d4ed8;
    --sp-surface: #f8fafc;
    --sp-border: #e2e8f0;
    --sp-muted: #64748b;
    --sp-dark: #0f172a;
    --sp-green: #059669;
    --sp-amber: #d97706;
    --sp-red: #dc2626;
  }

  /* ── Page Header ─────────────────────────────── */
  .spms-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1.75rem;
    padding-bottom: 1rem;
    border-bottom: 2px solid var(--sp-border);
  }

  .spms-header .spms-title {
    font-size: 1.45rem;
    font-weight: 700;
    color: var(--sp-dark);
    margin: 0;
    display: flex;
    align-items: center;
    gap: .5rem;
  }

  .spms-header .spms-title i {
    color: var(--sp-blue);
    font-size: 1.3rem;
  }

  .spms-header .spms-subtitle {
    font-size: .82rem;
    color: var(--sp-muted);
    margin: .2rem 0 0;
    font-weight: 400;
  }

  /* ── Cards ───────────────────────────────────── */
  .card {
    border: none !important;
    border-radius: .875rem !important;
    box-shadow: 0 1px 4px rgba(0, 0, 0, .06), 0 1px 2px rgba(0, 0, 0, .04) !important;
  }

  .card-header {
    background: var(--sp-surface) !important;
    border-bottom: 1px solid var(--sp-border) !important;
    font-weight: 600;
    color: var(--sp-dark);
    padding: .875rem 1.25rem !important;
    border-radius: .875rem .875rem 0 0 !important;
  }

  .card-header.ch-primary {
    background: var(--sp-blue) !important;
    color: #fff;
    border: none !important;
  }

  .card-header.ch-dark {
    background: var(--sp-dark) !important;
    color: #fff;
    border: none !important;
  }

  /* ── Tables ──────────────────────────────────── */
  .table thead th {
    background: var(--sp-surface);
    color: var(--sp-muted);
    font-size: .71rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .55px;
    border-bottom: 2px solid var(--sp-border) !important;
    border-top: none !important;
    white-space: nowrap;
    padding: .6rem .9rem;
  }

  .table tbody td {
    vertical-align: middle;
    padding: .6rem .9rem;
    font-size: .88rem;
  }

  .table tbody tr {
    transition: background .12s;
  }

  .table tbody tr:hover>td {
    background: var(--sp-blue-lt) !important;
  }

  .table-striped>tbody>tr:nth-of-type(odd)>td {
    background: #fbfcfe;
  }

  /* ── Filter Bar ──────────────────────────────── */
  .spms-filter {
    background: var(--sp-surface);
    border: 1px solid var(--sp-border);
    border-radius: .875rem;
    padding: 1.1rem 1.25rem;
    margin-bottom: 1.5rem;
  }

  /* ── Stat Cards ──────────────────────────────── */
  .stat-card {
    border-radius: .875rem;
    padding: 1.1rem 1.4rem;
    position: relative;
    overflow: hidden;
    color: #fff;
    box-shadow: 0 2px 8px rgba(0, 0, 0, .12);
  }

  .stat-card .sc-icon {
    font-size: 2.4rem;
    opacity: .18;
    position: absolute;
    right: 1.1rem;
    top: 50%;
    transform: translateY(-50%);
  }

  .stat-card .sc-value {
    font-size: 2rem;
    font-weight: 800;
    line-height: 1.1;
  }

  .stat-card .sc-label {
    font-size: .78rem;
    opacity: .88;
    margin-top: .1rem;
  }

  /* ── Badges ──────────────────────────────────── */
  .badge {
    font-size: .71rem;
    font-weight: 600;
    padding: .28rem .55rem;
    border-radius: .35rem;
  }

  /* ── Modals ──────────────────────────────────── */
  .modal-header {
    border-bottom: none !important;
    padding: 1.1rem 1.5rem !important;
    border-radius: .875rem .875rem 0 0 !important;
  }

  .modal-header.bg-primary,
  .modal-header.bg-success,
  .modal-header.bg-danger,
  .modal-header.bg-info {
    color: #fff;
  }

  .modal-header.bg-warning {
    color: var(--sp-dark);
  }

  .modal-header.bg-dark {
    color: #fff !important;
  }

  .modal-header.bg-dark .modal-title {
    color: #fff !important;
  }

  .modal-footer {
    border-top: 1px solid var(--sp-border) !important;
    padding: .875rem 1.5rem !important;
  }

  .modal-content {
    border-radius: .875rem !important;
    border: none !important;
    overflow: hidden;
  }

  .modal-title {
    font-weight: 700;
    font-size: 1.05rem;
  }

  /* ── Nav Tabs ────────────────────────────────── */
  .nav-tabs {
    border-bottom: 2px solid var(--sp-border);
    gap: .1rem;
  }

  .nav-tabs .nav-link {
    color: var(--sp-muted);
    font-weight: 500;
    border: none;
    border-bottom: 3px solid transparent;
    margin-bottom: -2px;
    padding: .6rem 1.1rem;
    border-radius: .5rem .5rem 0 0;
    transition: all .15s;
  }

  .nav-tabs .nav-link.active {
    color: var(--sp-blue);
    border-bottom-color: var(--sp-blue);
    background: var(--sp-blue-lt);
    font-weight: 600;
  }

  .nav-tabs .nav-link:hover:not(.active) {
    color: var(--sp-dark);
    background: var(--sp-surface);
  }

  /* ── Buttons ─────────────────────────────────── */
  .btn {
    font-weight: 500;
    border-radius: .5rem !important;
    font-size: .875rem;
  }

  .btn-sm {
    font-size: .8rem !important;
    padding: .3rem .65rem !important;
  }

  .btn-group .btn {
    margin: 0 1px;
  }

  .btn-primary {
    background: var(--sp-blue) !important;
    border-color: var(--sp-blue) !important;
  }

  .btn-primary:hover {
    background: var(--sp-blue-dk) !important;
    border-color: var(--sp-blue-dk) !important;
  }

  /* ── Alerts ──────────────────────────────────── */
  .alert {
    border: none;
    border-radius: .75rem;
    font-size: .88rem;
    border-left: 4px solid;
  }

  .alert-success {
    border-left-color: var(--sp-green);
  }

  .alert-danger {
    border-left-color: var(--sp-red);
  }

  .alert-warning {
    border-left-color: var(--sp-amber);
  }

  .alert-info {
    border-left-color: var(--sp-blue);
  }

  /* ── Form Controls ───────────────────────────── */
  .form-control,
  .form-select {
    border-color: var(--sp-border);
    border-radius: .5rem !important;
    font-size: .875rem;
  }

  .form-control:focus,
  .form-select:focus {
    border-color: var(--sp-blue);
    box-shadow: 0 0 0 .2rem rgba(37, 99, 235, .15);
  }

  .form-label {
    font-size: .82rem;
    font-weight: 600;
    color: var(--sp-dark);
    margin-bottom: .35rem;
  }

  /* ── Misc ────────────────────────────────────── */
  .text-primary {
    color: var(--sp-blue) !important;
  }

  .border-primary {
    border-color: var(--sp-blue) !important;
  }

  .select2-container--bootstrap-5 .select2-selection {
    border-color: var(--sp-border) !important;
    border-radius: .5rem !important;
  }

  /* ── Result Badges (rBadge) ──────────────────── */
  .bpn { background-color: #059669 !important; color: #fff !important; }
  .bpc { background-color: #d97706 !important; color: #fff !important; }
  .bnp { background-color: #dc2626 !important; color: #fff !important; }

  /* ── Publication Status Badges (sBadge) ─────── */
  .badge-draft         { background-color: #94a3b8 !important; color: #fff !important; }
  .badge-submitted     { background-color: #2563eb !important; color: #fff !important; }
  .badge-under_review  { background-color: #7c3aed !important; color: #fff !important; }
  .badge-revision      { background-color: #d97706 !important; color: #fff !important; }
  .badge-accepted      { background-color: #059669 !important; color: #fff !important; }
  .badge-rejected      { background-color: #dc2626 !important; color: #fff !important; }
  .badge-withdrawn     { background-color: #64748b !important; color: #fff !important; }
  .badge-deferred      { background-color: #0891b2 !important; color: #fff !important; }
</style>
<div class="spms-header">
  <h2 class="spms-title"><i class="fas fa-book-open me-2"></i>Dissertation Management</h2>
</div>

<ul class="nav nav-tabs res-tab mb-3" id="resTabs">
  <li class="nav-item"><a class="nav-link active" id="t-prop-lnk" data-bs-toggle="tab" href="#t-proposal"><i
        class="fas fa-file-alt me-1"></i>Proposal</a></li>
  <li class="nav-item"><a class="nav-link" id="t-thesis-lnk" data-bs-toggle="tab" href="#t-thesis"><i
        class="fas fa-graduation-cap me-1"></i>Thesis</a></li>
  <li class="nav-item"><a class="nav-link" id="t-pub-lnk" data-bs-toggle="tab" href="#t-publication"><i
        class="fas fa-journal-whills me-1"></i>Publications</a></li>
  <li class="nav-item"><a class="nav-link" id="t-conf-lnk" data-bs-toggle="tab" href="#t-conference"><i
        class="fas fa-microphone me-1"></i>Conferences</a></li>
</ul>

<div class="tab-content">

  <div class="tab-pane fade show active" id="t-proposal">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-bold"><i class="fas fa-file-alt me-1"></i>Proposal List</span>
        <button class="btn btn-success btn-sm" onclick="openPropModal()"><i class="fas fa-plus me-1"></i>Add
          Proposal</button>
      </div>
      <div class="card-body table-responsive">
        <table id="tblProp" class="table table-hover align-middle w-100">
          <thead class="table-light">
            <tr>
              <th>#</th>
              <th>Student ID</th>
              <th>Name</th>
              <th>Proposal Title</th>
              <th>Advisor</th>
              <th>#Defenses</th>
              <th>Latest Result</th>
              <th>Approve Date</th>
              <th>File</th>
              <th>Thesis</th>
              <th style="width:170px">Actions</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="tab-pane fade" id="t-thesis">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-bold"><i class="fas fa-graduation-cap me-1"></i>Thesis / Dissertation List</span>
        <button class="btn btn-success btn-sm" onclick="openThesisModal()"><i class="fas fa-plus me-1"></i>Add
          Thesis</button>
      </div>
      <div class="card-body table-responsive">
        <table id="tblThesis" class="table table-hover align-middle w-100">
          <thead class="table-light">
            <tr>
              <th>#</th>
              <th>Student ID</th>
              <th>Name</th>
              <th>Thesis Title</th>
              <th>Advisor</th>
              <th>Co-chair</th>
              <th>File</th>
              <th>#Defenses</th>
              <th>Latest Result</th>
              <th style="width:190px">Actions</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="tab-pane fade" id="t-publication">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-bold"><i class="fas fa-journal-whills me-1"></i>Journal Publications</span>
        <button class="btn btn-success btn-sm" onclick="openPubModal('journal')"><i class="fas fa-plus me-1"></i>Add
          Publication</button>
      </div>
      <div class="card-body table-responsive">
        <table id="tblPub" class="table table-hover align-middle w-100">
          <thead class="table-light">
            <tr>
              <th>#</th>
              <th>Title</th>
              <th>Article Type</th>
              <th>Authors</th>
              <th>Journal</th>
              <th>Year</th>
              <th>Q</th>
              <th>%Tile</th>
              <th>Status</th>
              <th style="width:140px">Actions</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="tab-pane fade" id="t-conference">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="fw-bold"><i class="fas fa-microphone me-1"></i>Conference Papers</span>
        <div class="d-flex align-items-center gap-2">
          <label class="mb-0 small text-muted fw-semibold">Year In:</label>
          <select id="fConfYearIn" class="form-select form-select-sm" style="width:130px">
            <option value="">All Years</option>
          </select>
          <button class="btn btn-success btn-sm" onclick="openPubModal('conference')"><i
              class="fas fa-plus me-1"></i>Add Conference</button>
        </div>
      </div>
      <div class="card-body table-responsive">
        <table id="tblConf" class="table table-hover align-middle w-100">
          <thead class="table-light">
            <tr>
              <th>#</th>
              <th>Title</th>
              <th>Authors</th>
              <th>Conference</th>
              <th>Location</th>
              <th>Date</th>
              <th>Type</th>
              <th>Status</th>
              <th style="width:140px">Actions</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- ════ PROPOSAL Modal ════ -->
<div class="modal fade" id="mProp" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title"><i class="fas fa-file-alt me-2"></i><span id="propMT">Add Proposal</span></h5>
        <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="p_pid">
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label fw-bold">Student <span
                class="text-danger">*</span></label><select id="p_std" class="form-select"></select></div>
          <div class="col-md-6"><label class="form-label fw-bold">Approve Topic Date</label><input type="date"
              id="p_appr" class="form-control" lang="en-GB"></div>
          <div class="col-12"><label class="form-label fw-bold">Proposal Title <span
                class="text-danger">*</span></label><textarea id="p_title" class="form-control" rows="2"></textarea>
          </div>
          <div class="col-md-12"><label class="form-label fw-bold">Major Advisor</label><select id="p_major"
              class="form-select"></select></div>
          <div class="col-12">
            <label class="form-label fw-bold"><i class="fas fa-link me-1 text-primary"></i>Completed Proposal File
              Link</label>
            <input type="url" id="p_flink" class="form-control"
              placeholder="https://drive.google.com/... or any URL to the completed proposal">
            <small class="text-muted">Paste a link to the approved proposal document (Google Drive, OneDrive,
              etc.)</small>
          </div>
        </div>
        <hr>
        <div class="sh">Co-advisors <small class="text-muted fw-normal">(max 6)</small></div>
        <div class="row g-2">
          <?php for ($i = 1; $i <= 6; $i++): ?>
            <div class="col-md-6"><label class="form-label text-muted small">Co-advisor <?= $i ?></label><select
                id="p_co<?= $i ?>" class="form-select"></select></div>
          <?php endfor; ?>
        </div>
      </div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button
          class="btn btn-primary" onclick="saveProp()"><i class="fas fa-save me-1"></i>Save</button></div>
    </div>
  </div>
</div>

<!-- ════ PROPOSAL DEFENSE Modal ════ -->
<div class="modal fade" id="mPropDef" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-warning">
        <h5 class="modal-title"><i class="fas fa-shield-alt me-2"></i>Proposal Defense Records</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <div class="small text-muted mb-1"><i class="fas fa-user-graduate me-1"></i><span id="pdStudentName" class="fw-semibold text-dark"></span></div>
          <div class="d-flex justify-content-between align-items-center">
            <strong id="pdTitle" class="text-primary small"></strong>
            <button class="btn btn-sm btn-success" onclick="openDefForm('pd')"><i class="fas fa-plus me-1"></i>Add Defense</button>
          </div>
        </div>
        <div id="pdList"></div>
        <div id="pdForm" class="card border-primary p-3 mt-3 d-none">
          <input type="hidden" id="pd_did">
          <div class="row g-2">
            <div class="col-md-4"><label class="form-label fw-bold">Defense Date <span
                  class="text-danger">*</span></label><input type="date" id="pd_date" class="form-control" lang="en-GB"></div>
            <div class="col-md-4"><label class="form-label fw-bold">Result</label>
              <select id="pd_result" class="form-select" onchange="togCD('pd')">
                <option value="pass_no_condition">Pass without condition</option>
                <option value="pass_with_condition">Pass with condition</option>
                <option value="not_pass">Not pass</option>
              </select>
            </div>
            <div class="col-md-4 d-none" id="pdCDW"><label class="form-label fw-bold">Condition (days)</label><input
                type="number" id="pd_cdays" class="form-control" min="1"></div>
            <div class="col-12"><label class="form-label">Notes</label><textarea id="pd_notes" class="form-control"
                rows="2"></textarea></div>
            <div class="col-12 d-flex gap-2">
              <button class="btn btn-primary btn-sm" onclick="saveDef('proposal')"><i
                  class="fas fa-save me-1"></i>Save</button>
              <button class="btn btn-secondary btn-sm" onclick="cancelDF('pd')">Cancel</button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ════ THESIS Modal ════ -->
<div class="modal fade" id="mThesis" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header bg-primary">
        <h5 class="modal-title"><i class="fas fa-graduation-cap me-2"></i><span id="thMT">Add Thesis</span></h5>
        <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="th_id"><input type="hidden" id="th_yr">
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label fw-bold">Student <span
                class="text-danger">*</span></label><select id="th_std" class="form-select"></select></div>
          <div class="col-12"><label class="form-label fw-bold">Thesis / Dissertation Title <span
                class="text-danger">*</span></label><textarea id="th_title" class="form-control" rows="2"></textarea>
          </div>
          <div class="col-12" id="thTitleNoteW" style="display:none">
            <label class="form-label text-warning fw-bold"><i class="fas fa-exclamation-triangle me-1"></i>Title Change
              Note <small class="text-muted fw-normal">(optional – reason for changing the title)</small></label>
            <input type="text" id="th_cnote" class="form-control border-warning"
              placeholder="e.g. Scope refined after proposal defense...">
          </div>
          <div class="col-md-6"><label class="form-label fw-bold">Major Advisor</label><select id="th_major"
              class="form-select"></select></div>
          <div class="col-md-6"><label class="form-label fw-bold">Co-chair</label><select id="th_cochair"
              class="form-select"></select></div>
          <div class="col-12">
            <label class="form-label fw-bold"><i class="fas fa-link me-1 text-success"></i>Completed Thesis File
              Link</label>
            <input type="url" id="th_flink" class="form-control"
              placeholder="https://drive.google.com/... or any URL to the completed thesis">
            <small class="text-muted">Paste a link to the final thesis document (Google Drive, OneDrive, institutional
              repository, etc.)</small>
          </div>
        </div>
        <hr>
        <div class="sh">Co-advisors <small class="text-muted fw-normal">(max 6)</small></div>
        <div class="row g-2">
          <?php for ($i = 1; $i <= 6; $i++): ?>
            <div class="col-md-4"><label class="form-label text-muted small">Co-advisor <?= $i ?></label><select
                id="th_co<?= $i ?>" class="form-select"></select></div>
          <?php endfor; ?>
        </div>
        <hr>
        <div class="sh">External Examiners</div>
        <div class="alert alert-info py-2 small mb-2"><i class="fas fa-info-circle me-1"></i>Before 2022: 1 external
          examiner &nbsp;|&nbsp; 2022 onwards: up to 2 external examiners.</div>
        <div class="row g-2">
          <div class="col-md-6"><label class="form-label text-muted small">External Examiner 1</label><select
              id="th_ext1" class="form-select"></select></div>
          <div class="col-md-6" id="thExt2W"><label class="form-label text-muted small">External Examiner
              2</label><select id="th_ext2" class="form-select"></select></div>
        </div>
      </div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button
          class="btn btn-success" onclick="saveThesis()"><i class="fas fa-save me-1"></i>Save</button></div>
    </div>
  </div>
</div>

<!-- ════ THESIS DEFENSE Modal ════ -->
<div class="modal fade" id="mThDef" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header bg-warning">
        <h5 class="modal-title"><i class="fas fa-shield-alt me-2"></i>Thesis Defense Records</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <div class="small text-muted mb-1"><i class="fas fa-user-graduate me-1"></i><span id="tdStudentName" class="fw-semibold text-dark"></span></div>
          <div class="d-flex justify-content-between align-items-center">
            <strong id="tdTitle" class="text-success small"></strong>
            <button class="btn btn-sm btn-success" onclick="openDefForm('td')"><i class="fas fa-plus me-1"></i>Add Defense</button>
          </div>
        </div>
        <!-- Softskill count panel (requirement 4) -->
        <div id="tdSoftskillPanel" class="alert alert-info py-2 small mb-3 d-none">
          <i class="fas fa-star me-1 text-warning"></i>
          <strong>Softskill Progress:</strong>
          <span id="tdSoftskillText">Loading...</span>
        </div>
        <div id="tdList"></div>
        <div id="tdForm" class="card border-success p-3 mt-3 d-none">
          <input type="hidden" id="td_did">
          <div class="row g-2">
            <div class="col-md-3"><label class="form-label fw-bold">Defense Date <span
                  class="text-danger">*</span></label><input type="date" id="td_date" class="form-control" lang="en-GB"
                onchange="calcTD()"></div>
            <div class="col-md-3"><label class="form-label fw-bold">Result</label>
              <select id="td_result" class="form-select" onchange="togCD('td');calcTD()">
                <option value="pass_no_condition">Pass without condition</option>
                <option value="pass_with_condition">Pass with condition</option>
                <option value="not_pass">Not pass</option>
              </select>
            </div>
            <div class="col-md-3 d-none" id="tdCDW"><label class="form-label fw-bold">Condition (days)</label><input
                type="number" id="td_cdays" class="form-control" min="1" onchange="calcTD()"></div>
          </div>
          <div id="calcBox" class="calc-box mt-3 d-none">
            <div class="row g-2">
              <div class="col-md-6 d-none" id="finalDueW"><i
                  class="fas fa-calendar-alt text-warning me-1"></i><strong>Final Thesis Due:</strong> <span
                  id="cFinalDue" class="ms-1"></span></div>
              <div class="col-md-6"><i class="fas fa-calendar-check text-primary me-1"></i><strong>Submission
                  Due:</strong> <span id="cSubDue" class="ms-1"></span></div>
            </div>
          </div>
          <div class="row g-2 mt-2">
            <div class="col-md-4 d-none" id="actFinalW"><label class="form-label">Official Final Thesis
                Deadline</label><input type="date" id="td_af" class="form-control" lang="en-GB"></div>
            <div class="col-md-4"><label class="form-label">Submitted date</label><input type="date" id="td_as" lang="en-GB"
                class="form-control"></div>
            <div class="col-12"><label class="form-label">Notes</label><textarea id="td_notes" class="form-control"
                rows="2"></textarea></div>
            <div class="col-12 d-flex gap-2">
              <button class="btn btn-success btn-sm" onclick="saveDef('thesis')"><i
                  class="fas fa-save me-1"></i>Save</button>
              <button class="btn btn-secondary btn-sm" onclick="cancelDF('td')">Cancel</button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ════ THESIS TITLE HISTORY Modal ════ -->
<div class="modal fade" id="mThHistory" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title"><i class="fas fa-history me-2"></i>Thesis Title Change History</h5>
        <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="small text-muted mb-1"><i class="fas fa-user-graduate me-1"></i><span id="thHistStudentName" class="fw-semibold text-dark"></span></div>
        <strong id="thHistTitle" class="d-block mb-3 small text-success"></strong>
        <div id="thHistList">
          <p class="text-muted text-center py-3"><i class="fas fa-spinner fa-spin"></i> Loading...</p>
        </div>
      </div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<!-- ════ PUBLICATION / CONFERENCE Modal ════ -->
<div class="modal fade" id="mPub" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header bg-info text-dark">
        <h5 class="modal-title"><i class="fas fa-journal-whills me-2"></i><span id="pubMT">Add Publication</span></h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="pu_id"><input type="hidden" id="pu_type">

        <!-- Row 1: Student + Title -->
        <div class="row g-3 mb-3">
          <div class="col-md-5">
            <label class="form-label fw-bold">Related Student</label>
            <select id="pu_std" class="form-select"></select>
            <small class="text-muted">Selecting a student will auto-set them as First Author.</small>
          </div>
          <div class="col-md-7">
            <label class="form-label fw-bold">Title <span class="text-danger">*</span></label>
            <textarea id="pu_title" class="form-control" rows="2"></textarea>
          </div>
        </div>
        <!-- Proposal Approve Date alert -->
        <div id="pubApprAlert" class="alert alert-warning py-2 small d-none mb-2">
        </div>

        <!-- AUTHORS SECTION (moved above details) -->
        <div class="sh mb-2"><i class="fas fa-users me-1"></i>Authors

        </div>
        <div class="alert alert-info py-2 small mb-2">
          <i class="fas fa-lock me-1"></i><strong>First Author</strong> is automatically set to the selected student and
          cannot be removed.
          Add co-authors and corresponding author as needed.
        </div>
        <!-- Locked First Author row -->
        <div class="log-row author-locked mb-2" id="ar_first">
          <div class="row g-2 align-items-center">
            <div class="col-md-2">
              <span class="locked-badge"><i class="fas fa-lock me-1"></i>First Author</span>
            </div>
            <div class="col-md-4">
              <label class="form-label small text-muted mb-0">Student (First Author)</label>
              <select id="ar_first_mpd" class="form-select form-select-sm"></select>
            </div>
            <div class="col-md-3">
              <label class="form-label small text-muted mb-0">Affiliation</label>
              <input type="text" id="ar_first_af" class="form-control form-control-sm"
                placeholder="Department / University">
            </div>
            <div class="col-md-3 text-muted small fst-italic d-flex align-items-end pb-1">
              <i class="fas fa-info-circle me-1"></i>Auto-set from student dropdown
            </div>
          </div>
        </div>
        <!-- Dynamic additional authors -->
        <div id="authCon" class="mb-2"></div>
        <button class="btn btn-sm btn-outline-primary mb-3" onclick="addAR()"><i class="fas fa-plus me-1"></i>Add
          Author</button>

        <!-- JOURNAL DETAILS (simplified) -->
        <div id="journalF">
          <hr>
          <div class="sh"><i class="fas fa-journal-whills me-1"></i>Journal Details</div>
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label fw-bold">Journal Name</label><input type="text" id="pu_jname"
                class="form-control"></div>
            <div class="col-md-6">
              <label class="form-label fw-bold">Article Type</label>
              <select id="pu_atype" class="form-select">
                <option value="">— Select type —</option>
                <option value="Original Article">Original Article</option>
                <option value="Systematic Review and Meta-Analysis">Systematic Review and Meta-Analysis</option>
                <option value="Clinical Practice Guideline">Clinical Practice Guideline</option>
                <option value="Patent">Patent</option>
              </select>
            </div>
            <div class="col-md-3"><label class="form-label fw-bold">Year Published</label><input type="number"
                id="pu_year" class="form-control" min="2000" max="2100"></div>
            <div class="col-md-3"><label class="form-label fw-bold">Quartile</label>
              <select id="pu_qrt" class="form-select">
                <option value="">—</option>
                <option>Q1</option>
                <option>Q2</option>
                <option>Q3</option>
                <option>Q4</option>
              </select>
            </div>
            <div class="col-md-3"><label class="form-label fw-bold">Percentile</label>
              <select id="pu_pct" class="form-select">
                <option value="">—</option>
                <option value="1">Top 1%</option>
                <option value="5">Top 5%</option>
                <option value="10">Top 10%</option>
              </select>
            </div>
            <div class="col-md-4"><label class="form-label">DOI</label><input type="text" id="pu_doi"
                class="form-control" placeholder="10.xxxx/xxxxx"></div>
            <div class="col-md-5"><label class="form-label">PubMed ID</label><input type="text" id="pu_pmid"
                class="form-control" placeholder="PMID number"></div>
          </div>
        </div>

        <!-- CONFERENCE DETAILS -->
        <div id="confF" class="d-none">
          <hr>
          <div class="sh"><i class="fas fa-microphone me-1"></i>Conference Details</div>
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label fw-bold">Conference Name</label><input type="text"
                id="pu_cname" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">Location</label><input type="text" id="pu_cloc"
                class="form-control"></div>
            <div class="col-md-4"><label class="form-label">Conference Date</label><input type="date" id="pu_cdate" lang="en-GB"
                class="form-control"></div>
            <div class="col-md-4"><label class="form-label">Presentation Type</label>
              <select id="pu_ptype" class="form-select">
                <option value="">—</option>
                <option value="oral">Oral</option>
                <option value="poster">Poster</option>
                <option value="keynote">Keynote</option>
              </select>
            </div>
          </div>
        </div>

        <div class="alert alert-secondary py-2 small mt-3 mb-0">
          <i class="fas fa-info-circle me-1"></i><strong>Status</strong> is automatically derived from Submission
          History.
          Use the <i class="fas fa-paper-plane"></i> button on the list to record submission rounds.
        </div>
      </div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button
          class="btn btn-info" onclick="savePub()"><i class="fas fa-save me-1"></i>Save</button></div>
    </div>
  </div>
</div>

<!-- ════ SUBMISSION LOG Modal ════ -->
<div class="modal fade" id="mSubLog" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header bg-secondary text-white">
        <h5 class="modal-title"><i class="fas fa-paper-plane me-2"></i>Submission History</h5>
        <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="small text-muted mb-1"><i class="fas fa-user-graduate me-1"></i><span id="slStudentName" class="fw-semibold text-dark"></span></div>
        <strong id="slTitle" class="d-block mb-3 small text-primary"></strong>
        <div class="d-flex justify-content-end mb-3"><button class="btn btn-sm btn-success" onclick="openSLF()"><i
              class="fas fa-plus me-1"></i>Add Submission</button></div>
        <div id="slList"></div>
        <div id="slForm" class="card border-secondary p-3 mt-3 d-none">
          <input type="hidden" id="sl_lid">
          <div class="row g-2">
            <div class="col-md-5"><label class="form-label fw-bold">Journal / Conference <span
                  class="text-danger">*</span></label><input type="text" id="sl_j" class="form-control"></div>
            <div class="col-md-3"><label class="form-label fw-bold">Submit Date</label><input type="date" id="sl_sdate" lang="en-GB"
                class="form-control"></div>
            <div class="col-md-4"><label class="form-label fw-bold">Status</label>
              <select id="sl_stat" class="form-select">
                <option value="submitted">Submitted</option>
                <option value="revision_requested">Revision Requested</option>
                <option value="under_review">Under Review</option>
                <option value="resubmitted">Resubmitted</option>
                <option value="deferred">Decision in Process</option>
                <option value="accepted">Accepted</option>
                <option value="rejected">Rejected</option>
                <option value="withdrawn">Withdrawn</option>
              </select>
            </div>
            <div class="col-md-3"><label class="form-label">Response Date</label><input type="date" id="sl_rdate" lang="en-GB"
                class="form-control"></div>
            <div class="col-md-3"><label class="form-label">Resubmit Date</label><input type="date" id="sl_rsdate" lang="en-GB"
                class="form-control"></div>
            <div class="col-md-3"><label class="form-label">Decision Date</label><input type="date" id="sl_ddate" lang="en-GB"
                class="form-control"></div>
            <div class="col-12"><label class="form-label">Notes</label><textarea id="sl_notes" class="form-control"
                rows="2"></textarea></div>
            <div class="col-12 d-flex gap-2">
              <button class="btn btn-secondary btn-sm" onclick="saveSL()"><i class="fas fa-save me-1"></i>Save</button>
              <button class="btn btn-light btn-sm" onclick="cancelSL()">Cancel</button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
  (function () {
    function loadCSS(u) {
      if (document.querySelector('link[href="' + u + '"]')) return;
      var l = document.createElement('link'); l.rel = 'stylesheet'; l.href = u; document.head.appendChild(l);
    }
    function loadJS(u, cb) {
      var existing = document.querySelector('script[src="' + u + '"]');
      if (existing) {
        if (typeof cb === 'function') {
          if (existing.dataset.loaded === '1') cb();
          else existing.addEventListener('load', function onLoad() {
            existing.removeEventListener('load', onLoad);
            cb();
          });
        }
        return;
      }
      var s = document.createElement('script');
      s.src = u;
      s.onload = function () {
        s.dataset.loaded = '1';
        if (cb) cb();
      };
      document.head.appendChild(s);
    }
    function ensureJQuery(done) {
      if (typeof window.jQuery !== 'undefined') { done(); return; }
      loadJS('assets/js/jquery-3.7.1.min.js', done);
    }
    function waitLibs(cb) {
      if (typeof window.jQuery === 'undefined') {
        setTimeout(function () { waitLibs(cb); }, 50);
        return;
      }
      var $ = window.jQuery;
      if ($.fn && $.fn.select2 && $.fn.DataTable) {
        cb();
        return;
      }
      setTimeout(function () { waitLibs(cb); }, 50);
    }
    function bootResearchPage() {
      loadCSS('assets/css/select2.min.css');
      loadCSS('assets/css/select2-bootstrap-5-theme.min.css');
      loadCSS('assets/css/dataTables.bootstrap5.min.css');

      ensureJQuery(function () {
        var $ = window.jQuery;

        function afterLoaded() {
          waitLibs(function () {
            if (typeof researchInit === 'function') {
              researchInit();
            }
          });
        }

        if (!$.fn || !$.fn.select2) {
          loadJS('assets/js/select2.min.js', function () {
            if (!$.fn || !$.fn.DataTable) {
              loadJS('assets/js/jquery.dataTables.min.js', function () {
                loadJS('assets/js/dataTables.bootstrap5.min.js', afterLoaded);
              });
            } else {
              afterLoaded();
            }
          });
        } else if (!$.fn || !$.fn.DataTable) {
          loadJS('assets/js/jquery.dataTables.min.js', function () {
            loadJS('assets/js/dataTables.bootstrap5.min.js', afterLoaded);
          });
        } else {
          afterLoaded();
        }
      });
    }

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', bootResearchPage);
    } else {
      bootResearchPage();
    }
  })();
</script>

<script>
  // --- Configuration & Global Variables ---
  const LECS = <?= $lecJson ?>, STDS = <?= $stdJson ?>, PERS = <?= $perJson ?>, EXT_EXAMINERS = <?= $extExJson ?>;
  // Map: std_id (string) => approve_date for Publication validation
  const PROP_APPROVE_MAP = <?= $propApprJson ?>;
  const AURL = 'admin.php?page=research';

  let curPID = null, curTID = null, curPubID = null, curPubType = 'journal';
  let dtP = null, dtT = null, dtPu = null, dtCo = null;
  let researchPageInitialized = false;

  function getModalEl(selector) {
    return typeof selector === 'string' ? document.querySelector(selector) : selector;
  }
  function showModal(selector) {
    const el = getModalEl(selector);
    if (!el || typeof bootstrap === 'undefined' || !bootstrap.Modal) return;
    bootstrap.Modal.getOrCreateInstance(el).show();
  }
  function hideModal(selector) {
    const el = getModalEl(selector);
    if (!el || typeof bootstrap === 'undefined' || !bootstrap.Modal) return;
    const inst = bootstrap.Modal.getInstance(el) || bootstrap.Modal.getOrCreateInstance(el);
    inst.hide();
  }

  const post = d => $.ajax({ url: AURL, method: 'POST', data: d });
  const fmtD = d => {
    if (!d || d === '0000-00-00') return '—';
    const dt = new Date(d + 'T00:00:00');
    return dt.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
  };
  const toID = s => s ? String(s).slice(0, 10) : '';
  const addD = (ds, n) => {
    if (!ds || !n) return null;
    let d = new Date(ds + 'T00:00:00');
    d.setDate(d.getDate() + parseInt(n));
    return d.toISOString().slice(0, 10);
  };
  const rBadge = (r, days = null) => {
    if (!r) return '<span class="badge bg-secondary">—</span>';
    const base = { pass_no_condition: 'Pass ✓', pass_with_condition: 'Pass with condition', not_pass: 'Not Pass ✗' };
    let label = base[r] || r;
    if (r === 'pass_with_condition') {
      const d = (days !== null && days !== undefined && String(days).trim() !== '') ? parseInt(days, 10) : null;
      if (d && d > 0) label += ` (${d} days)`;
    }
    const cls = { pass_no_condition: 'bpn', pass_with_condition: 'bpc', not_pass: 'bnp' }[r] || 'bg-secondary';
    return `<span class="badge ${cls}">${label}</span>`;
  };
  const advisorCell = (major, coList) => {
    const m = major && String(major).trim() !== '' ? major : '—';
    const co = coList && String(coList).trim() !== '' ? coList : '—';
    return `<div class="small"><div><span class="text-muted">Major:</span> ${m}</div><div><span class="text-muted">Co:</span> ${co}</div></div>`;
  };
  const sBadge = s => {
    if (!s) return '<span class="badge bg-secondary">—</span>';
    return `<span class="badge badge-${s}">${s.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())}</span>`;
  };

  // --- Options Generators ---
  const lecOpts = () => '<option value=""></option>' + LECS.map(l => `<option value="${l.lec_id}">${l.full_name}</option>`).join('');
  const stdOpts = () => '<option value=""></option>' + STDS.map(s => {
    const grad = (s.std_status == 2) ? ' (Graduated)' : '';
    return `<option value="${s.std_id}">${s.student_id} – ${s.full_name}${grad}</option>`;
  }).join('');
  const perOpts = () => '<option value=""></option>' + PERS.map(p => `<option value="${p.mpd_id}">${p.full_name}</option>`).join('');
  const extOpts = () => '<option value=""></option>' + EXT_EXAMINERS.map(e => {
    const exp = e.expertise ? ` [${e.expertise}]` : '';
    return `<option value="${e.mpd_id}">${e.full_name}${exp}</option>`;
  }).join('');

  /** Lookup student's mpd_id by std_id */
  function stdMpdId(stdId) {
    if (!stdId) return null;
    const s = STDS.find(x => String(x.std_id) === String(stdId));
    return s ? s.mpd_id : null;
  }
  function stdFullName(stdId) {
    if (!stdId) return '';
    const s = STDS.find(x => String(x.std_id) === String(stdId));
    return s ? s.full_name : '';
  }

  // --- Select2 Helpers ---
  function s2i(id, opts, modalSel) {
    const $e = $('#' + id);
    try { if ($e.hasClass('select2-hidden-accessible')) $e.select2('destroy'); } catch (e) { }
    if (opts !== undefined) $e.html(opts);
    const cfg = { theme: 'bootstrap-5', width: '100%', allowClear: true, placeholder: '' };
    if (modalSel) cfg.dropdownParent = $(modalSel);
    $e.select2(cfg);
  }
  function s2v(id, v) { $('#' + id).val(v || '').trigger('change'); }

  /* ════ PROPOSAL LOGIC ════ */
  function loadProps() {
    post({ action: 'get_proposals' }).done(r => {
      if (!r.success) return;
      if (dtP) { dtP.destroy(); $('#tblProp tbody').empty(); }
      let h = '';
      r.data.forEach((d, i) => {
        const t = d.proposal_title ? d.proposal_title.replace(/\r?\n/g, ' ').replace(/`/g, '\\`').replace(/'/g, "\\'") : '';
        const sn_p = (d.student_name || '').replace(/'/g, "\\'");

        // --- File link cell ---
        const fileCell = d.file_link
          ? `<a href="${d.file_link}" target="_blank" class="btn btn-sm btn-outline-primary tba" title="Open file"><i class="fas fa-file-alt"></i></a>`
          : '<span class="text-muted small">—</span>';

        // --- Thesis status cell ---
        let thesisCell = '';
        if (!d.approve_date || d.approve_date === '0000-00-00') {
          thesisCell = '<span class="badge bg-light text-muted border">Pending Approve</span>';
        } else if (d.existing_thesis_id) {
          thesisCell = `<div class="d-flex flex-nowrap align-items-center gap-1"><span class="badge bg-success"><i class="fas fa-check me-1"></i>Created</span>
          <button class="btn btn-outline-success btn-sm tba"
            onclick="jumpToThesis(${d.existing_thesis_id})" title="Go to Thesis">
            <i class="fas fa-external-link-alt"></i></button></div>`;
        } else {
          thesisCell = `<button class="btn btn-success btn-sm tba text-nowrap"
            onclick="createThesisFromProposal(${d.proposal_id},'${t}')"
            title="Auto-create Thesis from this Proposal">
            <i class="fas fa-plus me-1"></i>Create Thesis</button>`;
        }

        const resultCell = (() => {
          let html = rBadge(d.latest_result, d.latest_condition_days);
          if (d.latest_result === 'pass_with_condition' && d.latest_condition_days && d.latest_defend_date) {
            const deadline = addD(d.latest_defend_date, d.latest_condition_days);
            const deadlineStr = fmtD(deadline);
            const isPast = deadline && new Date(deadline + 'T00:00:00') < new Date();
            const cls = isPast ? 'text-danger' : 'text-warning';
            html += `<div class="small mt-1 ${cls}"><i class="fas fa-calendar-day me-1"></i>Due: ${deadlineStr}</div>`;
          }
          return html;
        })();

        h += `<tr>
        <td>${i + 1}</td><td>${d.student_id}</td><td>${d.student_name}</td>
        <td class="small">${d.proposal_title}</td>
        <td>${advisorCell(d.major_advisor, d.coadvisors)}</td>
        <td><span class="badge bg-secondary">${d.defend_count}</span></td>
        <td>${resultCell}</td>
        <td>${fmtD(d.approve_date)}</td>
        <td>${fileCell}</td>
        <td>${thesisCell}</td>
        <td>
          <div class="d-flex flex-nowrap gap-1">
            <button class="btn btn-outline-warning btn-sm tba" onclick="openPropDefs(${d.proposal_id},'${t}','${sn_p}','${d.student_id||''}')"><i class="fas fa-shield-alt"></i></button>
            <button class="btn btn-outline-primary btn-sm tba" onclick="editProp(${d.proposal_id})"><i class="fas fa-edit"></i></button>
            <button class="btn btn-outline-danger btn-sm tba" onclick="delProp(${d.proposal_id})"><i class="fas fa-trash"></i></button>
          </div>
        </td></tr>`;
      });
      $('#tblProp tbody').html(h);
      dtP = $('#tblProp').DataTable({
        order: [[1, 'asc']], pageLength: 25, destroy: true,
        language: { emptyTable: 'No proposals' },
        columnDefs: [{ orderable: false, targets: [8, 9, 10] }]
      });
    });
  }

  function openPropModal() {
    $('#p_pid').val(''); $('#p_title').val(''); $('#p_appr').val('');
    $('#p_flink').val('');
    $('#propMT').text('Add Proposal');
    s2i('p_std', stdOpts(), '#mProp');
    s2i('p_major', lecOpts(), '#mProp');
    for (let i = 1; i <= 6; i++) s2i('p_co' + i, lecOpts(), '#mProp');
    showModal('#mProp');
  }

  function editProp(id) {
    openPropModal();
    post({ action: 'get_proposal', proposal_id: id }).done(r => {
      if (!r.success) return; const d = r.data;
      $('#p_pid').val(d.proposal_id);
      s2v('p_std', d.std_id);
      $('#p_title').val(d.proposal_title);
      s2v('p_major', d.major_advisor_id);
      $('#p_appr').val(toID(d.approve_date));
      
      $('#p_flink').val(d.file_link || '');
      for (let i = 1; i <= 6; i++) s2v('p_co' + i, '');
      (d.coadvisors || []).forEach((c, idx) => s2v('p_co' + (idx + 1), c.lec_id));
      $('#propMT').text('Edit Proposal');
    });
  }

  function saveProp() {
    const data = {
      action: 'save_proposal', proposal_id: $('#p_pid').val(), std_id: $('#p_std').val(),
      proposal_title: $('#p_title').val().trim(), major_advisor_id: $('#p_major').val(),
      approve_date: $('#p_appr').val(),
      file_link: $('#p_flink').val().trim()
    };
    if (!data.std_id || !data.proposal_title) { alert('Please select a Student and enter a Title.'); return; }

    // Client-side duplicate co-advisor check
    const major = data.major_advisor_id;
    const seen = new Set();
    for (let i = 1; i <= 6; i++) {
      const v = $('#p_co' + i).val();
      if (!v) continue;
      if (v === major) { alert(`Co-advisor ${i} is the same person as the Major Advisor.`); return; }
      if (seen.has(v)) { alert(`Co-advisor ${i} has already been selected.`); return; }
      seen.add(v);
      data['coadvisor_' + i] = v;
    }

    post(data).done(r => {
      if (r.success) {
        // Update local map so JS validation works without refresh
        if (data.approve_date) {
          PROP_APPROVE_MAP[String(data.std_id)] = data.approve_date;
        } else {
          delete PROP_APPROVE_MAP[String(data.std_id)];
        }

        hideModal('#mProp');
        loadProps();
        // If Approve Date is set on a new Proposal → offer to create Thesis
        if (data.approve_date && !data.proposal_id) {
          const offerThesis = confirm(
            `✅ Proposal saved successfully!\n\n` +
            `An Approve Topic Date has been set.\n` +
            `Would you like to auto-create a Thesis from this Proposal now?`
          );
          if (offerThesis) {
            createThesisFromProposal(r.proposal_id, data.proposal_title);
          }
        }
      }
      else alert('Error: ' + r.error);
    });
  }

  function delProp(id) {
    if (confirm('Delete this proposal and all its defenses?')) {
      // Find std_id for local map update
      const stdId = $('#p_std').val(); // Might not be accurate if deleted from list, but handled by reloading page if strictly needed
      post({ action: 'delete_proposal', proposal_id: id }).done(r => {
        if (r.success) {
          loadProps();
        }
      });
    }
  }

  /* ──────────────────────────────────────────────────────────────────
     AUTO-CREATE THESIS FROM PROPOSAL
     Called when a Proposal has an Approve Topic Date set.
     ────────────────────────────────────────────────────────────────── */
  function createThesisFromProposal(proposalId, proposalTitle) {
    // Confirm before creating
    if (!confirm(
      `Auto-create a Thesis from this Proposal?\n\n"${proposalTitle}"\n\n` +
      `The system will copy: Title, Student, Advisor and Co-advisors to the new Thesis.\n` +
      `You can add Co-chair and External Examiners afterwards.`
    )) return;

    post({ action: 'auto_create_thesis_from_proposal', proposal_id: proposalId })
      .done(r => {
        if (r.success) {
          // Created successfully → reload Proposal list + offer to open Thesis for editing
          loadProps();

          // Show success notification + ask whether to edit Thesis now
          const goEdit = confirm(
            `✅ Thesis created successfully!\n\n` +
            `Student: ${r.student_name} (${r.student_id})\n` +
            `Thesis ID: ${r.thesis_id}\n\n` +
            `Would you like to open the Thesis now to add Co-chair / External Examiners?`
          );
          if (goEdit) {
            // Switch to Thesis tab and open Edit Modal
            const thesisTab = document.getElementById('t-thesis-lnk');
            thesisTab.click();
            // Wait for DataTable to load before editing
            setTimeout(() => editThesis(r.thesis_id), 600);
          }
        } else if (r.already_exists) {
          const goView = confirm(
            `⚠️ This student already has a Thesis (Thesis ID: ${r.thesis_id})\n\n` +
            `Would you like to view / edit the existing Thesis?`
          );
          if (goView) jumpToThesis(r.thesis_id);
        } else {
          alert('Error: ' + r.error);
        }
      });
  }

  /** Switch to Thesis tab and open Edit Modal for the given thesis_id */
  function jumpToThesis(thesisId) {
    const thesisTab = document.getElementById('t-thesis-lnk');
    thesisTab.click();
    // If dtT not loaded yet, wait then edit
    const tryEdit = (attempts) => {
      if (dtT || attempts <= 0) {
        editThesis(thesisId);
      } else {
        setTimeout(() => tryEdit(attempts - 1), 400);
      }
    };
    setTimeout(() => tryEdit(8), 300);
  }

  function openPropDefs(id, title, studentName, studentId) {
    curPID = id; $('#pdTitle').text(title); $('#pdForm').addClass('d-none');
    $('#pdStudentName').text((studentId ? studentId + ' – ' : '') + (studentName || ''));
    loadPropDefs();
    showModal('#mPropDef');
  }

  function loadPropDefs() {
    post({ action: 'get_proposal_defends', proposal_id: curPID }).done(r => {
      let h = '';
      if (!r.data || !r.data.length) { h = '<p class="text-muted text-center py-3">No defenses yet.</p>'; }
      (r.data || []).forEach((d, i) => {
        h += `<div class="log-row">
        <div class="d-flex justify-content-between">
          <div><span class="fw-bold">Defense #${i + 1}</span>
            <span class="ms-2 text-muted">${fmtD(d.defend_date)}</span>
            <span class="ms-2">${rBadge(d.result, d.condition_days)}</span>
          </div>
          <div class="d-flex gap-1">
            <button class="btn btn-sm btn-outline-secondary tba" onclick="editDef('proposal',${d.defend_id})"><i class="fas fa-edit"></i></button>
            <button class="btn btn-sm btn-outline-danger tba" onclick="delPropDef(${d.defend_id})"><i class="fas fa-trash"></i></button>
          </div>
        </div>
        ${d.notes ? `<small class="text-muted d-block mt-1">📝 ${d.notes}</small>` : ''}
      </div>`;
      });
      $('#pdList').html(h);
    });
  }

  /* ════ THESIS LOGIC ════ */
  function loadTheses() {
    post({ action: 'get_theses' }).done(r => {
      if (!r.success) return;
      if (dtT) { dtT.destroy(); $('#tblThesis tbody').empty(); }
      let h = '';
      r.data.forEach((d, i) => {
        const t = d.thesis_title ? d.thesis_title.replace(/\r?\n/g, ' ').replace(/`/g, '\\`').replace(/'/g, "\\'") : '';
        const sn_t = (d.student_name || '').replace(/'/g, "\\'");
        const fileCell = d.file_link
          ? `<a href="${d.file_link}" target="_blank" class="btn btn-sm btn-outline-success tba" title="Open file"><i class="fas fa-file-alt"></i></a>`
          : '<span class="text-muted small">—</span>';
        h += `<tr>
        <td>${i + 1}</td><td>${d.student_id}</td><td>${d.student_name}</td>
        <td class="small">${d.thesis_title}</td>
        <td>${advisorCell(d.major_advisor, d.coadvisors)}</td><td>${d.cochair || '—'}</td>
        <td>${fileCell}</td>
        <td><span class="badge bg-secondary">${d.defend_count}</span></td>
        <td>${rBadge(d.latest_result, d.latest_condition_days)}</td>
        <td>
          <div class="d-flex flex-nowrap gap-1">
            <button class="btn btn-outline-warning btn-sm tba" onclick="openThDefs(${d.thesis_id},'${t}',${d.std_id},'${sn_t}','${d.student_id||''}')"><i class="fas fa-shield-alt"></i></button>
            <button class="btn btn-outline-dark btn-sm tba" onclick="openThHistory(${d.thesis_id},'${t}','${sn_t}','${d.student_id||''}')" title="Title Change History"><i class="fas fa-history"></i></button>
            <button class="btn btn-outline-primary btn-sm tba" onclick="editThesis(${d.thesis_id})"><i class="fas fa-edit"></i></button>
            <button class="btn btn-outline-danger btn-sm tba" onclick="delThesis(${d.thesis_id})"><i class="fas fa-trash"></i></button>
          </div>
        </td></tr>`;
      });
      $('#tblThesis tbody').html(h);
      dtT = $('#tblThesis').DataTable({ order: [[1, 'asc']], pageLength: 25, destroy: true, language: { emptyTable: 'No thesis' }, columnDefs: [{ orderable: false, targets: [6, 9] }] });
    });
  }

  function openThesisModal() {
    $('#th_id').val(''); $('#th_yr').val(''); $('#th_title').val(''); $('#thMT').text('Add Thesis');
    $('#th_flink').val(''); $('#th_cnote').val(''); $('#thTitleNoteW').hide();
    s2i('th_std', stdOpts(), '#mThesis');
    ['th_major', 'th_cochair', 'th_co1', 'th_co2', 'th_co3', 'th_co4', 'th_co5', 'th_co6'].forEach(id => s2i(id, lecOpts(), '#mThesis'));
    ['th_ext1', 'th_ext2'].forEach(id => s2i(id, extOpts(), '#mThesis'));
    $('#thExt2W').show();
    showModal('#mThesis');
  }

  let _origThesisTitle = '';
  function editThesis(id) {
    openThesisModal();
    post({ action: 'get_thesis', thesis_id: id }).done(r => {
      if (!r.success) return; const d = r.data;
      $('#th_id').val(d.thesis_id); $('#th_yr').val(d.s_yearin);
      _origThesisTitle = d.thesis_title || '';
      s2v('th_std', d.std_id); $('#th_title').val(d.thesis_title);
      s2v('th_major', d.major_advisor_id); s2v('th_cochair', d.cochair_id);
      $('#th_flink').val(d.file_link || '');
      for (let i = 1; i <= 6; i++) s2v('th_co' + i, '');
      (d.coadvisors || []).forEach((c, idx) => s2v('th_co' + (idx + 1), c.lec_id));
      ['th_ext1', 'th_ext2'].forEach(id2 => s2v(id2, ''));
      (d.externals || []).forEach(e => s2v('th_ext' + e.sort_order, e.mpd_id));
      if (d.s_yearin && parseInt(d.s_yearin) < 2022) $('#thExt2W').hide(); else $('#thExt2W').show();
      $('#thMT').text('Edit Thesis');
      // Detect title change to show note field
      $('#th_title').off('input.titlechg').on('input.titlechg', function () {
        const changed = $(this).val().trim() !== _origThesisTitle.trim();
        $('#thTitleNoteW').toggle(changed);
      });
    });
  }

  function saveThesis() {
    const data = {
      action: 'save_thesis', thesis_id: $('#th_id').val(), std_id: $('#th_std').val(),
      thesis_title: $('#th_title').val().trim(), major_advisor_id: $('#th_major').val(),
      cochair_id: $('#th_cochair').val(), file_link: $('#th_flink').val().trim(),
      change_note: $('#th_cnote').val().trim()
    };
    if (!data.std_id || !data.thesis_title) { alert('Please select a Student and enter a Title.'); return; }

    // Client-side duplicate co-advisor check
    const major = data.major_advisor_id;
    const cochair = data.cochair_id;
    const seen = new Set();
    for (let i = 1; i <= 6; i++) {
      const v = $('#th_co' + i).val();
      if (!v) continue;
      if (v === major) { alert(`Co-advisor ${i} is the same person as the Major Advisor.`); return; }
      if (cochair && v === cochair) { alert(`Co-advisor ${i} is the same person as the Co-chair.`); return; }
      if (seen.has(v)) { alert(`Co-advisor ${i} has already been selected.`); return; }
      seen.add(v);
      data['coadvisor_' + i] = v;
    }
    for (let i = 1; i <= 2; i++) data['external_' + i] = $('#th_ext' + i).val();
    post(data).done(r => {
      if (r.success) { hideModal('#mThesis'); loadTheses(); }
      else alert('Error: ' + r.error);
    });
  }

  function delThesis(id) {
    if (confirm('Delete thesis and all defenses?'))
      post({ action: 'delete_thesis', thesis_id: id }).done(r => { if (r.success) loadTheses(); });
  }

  function openThDefs(id, title, stdId, studentName, studentId) {
    curTID = id; $('#tdTitle').text(title); $('#tdForm').addClass('d-none');
    $('#tdStudentName').text((studentId ? studentId + ' – ' : '') + (studentName || ''));
    // Show softskill count (requirement 4)
    $('#tdSoftskillPanel').removeClass('d-none');
    $('#tdSoftskillText').html('<i class="fas fa-spinner fa-spin"></i> Loading...');
    if (stdId) {
      post({ action: 'get_softskill_count', std_id: stdId }).done(r => {
        if (r.success) {
          const pct = r.total > 0 ? Math.round((r.passed / r.total) * 100) : 0;
          const color = pct === 100 ? 'text-success' : (pct >= 50 ? 'text-warning' : 'text-danger');
          $('#tdSoftskillText').html(
            `<span class="${color} fw-bold">${r.passed} / ${r.total}</span> skills passed (${pct}%)` +
            (pct < 100 ? `  <span class="text-danger ms-2"><i class="fas fa-exclamation-circle"></i> ${r.total - r.passed} remaining</span>` : `  <span class="text-success ms-2"><i class="fas fa-check-circle"></i> All complete!</span>`)
          );
        } else {
          $('#tdSoftskillText').text('Unable to load softskill data.');
        }
      });
    } else {
      $('#tdSoftskillPanel').addClass('d-none');
    }
    loadThDefs();
    showModal('#mThDef');
  }

  function openThHistory(thesisId, title, studentName, studentId) {
    $('#thHistTitle').text(title);
    $('#thHistStudentName').text((studentId ? studentId + ' – ' : '') + (studentName || ''));
    $('#thHistList').html('<p class="text-muted text-center py-3"><i class="fas fa-spinner fa-spin"></i> Loading...</p>');
    showModal('#mThHistory');
    post({ action: 'get_thesis_title_history', thesis_id: thesisId }).done(r => {
      if (!r.success || !r.data || !r.data.length) {
        $('#thHistList').html('<p class="text-muted text-center py-4"><i class="fas fa-info-circle me-1"></i>No title changes recorded for this thesis.</p>');
        return;
      }
      let h = '<div class="timeline-hist">';
      r.data.forEach((rec, idx) => {
        h += `<div class="log-row mb-2">
        <div class="d-flex justify-content-between align-items-start">
          <span class="badge bg-secondary">#${idx + 1}</span>
          <small class="text-muted">${fmtD(rec.changed_at ? rec.changed_at.slice(0, 10) : '')} ${rec.changed_at ? rec.changed_at.slice(11, 16) : ''}</small>
        </div>
        <div class="mt-2">
          <div class="small text-danger"><i class="fas fa-minus-circle me-1"></i><strong>Old:</strong> ${rec.old_title || '—'}</div>
          <div class="small text-success mt-1"><i class="fas fa-plus-circle me-1"></i><strong>New:</strong> ${rec.new_title || '—'}</div>
          ${rec.change_note ? `<div class="small text-muted mt-1"><i class="fas fa-sticky-note me-1"></i>${rec.change_note}</div>` : ''}
        </div>
      </div>`;
      });
      h += '</div>';
      $('#thHistList').html(h);
    });
  }

  function loadThDefs() {
    post({ action: 'get_thesis_defends', thesis_id: curTID }).done(r => {
      let h = '';
      if (!r.data || !r.data.length) { h = '<p class="text-muted text-center py-3">No defenses yet.</p>'; }
      (r.data || []).forEach((d, i) => {
        let dh = '';
        if (d.result === 'pass_with_condition')
          dh = `<div class="mt-1 small">📅 <b>Final Due:</b> ${fmtD(d.final_thesis_due)} | 📅 <b>Sub. Due:</b> ${fmtD(d.due_date_submission)}${d.actual_final_thesis_date ? ` | ✅ <b>Final Submitted:</b> ${fmtD(d.actual_final_thesis_date)}` : ''}${d.actual_submission_date ? ` | ✅ <b>Submitted:</b> ${fmtD(d.actual_submission_date)}` : ''}</div>`;
        else if (d.result === 'pass_no_condition')
          dh = `<div class="mt-1 small">📅 <b>Sub. Due:</b> ${fmtD(d.due_date_submission)}${d.actual_submission_date ? ` | ✅ <b>Submitted:</b> ${fmtD(d.actual_submission_date)}` : ''}</div>`;
        h += `<div class="log-row">
        <div class="d-flex justify-content-between">
          <div><span class="fw-bold">Defense #${i + 1}</span>
            <span class="ms-2 text-muted">${fmtD(d.defend_date)}</span>
            <span class="ms-2">${rBadge(d.result, d.condition_days)}</span>
            ${d.result === 'pass_with_condition' ? `<small class="ms-1 text-warning">(${d.condition_days}d)</small>` : ''}
          </div>
          <div class="d-flex gap-1">
            <button class="btn btn-sm btn-outline-secondary tba" onclick="editDef('thesis',${d.defend_id})"><i class="fas fa-edit"></i></button>
            <button class="btn btn-sm btn-outline-danger tba" onclick="delThDef(${d.defend_id})"><i class="fas fa-trash"></i></button>
          </div>
        </div>
        ${dh}
        ${d.notes ? `<small class="text-muted d-block mt-1">📝 ${d.notes}</small>` : ''}
      </div>`;
      });
      $('#tdList').html(h);
    });
  }

  function calcTD() {
    const date = $('#td_date').val(), result = $('#td_result').val(), cdays = parseInt($('#td_cdays').val()) || 0;
    if (!date || result === 'not_pass') { $('#calcBox').addClass('d-none'); $('#actFinalW').addClass('d-none'); return; }
    $('#calcBox').removeClass('d-none');
    if (result === 'pass_no_condition') {
      $('#finalDueW').addClass('d-none'); $('#actFinalW').addClass('d-none');
      $('#cSubDue').text(fmtD(addD(date, 21)));
    } else if (result === 'pass_with_condition' && cdays > 0) {
      const fd = addD(date, cdays);
      $('#finalDueW').removeClass('d-none'); $('#actFinalW').removeClass('d-none');
      $('#cFinalDue').text(fmtD(fd)); $('#cSubDue').text(fmtD(addD(fd, 21)));
    }
  }

  /* ── Shared Defend Form ── */
  function openDefForm(pre) {
    $(`#${pre}_did`).val(''); $(`#${pre}_date`).val('');
    $(`#${pre}_result`).val('pass_no_condition');
    $(`#${pre}_notes`).val(''); $(`#${pre}CDW`).addClass('d-none'); $(`#${pre}_cdays`).val('');
    if (pre === 'td') { $('#calcBox').addClass('d-none'); $('#td_af').val(''); $('#td_as').val(''); $('#actFinalW').addClass('d-none'); }
    $(`#${pre}Form`).removeClass('d-none');
  }
  function cancelDF(pre) { $(`#${pre}Form`).addClass('d-none'); }
  function togCD(pre) {
    $('#' + pre + '_result').val() === 'pass_with_condition' ? $('#' + pre + 'CDW').removeClass('d-none') : $('#' + pre + 'CDW').addClass('d-none');
  }

  function editDef(type, did) {
    const act = type === 'proposal' ? 'get_proposal_defends' : 'get_thesis_defends';
    const idk = type === 'proposal' ? 'proposal_id' : 'thesis_id';
    const cid = type === 'proposal' ? curPID : curTID;
    const pre = type === 'proposal' ? 'pd' : 'td';
    post({ action: act, [idk]: cid }).done(r => {
      const d = (r.data || []).find(x => x.defend_id == did); if (!d) return;
      $(`#${pre}_did`).val(d.defend_id); $(`#${pre}_date`).val(toID(d.defend_date));
      $(`#${pre}_result`).val(d.result); $(`#${pre}_notes`).val(d.notes || '');
      togCD(pre);
      if (d.result === 'pass_with_condition') $(`#${pre}_cdays`).val(d.condition_days);
      if (type === 'thesis') { $('#td_af').val(toID(d.actual_final_thesis_date)); $('#td_as').val(toID(d.actual_submission_date)); calcTD(); }
      $(`#${pre}Form`).removeClass('d-none');
    });
  }

  function saveDef(type) {
    const pre = type === 'proposal' ? 'pd' : 'td';
    const act = type === 'proposal' ? 'save_proposal_defend' : 'save_thesis_defend';
    const idk = type === 'proposal' ? 'proposal_id' : 'thesis_id';
    const cid = type === 'proposal' ? curPID : curTID;
    const data = {
      action: act, [idk]: cid,
      defend_id: $(`#${pre}_did`).val(), defend_date: $(`#${pre}_date`).val(),
      result: $(`#${pre}_result`).val(), condition_days: $(`#${pre}_cdays`).val(), notes: $(`#${pre}_notes`).val()
    };
    if (type === 'thesis') { data.actual_final_thesis_date = $('#td_af').val(); data.actual_submission_date = $('#td_as').val(); }
    if (!data.defend_date) { alert('Please enter a Defense Date.'); return; }
    post(data).done(r => {
      if (r.success) {
        cancelDF(pre);
        if (type === 'proposal') { loadPropDefs(); loadProps(); } else { loadThDefs(); loadTheses(); }
      } else alert('Error: ' + r.error);
    });
  }

  function delPropDef(did) {
    if (confirm('Delete this defense record?'))
      post({ action: 'delete_proposal_defend', defend_id: did }).done(r => { if (r.success) { loadPropDefs(); loadProps(); } });
  }
  function delThDef(did) {
    if (confirm('Delete this defense record?'))
      post({ action: 'delete_thesis_defend', defend_id: did }).done(r => { if (r.success) { loadThDefs(); loadTheses(); } });
  }

  /* ════ PUBLICATIONS LOGIC ════ */

  var allConfData = []; // store full conference dataset for client-side filtering

  function loadPubs(type) {
    post({ action: 'get_publications', pub_type: type }).done(r => {
      if (!r.success) return;
      if (type === 'conference') {
        allConfData = r.data || [];
        populateConfYearFilter();
        renderConfTable();
      } else {
        renderPubTable(r.data || []);
      }
    });
  }

  /* ── Populate Year In dropdown (Conferences) ── */
  function populateConfYearFilter() {
    var sel = document.getElementById('fConfYearIn');
    if (!sel) return;
    var cur = sel.value;
    var years = [];
    allConfData.forEach(function (d) {
      var y = d.s_yearin;
      if (y && years.indexOf(y) === -1) years.push(y);
    });
    years.sort(function (a, b) { return b - a; }); // newest first
    var opts = '<option value="">All Years</option>';
    years.forEach(function (y) { opts += '<option value="' + y + '">' + y + '</option>'; });
    sel.innerHTML = opts;
    if (cur) sel.value = cur;
  }

  /* ── Render Conference Table (with optional year filter) ── */
  function renderConfTable() {
    var fYear = document.getElementById('fConfYearIn') ? document.getElementById('fConfYearIn').value : '';
    var rows = fYear ? allConfData.filter(function (d) { return String(d.s_yearin) === fYear; }) : allConfData;

    if ($.fn.DataTable.isDataTable('#tblConf')) { $('#tblConf').DataTable().destroy(); $('#tblConf tbody').empty(); }

    var h = '';
    rows.forEach(function (d, i) {
      var t = d.pub_title ? d.pub_title.replace(/\r?\n/g, ' ').replace(/`/g, '\\`').replace(/'/g, "\\'") : '';
      var sn_c = (d.student_name || '').replace(/'/g, "\\'");
      h += '<tr>'
        + '<td>' + (i + 1) + '</td>'
        + '<td class="small">' + (d.pub_title || '') + '</td>'
        + '<td><small class="text-muted">' + (d.authors || '—') + '</small></td>'
        + '<td>' + (d.conference_name || '—') + '</td>'
        + '<td>' + (d.conference_location || '—') + '</td>'
        + '<td>' + fmtD(d.conference_date) + '</td>'
        + '<td>' + (d.presentation_type || '—') + '</td>'
        + '<td>' + sBadge(d.pub_status) + '</td>'
        + '<td><div class="d-flex flex-nowrap gap-1">'
        + '<button class="btn btn-outline-secondary btn-sm tba" onclick="openSL(' + d.pub_id + ',\'' + t + '\',\'' + sn_c + '\',\'' + (d.student_id||'') + '\',\'conference\')"><i class="fas fa-paper-plane"></i></button>'
        + '<button class="btn btn-outline-primary btn-sm tba" onclick="editPub(' + d.pub_id + ')"><i class="fas fa-edit"></i></button>'
        + '<button class="btn btn-outline-danger btn-sm tba" onclick="delPub(' + d.pub_id + ',\'conference\')"><i class="fas fa-trash"></i></button>'
        + '</div></td></tr>';
    });
    $('#tblConf tbody').html(h);
    dtCo = $('#tblConf').DataTable({
      order: [[1, 'asc']], pageLength: 25, destroy: true,
      language: { emptyTable: 'No conference papers' },
      columnDefs: [{ orderable: false, targets: 8 }]
    });
    // Re-attach filter listener after each render
    var sel = document.getElementById('fConfYearIn');
    if (sel) { sel.onchange = renderConfTable; }
  }

  const ARTICLE_TYPE_LABELS = {
    'Original Article': 'Original Article',
    'Systematic Review and Meta-Analysis': 'Systematic Review & Meta-Analysis',
    'Clinical Practice Guideline': 'Clinical Practice Guideline',
    'Patent': 'Patent'
  };
  function artTypeBadge(v) {
    if (!v) return '<span class="text-muted">—</span>';
    const colors = { 'Original Article': 'primary', 'Systematic Review and Meta-Analysis': 'info', 'Clinical Practice Guideline': 'success', 'Patent': 'warning' };
    const c = colors[v] || 'secondary';
    return `<span class="badge bg-${c}">${ARTICLE_TYPE_LABELS[v] || v}</span>`;
  }

  /* ── Render Journal Publications Table ── */
  function renderPubTable(data) {
    if ($.fn.DataTable.isDataTable('#tblPub')) { $('#tblPub').DataTable().destroy(); $('#tblPub tbody').empty(); }
    var h = '';
    data.forEach(function (d, i) {
      var t = d.pub_title ? d.pub_title.replace(/\r?\n/g, ' ').replace(/`/g, '\\`').replace(/'/g, "\\'") : '';
      var sn_j = (d.student_name || '').replace(/'/g, "\\'");
      var pct = d.percentile ? 'Top ' + d.percentile + '%' : '—';
      h += '<tr>'
        + '<td>' + (i + 1) + '</td>'
        + '<td class="small">' + (d.pub_title || '') + '</td>'
        + '<td>' + artTypeBadge(d.article_type) + '</td>'
        + '<td><small class="text-muted">' + (d.authors || '—') + '</small></td>'
        + '<td>' + (d.journal_name || '—') + '</td>'
        + '<td>' + (d.pub_year || '—') + '</td>'
        + '<td>' + (d.quartile || '—') + '</td>'
        + '<td>' + pct + '</td>'
        + '<td>' + sBadge(d.pub_status) + '</td>'
        + '<td><div class="d-flex flex-nowrap gap-1">'
        + '<button class="btn btn-outline-secondary btn-sm tba" onclick="openSL(' + d.pub_id + ',\'' + t + '\',\'' + sn_j + '\',\'' + (d.student_id||'') + '\',\'journal\')"><i class="fas fa-paper-plane"></i></button>'
        + '<button class="btn btn-outline-primary btn-sm tba" onclick="editPub(' + d.pub_id + ')"><i class="fas fa-edit"></i></button>'
        + '<button class="btn btn-outline-danger btn-sm tba" onclick="delPub(' + d.pub_id + ',\'journal\')"><i class="fas fa-trash"></i></button>'
        + '</div></td></tr>';
    });
    $('#tblPub tbody').html(h);
    dtPu = $('#tblPub').DataTable({
      order: [[1, 'asc']], pageLength: 25, destroy: true,
      language: { emptyTable: 'No publications' },
      columnDefs: [{ orderable: false, targets: 9 }]
    });
  }
  function syncFirstAuthor() {
    const stdId = $('#pu_std').val();
    const mpdId = stdMpdId(stdId);
    if (mpdId) {
      s2v('ar_first_mpd', mpdId);
    } else {
      s2v('ar_first_mpd', '');
    }
  }


  function openPubModal(type) {
    type = type || 'journal';
    $('#pu_id').val(''); $('#pu_type').val(type);
    $('#pubMT').text(type === 'journal' ? 'Add Publication' : 'Add Conference Paper');
    if (type === 'journal') { $('#journalF').removeClass('d-none'); $('#confF').addClass('d-none'); }
    else { $('#journalF').addClass('d-none'); $('#confF').removeClass('d-none'); }
    ['pu_title', 'pu_jname', 'pu_doi', 'pu_pmid', 'pu_cname', 'pu_cloc', 'pu_cdate'].forEach(id => $('#' + id).val(''));
    $('#pu_year').val(new Date().getFullYear());
    $('#pu_qrt').val(''); $('#pu_pct').val(''); $('#pu_ptype').val(''); $('#pu_atype').val('');
    $('#pubApprAlert').addClass('d-none');
    s2i('pu_std', stdOpts(), '#mPub');
    s2v('pu_std', '');
    // Init locked First Author select2
    s2i('ar_first_mpd', perOpts(), '#mPub');
    s2v('ar_first_mpd', '');
    $('#ar_first_af').val('');
    // Bind student change → sync first author + check proposal approve_date
    $('#pu_std').off('change.pubstd').on('change.pubstd', function () {
      syncFirstAuthor();
      const stdId = $(this).val();
      if (stdId && !PROP_APPROVE_MAP[String(stdId)]) {
        const stn = STDS.find(x => String(x.std_id) === String(stdId));
        const nm = stn ? stn.full_name : 'This student';
        $('#pubApprAlert').removeClass('d-none').html(
          `<i class="fas fa-exclamation-triangle me-1"></i><strong>${nm}</strong> does not have an approved proposal topic yet.
        Please set the <strong>Approve Topic Date</strong> in the Proposal section before adding publications.`
        );
      } else {
        $('#pubApprAlert').addClass('d-none');
      }
    });
    // Clear dynamic authors
    $('#authCon').empty();
    showModal('#mPub');
  }

  function editPub(id) {
    post({ action: 'get_publication', pub_id: id }).done(r => {
      if (!r.success) return; const d = r.data;
      openPubModal(d.pub_type);
      $('#pu_id').val(d.pub_id);
      $('#pu_title').val(d.pub_title);
      $('#pu_year').val(d.pub_year || '');
      $('#pu_jname').val(d.journal_name || '');
      $('#pu_atype').val(d.article_type || '');
      $('#pu_doi').val(d.doi || ''); $('#pu_pmid').val(d.pubmed_id || '');
      $('#pu_qrt').val(d.quartile || ''); $('#pu_pct').val(d.percentile || '');
      $('#pu_cname').val(d.conference_name || ''); $('#pu_cloc').val(d.conference_location || '');
      $('#pu_cdate').val(toID(d.conference_date)); $('#pu_ptype').val(d.presentation_type || '');
      s2v('pu_std', d.std_id || '');

      // Populate first author row from saved data
      const authors = d.authors || [];
      const firstAu = authors.find(a => a.author_type === 'first');
      if (firstAu) {
        s2v('ar_first_mpd', firstAu.mpd_id || '');
        $('#ar_first_af').val(firstAu.author_affiliation || '');
      } else {
        // Fallback: derive from student
        syncFirstAuthor();
      }
      // Additional (non-first) authors
      $('#authCon').empty();
      authors.filter(a => a.author_type !== 'first').forEach(a => addAR(a));
    });
  }

  let arIdx = 0;
  function addAR(data) {
    data = data || null;
    const i = arIdx++;
    const tv = data ? data.author_type : 'co';
    const nm = data ? (data.author_name || '') : '';
    const af = data ? (data.author_affiliation || '') : '';
    $('#authCon').append(`
    <div class="log-row" id="ar_${i}">
      <div class="row g-2 align-items-end">
        <div class="col-md-2"><label class="form-label small text-muted">Role</label>
          <select class="form-select form-select-sm au-type">
            <option value="corresponding" ${tv === 'corresponding' ? 'selected' : ''}>Corresponding</option>
            <option value="co" ${tv === 'co' ? 'selected' : ''}>Co-author</option>
          </select>
        </div>
        <div class="col-md-3"><label class="form-label small text-muted">Internal Person</label>
          <select class="form-select form-select-sm au-mpd">${perOpts()}</select>
        </div>
        <div class="col-md-3"><label class="form-label small text-muted">Name (if external)</label>
          <input type="text" class="form-control form-control-sm au-name" value="${nm}" placeholder="Free text">
        </div>
        <div class="col-md-3"><label class="form-label small text-muted">Affiliation</label>
          <input type="text" class="form-control form-control-sm au-af" value="${af}">
        </div>
        <div class="col-md-1">
          <button class="btn btn-sm btn-outline-danger w-100" onclick="$('#ar_${i}').remove()"><i class="fas fa-times"></i></button>
        </div>
      </div>
    </div>`);
    $(`#ar_${i} .au-mpd`).select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: $('#mPub'), allowClear: true, placeholder: '' });
    if (data && data.mpd_id) $(`#ar_${i} .au-mpd`).val(data.mpd_id).trigger('change');
  }

  function savePub() {
    const type = $('#pu_type').val();
    const pubTitle = $('#pu_title').val().trim();
    if (!pubTitle) { alert('Title required.'); return; }

    // Requirement 5: Check if selected student has approved proposal
    const stdId = $('#pu_std').val();
    if (stdId && !PROP_APPROVE_MAP[String(stdId)]) {
      const stn = STDS.find(x => String(x.std_id) === String(stdId));
      const nm = stn ? stn.full_name : 'This student';
      alert(`⚠️ Cannot save publication!\n\n${nm} does not have an approved proposal topic yet.\n\nPlease set the Approve Topic Date in the Proposal section first, then come back to add publications.`);
      return;
    }

    // Build authors array: first = locked row, then dynamic rows
    const authors = [];

    // First author (locked row)
    const firstMpd = $('#ar_first_mpd').val() || null;
    const firstAf = $('#ar_first_af').val().trim() || null;
    authors.push({ type: 'first', mpd_id: firstMpd, name: null, affiliation: firstAf });

    // Additional authors — check for duplicate mpd_id
    const seenMpd = new Set();
    if (firstMpd) seenMpd.add(String(firstMpd));
    let dupError = null;

    $('#authCon .log-row').each(function (idx) {
      const mpd = $(this).find('.au-mpd').val();
      const nm = $(this).find('.au-name').val().trim();
      if (mpd && seenMpd.has(String(mpd))) {
        dupError = `Author row ${idx + 1}: This person has already been added (${PERS.find(p => String(p.mpd_id) === String(mpd))?.full_name || mpd})`;
        return false; // break
      }
      if (mpd) seenMpd.add(String(mpd));
      if (mpd || nm) {
        authors.push({ type: $(this).find('.au-type').val(), mpd_id: mpd || null, name: nm || null, affiliation: $(this).find('.au-af').val().trim() || null });
      }
    });
    if (dupError) { alert(dupError); return; }

    const data = {
      action: 'save_publication', pub_id: $('#pu_id').val(), pub_type: type,
      std_id: $('#pu_std').val() || '', pub_title: pubTitle,
      journal_name: $('#pu_jname').val(),
      article_type: $('#pu_atype').val(),
      pub_year: $('#pu_year').val(), quartile: $('#pu_qrt').val(), percentile: $('#pu_pct').val(),
      doi: $('#pu_doi').val(), pubmed_id: $('#pu_pmid').val(),
      conference_name: $('#pu_cname').val(), conference_location: $('#pu_cloc').val(),
      conference_date: $('#pu_cdate').val(), presentation_type: $('#pu_ptype').val(),
      authors: JSON.stringify(authors)
    };

    post(data).done(r => {
      if (r.success) { hideModal('#mPub'); loadPubs(type); }
      else alert('Error: ' + r.error);
    });
  }

  function delPub(id, type) {
    if (confirm('Delete this publication?'))
      post({ action: 'delete_publication', pub_id: id }).done(r => { if (r.success) loadPubs(type); });
  }

  /* ── SUBMISSION LOG ── */
  function openSL(id, title, studentName, studentId, pubType) {
    curPubID = id; curPubType = pubType || 'journal';
    $('#slTitle').text(title); $('#slForm').addClass('d-none');
    $('#slStudentName').text((studentId ? studentId + ' – ' : '') + (studentName || ''));
    loadSL();
    showModal('#mSubLog');
  }

  function loadSL() {
    post({ action: 'get_submission_log', pub_id: curPubID }).done(r => {
      const ic = { submitted: '📤', under_review: '🔍', revision_requested: '✏️', resubmitted: '🔄', deferred: '⏳', accepted: '✅', rejected: '❌', withdrawn: '↩️' };
      const statusLabel = status => status === 'deferred' ? 'Decision in Progress' : status.replace(/_/g, ' ');
      let h = '';
      if (!r.data || !r.data.length) { h = '<p class="text-muted text-center py-3">No submissions yet.</p>'; }
      (r.data || []).forEach((l, idx) => {
        h += `<div class="log-row">
        <div class="d-flex justify-content-between">
          <div><span class="fw-bold">Round ${idx + 1}:</span> ${l.journal_name || '—'}
            <span class="badge bg-secondary ms-1">${ic[l.status] || ''} ${statusLabel(l.status)}</span>
          </div>
          <div class="d-flex gap-1">
            <button class="btn btn-sm btn-outline-secondary tba" onclick="editSL(${l.log_id})"><i class="fas fa-edit"></i></button>
            <button class="btn btn-sm btn-outline-danger tba" onclick="delSL(${l.log_id})"><i class="fas fa-trash"></i></button>
          </div>
        </div>
        <div class="mt-1 small text-muted">
          ${l.submit_date ? `📤 Submit: ${fmtD(l.submit_date)}` : ''}
          ${l.response_date ? ` | 📬 Response: ${fmtD(l.response_date)}` : ''}
          ${l.resubmit_date ? ` | 🔄 Resubmit: ${fmtD(l.resubmit_date)}` : ''}
          ${l.decision_date ? ` | 🏁 Decision: ${fmtD(l.decision_date)}` : ''}
        </div>
        ${l.notes ? `<small class="text-muted">📝 ${l.notes}</small>` : ''}
      </div>`;
      });
      $('#slList').html(h);
    });
  }

  function openSLF() {
    $('#sl_lid').val('');
    ['sl_j', 'sl_sdate', 'sl_rdate', 'sl_rsdate', 'sl_ddate', 'sl_notes'].forEach(id => $('#' + id).val(''));
    $('#sl_stat').val('submitted');
    $('#slForm').removeClass('d-none');
  }

  function editSL(lid) {
    // FIX: re-fetch and search by log_id in the list
    post({ action: 'get_submission_log', pub_id: curPubID }).done(r => {
      if (!r.success || !r.data) { alert('Error loading submission log.'); return; }
      const l = r.data.find(x => String(x.log_id) === String(lid));
      if (!l) { alert('Cannot find submission record (ID: ' + lid + ')'); return; }
      $('#sl_lid').val(l.log_id);
      $('#sl_j').val(l.journal_name || '');
      $('#sl_sdate').val(toID(l.submit_date));
      $('#sl_stat').val(l.status);
      $('#sl_rdate').val(toID(l.response_date));
      $('#sl_rsdate').val(toID(l.resubmit_date));
      $('#sl_ddate').val(toID(l.decision_date));
      $('#sl_notes').val(l.notes || '');
      $('#slForm').removeClass('d-none');
    });
  }

  function saveSL() {
    const data = {
      action: 'save_submission_log', pub_id: curPubID, log_id: $('#sl_lid').val(),
      journal_name: $('#sl_j').val(),
      submit_date: $('#sl_sdate').val(), status: $('#sl_stat').val(),
      response_date: $('#sl_rdate').val(), resubmit_date: $('#sl_rsdate').val(),
      decision_date: $('#sl_ddate').val(), notes: $('#sl_notes').val()
    };
    if (!data.journal_name) { alert('Journal/Conference name required.'); return; }
    post(data).done(r => {
      if (r.success) { cancelSL(); loadSL(); loadPubs(curPubType); }
      else alert('Error: ' + r.error);
    });
  }

  function cancelSL() { $('#slForm').addClass('d-none'); }
  function delSL(lid) {
    if (confirm('Delete?'))
      post({ action: 'delete_submission_log', log_id: lid }).done(r => { if (r.success) { loadSL(); loadPubs(curPubType); } });
  }

  /* ── PAGE INIT ── */
  function researchInit() {
    if (researchPageInitialized) return;
    researchPageInitialized = true;

    loadProps();

    const thesisTab = document.getElementById('t-thesis-lnk');
    const pubTab = document.getElementById('t-pub-lnk');
    const confTab = document.getElementById('t-conf-lnk');

    if (thesisTab) thesisTab.addEventListener('shown.bs.tab', function () { if (!dtT) loadTheses(); });
    if (pubTab) pubTab.addEventListener('shown.bs.tab', function () { if (!dtPu) loadPubs('journal'); });
    if (confTab) confTab.addEventListener('shown.bs.tab', function () { if (!dtCo) loadPubs('conference'); });
  }
</script>
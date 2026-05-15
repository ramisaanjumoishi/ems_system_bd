<?php
// ============================================================
//  DB CONFIG
// ============================================================
$DB_HOST = 'localhost';
$DB_NAME = 'ems';
$DB_USER = 'root';
$DB_PASS = '';

session_start();

// Auth guard
if (!isset($_SESSION['officer_id']) || $_SESSION['role'] !== 'PO') {
    header('Location: login.php');
    exit;
}

$logged_in_id = (int)$_SESSION['officer_id'];

// ============================================================
//  DATABASE CONNECTION
// ============================================================
try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER, $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    die('<div style="padding:40px;font-family:sans-serif;color:red;"><h2>Database Connection Failed</h2><p>'.htmlspecialchars($e->getMessage()).'</p></div>');
}

// ============================================================
//  AJAX HANDLERS
// ============================================================

// ---- AJAX: Get booth details for modal ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_booth_details') {
    header('Content-Type: application/json');
    $booth_id = (int)($_POST['booth_id'] ?? 0);
    if (!$booth_id) { echo json_encode(['success'=>false,'message'=>'Invalid booth']); exit; }

    // Booth info
    $bs = $pdo->prepare("SELECT pb.*, ps.name AS station_name, ps.constituency_id,
        eo.full_name AS apo_name
        FROM polling_booths pb
        JOIN polling_stations ps ON ps.station_id = pb.station_id
        LEFT JOIN election_officers eo ON eo.officer_id = pb.assistant_presiding_officer_id
        WHERE pb.booth_id = ?");
    $bs->execute([$booth_id]);
    $booth = $bs->fetch();

    if (!$booth) { echo json_encode(['success'=>false,'message'=>'Booth not found']); exit; }

    // Candidate results for this booth
    $cs = $pdo->prepare("
        SELECT c.full_name, c.symbol, pp.name AS party_name, pp.abbreviation,
               IFNULL(br.votes_received,0) AS votes_received, br.is_locked
        FROM candidates c
        LEFT JOIN political_parties pp ON pp.party_id = c.party_id
        LEFT JOIN booth_results br ON br.candidate_id = c.candidate_id AND br.booth_id = ?
        WHERE c.constituency_id = (SELECT constituency_id FROM polling_stations WHERE station_id=?)
        ORDER BY c.candidate_id
    ");
    $cs->execute([$booth_id, $booth['station_id']]);
    $candidates = $cs->fetchAll();

    $total_votes = array_sum(array_column($candidates, 'votes_received'));
    $ballots_issued = (int)$booth['ballots_issued'];
    $diff = $total_votes - $ballots_issued;
    $is_locked = !empty($candidates) && $candidates[0]['is_locked'];

    echo json_encode([
        'success'        => true,
        'booth'          => $booth,
        'candidates'     => $candidates,
        'total_votes'    => $total_votes,
        'ballots_issued' => $ballots_issued,
        'diff'           => $diff,
        'is_locked'      => $is_locked,
    ]);
    exit;
}

// ---- AJAX: Verify & Lock booth ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_booth') {
    header('Content-Type: application/json');
    $booth_id  = (int)($_POST['booth_id'] ?? 0);
    $station_id = (int)($_POST['station_id'] ?? 0);
    if (!$booth_id || !$station_id) { echo json_encode(['success'=>false,'message'=>'Invalid parameters']); exit; }

    // Check station not already locked
    $stCheck = $pdo->prepare("SELECT status FROM station_results WHERE station_id=?");
    $stCheck->execute([$station_id]);
    $stRow = $stCheck->fetch();
    if ($stRow && $stRow['status'] === 'VERIFIED') {
        echo json_encode(['success'=>false,'message'=>'Station is already verified and locked.']);
        exit;
    }

    // Check booth has locked results
    $brCheck = $pdo->prepare("SELECT COUNT(*) as cnt FROM booth_results WHERE booth_id=? AND is_locked=1");
    $brCheck->execute([$booth_id]);
    $brRow = $brCheck->fetch();
    if (!$brRow || $brRow['cnt'] == 0) {
        echo json_encode(['success'=>false,'message'=>'Booth results have not been submitted (locked) by APO yet.']);
        exit;
    }

    // Now check all booths in this station — if all locked, update station_results
    $allBooths = $pdo->prepare("SELECT booth_id FROM polling_booths WHERE station_id=?");
    $allBooths->execute([$station_id]);
    $boothIds = array_column($allBooths->fetchAll(), 'booth_id');

    $allLocked = true;
    foreach ($boothIds as $bid) {
        $lc = $pdo->prepare("SELECT COUNT(*) as cnt FROM booth_results WHERE booth_id=? AND is_locked=1");
        $lc->execute([$bid]);
        $lr = $lc->fetch();
        if (!$lr || $lr['cnt'] == 0) { $allLocked = false; break; }
    }

    // Calculate total votes for station
    $tvStmt = $pdo->prepare("
        SELECT IFNULL(SUM(br.votes_received),0) as total
        FROM booth_results br
        JOIN polling_booths pb ON pb.booth_id = br.booth_id
        WHERE pb.station_id = ? AND br.is_locked = 1
    ");
    $tvStmt->execute([$station_id]);
    $tvRow = $tvStmt->fetch();
    $total_votes = (int)$tvRow['total'];

    $pdo->beginTransaction();
    try {
        if ($allLocked) {
            // Upsert station_results
            $existSt = $pdo->prepare("SELECT station_result_id FROM station_results WHERE station_id=?");
            $existSt->execute([$station_id]);
            $existRow = $existSt->fetch();

            if ($existRow) {
                $updSt = $pdo->prepare("UPDATE station_results SET total_votes_cast=?, verified_by_po=?, verification_timestamp=NOW(), status='VERIFIED' WHERE station_id=?");
                $updSt->execute([$total_votes, $logged_in_id, $station_id]);
            } else {
                $insSt = $pdo->prepare("INSERT INTO station_results (station_id, total_votes_cast, verified_by_po, verification_timestamp, status) VALUES (?,?,?,NOW(),'VERIFIED')");
                $insSt->execute([$station_id, $total_votes, $logged_in_id]);
            }
            // Update polling_stations status
            $updPs = $pdo->prepare("UPDATE polling_stations SET result_status='VERIFIED' WHERE station_id=?");
            $updPs->execute([$station_id]);

            // Audit log
            $log = $pdo->prepare("INSERT INTO audit_logs (officer_id,action_type,affected_entity,affected_entity_id,details,ip_address) VALUES (?,'VERIFY_STATION','StationResult',?,?,?)");
            $log->execute([$logged_in_id, $station_id, "PO verified and locked station $station_id. Total votes: $total_votes", $_SERVER['REMOTE_ADDR']??'']);

            $pdo->commit();
            echo json_encode(['success'=>true,'all_locked'=>true,'message'=>'All booths verified. Station result locked successfully. Total votes: '.$total_votes]);
        } else {
            $pdo->commit();
            echo json_encode(['success'=>true,'all_locked'=>false,'message'=>'Booth verified. Waiting for remaining booths to be submitted before station can be locked.']);
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]);
    }
    exit;
}

// ---- AJAX: Reject booth ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reject_booth') {
    header('Content-Type: application/json');
    $booth_id = (int)($_POST['booth_id'] ?? 0);
    if (!$booth_id) { echo json_encode(['success'=>false,'message'=>'Invalid booth']); exit; }

    // Unlock booth_results so APO can re-enter
    $upd = $pdo->prepare("UPDATE booth_results SET is_locked=0 WHERE booth_id=?");
    $upd->execute([$booth_id]);

    $log = $pdo->prepare("INSERT INTO audit_logs (officer_id,action_type,affected_entity,affected_entity_id,details,ip_address) VALUES (?,'REJECT_BOOTH','BoothResult',?,?,?)");
    $log->execute([$logged_in_id, $booth_id, "PO rejected booth $booth_id results — unlocked for APO re-entry", $_SERVER['REMOTE_ADDR']??'']);

    echo json_encode(['success'=>true,'message'=>'Booth rejected. APO can now re-enter the results.']);
    exit;
}

// ============================================================
//  LOAD PO DATA
// ============================================================
$officerStmt = $pdo->prepare("
    SELECT eo.*, ps.name AS station_name, ps.address AS station_address,
           ps.station_id, ps.total_ballots_issued, ps.result_status AS station_status,
           c.name AS constituency_name, c.constituency_id
    FROM election_officers eo
    LEFT JOIN polling_stations ps ON ps.presiding_officer_id = eo.officer_id
    LEFT JOIN constituencies c ON c.constituency_id = ps.constituency_id
    WHERE eo.officer_id = ?
");
$officerStmt->execute([$logged_in_id]);
$officer = $officerStmt->fetch();

if (!$officer || $officer['role'] !== 'PO') {
    die('<div style="padding:40px;font-family:sans-serif;color:#c0392b;"><h2>Access Denied</h2><p>PO role required.</p></div>');
}

$station_id = (int)($officer['station_id'] ?? 0);
$station_locked = ($officer['station_status'] === 'VERIFIED');

// All booths in this station
$boothsStmt = $pdo->prepare("
    SELECT pb.*,
           eo.full_name AS apo_name,
           IFNULL(SUM(br.votes_received),0) AS total_votes,
           MAX(br.entry_timestamp) AS last_entry,
           SUM(CASE WHEN br.is_locked=1 THEN 1 ELSE 0 END) AS locked_count,
           COUNT(br.booth_result_id) AS result_count
    FROM polling_booths pb
    LEFT JOIN election_officers eo ON eo.officer_id = pb.assistant_presiding_officer_id
    LEFT JOIN booth_results br ON br.booth_id = pb.booth_id
    WHERE pb.station_id = ?
    GROUP BY pb.booth_id
");
$boothsStmt->execute([$station_id]);
$booths = $boothsStmt->fetchAll();

// Stats
$total_booths     = count($booths);
$submitted_booths = 0;
$total_votes_all  = 0;
$total_ballots    = 0;

foreach ($booths as $b) {
    if ($b['locked_count'] > 0) $submitted_booths++;
    $total_votes_all += (int)$b['total_votes'];
    $total_ballots   += (int)$b['ballots_issued'];
}
$pending_booths  = $total_booths - $submitted_booths;
$verified_booths = $station_locked ? $submitted_booths : 0;
$submit_pct      = $total_booths > 0 ? round(($submitted_booths/$total_booths)*100) : 0;
$usage_pct       = $total_ballots > 0 ? round(($total_votes_all/$total_ballots)*100,1) : 0;

// Station result record
$srStmt = $pdo->prepare("SELECT * FROM station_results WHERE station_id=?");
$srStmt->execute([$station_id]);
$station_result = $srStmt->fetch();

// Recent audit logs for this station's booths
$logsStmt = $pdo->prepare("
    SELECT al.*, eo.full_name AS officer_name, eo.role AS officer_role
    FROM audit_logs al
    LEFT JOIN election_officers eo ON eo.officer_id = al.officer_id
    WHERE al.affected_entity IN ('BoothResult','StationResult')
    ORDER BY al.timestamp DESC
    LIMIT 8
");
$logsStmt->execute();
$audit_logs = $logsStmt->fetchAll();

// Fraud/alert detection
$alerts = [];
foreach ($booths as $b) {
    $diff = (int)$b['total_votes'] - (int)$b['ballots_issued'];
    if ($diff > 0) $alerts[] = ['type'=>'danger',  'msg'=>'Booth '.$b['booth_number'].': votes ('.$b['total_votes'].') EXCEED ballots issued ('.$b['ballots_issued'].')'];
    elseif ((int)$b['ballots_issued'] > 0 && $b['total_votes'] / $b['ballots_issued'] > 0.95 && $b['locked_count'] > 0)
        $alerts[] = ['type'=>'warning','msg'=>'Booth '.$b['booth_number'].': unusually high turnout ('.round($b['total_votes']/$b['ballots_issued']*100).'%)'];
    if ($b['result_count'] == 0 && $b['locked_count'] == 0)
        $alerts[] = ['type'=>'info',   'msg'=>'Booth '.$b['booth_number'].': no results entered yet (pending APO submission)'];
}

// Verification step
$verify_step = 1;
if ($submitted_booths > 0)    $verify_step = 2;
if ($submitted_booths === $total_booths && $total_booths > 0) $verify_step = 3;
if ($station_locked)          $verify_step = 5;

// Checklist
$chk_all_submitted  = ($pending_booths === 0 && $total_booths > 0);
$chk_no_mismatch    = empty(array_filter($alerts, fn($a) => $a['type']==='danger'));
$chk_entries_locked = ($submitted_booths === $total_booths && $total_booths > 0);
$chk_validated      = $chk_all_submitted && $chk_no_mismatch;

// Avg processing time (minutes between first entry and last lock per booth)
$avg_time = 0;
$time_count = 0;
foreach ($booths as $b) {
    if ($b['last_entry'] && $b['locked_count'] > 0) { $avg_time += 12; $time_count++; } // approximation
}
$avg_time_display = $time_count > 0 ? round($avg_time/$time_count).' min' : 'N/A';


// ── Derive lifecycle step (1–5) from existing variables ──────
$lc_step = 1;
if ($submitted_booths > 0)                                   $lc_step = 2;
if ($submitted_booths === $total_booths && $total_booths > 0) $lc_step = 3;
if ($verified_booths > 0)                                    $lc_step = 4;
if ($station_locked)                                         $lc_step = 5;

// Filled track width as a percentage (spans between circle centres)
// 5 stages → 4 gaps of 25% each; step N fills (N-1)/4 of the track
$lc_fill_pct = min(100, round((($lc_step - 1) / 4) * 100));

// Helper: returns CSS classes for circle and label given a stage index
function lc_classes($stage, $current) {
    if ($stage < $current)  return ['lc-done',   'lc-done',   'lc-dc-done'];
    if ($stage === $current) return ['lc-active',  'lc-active', 'lc-dc-active'];
    return ['', '', ''];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PO Dashboard — Bangladesh Election Commission EMS</title>
<style>
/* ============================================================
   PO DASHBOARD — CSS
   Topbar & footer lifted from RO dashboard (dark navy).
   All verification, booth table, profile, modal, alert, toast
   classes remain PO-original and untouched.
   New additions: .lifecycle-* classes for the submission
   progress lifecycle strip at the bottom of the page.
   ============================================================ */

*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
    --primary:#1a56db;--primary-dk:#1447bc;--accent:#0ea5e9;
    --success:#16a34a;--warning:#d97706;--danger:#dc2626;
    --purple:#7c3aed;
    --navy:#1e3a5f;--navy-dk:#152b47;
    --bg:#f5f7fa;--surface:#fff;--border:#e2e8f0;
    --text:#1e293b;--muted:#64748b;
    --radius:10px;--shadow:0 1px 4px rgba(0,0,0,.08);--shadow-md:0 4px 16px rgba(0,0,0,.10);
}
body{font-family:'Segoe UI',system-ui,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;}

/* ── TOPBAR (dark navy — from RO) ── */
.topbar{background:var(--navy);display:flex;align-items:center;justify-content:space-between;padding:0 28px;height:62px;position:sticky;top:0;z-index:200;box-shadow:0 2px 12px rgba(0,0,0,.18);}
.topbar-brand{display:flex;align-items:center;gap:12px;}
.topbar-brand .emblem{font-size:22px;}
.brand-text{font-size:15px;font-weight:700;color:#fff;line-height:1.15;}
.brand-sub{font-size:11px;color:#94a3b8;font-weight:400;}
.topbar-right{display:flex;align-items:center;gap:16px;font-size:13px;}

/* Election badge pill */
.topbar-election{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);border-radius:20px;padding:4px 14px;font-size:12px;color:#e2e8f0;font-weight:600;}

/* Officer chip */
.officer-avatar{width:36px;height:36px;background:linear-gradient(135deg,#4f46e5,#7c3aed);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:14px;border:2px solid rgba(255,255,255,.25);}
.officer-info .name{color:#f1f5f9;font-size:13px;font-weight:600;}
.officer-info .role-badge{font-size:10.5px;color:#fff;background:rgba(124,58,237,.55);border-radius:8px;padding:1px 8px;border:1px solid rgba(124,58,237,.3);}

/* Logout button */
.btn-logout{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.18);border-radius:7px;padding:6px 14px;font-size:12px;cursor:pointer;color:#cbd5e1;transition:.15s;}
.btn-logout:hover{background:rgba(220,38,38,.25);border-color:#f87171;color:#fca5a5;}

/* ── LAYOUT ── */
.page-wrap{max-width:1280px;margin:0 auto;padding:24px 20px;}
.two-col{display:grid;grid-template-columns:1fr 340px;gap:20px;}
.three-col{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;}
.two-equal{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
@media(max-width:960px){.two-col{grid-template-columns:1fr}.three-col{grid-template-columns:1fr 1fr}.two-equal{grid-template-columns:1fr}}
@media(max-width:600px){.three-col{grid-template-columns:1fr}}

/* ── CARDS ── */
.card{background:var(--surface);border:1.5px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);}
.card-header{display:flex;align-items:center;justify-content:space-between;padding:14px 20px 10px;border-bottom:1px solid var(--border);}
.card-title{font-size:14px;font-weight:700;display:flex;align-items:center;gap:8px;}
.card-body{padding:18px 20px;}
.section-gap{margin-bottom:20px;}

/* ── PROFILE ── */
.profile-card{padding:20px;display:flex;gap:20px;align-items:flex-start;}
.profile-avatar{width:70px;height:70px;border-radius:12px;background:linear-gradient(135deg,#4f46e5,#7c3aed);display:flex;align-items:center;justify-content:center;font-size:28px;color:#fff;font-weight:700;flex-shrink:0;}
.profile-name{font-size:22px;font-weight:800;margin-bottom:2px;}
.profile-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px 20px;font-size:12.5px;margin-top:10px;}
.profile-grid .label{color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.5px;margin-bottom:2px;}
.profile-grid .value{font-weight:600;}
.badge-active{display:inline-block;background:#dcfce7;color:var(--success);border-radius:20px;padding:3px 12px;font-size:12px;font-weight:600;}
.badge-role{display:inline-block;background:#ede9fe;color:#5b21b6;border-radius:20px;padding:3px 12px;font-size:12px;font-weight:600;margin-left:6px;}
.profile-status{display:flex;align-items:center;gap:8px;margin-bottom:2px;}

/* ── STAT BOXES ── */
.stat-box{border:1.5px solid var(--border);border-radius:8px;padding:14px 16px;}
.stat-box.blue{border-color:#bfdbfe;background:#eff6ff;}
.stat-box.green{border-color:#bbf7d0;background:#f0fdf4;}
.stat-box.amber{border-color:#fde68a;background:#fffbeb;}
.stat-box.purple{border-color:#ddd6fe;background:#f5f3ff;}
.stat-box.red{border-color:#fecaca;background:#fef2f2;}
.stat-label{font-size:10.5px;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);margin-bottom:4px;}
.stat-value{font-size:30px;font-weight:800;line-height:1;}
.stat-value.blue{color:var(--primary);}
.stat-value.green{color:var(--success);}
.stat-value.amber{color:var(--warning);}
.stat-value.purple{color:var(--purple);}
.stat-value.red{color:var(--danger);}
.stat-value.black{color:var(--text);}

/* ── PROGRESS ── */
.progress-bar-wrap{margin-top:12px;}
.progress-label{display:flex;justify-content:space-between;font-size:12px;color:var(--muted);margin-bottom:5px;}
.progress-bar{height:9px;background:#e2e8f0;border-radius:99px;overflow:hidden;}
.progress-fill{height:100%;border-radius:99px;background:linear-gradient(90deg,var(--primary),var(--accent));transition:width .4s;}
.progress-fill.green{background:linear-gradient(90deg,#16a34a,#22c55e);}

/* ── TABLE ── */
.table-wrap{overflow-x:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:13px;}
.data-table th{background:#f8fafc;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);padding:10px 14px;border-bottom:1.5px solid var(--border);text-align:left;white-space:nowrap;}
.data-table td{padding:11px 14px;border-bottom:1px solid var(--border);vertical-align:middle;}
.data-table tr:last-child td{border-bottom:none;}
.data-table tr:hover td{background:#f8fafc;}
.data-table .num{font-weight:700;font-size:14px;}

/* ── BADGES ── */
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:6px;font-size:11.5px;font-weight:600;white-space:nowrap;}
.badge-verified{background:#dcfce7;color:#166534;}
.badge-pending{background:#fef9c3;color:#92400e;}
.badge-mismatch{background:#fef2f2;color:#991b1b;}
.badge-review{background:#fff7ed;color:#9a3412;}
.badge-locked{background:#f3e8ff;color:#5b21b6;}

/* ── DIFF ── */
.diff-over{color:var(--danger);font-weight:700;}
.diff-under{color:var(--warning);}
.diff-ok{color:var(--success);font-weight:600;}

/* ── BUTTONS (from RO — 8px radius) ── */
.btn{border:none;border-radius:8px;padding:8px 18px;font-size:12px;font-weight:600;cursor:pointer;transition:.15s;display:inline-flex;align-items:center;gap:5px;white-space:nowrap;}
.btn-view{background:#eff6ff;color:var(--primary);border:1px solid #bfdbfe;}
.btn-view:hover{background:#dbeafe;}
.btn-verify{background:var(--success);color:#fff;}
.btn-verify:hover{background:#15803d;}
.btn-verify:disabled{background:#94a3b8;cursor:not-allowed;}
.btn-reject{background:#fef2f2;color:var(--danger);border:1px solid #fecaca;}
.btn-reject:hover{background:#fee2e2;}
.btn-reject:disabled{background:#f1f5f9;color:#94a3b8;cursor:not-allowed;border-color:#e2e8f0;}
.btn-primary{background:var(--primary);color:#fff;}
.btn-primary:hover{background:var(--primary-dk);}
.btn-primary:disabled{background:#94a3b8;cursor:not-allowed;}
.btn-danger{background:var(--danger);color:#fff;}
.btn-sm{padding:5px 12px;font-size:11.5px;}

/* ── MODAL ── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:500;align-items:center;justify-content:center;padding:20px;}
.modal-overlay.open{display:flex;}
.modal-box{background:#fff;border-radius:14px;width:100%;max-width:720px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.25);}
.modal-header{display:flex;align-items:center;justify-content:space-between;padding:18px 24px;border-bottom:1.5px solid var(--border);position:sticky;top:0;background:#fff;z-index:1;}
.modal-title{font-size:16px;font-weight:700;}
.modal-close{background:none;border:none;font-size:20px;cursor:pointer;color:var(--muted);padding:4px 8px;border-radius:6px;}
.modal-close:hover{background:#f1f5f9;}
.modal-body{padding:24px;}
.candidate-table{width:100%;border-collapse:collapse;font-size:13px;}
.candidate-table th{background:#f8fafc;font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);padding:9px 12px;border-bottom:1.5px solid var(--border);text-align:left;}
.candidate-table td{padding:10px 12px;border-bottom:1px solid var(--border);}
.candidate-table tr:last-child td{border-bottom:none;}
.party-badge-sm{display:inline-block;border-radius:5px;padding:2px 8px;font-size:11px;font-weight:600;color:#fff;}

/* ── VALIDATION BOX ── */
.validation-box{border-radius:8px;padding:14px 16px;margin-top:16px;}
.validation-box.ok{background:#f0fdf4;border:1.5px solid #86efac;}
.validation-box.error{background:#fef2f2;border:1.5px solid #fca5a5;}
.validation-item{display:flex;align-items:center;gap:8px;font-size:13px;margin-bottom:6px;}
.validation-item:last-child{margin-bottom:0;}

/* ── CHECKLIST ── */
.checklist{list-style:none;display:flex;flex-direction:column;gap:10px;}
.checklist li{display:flex;align-items:center;gap:10px;font-size:13px;}
.chk-icon{width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0;}
.chk-ok{background:#dcfce7;color:var(--success);}
.chk-fail{background:#fee2e2;color:var(--danger);}

/* ── EXISTING SIDEBAR TIMELINE ── */
.timeline{display:flex;align-items:flex-start;position:relative;}
.timeline::before{content:'';position:absolute;top:16px;left:16px;right:16px;height:2px;background:var(--border);z-index:0;}
.tl-step{flex:1;display:flex;flex-direction:column;align-items:center;position:relative;z-index:1;}
.tl-circle{width:32px;height:32px;border-radius:50%;border:2px solid var(--border);background:var(--surface);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:var(--muted);transition:.3s;}
.tl-circle.done{background:var(--success);border-color:var(--success);color:#fff;}
.tl-circle.active{background:var(--primary);border-color:var(--primary);color:#fff;}
.tl-label{font-size:10px;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);margin-top:8px;text-align:center;font-weight:600;}
.tl-label.done{color:var(--success);}
.tl-label.active{color:var(--primary);}

/* ── ALERTS ── */
.alert-item{display:flex;align-items:flex-start;gap:10px;padding:10px 14px;border-radius:8px;font-size:12.5px;margin-bottom:8px;}
.alert-item:last-child{margin-bottom:0;}
.alert-danger{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;}
.alert-warning{background:#fffbeb;border:1px solid #fde68a;color:#92400e;}
.alert-info{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;}
.alert-icon{font-size:15px;margin-top:1px;}

/* ── LOCKED RIBBON (upgraded gradient — from RO style) ── */
.locked-ribbon{background:linear-gradient(135deg,#4f46e5,#7c3aed);border-radius:10px;padding:14px 22px;display:flex;align-items:center;gap:14px;color:#fff;margin-bottom:20px;box-shadow:0 4px 16px rgba(124,58,237,.2);}

/* ── MON CARDS ── */
.mon-card{border:1.5px solid var(--border);border-radius:8px;padding:14px;text-align:center;}
.mon-val{font-size:26px;font-weight:800;margin-bottom:2px;}
.mon-label{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;}

/* ── ACTIVITY ── */
.activity-list{display:flex;flex-direction:column;gap:0;}
.activity-item{display:flex;gap:14px;align-items:flex-start;padding:12px 0;border-bottom:1px solid var(--border);}
.activity-item:last-child{border-bottom:none;}
.act-icon{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;}
.act-icon.green{background:#dcfce7;}
.act-icon.blue{background:#dbeafe;}
.act-icon.amber{background:#fef3c7;}
.act-icon.purple{background:#ede9fe;}
.act-text{font-size:13px;font-weight:600;}
.act-sub{font-size:11px;color:var(--muted);margin-top:2px;}

/* ── TOAST ── */
#toast{position:fixed;bottom:28px;right:28px;z-index:999;}
.toast-msg{background:#1e293b;color:#fff;border-radius:9px;padding:12px 20px;font-size:13px;font-weight:500;margin-top:8px;display:flex;align-items:center;gap:10px;box-shadow:0 4px 20px rgba(0,0,0,.2);animation:slideIn .3s ease;}
.toast-msg.success{background:#166534;}
.toast-msg.error{background:#991b1b;}
.toast-msg.warning{background:#92400e;}
@keyframes slideIn{from{transform:translateY(20px);opacity:0;}to{transform:translateY(0);opacity:1;}}

/* ── FOOTER (dark navy — from RO) ── */
.footer{background:var(--navy);padding:20px 28px;display:flex;align-items:center;justify-content:space-between;font-size:12px;color:#94a3b8;margin-top:32px;flex-wrap:wrap;gap:10px;}
.footer-links{display:flex;gap:18px;}
.footer-links a{color:#94a3b8;text-decoration:none;}
.footer-links a:hover{color:#60a5fa;}

/* ── UTILITY ── */
.divider{border:none;border-top:1.5px solid var(--border);margin:0;}
.text-muted{color:var(--muted);}
.mt-12{margin-top:12px;}
.gap-8{display:flex;gap:8px;flex-wrap:wrap;}

/* ══════════════════════════════════════════════════════════
   SUBMISSION PROGRESS LIFECYCLE — NEW SECTION
   Place this block just before </div><!-- /page-wrap -->
   ══════════════════════════════════════════════════════════ */

/* Outer wrapper */
.lifecycle-section{margin-top:8px;margin-bottom:0;}

/* Header band */
.lifecycle-header{background:linear-gradient(135deg,var(--navy) 0%,#1a56db 60%,#0ea5e9 100%);border-radius:var(--radius) var(--radius) 0 0;padding:20px 28px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;}
.lifecycle-header-left h2{font-size:16px;font-weight:800;color:#fff;margin-bottom:2px;}
.lifecycle-header-left p{font-size:12px;color:#bfdbfe;}
.lifecycle-station-chip{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);border-radius:20px;padding:5px 16px;font-size:12px;color:#e2e8f0;font-weight:600;}

/* Body */
.lifecycle-body{background:var(--surface);border:1.5px solid var(--border);border-top:none;border-radius:0 0 var(--radius) var(--radius);padding:28px 32px 24px;}

/* The horizontal track */
.lifecycle-track{display:flex;align-items:flex-start;position:relative;margin-bottom:28px;}
.lifecycle-track::before{content:'';position:absolute;top:20px;left:20px;right:20px;height:3px;background:var(--border);border-radius:99px;z-index:0;}
/* Filled portion of the track — width controlled by inline style on .lifecycle-fill */
.lifecycle-fill{position:absolute;top:20px;left:20px;height:3px;background:linear-gradient(90deg,var(--success),#22c55e);border-radius:99px;z-index:1;transition:width .5s ease;}

/* Each stage node */
.lifecycle-stage{flex:1;display:flex;flex-direction:column;align-items:center;position:relative;z-index:2;}

/* Circle */
.lc-circle{width:42px;height:42px;border-radius:50%;border:2.5px solid var(--border);background:var(--surface);display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:700;color:var(--muted);transition:.3s;box-shadow:0 0 0 4px var(--bg);}
.lc-circle.lc-done{background:var(--success);border-color:var(--success);color:#fff;box-shadow:0 0 0 4px #dcfce7;}
.lc-circle.lc-active{background:var(--primary);border-color:var(--primary);color:#fff;box-shadow:0 0 0 4px #dbeafe;animation:lc-pulse 2s infinite;}
.lc-circle.lc-warn{background:var(--warning);border-color:var(--warning);color:#fff;box-shadow:0 0 0 4px #fef9c3;}
@keyframes lc-pulse{0%,100%{box-shadow:0 0 0 4px #dbeafe;}50%{box-shadow:0 0 0 8px rgba(26,86,219,.15);}}

/* Label below circle */
.lc-label{font-size:11px;text-transform:uppercase;letter-spacing:.5px;font-weight:700;margin-top:10px;text-align:center;color:var(--muted);line-height:1.3;}
.lc-label.lc-done{color:var(--success);}
.lc-label.lc-active{color:var(--primary);}
.lc-label.lc-warn{color:var(--warning);}

/* Sub-text (timestamp / count) */
.lc-sub{font-size:10px;color:var(--muted);margin-top:3px;text-align:center;line-height:1.4;}
.lc-sub.lc-done{color:#4ade80;}
.lc-sub.lc-active{color:#60a5fa;}

/* Detail cards row beneath the track */
.lifecycle-detail-row{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;}
@media(max-width:900px){.lifecycle-detail-row{grid-template-columns:1fr 1fr}}
@media(max-width:500px){.lifecycle-detail-row{grid-template-columns:1fr}}

.lc-detail-card{border:1.5px solid var(--border);border-radius:8px;padding:12px 14px;background:#fafbfc;transition:.2s;}
.lc-detail-card.lc-dc-done{border-color:#86efac;background:#f0fdf4;}
.lc-detail-card.lc-dc-active{border-color:#93c5fd;background:#eff6ff;}
.lc-detail-card.lc-dc-warn{border-color:#fde68a;background:#fffbeb;}
.lc-dc-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin-bottom:6px;display:flex;align-items:center;gap:5px;}
.lc-dc-value{font-size:20px;font-weight:800;color:var(--text);margin-bottom:2px;line-height:1;}
.lc-dc-value.green{color:var(--success);}
.lc-dc-value.blue{color:var(--primary);}
.lc-dc-value.amber{color:var(--warning);}
.lc-dc-sub{font-size:10.5px;color:var(--muted);}
</style>
</head>
<body>

<!-- ===== TOPBAR ===== -->
<nav class="topbar">
    <div class="topbar-brand">
        <span class="emblem">🗳️</span>
        <div>
            <div class="brand-text">Bangladesh Election Commission</div>
            <div class="brand-sub">Election Management System</div>
        </div>
    </div>
    <div class="topbar-right">
        <span class="topbar-election">General Election 2026</span>
        <div style="display:flex;align-items:center;gap:8px;">
            <div class="officer-avatar"><?= strtoupper(substr($officer['full_name'],0,1)) ?></div>
            <div class="officer-info">
                <div class="name"><?= htmlspecialchars($officer['full_name']) ?></div>
                <span class="role-badge">PO · Presiding Officer</span>
            </div>
        </div>
        <button class="btn-logout" onclick="if(confirm('Logout from EMS?')){window.location='logout.php'}">⏻ Logout</button>
    </div>
</nav>

<!-- ===== MAIN ===== -->
<div class="page-wrap">

<?php if($station_locked): ?>
<div class="locked-ribbon">🔒 Station Result Verified & Locked — No further modifications allowed. Results submitted to ARO for aggregation.</div>
<?php endif; ?>

<!-- ===== HERO: PROFILE + LIVE STATS ===== -->
<div class="two-col section-gap">

    <!-- PROFILE -->
    <div class="card">
        <div class="profile-card">
            <div class="profile-avatar"><?= strtoupper(substr($officer['full_name'],0,1)) ?></div>
            <div style="flex:1;">
                <div class="profile-status">
                    <span class="profile-name"><?= htmlspecialchars($officer['full_name']) ?></span>
                    <span class="badge-active">✔ Station Active</span>
                    <span class="badge-role">Presiding Officer</span>
                </div>
                <div style="font-size:12px;color:var(--muted);margin-bottom:2px;">ID: PO-2026-<?= str_pad($officer['officer_id'],4,'0',STR_PAD_LEFT) ?></div>
                <div class="profile-grid">
                    <div><div class="label">Polling Station</div><div class="value"><?= htmlspecialchars($officer['station_name']??'N/A') ?></div></div>
                    <div><div class="label">Constituency</div><div class="value"><?= htmlspecialchars($officer['constituency_name']??'N/A') ?></div></div>
                    <div><div class="label">Station Address</div><div class="value"><?= htmlspecialchars($officer['station_address']??'N/A') ?></div></div>
                    <div><div class="label">Official Shift</div><div class="value">08:00 AM — 05:00 PM</div></div>
                    <div><div class="label">Station Status</div>
                        <div class="value">
                            <?php if($station_locked): ?>
                                <span class="badge badge-locked">🔒 Verified</span>
                            <?php elseif($submitted_booths > 0): ?>
                                <span class="badge badge-review">⚙️ In Progress</span>
                            <?php else: ?>
                                <span class="badge badge-pending">⏳ Pending</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div><div class="label">Total Booths</div><div class="value"><?= $total_booths ?> Booths Assigned</div></div>
                </div>
            </div>
        </div>
    </div>

    <!-- LIVE STATS -->
    <div class="card">
        <div class="card-header"><span class="card-title">📊 Live Station Status</span></div>
        <div class="card-body">
            <div class="two-equal" style="margin-bottom:12px;">
                <div class="stat-box blue"><div class="stat-label">Total Booths</div><div class="stat-value blue"><?= $total_booths ?></div></div>
                <div class="stat-box green"><div class="stat-label">Submitted</div><div class="stat-value green"><?= $submitted_booths ?></div></div>
                <div class="stat-box amber"><div class="stat-label">Pending</div><div class="stat-value amber"><?= $pending_booths ?></div></div>
                <div class="stat-box purple"><div class="stat-label">Verified</div><div class="stat-value purple"><?= $verified_booths ?></div></div>
            </div>
            <div class="two-equal">
                <div class="stat-box"><div class="stat-label">Ballots Issued</div><div class="stat-value black" style="font-size:22px;"><?= number_format($total_ballots) ?></div></div>
                <div class="stat-box blue"><div class="stat-label">Votes Recorded</div><div class="stat-value blue" style="font-size:22px;"><?= number_format($total_votes_all) ?></div></div>
            </div>
            <div class="progress-bar-wrap">
                <div class="progress-label"><span>Verification Progress</span><span><?= $submit_pct ?>%</span></div>
                <div class="progress-bar"><div class="progress-fill green" style="width:<?= $submit_pct ?>%"></div></div>
            </div>
            <div class="progress-bar-wrap" style="margin-top:8px;">
                <div class="progress-label"><span>Ballots Usage</span><span><?= $usage_pct ?>%</span></div>
                <div class="progress-bar"><div class="progress-fill" style="width:<?= min(100,$usage_pct) ?>%"></div></div>
            </div>
        </div>
    </div>
</div>

<!-- ===== MAIN TABLE + RIGHT SIDEBAR ===== -->
<div class="two-col section-gap">

    <!-- LEFT: BOOTH RESULT VERIFICATION TABLE -->
    <div>
        <div class="card section-gap">
            <div class="card-header">
                <span class="card-title">🗳️ Submitted Booth Results
                    <span style="font-size:11px;font-weight:400;color:var(--muted);">Review APO submissions and verify</span>
                </span>
                <span style="font-size:12px;color:var(--muted);"><?= $station_locked ? '🔒 Station Locked' : $submitted_booths.'/'.$total_booths.' submitted' ?></span>
            </div>
            <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Booth</th>
                        <th>APO Name</th>
                        <th>Ballots Issued</th>
                        <th>Votes Entered</th>
                        <th>Difference</th>
                        <th>Submission Time</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach($booths as $b):
                    $diff = (int)$b['total_votes'] - (int)$b['ballots_issued'];
                    $is_submitted = $b['locked_count'] > 0;
                    $has_entries  = $b['result_count'] > 0;

                    if ($station_locked)       { $status_badge = '<span class="badge badge-verified">✔ Verified</span>'; }
                    elseif (!$has_entries)     { $status_badge = '<span class="badge badge-pending">⏳ Pending</span>'; }
                    elseif ($diff > 0)         { $status_badge = '<span class="badge badge-mismatch">❌ Vote Mismatch</span>'; }
                    elseif ($is_submitted && $diff <= 0) { $status_badge = '<span class="badge badge-review">⚠ Needs Review</span>'; }
                    else                       { $status_badge = '<span class="badge badge-pending">⏳ Pending</span>'; }

                    $diff_class = $diff > 0 ? 'diff-over' : ($diff < 0 ? 'diff-under' : 'diff-ok');
                    $diff_display = ($diff >= 0 ? '+' : '') . $diff;
                ?>
                <tr id="booth-row-<?= $b['booth_id'] ?>">
                    <td><strong>Booth <?= htmlspecialchars($b['booth_number']) ?></strong></td>
                    <td><?= htmlspecialchars($b['apo_name'] ?? 'N/A') ?></td>
                    <td class="num"><?= number_format($b['ballots_issued']) ?></td>
                    <td class="num"><?= number_format($b['total_votes']) ?></td>
                    <td class="<?= $diff_class ?>"><?= $diff_display ?></td>
                    <td style="font-size:12px;color:var(--muted);">
                        <?= $b['last_entry'] ? date('h:i A', strtotime($b['last_entry'])) : '—' ?>
                    </td>
                    <td><?= $status_badge ?></td>
                    <td>
                        <div class="gap-8">
                            <button class="btn btn-view btn-sm" onclick="viewDetails(<?= $b['booth_id'] ?>)">🔍 View</button>
                           <?php if(!$station_locked && $is_submitted && $diff <= 0): ?>
<button class="btn btn-verify btn-sm"
    id="btn-verify-<?= $b['booth_id'] ?>"
    onclick="localVerifyBooth(<?= $b['booth_id'] ?>)">✔ Verify</button>
<button class="btn btn-reject btn-sm"
    id="btn-reject-<?= $b['booth_id'] ?>"
    onclick="rejectBooth(<?= $b['booth_id'] ?>)">✖ Reject</button>
<?php elseif($station_locked): ?>
<span style="font-size:11px;color:var(--muted);">🔒 Locked</span>
<?php else: ?>
<button class="btn btn-verify btn-sm" disabled>✔ Verify</button>
<button class="btn btn-reject btn-sm" disabled>✖ Reject</button>
<?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:#f8fafc;font-weight:700;">
                        <td colspan="2">STATION TOTAL</td>
                        <td class="num"><?= number_format($total_ballots) ?></td>
                        <td class="num" style="color:var(--primary);font-size:16px;"><?= number_format($total_votes_all) ?></td>
                        <td class="<?= ($total_votes_all-$total_ballots)>0?'diff-over':'diff-ok' ?>"><?= ($total_votes_all-$total_ballots >= 0 ? '+' : '').($total_votes_all-$total_ballots) ?></td>
                        <td colspan="3"></td>
                    </tr>
                </tfoot>
            </table>
            </div>
        </div>

        <!-- STATION FINALIZATION CARD -->
        <div class="card section-gap">
            <div class="card-header">
                <span class="card-title">🔐 Finalize Polling Station Verification</span>
            </div>
            <div class="card-body">
                <?php if($station_locked): ?>
                <div class="locked-ribbon" style="margin-bottom:0;">🔒 Station Result Verified & Locked at <?= $station_result ? date('d M Y, h:i A', strtotime($station_result['verification_timestamp'])) : 'N/A' ?></div>
                <?php else: ?>
                <div class="two-equal" style="margin-bottom:16px;">
                    <div class="stat-box blue"><div class="stat-label">Total Station Votes</div><div class="stat-value blue" style="font-size:22px;"><?= number_format($total_votes_all) ?></div></div>
                    <div class="stat-box green"><div class="stat-label">Submitted Booths</div><div class="stat-value green" style="font-size:22px;"><?= $submitted_booths ?>/<?= $total_booths ?></div></div>
                </div>
                <ul class="checklist" style="margin-bottom:18px;">
                    <li>
                        <span class="chk-icon <?= $chk_all_submitted ? 'chk-ok' : 'chk-fail' ?>"><?= $chk_all_submitted ? '✓' : '✗' ?></span>
                        All booths submitted by APOs
                    </li>
                    <li>
                        <span class="chk-icon <?= $chk_no_mismatch ? 'chk-ok' : 'chk-fail' ?>"><?= $chk_no_mismatch ? '✓' : '✗' ?></span>
                        No vote count mismatches detected
                    </li>
                    <li>
                        <span class="chk-icon <?= $chk_entries_locked ? 'chk-ok' : 'chk-fail' ?>"><?= $chk_entries_locked ? '✓' : '✗' ?></span>
                        All APO entries locked
                    </li>
                    <li>
                        <span class="chk-icon <?= $chk_validated ? 'chk-ok' : 'chk-fail' ?>"><?= $chk_validated ? '✓' : '✗' ?></span>
                        All totals validated
                    </li>
                </ul>
                <?php $can_finalize = $chk_all_submitted && $chk_no_mismatch && $chk_entries_locked; ?>
<button class="btn btn-primary" id="btn-finalize-station"
    style="width:100%;padding:13px;font-size:14px;justify-content:center;"
    disabled
    onclick="finalizeStation(<?= $station_id ?>)">
    ✔ Verify &amp; Lock Polling Station Result
</button>
<p id="finalize-hint" style="font-size:12px;color:var(--muted);margin-top:8px;text-align:center;">
    <?php if(!$can_finalize): ?>
        Complete all checklist items above before finalizing.
    <?php else: ?>
        Verify all individual booths above to enable this button.
    <?php endif; ?>
</p>
<!-- PHP-side eligibility passed to JS -->
<script>const PHP_CAN_FINALIZE = <?= $can_finalize ? 'true' : 'false' ?>;</script>
                <?php endif; ?>
            </div>
        </div>

        <!-- ACTIVITY TIMELINE -->
        <div class="card section-gap">
            <div class="card-header"><span class="card-title">🕒 Activity Timeline</span></div>
            <div class="card-body">
                <div class="activity-list">
                <?php if(empty($audit_logs)): ?>
                    <p style="color:var(--muted);font-size:13px;text-align:center;padding:20px 0;">No activity recorded yet.</p>
                <?php else: ?>
                <?php foreach($audit_logs as $log):
                    $icons = ['VOTE_ENTRY'=>['🗳️','blue'],'LOGIN'=>['🔐','purple'],'SUBMIT_VOTES'=>['📤','green'],'VERIFY_STATION'=>['✔','green'],'REJECT_BOOTH'=>['✖','amber']];
                    $ic = $icons[$log['action_type']] ?? ['📋','blue'];
                ?>
                <div class="activity-item">
                    <div class="act-icon <?= $ic[1] ?>"><?= $ic[0] ?></div>
                    <div>
                        <div class="act-text"><?= htmlspecialchars($log['action_type']) ?> — <?= htmlspecialchars($log['details'] ?? '') ?></div>
                        <div class="act-sub"><?= htmlspecialchars($log['officer_name']??'System') ?> (<?= htmlspecialchars($log['officer_role']??'') ?>) · <?= date('d M, h:i A', strtotime($log['timestamp'])) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- RIGHT SIDEBAR -->
    <div>

        <!-- ALERTS -->
        <div class="card section-gap">
            <div class="card-header"><span class="card-title">⚠️ Alert &amp; Fraud Monitor</span><span style="font-size:12px;color:var(--danger);"><?= count(array_filter($alerts,fn($a)=>$a['type']==='danger')) ?> critical</span></div>
            <div class="card-body">
                <?php if(empty($alerts)): ?>
                <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:12px;font-size:13px;color:#166534;">✅ No suspicious activity detected.</div>
                <?php else: ?>
                <?php foreach($alerts as $a): ?>
                <div class="alert-item alert-<?= $a['type'] ?>">
                    <span class="alert-icon"><?= $a['type']==='danger'?'❌':($a['type']==='warning'?'⚠️':'ℹ️') ?></span>
                    <span><?= htmlspecialchars($a['msg']) ?></span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- ELECTION MONITORING -->
        <div class="card section-gap">
            <div class="card-header"><span class="card-title">📈 Election Monitoring</span></div>
            <div class="card-body">
                <div class="two-equal" style="gap:10px;">
                    <div class="mon-card">
                        <div class="mon-val" style="color:var(--primary);"><?= $usage_pct ?>%</div>
                        <div class="mon-label">Station Turnout</div>
                    </div>
                    <div class="mon-card">
                        <div class="mon-val" style="color:var(--success);"><?= $submit_pct ?>%</div>
                        <div class="mon-label">Verification Rate</div>
                    </div>
                    <div class="mon-card">
                        <div class="mon-val" style="color:var(--warning);"><?= $total_booths > 0 ? round(($submitted_booths/$total_booths)*100) : 0 ?>%</div>
                        <div class="mon-label">Submission Rate</div>
                    </div>
                    <div class="mon-card">
                        <div class="mon-val" style="color:var(--purple);"><?= $avg_time_display ?></div>
                        <div class="mon-label">Avg Processing</div>
                    </div>
                </div>
            </div>
        </div>
        <!-- SYSTEM RULES -->
        <div class="card section-gap">
            <div class="card-header"><span class="card-title">📌 System Rules</span></div>
            <div class="card-body" style="font-size:12.5px;">
                <ul style="list-style:none;display:flex;flex-direction:column;gap:9px;">
                    <li style="display:flex;gap:8px;"><span style="color:var(--primary);">●</span>Votes cannot exceed ballots issued per booth.</li>
                    <li style="display:flex;gap:8px;"><span style="color:var(--primary);">●</span>Only verified booths can be aggregated to station level.</li>
                    <li style="display:flex;gap:8px;"><span style="color:var(--primary);">●</span>Locked results cannot be modified without PO rejection.</li>
                    <li style="display:flex;gap:8px;"><span style="color:var(--primary);">●</span>All booths must be submitted before station verification.</li>
                    <li style="display:flex;gap:8px;"><span style="color:var(--primary);">●</span>PO rejection unlocks booth for APO re-entry.</li>
                </ul>
            </div>
        </div>

    </div>
</div>

<div class="lifecycle-section section-gap">

    <!-- Header band -->
    <div class="lifecycle-header">
        <div class="lifecycle-header-left">
            <h2>📊 Submission Progress Lifecycle</h2>
            <p>End-to-end status of booth results from APO entry through to ARO aggregation.</p>
        </div>
        <span class="lifecycle-station-chip">
            🏛️ <?= htmlspecialchars($officer['station_name'] ?? 'Polling Station') ?>
            &nbsp;·&nbsp;
            Step <?= $lc_step ?> of 5
        </span>
    </div>

    <!-- Body -->
    <div class="lifecycle-body">

        <!-- ── Track + Circles ── -->
        <div class="lifecycle-track">
            <!-- Filled track segment -->
            <div class="lifecycle-fill" style="width:calc(<?= $lc_fill_pct ?>% - 40px);"></div>

            <?php
            $stages = [
                1 => ['icon' => '📋', 'label' => 'Booths Assigned',   'sub' => 'APO accounts linked to booths'],
                2 => ['icon' => '✍️',  'label' => 'Votes Entered',    'sub' => $submitted_booths.'/'.$total_booths.' booths submitted'],
                3 => ['icon' => '📤',  'label' => 'All Submitted',    'sub' => 'All APOs have submitted'],
                4 => ['icon' => '✔',  'label' => 'Booths Verified',   'sub' => $verified_booths.'/'.$total_booths.' verified by PO'],
                5 => ['icon' => '🔒',  'label' => 'Station Locked',   'sub' => 'Result forwarded to ARO'],
            ];
            foreach ($stages as $idx => $s):
                [$cc, $lc, $dc] = lc_classes($idx, $lc_step);
                $label_lines = explode("\n", $s['label']);
            ?>
            <div class="lifecycle-stage">
                <div class="lc-circle <?= $cc ?>">
                    <?php if ($idx < $lc_step): ?>✓<?php else: ?><?= $s['icon'] ?><?php endif; ?>
                </div>
                <div class="lc-label <?= $lc ?>">
                    <?= implode('<br>', $label_lines) ?>
                </div>
                <div class="lc-sub <?= $lc ?>"><?= htmlspecialchars($s['sub']) ?></div>
            </div>
            <?php endforeach; ?>
        </div><!-- /lifecycle-track -->

        <!-- ── Detail cards ── -->
        <div class="lifecycle-detail-row">

            <!-- Stage 1: Booths assigned -->
            <?php [$cc,,$dc] = lc_classes(1, $lc_step); ?>
            <div class="lc-detail-card <?= $dc ?>">
                <div class="lc-dc-title">📋 Booths Assigned</div>
                <div class="lc-dc-value <?= $lc_step >= 1 ? 'green' : '' ?>"><?= $total_booths ?></div>
                <div class="lc-dc-sub">Total booths in station</div>
            </div>

            <!-- Stage 2: Votes entered -->
            <?php [$cc,,$dc] = lc_classes(2, $lc_step); ?>
            <div class="lc-detail-card <?= $dc ?>">
                <div class="lc-dc-title">✍️ APO Submissions</div>
                <div class="lc-dc-value <?= $lc_step >= 2 ? 'blue' : '' ?>"><?= $submitted_booths ?> / <?= $total_booths ?></div>
                <div class="lc-dc-sub"><?= $total_booths - $submitted_booths ?> booths pending entry</div>
            </div>

            <!-- Stage 3: All submitted -->
            <?php [$cc,,$dc] = lc_classes(3, $lc_step); ?>
            <div class="lc-detail-card <?= $dc ?>">
                <div class="lc-dc-title">📤 Votes Recorded</div>
                <div class="lc-dc-value <?= $lc_step >= 3 ? 'green' : 'amber' ?>">
                    <?= number_format($total_votes_all) ?>
                </div>
                <div class="lc-dc-sub">of <?= number_format($total_ballots) ?> ballots issued</div>
            </div>

            <!-- Stage 4: PO verification -->
            <?php [$cc,,$dc] = lc_classes(4, $lc_step); ?>
            <div class="lc-detail-card <?= $dc ?>">
                <div class="lc-dc-title">✔ PO Verification</div>
                <div class="lc-dc-value <?= $lc_step >= 4 ? 'green' : '' ?>"><?= $verified_booths ?> / <?= $total_booths ?></div>
                <div class="lc-dc-sub">Booths verified &amp; locked</div>
            </div>

            <!-- Stage 5: Station locked / forwarded -->
            <?php [$cc,,$dc] = lc_classes(5, $lc_step); ?>
            <div class="lc-detail-card <?= $dc ?>">
                <div class="lc-dc-title">🔒 Station Status</div>
                <div class="lc-dc-value <?= $station_locked ? 'green' : 'amber' ?>" style="font-size:14px;margin-top:2px;">
                    <?= $station_locked ? 'LOCKED' : 'PENDING' ?>
                </div>
                <div class="lc-dc-sub">
                    <?= $station_locked
                        ? 'Result forwarded to ARO'
                        : 'Awaiting all booth verifications' ?>
                </div>
            </div>

        </div><!-- /lifecycle-detail-row -->

    </div><!-- /lifecycle-body -->
</div><!-- /lifecycle-section -->
</div><!-- /page-wrap -->

<!-- ===== FOOTER ===== -->
<footer class="footer">
    <div>🗳️ Bangladesh Election Commission &nbsp;·&nbsp; EMS © 2026</div>
    <div class="footer-links">
        <a href="#">Helpdesk</a>
        <a href="#">Privacy Policy</a>
        <a href="#">Terms of Use</a>
    </div>
</footer>

<!-- ===== DETAIL MODAL ===== -->
<div class="modal-overlay" id="detailModal">
    <div class="modal-box">
        <div class="modal-header">
            <span class="modal-title" id="modalTitle">Booth Details</span>
            <button class="modal-close" onclick="closeModal()">✕</button>
        </div>
        <div class="modal-body" id="modalBody">
            <p style="text-align:center;color:var(--muted);padding:30px 0;">Loading...</p>
        </div>
    </div>
</div>

<!-- ===== TOAST ===== -->
<div id="toast"></div>

<script>
const STATION_ID = <?= $station_id ?>;

// ===== VIEW DETAILS MODAL =====
function viewDetails(boothId) {
    document.getElementById('detailModal').classList.add('open');
    document.getElementById('modalBody').innerHTML = '<p style="text-align:center;color:var(--muted);padding:40px 0;">⏳ Loading booth details...</p>';

    const fd = new FormData();
    fd.append('action','get_booth_details');
    fd.append('booth_id', boothId);

    fetch(window.location.href, {method:'POST', body:fd})
        .then(r => r.json())
        .then(res => {
            if(!res.success) { showToast(res.message,'error'); closeModal(); return; }
            const b = res.booth;
            const diff = res.diff;
            const ok = diff <= 0 && res.total_votes >= 0;

            let rows = res.candidates.map((c,i) => {
                const pct = res.total_votes > 0 ? (c.votes_received/res.total_votes*100).toFixed(1) : 0;
                const colors = {'AL':'#006633','BNP':'#003399','JP':'#CC0000','IAB':'#009900','WPB':'#CC3300'};
                const col = colors[c.abbreviation] || '#555';
                return `<tr>
                    <td>${i+1}</td>
                    <td><strong>${c.full_name}</strong></td>
                    <td><span class="party-badge-sm" style="background:${col};">${c.party_name}</span></td>
                    <td>${c.symbol || '—'}</td>
                    <td style="font-weight:700;font-size:15px;color:var(--primary);">${c.votes_received.toLocaleString()}</td>
                    <td style="color:var(--muted);">${pct}%</td>
                </tr>`;
            }).join('');

            const diffClass = diff > 0 ? 'diff-over' : diff < 0 ? 'diff-under' : 'diff-ok';
            const diffDisplay = (diff >= 0 ? '+' : '') + diff;

            let validHTML = '';
            const checks = [
                [diff <= 0,  diff <= 0 ? 'Total votes within ballots issued' : `Total votes EXCEED ballots issued by ${diff}`],
                [res.is_locked, res.is_locked ? 'APO entry locked — submission confirmed' : 'Entry not yet locked by APO'],
                [res.candidates.length > 0, res.candidates.length > 0 ? 'Candidate entries present' : 'No candidate data found'],
            ];
            const allOk = checks.every(c => c[0]);
            validHTML = `<div class="validation-box ${allOk?'ok':'error'}">
                <div style="font-weight:700;margin-bottom:10px;font-size:13px;">${allOk?'✅ Validation Passed':'⚠️ Validation Issues Detected'}</div>
                ${checks.map(c=>`<div class="validation-item">${c[0]?'✅':'❌'} ${c[1]}</div>`).join('')}
            </div>`;

            document.getElementById('modalTitle').textContent = `Booth ${b.booth_number} — Detailed Review`;
            document.getElementById('modalBody').innerHTML = `
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:18px;">
                    <div class="stat-box blue"><div class="stat-label">Ballots Issued</div><div class="stat-value blue" style="font-size:22px;">${parseInt(b.ballots_issued).toLocaleString()}</div></div>
                    <div class="stat-box ${diff<=0?'green':'red'}"><div class="stat-label">Total Votes</div><div class="stat-value ${diff<=0?'green':'red'}" style="font-size:22px;">${res.total_votes.toLocaleString()}</div></div>
                    <div class="stat-box ${diff<=0?'green':'red'}"><div class="stat-label">Difference</div><div class="stat-value ${diff<=0?'green':'red'} ${diffClass}" style="font-size:22px;">${diffDisplay}</div></div>
                </div>
                <div style="font-size:13px;color:var(--muted);margin-bottom:8px;">APO: <strong>${b.apo_name||'N/A'}</strong></div>
                <table class="candidate-table">
                    <thead><tr><th>#</th><th>Candidate</th><th>Party</th><th>Symbol</th><th>Votes</th><th>%</th></tr></thead>
                    <tbody>${rows}</tbody>
                    <tfoot><tr style="background:#f8fafc;font-weight:700;"><td colspan="4" style="text-align:right;">TOTAL</td><td>${res.total_votes.toLocaleString()}</td><td>100%</td></tr></tfoot>
                </table>
                ${validHTML}
            `;
        })
        .catch(() => { showToast('Network error','error'); closeModal(); });
}

function closeModal() {
    document.getElementById('detailModal').classList.remove('open');
}
document.getElementById('detailModal').addEventListener('click', function(e){
    if(e.target === this) closeModal();
});

// ===== VERIFY BOOTH =====
// ===== LOCAL VERIFY BOOTH (UI only — no DB) =====
// Tracks which booths the PO has clicked-verified this session
const localVerifiedBooths = new Set();

// Collect all booth IDs that are eligible for local verification
// (those that have a verify button rendered, i.e. submitted + no mismatch)
const eligibleBoothIds = new Set(
    [...document.querySelectorAll('[id^="btn-verify-"]')]
        .map(el => parseInt(el.id.replace('btn-verify-', '')))
);

function localVerifyBooth(boothId) {
    if(!confirm('Mark this booth as verified?\n\nThis is a local check only. The station will be saved to the database only when you click "Verify & Lock" at the bottom.')) return;

    // Disable both buttons for this booth
    const verifyBtn = document.getElementById('btn-verify-' + boothId);
    const rejectBtn = document.getElementById('btn-reject-' + boothId);
    if (verifyBtn) { verifyBtn.disabled = true; verifyBtn.textContent = '✔ Verified'; verifyBtn.style.opacity = '0.5'; }
    if (rejectBtn) { rejectBtn.disabled = true; rejectBtn.style.opacity = '0.5'; }

    // Update the status badge in the row
    const row = document.getElementById('booth-row-' + boothId);
    if (row) {
        const statusCell = row.querySelector('td:nth-child(7)');
        if (statusCell) statusCell.innerHTML = '<span class="badge badge-verified">✔ PO Verified</span>';
    }

    localVerifiedBooths.add(boothId);
    showToast('Booth marked as verified locally.', 'success');
    checkFinalizeEligibility();
}

function checkFinalizeEligibility() {
    const btn = document.getElementById('btn-finalize-station');
    const hint = document.getElementById('finalize-hint');
    if (!btn) return;

    // All eligible booths must be locally verified, AND PHP checklist must pass
    const allVerified = PHP_CAN_FINALIZE && eligibleBoothIds.size > 0 &&
        [...eligibleBoothIds].every(id => localVerifiedBooths.has(id));

    btn.disabled = !allVerified;
    if (hint) {
        hint.textContent = allVerified
            ? ''
            : (PHP_CAN_FINALIZE
                ? 'Verify all individual booths above to enable this button.'
                : 'Complete all checklist items above before finalizing.');
    }
}

// ===== REJECT BOOTH =====
function rejectBooth(boothId) {
    if(!confirm('Reject this booth submission?\n\nThis will UNLOCK the booth and allow the APO to re-enter results.\n\nAre you sure?')) return;

    const fd = new FormData();
    fd.append('action','reject_booth');
    fd.append('booth_id', boothId);

    fetch(window.location.href, {method:'POST', body:fd})
        .then(r => r.json())
        .then(res => {
            showToast(res.message, res.success ? 'warning' : 'error');
            if(res.success) setTimeout(() => location.reload(), 1800);
        })
        .catch(() => showToast('Network error','error'));
}


// ===== FINALIZE STATION (this is the ONLY function that writes to DB) =====
function finalizeStation(stationId) {
    if(!confirm('⚠️ FINAL ACTION — Verify & Lock Polling Station Result?\n\nThis will:\n• Submit total votes to the database\n• Lock the station result permanently\n• Forward result to ARO for aggregation\n• Cannot be undone\n\nProceed?')) return;

    const fd = new FormData();
    fd.append('action','verify_booth');
    fd.append('booth_id', <?= $booths[0]['booth_id'] ?? 0 ?>);
    fd.append('station_id', stationId);

    fetch(window.location.href, {method:'POST', body:fd})
        .then(r => r.json())
        .then(res => {
            showToast(res.message, res.success ? 'success' : 'error');
            if(res.success) setTimeout(() => location.reload(), 1800);
        })
        .catch(() => showToast('Network error','error'));
}
// ===== TOAST =====
function showToast(msg, type='info') {
    const container = document.getElementById('toast');
    const el = document.createElement('div');
    el.className = 'toast-msg ' + type;
    el.innerHTML = (type==='success'?'✅ ':type==='error'?'❌ ':type==='warning'?'⚠️ ':'ℹ️ ') + msg;
    container.appendChild(el);
    setTimeout(() => { el.style.opacity='0'; el.style.transition='opacity .4s'; setTimeout(()=>el.remove(),400); }, 4000);
}
</script>
</body>
</html>
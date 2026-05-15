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
if (!isset($_SESSION['officer_id']) || $_SESSION['role'] !== 'ARO') {
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

// ---- AJAX: Station detail modal ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'get_station_details') {
    header('Content-Type: application/json');
    $station_id = (int)($_POST['station_id'] ?? 0);
    if (!$station_id) { echo json_encode(['success'=>false,'message'=>'Invalid station']); exit; }

    $st = $pdo->prepare("
        SELECT ps.*, sr.total_votes_cast, sr.verification_timestamp, sr.status AS sr_status,
               eo.full_name AS po_name
        FROM polling_stations ps
        LEFT JOIN station_results sr ON sr.station_id = ps.station_id
        LEFT JOIN election_officers eo ON eo.officer_id = ps.presiding_officer_id
        WHERE ps.station_id = ?
    ");
    $st->execute([$station_id]);
    $station = $st->fetch();
    if (!$station) { echo json_encode(['success'=>false,'message'=>'Station not found']); exit; }

    // Booths for this station
    $bs = $pdo->prepare("
        SELECT pb.booth_number, pb.ballots_issued,
               IFNULL(SUM(br.votes_received),0) AS total_votes,
               MAX(CASE WHEN br.is_locked=1 THEN 1 ELSE 0 END) AS is_locked,
               apo.full_name AS apo_name
        FROM polling_booths pb
        LEFT JOIN booth_results br ON br.booth_id = pb.booth_id
        LEFT JOIN election_officers apo ON apo.officer_id = pb.assistant_presiding_officer_id
        WHERE pb.station_id = ?
        GROUP BY pb.booth_id
        ORDER BY pb.booth_number
    ");
    $bs->execute([$station_id]);
    $booths = $bs->fetchAll();

    echo json_encode(['success'=>true,'station'=>$station,'booths'=>$booths]);
    exit;
}

// ---- AJAX: Compile constituency result ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'compile_result') {
    header('Content-Type: application/json');
    $constituency_id = (int)($_POST['constituency_id'] ?? 0);
    if (!$constituency_id) { echo json_encode(['success'=>false,'message'=>'Invalid constituency']); exit; }

    // Verify ALL stations in constituency are VERIFIED
    $chk = $pdo->prepare("
        SELECT COUNT(*) as total,
               SUM(CASE WHEN sr.status='VERIFIED' THEN 1 ELSE 0 END) as verified
        FROM polling_stations ps
        LEFT JOIN station_results sr ON sr.station_id = ps.station_id
        WHERE ps.constituency_id = ?
    ");
    $chk->execute([$constituency_id]);
    $chkRow = $chk->fetch();
    if ((int)$chkRow['total'] === 0) {
        echo json_encode(['success'=>false,'message'=>'No polling stations found for this constituency.']);
        exit;
    }
    if ((int)$chkRow['verified'] < (int)$chkRow['total']) {
        echo json_encode(['success'=>false,'message'=>'All polling stations must be VERIFIED before compilation. '.($chkRow['total']-$chkRow['verified']).' station(s) still pending.']);
        exit;
    }

    // Check not already compiled
    $existChk = $pdo->prepare("SELECT status FROM constituency_results WHERE constituency_id=? LIMIT 1");
    $existChk->execute([$constituency_id]);
    $existRow = $existChk->fetch();
    if ($existRow && in_array($existRow['status'], ['AGGREGATED','APPROVED','PUBLISHED'])) {
        echo json_encode(['success'=>false,'message'=>'Constituency result already compiled (status: '.$existRow['status'].').']);
        exit;
    }

    // Aggregate total votes
    $totStmt = $pdo->prepare("
        SELECT IFNULL(SUM(sr.total_votes_cast),0) as grand_total
        FROM station_results sr
        JOIN polling_stations ps ON ps.station_id = sr.station_id
        WHERE ps.constituency_id = ? AND sr.status = 'VERIFIED'
    ");
    $totStmt->execute([$constituency_id]);
    $totRow = $totStmt->fetch();
    $grand_total = (int)$totRow['grand_total'];

    // Find winner: candidate with most votes across all booths in constituency
    $winStmt = $pdo->prepare("
        SELECT br.candidate_id, IFNULL(SUM(br.votes_received),0) as total_votes
        FROM booth_results br
        JOIN polling_booths pb ON pb.booth_id = br.booth_id
        JOIN polling_stations ps ON ps.station_id = pb.station_id
        WHERE ps.constituency_id = ? AND br.is_locked = 1
        GROUP BY br.candidate_id
        ORDER BY total_votes DESC
        LIMIT 1
    ");
    $winStmt->execute([$constituency_id]);
    $winRow = $winStmt->fetch();
    $winner_id = $winRow ? (int)$winRow['candidate_id'] : null;

    $pdo->beginTransaction();
    try {
        if ($existRow) {
            $upd = $pdo->prepare("
                UPDATE constituency_results
                SET total_votes_cast=?, winner_candidate_id=?, compiled_by_aro=?,
                    approved_by_ro=NULL, approval_timestamp=NULL, status='AGGREGATED'
                WHERE constituency_id=?
            ");
            $upd->execute([$grand_total, $winner_id, $logged_in_id, $constituency_id]);
        } else {
            $ins = $pdo->prepare("
                INSERT INTO constituency_results
                (constituency_id, winner_candidate_id, total_votes_cast, compiled_by_aro,
                 approved_by_ro, approval_timestamp, status)
                VALUES (?,?,?,?,NULL,NULL,'AGGREGATED')
            ");
            $ins->execute([$constituency_id, $winner_id, $grand_total, $logged_in_id]);
        }

        // Update constituency status
        $updC = $pdo->prepare("UPDATE constituencies SET result_status='AGGREGATED' WHERE constituency_id=?");
        $updC->execute([$constituency_id]);

        // Audit log
        $log = $pdo->prepare("
            INSERT INTO audit_logs (officer_id,action_type,affected_entity,affected_entity_id,details,ip_address)
            VALUES (?,'COMPILE_RESULT','ConstituencyResult',?,?,?)
        ");
        $log->execute([$logged_in_id, $constituency_id,
            "ARO compiled constituency $constituency_id result. Total votes: $grand_total. Winner candidate ID: $winner_id",
            $_SERVER['REMOTE_ADDR']??'']);

        $pdo->commit();
        echo json_encode(['success'=>true,'message'=>'Constituency result compiled successfully. Total votes: '.number_format($grand_total).'. Forwarded to Returning Officer for approval.']);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]);
    }
    exit;
}

// ============================================================
//  LOAD ARO DATA
// ============================================================

// Officer info — ARO may have assigned_constituency_id set, or we derive from stations
$officerStmt = $pdo->prepare("
    SELECT eo.*, c.name AS constituency_name, c.code AS constituency_code,
           c.constituency_id, c.total_registered_voters, c.result_status AS con_status,
           c.returning_officer_id,
           ro.full_name AS ro_name
    FROM election_officers eo
    LEFT JOIN constituencies c ON c.constituency_id = eo.assigned_constituency_id
    LEFT JOIN election_officers ro ON ro.officer_id = c.returning_officer_id
    WHERE eo.officer_id = ?
");
$officerStmt->execute([$logged_in_id]);
$officer = $officerStmt->fetch();

if (!$officer || $officer['role'] !== 'ARO') {
    die('<div style="padding:40px;font-family:sans-serif;color:#c0392b;"><h2>Access Denied</h2><p>ARO role required.</p></div>');
}

$constituency_id = (int)($officer['constituency_id'] ?? 0);

// If no assigned_constituency_id, try to find from compiled results
if (!$constituency_id) {
    $fallback = $pdo->prepare("SELECT constituency_id FROM constituency_results WHERE compiled_by_aro=? LIMIT 1");
    $fallback->execute([$logged_in_id]);
    $fbRow = $fallback->fetch();
    if ($fbRow) $constituency_id = (int)$fbRow['constituency_id'];
}

// If still none, default to constituency 1 for seed data compatibility
if (!$constituency_id) $constituency_id = 1;

// Re-fetch constituency if we derived it
if (!$officer['constituency_id']) {
    $conStmt = $pdo->prepare("
        SELECT c.*, ro.full_name AS ro_name
        FROM constituencies c
        LEFT JOIN election_officers ro ON ro.officer_id = c.returning_officer_id
        WHERE c.constituency_id = ?
    ");
    $conStmt->execute([$constituency_id]);
    $con = $conStmt->fetch();
    if ($con) {
        $officer['constituency_name']      = $con['name'];
        $officer['constituency_code']      = $con['code'];
        $officer['constituency_id']        = $con['constituency_id'];
        $officer['total_registered_voters']= $con['total_registered_voters'];
        $officer['con_status']             = $con['result_status'];
        $officer['returning_officer_id']   = $con['returning_officer_id'];
        $officer['ro_name']                = $con['ro_name'];
    }
}

$con_status   = $officer['con_status'] ?? 'PENDING';
$is_compiled  = in_array($con_status, ['AGGREGATED','APPROVED','PUBLISHED']);
$is_approved  = in_array($con_status, ['APPROVED','PUBLISHED']);

// Polling stations in this constituency — only those that have station_results
$stationsStmt = $pdo->prepare("
    SELECT ps.*,
           sr.total_votes_cast AS sr_votes, sr.status AS sr_status,
           sr.verification_timestamp,
           eo.full_name AS po_name,
           COUNT(DISTINCT pb.booth_id) AS total_booths,
           SUM(CASE WHEN br.is_locked=1 THEN 1 ELSE 0 END) AS locked_results
    FROM polling_stations ps
    LEFT JOIN station_results sr ON sr.station_id = ps.station_id
    LEFT JOIN election_officers eo ON eo.officer_id = ps.presiding_officer_id
    LEFT JOIN polling_booths pb ON pb.station_id = ps.station_id
    LEFT JOIN booth_results br ON br.booth_id = pb.booth_id
    WHERE ps.constituency_id = ?
    GROUP BY ps.station_id
    ORDER BY ps.station_id
");
$stationsStmt->execute([$constituency_id]);
$stations = $stationsStmt->fetchAll();

// Stats
$total_stations    = count($stations);
$verified_stations = 0;
$pending_stations  = 0;
$draft_stations    = 0;
$total_votes_agg   = 0;
$total_ballots_con = 0;

foreach ($stations as $s) {
    $total_ballots_con += (int)$s['total_ballots_issued'];
    $total_votes_agg   += (int)$s['sr_votes'];
    $sr = $s['sr_status'] ?? 'PENDING';
    if ($sr === 'VERIFIED')    $verified_stations++;
    elseif ($sr === 'DRAFT')   $draft_stations++;
    else                       $pending_stations++;
}
$readiness_pct = $total_stations > 0 ? round(($verified_stations/$total_stations)*100) : 0;
$turnout_pct   = $total_ballots_con > 0 ? round(($total_votes_agg/$total_ballots_con)*100,1) : 0;

// All stations verified check
$all_verified = ($verified_stations === $total_stations && $total_stations > 0);
$no_mismatch  = true; // could add more checks
foreach ($stations as $s) {
    if ((int)$s['sr_votes'] > (int)$s['total_ballots_issued'] && (int)$s['total_ballots_issued'] > 0) {
        $no_mismatch = false;
    }
}
$can_compile = $all_verified && $no_mismatch && !$is_compiled;

// Constituency result record
$crStmt = $pdo->prepare("SELECT cr.*, c.full_name AS winner_name, pp.name AS winner_party, pp.abbreviation AS winner_abbr
    FROM constituency_results cr
    LEFT JOIN candidates c ON c.candidate_id = cr.winner_candidate_id
    LEFT JOIN political_parties pp ON pp.party_id = c.party_id
    WHERE cr.constituency_id = ? LIMIT 1");
$crStmt->execute([$constituency_id]);
$con_result = $crStmt->fetch();

// Candidate totals for preview
$candTotStmt = $pdo->prepare("
    SELECT c.candidate_id, c.full_name, c.symbol,
           pp.name AS party_name, pp.abbreviation,
           IFNULL(SUM(br.votes_received),0) AS total_votes
    FROM candidates c
    LEFT JOIN political_parties pp ON pp.party_id = c.party_id
    LEFT JOIN booth_results br ON br.candidate_id = c.candidate_id AND br.is_locked=1
    LEFT JOIN polling_booths pb ON pb.booth_id = br.booth_id
    LEFT JOIN polling_stations ps ON ps.station_id = pb.station_id
    WHERE c.constituency_id = ?
    GROUP BY c.candidate_id
    ORDER BY total_votes DESC
");
$candTotStmt->execute([$constituency_id]);
$candidate_totals = $candTotStmt->fetchAll();
$leading = $candidate_totals[0] ?? null;

// Alerts
$alerts = [];
foreach ($stations as $s) {
    if (!$s['sr_status'] || $s['sr_status'] === 'PENDING')
        $alerts[] = ['type'=>'info', 'msg'=>$s['name'].': No results submitted yet — awaiting PO.'];
    if ((int)$s['sr_votes'] > (int)$s['total_ballots_issued'] && (int)$s['total_ballots_issued'] > 0)
        $alerts[] = ['type'=>'danger','msg'=>$s['name'].': Votes exceed ballots issued!'];
    if ($s['sr_status']==='DRAFT')
        $alerts[] = ['type'=>'warning','msg'=>$s['name'].': Result is in DRAFT — not yet verified by PO.'];
}

// Audit logs
$logsStmt = $pdo->prepare("
    SELECT al.*, eo.full_name AS officer_name, eo.role AS officer_role
    FROM audit_logs al
    LEFT JOIN election_officers eo ON eo.officer_id = al.officer_id
    ORDER BY al.timestamp DESC LIMIT 6
");
$logsStmt->execute();
$audit_logs = $logsStmt->fetchAll();

// Workflow step
$workflow_step = 2; // PO verification done by default when any verified
if ($verified_stations > 0)  $workflow_step = 3;
if ($all_verified)           $workflow_step = 4;
if ($is_compiled)            $workflow_step = 5;
if ($is_approved)            $workflow_step = 6;

// Party colours
$party_colors = ['AL'=>'#006633','BNP'=>'#003399','JP'=>'#CC0000','IAB'=>'#009900','WPB'=>'#CC3300'];
function pcolor($abbr,$map){ return $map[$abbr]??'#555'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>ARO Dashboard — Bangladesh Election Commission EMS</title>

   <style>

*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
    --primary:#1a56db;--primary-dk:#1447bc;--accent:#0ea5e9;
    --success:#16a34a;--warning:#d97706;--danger:#dc2626;--purple:#7c3aed;
    --teal:#0d9488;
    --gold:#b45309;--gold-lt:#fef3c7;--gold-border:#fcd34d;
    --navy:#1e3a5f;--navy-dk:#152b47;
    --bg:#f5f7fa;--surface:#fff;--border:#e2e8f0;
    --text:#1e293b;--muted:#64748b;
    --radius:10px;--shadow:0 1px 4px rgba(0,0,0,.08);--shadow-md:0 4px 20px rgba(0,0,0,.10);
}
body{font-family:'Segoe UI',system-ui,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;}

/* ── TOPBAR (from RO — dark navy) ── */
.topbar{background:var(--navy);display:flex;align-items:center;justify-content:space-between;padding:0 28px;height:62px;position:sticky;top:0;z-index:200;box-shadow:0 2px 12px rgba(0,0,0,.18);}
.topbar-brand{display:flex;align-items:center;gap:12px;}
.brand-text{font-size:15px;font-weight:700;color:#fff;line-height:1.15;}
.brand-sub{font-size:10.5px;color:#94a3b8;font-weight:400;}
.topbar-right{display:flex;align-items:center;gap:14px;}

/* Election badge pill (from RO) */
.topbar-election{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);border-radius:20px;padding:4px 14px;font-size:12px;color:#e2e8f0;font-weight:600;}

/* Officer chip (from RO — gradient avatar, white text) */
.officer-avatar{width:36px;height:36px;background:linear-gradient(135deg,#0d9488,#0ea5e9);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:14px;border:2px solid rgba(255,255,255,.25);}
.officer-info .name{color:#f1f5f9;font-size:13px;font-weight:600;}
.role-badge{font-size:10.5px;color:#fff;background:rgba(13,148,136,.6);border-radius:8px;padding:1px 8px;border:1px solid rgba(13,148,136,.4);}

/* Logout button (from RO) */
.btn-logout{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.18);border-radius:7px;padding:6px 14px;font-size:12px;cursor:pointer;color:#cbd5e1;transition:.15s;}
.btn-logout:hover{background:rgba(220,38,38,.25);border-color:#f87171;color:#fca5a5;}

/* ── LAYOUT ── */
.page-wrap{max-width:1280px;margin:0 auto;padding:24px 20px;}
.two-col{display:grid;grid-template-columns:1fr 340px;gap:20px;}
.two-equal{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.three-col{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;}
.four-col{display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:12px;}
@media(max-width:960px){.two-col{grid-template-columns:1fr}.four-col{grid-template-columns:1fr 1fr}}
@media(max-width:600px){.three-col,.two-equal{grid-template-columns:1fr}.four-col{grid-template-columns:1fr}}
.section-gap{margin-bottom:20px;}

/* ── CARDS ── */
.card{background:var(--surface);border:1.5px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);}
.card-header{display:flex;align-items:center;justify-content:space-between;padding:14px 20px 10px;border-bottom:1px solid var(--border);}
.card-title{font-size:14px;font-weight:700;display:flex;align-items:center;gap:8px;}
.card-body{padding:18px 20px;}

/* ── PROFILE (unchanged from ARO) ── */
.profile-card{padding:20px;display:flex;gap:20px;align-items:flex-start;}
.profile-avatar-lg{width:70px;height:70px;border-radius:12px;background:linear-gradient(135deg,#0d9488,#059669);display:flex;align-items:center;justify-content:center;font-size:28px;color:#fff;font-weight:700;flex-shrink:0;}
.profile-name{font-size:20px;font-weight:800;margin-bottom:2px;}
.profile-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px 20px;font-size:12.5px;margin-top:10px;}
.profile-grid .label{color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.5px;margin-bottom:2px;}
.profile-grid .value{font-weight:600;}
.badge-active{display:inline-block;background:#dcfce7;color:var(--success);border-radius:20px;padding:3px 12px;font-size:12px;font-weight:600;}
.badge-role-aro{display:inline-block;background:#ccfbf1;color:#0f766e;border-radius:20px;padding:3px 12px;font-size:12px;font-weight:600;margin-left:6px;}
.profile-status-row{display:flex;align-items:center;gap:8px;margin-bottom:3px;flex-wrap:wrap;}

/* ── STAT BOXES (unchanged) ── */
.stat-box{border:1.5px solid var(--border);border-radius:8px;padding:14px 16px;}
.stat-box.blue{border-color:#bfdbfe;background:#eff6ff;}
.stat-box.green{border-color:#bbf7d0;background:#f0fdf4;}
.stat-box.amber{border-color:#fde68a;background:#fffbeb;}
.stat-box.teal{border-color:#99f6e4;background:#f0fdfa;}
.stat-box.purple{border-color:#ddd6fe;background:#f5f3ff;}
.stat-box.red{border-color:#fecaca;background:#fef2f2;}
.stat-label{font-size:10.5px;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);margin-bottom:4px;}
.stat-value{font-size:28px;font-weight:800;line-height:1;}
.sv-blue{color:var(--primary);}
.sv-green{color:var(--success);}
.sv-amber{color:var(--warning);}
.sv-teal{color:var(--teal);}
.sv-purple{color:var(--purple);}
.sv-red{color:var(--danger);}
.sv-black{color:var(--text);}

/* ── PROGRESS ── */
.progress-bar-wrap{margin-top:10px;}
.progress-label{display:flex;justify-content:space-between;font-size:12px;color:var(--muted);margin-bottom:5px;}
.progress-bar{height:9px;background:#e2e8f0;border-radius:99px;overflow:hidden;}
.progress-fill{height:100%;border-radius:99px;background:linear-gradient(90deg,var(--teal),#22c55e);transition:width .4s;}
.progress-fill.blue{background:linear-gradient(90deg,var(--primary),var(--accent));}

/* ── TABLE ── */
.table-wrap{overflow-x:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:13px;}
.data-table th{background:#f8fafc;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);padding:10px 14px;border-bottom:1.5px solid var(--border);text-align:left;white-space:nowrap;}
.data-table td{padding:11px 14px;border-bottom:1px solid var(--border);vertical-align:middle;}
.data-table tr:last-child td{border-bottom:none;}
.data-table tr:hover td{background:#f8fafc;}
.num{font-weight:700;}

/* ── BADGES ── */
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:6px;font-size:11.5px;font-weight:600;white-space:nowrap;}
.badge-verified{background:#dcfce7;color:#166534;}
.badge-pending{background:#fef9c3;color:#92400e;}
.badge-draft{background:#e0f2fe;color:#0369a1;}
.badge-inconsistent{background:#fef2f2;color:#991b1b;}
.badge-aggregated{background:#f3e8ff;color:#5b21b6;}
.badge-approved{background:#dcfce7;color:#166534;}

/* ── BUTTONS (from RO — polished, border-radius 8px) ── */
.btn{border:none;border-radius:8px;padding:9px 22px;font-size:13px;font-weight:600;cursor:pointer;transition:.15s;display:inline-flex;align-items:center;gap:6px;white-space:nowrap;}
.btn-view{background:#eff6ff;color:var(--primary);border:1px solid #bfdbfe;}
.btn-view:hover{background:#dbeafe;}
.btn-teal{background:var(--teal);color:#fff;}
.btn-teal:hover{background:#0f766e;}
.btn-primary{background:var(--primary);color:#fff;}
.btn-primary:hover{background:var(--primary-dk);}
.btn-primary:disabled{background:#94a3b8;cursor:not-allowed;}
.btn-muted{background:#f1f5f9;color:var(--text);border:1.5px solid var(--border);}
.btn-muted:hover{background:#e2e8f0;}
.btn-sm{padding:5px 12px;font-size:11.5px;}
.btn-lg{padding:13px 32px;font-size:15px;font-weight:700;}

/* Compile button (from RO btn-publish-lg style — gradient, full-width, bold) */
.btn-compile{background:linear-gradient(135deg,#0d9488,#059669);color:#fff;padding:14px 28px;font-size:15px;font-weight:700;border-radius:10px;justify-content:center;width:100%;box-shadow:0 4px 16px rgba(13,148,136,.25);}
.btn-compile:hover{background:linear-gradient(135deg,#0f766e,#047857);box-shadow:0 4px 16px rgba(13,148,136,.35);}
.btn-compile:disabled{background:#94a3b8;box-shadow:none;cursor:not-allowed;}

/* ── CHECKLIST ── */
.checklist{list-style:none;display:flex;flex-direction:column;gap:10px;}
.checklist li{display:flex;align-items:center;gap:10px;font-size:13px;}
.chk-icon{width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0;}
.chk-ok{background:#dcfce7;color:var(--success);}
.chk-fail{background:#fee2e2;color:var(--danger);}

/* ── CANDIDATE TABLE ── */
.cand-table{width:100%;border-collapse:collapse;font-size:13px;}
.cand-table th{background:#f8fafc;font-size:11px;text-transform:uppercase;color:var(--muted);padding:8px 12px;border-bottom:1.5px solid var(--border);text-align:left;}
.cand-table td{padding:9px 12px;border-bottom:1px solid var(--border);}
.cand-table tr:last-child td{border-bottom:none;}
.party-chip{display:inline-block;border-radius:5px;padding:2px 9px;font-size:11px;font-weight:600;color:#fff;}
.leader-row{background:#f0fdfa;}

/* ── TIMELINE (unchanged) ── */
.timeline-wrap{padding:22px 24px;}
.timeline-title{font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);margin-bottom:20px;font-weight:700;}
.timeline{display:flex;align-items:flex-start;position:relative;}
.timeline::before{content:'';position:absolute;top:16px;left:16px;right:16px;height:2px;background:var(--border);z-index:0;}
.tl-step{flex:1;display:flex;flex-direction:column;align-items:center;position:relative;z-index:1;}
.tl-circle{width:32px;height:32px;border-radius:50%;border:2px solid var(--border);background:var(--surface);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:var(--muted);}
.tl-circle.done{background:var(--success);border-color:var(--success);color:#fff;}
.tl-circle.active{background:var(--teal);border-color:var(--teal);color:#fff;}
.tl-label{font-size:10px;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);margin-top:8px;text-align:center;font-weight:600;line-height:1.3;}
.tl-label.done{color:var(--success);}
.tl-label.active{color:var(--teal);}

/* ── ALERTS ── */
.alert-item{display:flex;gap:10px;padding:10px 14px;border-radius:8px;font-size:12.5px;margin-bottom:8px;}
.alert-item:last-child{margin-bottom:0;}
.alert-danger{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;}
.alert-warning{background:#fffbeb;border:1px solid #fde68a;color:#92400e;}
.alert-info{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;}
.alert-icon{font-size:15px;margin-top:1px;flex-shrink:0;}

/* ── MON CARDS ── */
.mon-card{border:1.5px solid var(--border);border-radius:8px;padding:14px;text-align:center;}
.mon-val{font-size:24px;font-weight:800;margin-bottom:2px;}
.mon-label{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;}

/* ── RIBBONS (upgraded to match RO gradient style) ── */
.compiled-ribbon{background:linear-gradient(135deg,#0f766e,#0d9488);border-radius:10px;padding:14px 22px;display:flex;align-items:center;gap:14px;color:#fff;margin-bottom:20px;box-shadow:0 4px 16px rgba(13,148,136,.2);}
.compiled-ribbon .rb-icon{font-size:24px;}
.compiled-ribbon .rb-title{font-size:14px;font-weight:700;}
.pending-ribbon{background:linear-gradient(135deg,#1e3a5f,#1a56db);border-radius:10px;padding:14px 22px;display:flex;align-items:center;gap:14px;color:#fff;margin-bottom:20px;}
.pending-ribbon .rb-icon{font-size:24px;}
.pending-ribbon .rb-title{font-size:14px;font-weight:700;}

/* ── ACTIVITY ── */
.activity-item{display:flex;gap:12px;align-items:flex-start;padding:11px 0;border-bottom:1px solid var(--border);}
.activity-item:last-child{border-bottom:none;}
.act-dot{width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;}
.act-dot.green{background:#dcfce7;}
.act-dot.blue{background:#dbeafe;}
.act-dot.amber{background:#fef3c7;}
.act-dot.teal{background:#ccfbf1;}
.act-text{font-size:13px;font-weight:600;}
.act-sub{font-size:11px;color:var(--muted);margin-top:2px;}

/* ── MODAL ── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:500;align-items:center;justify-content:center;padding:20px;}
.modal-overlay.open{display:flex;}
.modal-box{background:#fff;border-radius:14px;width:100%;max-width:700px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.25);}
.modal-header{display:flex;align-items:center;justify-content:space-between;padding:18px 24px;border-bottom:1.5px solid var(--border);position:sticky;top:0;background:#fff;z-index:1;}
.modal-title{font-size:16px;font-weight:700;}
.modal-close{background:none;border:none;font-size:20px;cursor:pointer;color:var(--muted);padding:4px 8px;border-radius:6px;}
.modal-close:hover{background:#f1f5f9;}
.modal-body{padding:24px;}
.booth-table{width:100%;border-collapse:collapse;font-size:13px;margin-top:12px;}
.booth-table th{background:#f8fafc;font-size:11px;text-transform:uppercase;color:var(--muted);padding:8px 12px;border-bottom:1.5px solid var(--border);text-align:left;}
.booth-table td{padding:9px 12px;border-bottom:1px solid var(--border);}
.booth-table tr:last-child td{border-bottom:none;}

/* ── TOAST ── */
#toast{position:fixed;bottom:28px;right:28px;z-index:999;}
.toast-msg{background:#1e293b;color:#fff;border-radius:9px;padding:12px 20px;font-size:13px;font-weight:500;margin-top:8px;display:flex;align-items:center;gap:10px;box-shadow:0 4px 20px rgba(0,0,0,.2);animation:slideIn .3s ease;}
.toast-msg.success{background:#166534;}
.toast-msg.error{background:#991b1b;}
.toast-msg.warning{background:#92400e;}
@keyframes slideIn{from{transform:translateY(20px);opacity:0;}to{transform:translateY(0);opacity:1;}}

/* ── FOOTER (from RO — dark navy) ── */
.footer{background:var(--navy);padding:20px 28px;display:flex;align-items:center;justify-content:space-between;font-size:12px;color:#94a3b8;margin-top:32px;flex-wrap:wrap;gap:10px;}
.footer-links{display:flex;gap:18px;}
.footer-links a{color:#94a3b8;text-decoration:none;}
.footer-links a:hover{color:#60a5fa;}

/* ── UTILS ── */
.divider{border:none;border-top:1.5px solid var(--border);margin:0;}
.text-muted{color:var(--muted);}
.gap-8{display:flex;gap:8px;flex-wrap:wrap;}
.mt-12{margin-top:12px;}
.diff-over{color:var(--danger);font-weight:700;}
.diff-ok{color:var(--success);font-weight:600;}
</style>
</head>
<body>

<!-- TOPBAR -->
<nav class="topbar">
    <div class="topbar-brand">
        <span style="font-size:22px;">🗳️</span>
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
                <span class="role-badge">ARO · Asst. Returning Officer</span>
            </div>
        </div>
        <button class="btn-logout" onclick="if(confirm('Logout from EMS?')){window.location='logout.php'}">⏻ Logout</button>
    </div>
</nav>

<div class="page-wrap">

<!-- COMPILED / PENDING RIBBON -->
<?php if($is_approved): ?>
<div class="compiled-ribbon">✅ Constituency Result Approved by Returning Officer — <?= htmlspecialchars($officer['ro_name']??'RO') ?>. Status: <?= $con_status ?></div>
<?php elseif($is_compiled): ?>
<div class="pending-ribbon">⏳ Result Compiled — Awaiting Returning Officer Approval (<?= htmlspecialchars($officer['ro_name']??'RO') ?>)</div>
<?php endif; ?>

<!-- HERO: PROFILE + LIVE STATS -->
<div class="two-col section-gap">

    <!-- PROFILE -->
    <div class="card">
        <div class="profile-card">
            <div class="profile-avatar-lg"><?= strtoupper(substr($officer['full_name'],0,1)) ?></div>
            <div style="flex:1;">
                <div class="profile-status-row">
                    <span class="profile-name"><?= htmlspecialchars($officer['full_name']) ?></span>
                    <span class="badge-active">✔ Constituency Active</span>
                    <span class="badge-role-aro">Asst. Returning Officer</span>
                </div>
                <div style="font-size:12px;color:var(--muted);margin-bottom:2px;">ID: ARO-2026-<?= str_pad($officer['officer_id'],4,'0',STR_PAD_LEFT) ?></div>
                <div class="profile-grid">
                    <div><div class="label">Constituency</div><div class="value"><?= htmlspecialchars($officer['constituency_name']??'N/A') ?></div></div>
                    <div><div class="label">Constituency Code</div><div class="value"><?= htmlspecialchars($officer['constituency_code']??'N/A') ?></div></div>
                    <div><div class="label">Returning Officer</div><div class="value"><?= htmlspecialchars($officer['ro_name']??'N/A') ?></div></div>
                    <div><div class="label">Registered Voters</div><div class="value"><?= number_format($officer['total_registered_voters']??0) ?></div></div>
                    <div><div class="label">Compilation Status</div>
                        <div class="value">
                            <?php if($is_approved): ?><span class="badge badge-approved">✔ Approved</span>
                            <?php elseif($is_compiled): ?><span class="badge badge-aggregated">⏳ Awaiting RO</span>
                            <?php elseif($all_verified): ?><span class="badge badge-verified">✔ Ready to Compile</span>
                            <?php else: ?><span class="badge badge-pending">⏳ Pending</span><?php endif; ?>
                        </div>
                    </div>
                    <div><div class="label">Official Shift</div><div class="value">08:00 AM — 08:00 PM</div></div>
                </div>
            </div>
        </div>
    </div>

    <!-- LIVE STATS -->
    <div class="card">
        <div class="card-header"><span class="card-title">📊 Live Constituency Status</span></div>
        <div class="card-body">
            <div class="two-equal" style="margin-bottom:12px;">
                <div class="stat-box blue"><div class="stat-label">Total Stations</div><div class="stat-value sv-blue"><?= $total_stations ?></div></div>
                <div class="stat-box green"><div class="stat-label">Verified</div><div class="stat-value sv-green"><?= $verified_stations ?></div></div>
                <div class="stat-box amber"><div class="stat-label">Pending</div><div class="stat-value sv-amber"><?= $pending_stations ?></div></div>
                <div class="stat-box teal"><div class="stat-label">Total Votes</div><div class="stat-value sv-teal" style="font-size:20px;"><?= number_format($total_votes_agg) ?></div></div>
            </div>
            <div class="progress-bar-wrap">
                <div class="progress-label"><span>Verification Readiness</span><span><?= $readiness_pct ?>%</span></div>
                <div class="progress-bar"><div class="progress-fill" style="width:<?= $readiness_pct ?>%"></div></div>
            </div>
            <div class="progress-bar-wrap mt-12">
                <div class="progress-label"><span>Constituency Turnout</span><span><?= $turnout_pct ?>%</span></div>
                <div class="progress-bar"><div class="progress-fill blue" style="width:<?= min(100,$turnout_pct) ?>%"></div></div>
            </div>
        </div>
    </div>
</div>

<!-- MAIN TABLE + SIDEBAR -->
<div class="two-col section-gap">
<div>

    <!-- STATION STATUS TABLE -->
    <div class="card section-gap">
        <div class="card-header">
            <span class="card-title">🏛️ Constituency Polling Station Verification Status</span>
            <span style="font-size:12px;color:var(--muted);"><?= $verified_stations ?>/<?= $total_stations ?> verified</span>
        </div>
        <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Station</th>
                    <th>Presiding Officer</th>
                    <th>Booths</th>
                    <th>Ballots Issued</th>
                    <th>Votes Cast</th>
                    <th>Status</th>
                    <th>Last Updated</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach($stations as $s):
                $sr = $s['sr_status'] ?? 'PENDING';
                $diff = (int)$s['sr_votes'] - (int)$s['total_ballots_issued'];
                if     ($sr==='VERIFIED')   $badge = '<span class="badge badge-verified">✔ VERIFIED</span>';
                elseif ($sr==='DRAFT')      $badge = '<span class="badge badge-draft">⚠ DRAFT</span>';
                elseif ($diff>0)            $badge = '<span class="badge badge-inconsistent">❌ INCONSISTENT</span>';
                else                        $badge = '<span class="badge badge-pending">⏳ PENDING</span>';
            ?>
            <tr>
                <td><strong><?= htmlspecialchars($s['name']) ?></strong><br><span style="font-size:11px;color:var(--muted);">ID: <?= $s['station_id'] ?></span></td>
                <td><?= htmlspecialchars($s['po_name']??'N/A') ?></td>
                <td class="num"><?= (int)$s['total_booths'] ?></td>
                <td class="num"><?= number_format($s['total_ballots_issued']) ?></td>
                <td class="num <?= $diff>0?'diff-over':'' ?>"><?= number_format($s['sr_votes']??0) ?></td>
                <td><?= $badge ?></td>
                <td style="font-size:12px;color:var(--muted);"><?= $s['verification_timestamp'] ? date('d M, h:i A',strtotime($s['verification_timestamp'])) : '—' ?></td>
                <td><button class="btn btn-view btn-sm" onclick="viewStation(<?= $s['station_id'] ?>)">🔍 View Details</button></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background:#f8fafc;font-weight:700;">
                    <td colspan="3">CONSTITUENCY TOTAL</td>
                    <td class="num"><?= number_format($total_ballots_con) ?></td>
                    <td class="num sv-teal" style="font-size:16px;"><?= number_format($total_votes_agg) ?></td>
                    <td colspan="3"></td>
                </tr>
            </tfoot>
        </table>
        </div>
    </div>

    <!-- CANDIDATE PREVIEW -->
    <div class="card section-gap">
        <div class="card-header">
            <span class="card-title">🏆 Constituency Result Preview — Candidate Totals</span>
            <?php if($leading): ?>
            <span style="font-size:12px;font-weight:600;color:var(--teal);">Leading: <?= htmlspecialchars($leading['full_name']) ?></span>
            <?php endif; ?>
        </div>
        <div class="card-body">
        <?php if(empty($candidate_totals)): ?>
            <p style="color:var(--muted);text-align:center;padding:20px 0;">No candidate votes recorded yet.</p>
        <?php else: ?>
            <table class="cand-table">
                <thead>
                    <tr><th>#</th><th>Candidate</th><th>Party</th><th>Symbol</th><th>Total Votes</th><th>Share %</th></tr>
                </thead>
                <tbody>
                <?php foreach($candidate_totals as $idx => $c):
                    $share = $total_votes_agg > 0 ? round($c['total_votes']/$total_votes_agg*100,1) : 0;
                    $col   = pcolor($c['abbreviation']??'',$party_colors);
                    $isTop = $idx === 0;
                ?>
                <tr class="<?= $isTop?'leader-row':'' ?>">
                    <td><?= $isTop ? '🏆' : ($idx+1) ?></td>
                    <td><strong><?= htmlspecialchars($c['full_name']) ?></strong></td>
                    <td><span class="party-chip" style="background:<?= $col ?>;"><?= htmlspecialchars($c['party_name']??'IND') ?></span></td>
                    <td style="color:var(--muted);"><?= htmlspecialchars($c['symbol']??'—') ?></td>
                    <td class="num" style="color:var(--primary);font-size:15px;"><?= number_format($c['total_votes']) ?></td>
                    <td style="color:var(--muted);"><?= $share ?>%</td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        </div>
    </div>

    <!-- COMPILE PANEL -->
    <div class="card section-gap">
        <div class="card-header"><span class="card-title">🧮 Compile Constituency Result</span></div>
        <div class="card-body">
            <?php if($is_compiled): ?>
            <div style="background:#f0fdfa;border:1.5px solid #99f6e4;border-radius:8px;padding:16px 18px;margin-bottom:16px;">
                <div style="font-weight:700;font-size:14px;color:var(--teal);margin-bottom:10px;">✅ Constituency Result Compiled</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 20px;font-size:13px;">
                    <div><span style="color:var(--muted);font-size:11px;text-transform:uppercase;">Compiled By</span><br><strong><?= htmlspecialchars($officer['full_name']) ?></strong></div>
                    <div><span style="color:var(--muted);font-size:11px;text-transform:uppercase;">Total Votes</span><br><strong><?= number_format($con_result['total_votes_cast']??0) ?></strong></div>
                    <div><span style="color:var(--muted);font-size:11px;text-transform:uppercase;">Winner</span><br><strong><?= htmlspecialchars($con_result['winner_name']??'N/A') ?></strong></div>
                    <div><span style="color:var(--muted);font-size:11px;text-transform:uppercase;">Status</span><br><span class="badge badge-aggregated"><?= $con_status ?></span></div>
                    <div><span style="color:var(--muted);font-size:11px;text-transform:uppercase;">RO Approval</span><br>
                        <?php if($is_approved): ?><span class="badge badge-approved">✔ Approved</span>
                        <?php else: ?><span class="badge badge-pending">⏳ Pending — <?= htmlspecialchars($officer['ro_name']??'RO') ?></span><?php endif; ?>
                    </div>
                    <div><span style="color:var(--muted);font-size:11px;text-transform:uppercase;">Approval Time</span><br><?= $con_result['approval_timestamp'] ? date('d M Y, h:i A',strtotime($con_result['approval_timestamp'])) : 'Not yet approved' ?></div>
                </div>
            </div>
            <?php else: ?>
            <!-- Pre-check checklist -->
            <ul class="checklist" style="margin-bottom:18px;">
                <li><span class="chk-icon <?= $all_verified?'chk-ok':'chk-fail' ?>"><?= $all_verified?'✓':'✗' ?></span>All polling stations verified by Presiding Officers</li>
                <li><span class="chk-icon <?= $no_mismatch?'chk-ok':'chk-fail' ?>"><?= $no_mismatch?'✓':'✗' ?></span>No vote count mismatches detected</li>
                <li><span class="chk-icon <?= ($total_stations>0)?'chk-ok':'chk-fail' ?>"><?= ($total_stations>0)?'✓':'✗' ?></span>Station results present for constituency</li>
                <li><span class="chk-icon <?= !$is_compiled?'chk-ok':'chk-fail' ?>"><?= !$is_compiled?'✓':'✗' ?></span>Result not previously compiled</li>
            </ul>

            <?php if(!$can_compile): ?>
            <div class="alert-item alert-warning" style="margin-bottom:14px;">
                <span class="alert-icon">⚠️</span>
                <span>Cannot compile until all <?= $total_stations ?> stations are VERIFIED. Currently <?= $verified_stations ?> verified, <?= $pending_stations + $draft_stations ?> pending.</span>
            </div>
            <?php endif; ?>

            <button class="btn btn-compile" <?= !$can_compile?'disabled':'' ?> onclick="compileResult(<?= $constituency_id ?>)">
                ✔ Compile Constituency Result
            </button>
            <?php if(!$can_compile): ?>
            <p style="font-size:12px;color:var(--muted);margin-top:8px;text-align:center;">All checklist conditions must be met before compilation.</p>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- TIMELINE (horizontal bottom) -->
    <div class="card section-gap">
        <div class="timeline-wrap">
            <div class="timeline-title">Election Workflow Progress</div>
            <div class="timeline">
            <?php
            $steps = [
                'Booth Vote Entry',
                'APO Submit to PO',
                'PO Verification',
                'ARO Compile Result',
                'Approval Pending by RO',
                'Result Published',
            ];
            foreach($steps as $i => $step):
                $sn  = $i+1;
                $cls = '';
                if($sn < $workflow_step)       $cls = 'done';
                elseif($sn === $workflow_step) $cls = 'active';
            ?>
            <div class="tl-step">
                <div class="tl-circle <?= $cls ?>"><?= $cls==='done'?'✓':$sn ?></div>
                <div class="tl-label <?= $cls ?>"><?= $step ?></div>
            </div>
            <?php endforeach; ?>
            </div>
        </div>
    </div>

</div>

<!-- RIGHT SIDEBAR -->
<div>

    <!-- ALERTS -->
    <div class="card section-gap">
        <div class="card-header">
            <span class="card-title">⚠️ Fraud &amp; Alert Monitor</span>
            <span style="font-size:12px;color:var(--danger);"><?= count(array_filter($alerts,fn($a)=>$a['type']==='danger')) ?> critical</span>
        </div>
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

    <!-- MONITORING -->
    <div class="card section-gap">
        <div class="card-header"><span class="card-title">📈 Constituency Analytics</span></div>
        <div class="card-body">
            <div class="two-equal" style="gap:10px;">
                <div class="mon-card"><div class="mon-val sv-teal"><?= $turnout_pct ?>%</div><div class="mon-label">Turnout</div></div>
                <div class="mon-card"><div class="mon-val sv-green"><?= $readiness_pct ?>%</div><div class="mon-label">Verified</div></div>
                <div class="mon-card"><div class="mon-val sv-blue"><?= number_format($total_votes_agg) ?></div><div class="mon-label">Total Votes</div></div>
                <div class="mon-card"><div class="mon-val sv-amber"><?= $pending_stations ?></div><div class="mon-label">Pending</div></div>
            </div>
            <?php if($leading): ?>
            <div style="margin-top:14px;background:#f0fdfa;border:1px solid #99f6e4;border-radius:8px;padding:12px;">
                <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Leading Candidate</div>
                <div style="font-weight:700;font-size:14px;"><?= htmlspecialchars($leading['full_name']) ?></div>
                <div style="font-size:12px;color:var(--muted);"><?= htmlspecialchars($leading['party_name']??'') ?> · <?= number_format($leading['total_votes']) ?> votes</div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- SYSTEM RULES -->
    <div class="card section-gap">
        <div class="card-header"><span class="card-title">📌 System Rules</span></div>
        <div class="card-body" style="font-size:12.5px;">
            <ul style="list-style:none;display:flex;flex-direction:column;gap:9px;">
                <li style="display:flex;gap:8px;"><span style="color:var(--teal);">●</span>Only VERIFIED stations may be aggregated into constituency result.</li>
                <li style="display:flex;gap:8px;"><span style="color:var(--teal);">●</span>Compilation requires all stations to be verified by PO.</li>
                <li style="display:flex;gap:8px;"><span style="color:var(--teal);">●</span>Compiled results await RO approval — ARO cannot approve own result.</li>
                <li style="display:flex;gap:8px;"><span style="color:var(--teal);">●</span>Approved results cannot be modified.</li>
                <li style="display:flex;gap:8px;"><span style="color:var(--teal);">●</span>Winner determined by highest locked vote count.</li>
            </ul>
        </div>
    </div>

    <!-- ACTIVITY TIMELINE -->
    <div class="card section-gap">
        <div class="card-header"><span class="card-title">🕒 Recent Activity</span></div>
        <div class="card-body" style="padding:14px 18px;">
            <?php if(empty($audit_logs)): ?>
            <p style="color:var(--muted);font-size:13px;text-align:center;padding:16px 0;">No activity yet.</p>
            <?php else: ?>
            <?php foreach($audit_logs as $log):
                $ic = ['VOTE_ENTRY'=>['🗳️','blue'],'LOGIN'=>['🔐','blue'],'SUBMIT_VOTES'=>['📤','green'],'VERIFY_STATION'=>['✔','teal'],'REJECT_BOOTH'=>['✖','amber'],'COMPILE_RESULT'=>['🧮','teal']];
                $i  = $ic[$log['action_type']] ?? ['📋','blue'];
            ?>
            <div class="activity-item">
                <div class="act-dot <?= $i[1] ?>"><?= $i[0] ?></div>
                <div>
                    <div class="act-text"><?= htmlspecialchars($log['action_type']) ?></div>
                    <div class="act-sub"><?= htmlspecialchars(substr($log['details']??'',0,60)) ?> · <?= date('d M, h:i A',strtotime($log['timestamp'])) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

</div>
</div><!-- /two-col -->

</div><!-- /page-wrap -->

<!-- FOOTER -->
<footer class="footer">
    <div>🗳️ Bangladesh Election Commission &nbsp;·&nbsp; EMS © 2026</div>
    <div class="footer-links">
        <a href="#">Helpdesk</a>
        <a href="#">Privacy Policy</a>
        <a href="#">Terms of Use</a>
    </div>
</footer>

<!-- STATION DETAIL MODAL -->
<div class="modal-overlay" id="stationModal">
    <div class="modal-box">
        <div class="modal-header">
            <span class="modal-title" id="modalTitle">Station Details</span>
            <button class="modal-close" onclick="closeModal()">✕</button>
        </div>
        <div class="modal-body" id="modalBody">
            <p style="text-align:center;color:var(--muted);padding:30px 0;">Loading...</p>
        </div>
    </div>
</div>

<!-- TOAST -->
<div id="toast"></div>

<script>
// ===== VIEW STATION DETAILS =====
function viewStation(stationId) {
    document.getElementById('stationModal').classList.add('open');
    document.getElementById('modalBody').innerHTML = '<p style="text-align:center;color:var(--muted);padding:40px 0;">⏳ Loading station details...</p>';

    const fd = new FormData();
    fd.append('action','get_station_details');
    fd.append('station_id', stationId);

    fetch(window.location.href, {method:'POST', body:fd})
        .then(r => r.json())
        .then(res => {
            if(!res.success) { showToast(res.message,'error'); closeModal(); return; }
            const s  = res.station;
            const bs = res.booths;
            const tv = bs.reduce((a,b) => a + parseInt(b.total_votes||0), 0);
            const bi = parseInt(s.total_ballots_issued||0);
            const diff = tv - bi;
            const diffClass = diff > 0 ? 'diff-over' : 'diff-ok';

            const srStatus = s.sr_status || 'PENDING';
            const badgeMap = {VERIFIED:'badge-verified',DRAFT:'badge-draft',PENDING:'badge-pending'};
            const labelMap = {VERIFIED:'✔ VERIFIED',DRAFT:'⚠ DRAFT',PENDING:'⏳ PENDING'};

            const boothRows = bs.map(b => {
                const bDiff = parseInt(b.total_votes||0) - parseInt(b.ballots_issued||0);
                return `<tr>
                    <td>Booth ${b.booth_number}</td>
                    <td>${b.apo_name||'N/A'}</td>
                    <td>${parseInt(b.ballots_issued||0).toLocaleString()}</td>
                    <td style="font-weight:700;color:var(--primary);">${parseInt(b.total_votes||0).toLocaleString()}</td>
                    <td class="${bDiff>0?'diff-over':'diff-ok'}">${(bDiff>=0?'+':'')+bDiff}</td>
                    <td>${b.is_locked ? '<span class="badge badge-verified">🔒 Locked</span>' : '<span class="badge badge-pending">⏳ Open</span>'}</td>
                </tr>`;
            }).join('');

            document.getElementById('modalTitle').textContent = s.name + ' — Station Details';
            document.getElementById('modalBody').innerHTML = `
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:18px;">
                    <div class="stat-box blue"><div class="stat-label">Ballots Issued</div><div class="stat-value sv-blue" style="font-size:20px;">${bi.toLocaleString()}</div></div>
                    <div class="stat-box ${diff<=0?'teal':'red'}"><div class="stat-label">Votes Cast</div><div class="stat-value ${diff<=0?'sv-teal':'sv-red'}" style="font-size:20px;">${tv.toLocaleString()}</div></div>
                    <div class="stat-box ${diff<=0?'green':'red'}"><div class="stat-label">Difference</div><div class="stat-value ${diff<=0?'sv-green':'sv-red'} ${diffClass}" style="font-size:20px;">${(diff>=0?'+':'')+diff}</div></div>
                </div>
                <div style="display:flex;gap:16px;font-size:13px;margin-bottom:14px;flex-wrap:wrap;">
                    <span>📍 <strong>${s.address||'N/A'}</strong></span>
                    <span>👤 PO: <strong>${s.po_name||'N/A'}</strong></span>
                    <span>Status: <span class="badge ${badgeMap[srStatus]||'badge-pending'}">${labelMap[srStatus]||'⏳ PENDING'}</span></span>
                </div>
                <div style="font-size:13px;font-weight:700;margin-bottom:8px;">Booth Breakdown</div>
                <table class="booth-table">
                    <thead><tr><th>Booth</th><th>APO</th><th>Issued</th><th>Votes</th><th>Diff</th><th>Lock</th></tr></thead>
                    <tbody>${boothRows || '<tr><td colspan="6" style="text-align:center;color:var(--muted);padding:16px;">No booth data found.</td></tr>'}</tbody>
                </table>
                <div style="background:${diff<=0?'#f0fdf4':'#fef2f2'};border:1.5px solid ${diff<=0?'#86efac':'#fca5a5'};border-radius:8px;padding:14px;margin-top:14px;font-size:13px;">
                    <div style="font-weight:700;margin-bottom:8px;color:${diff<=0?'#166534':'#991b1b'};">${diff<=0?'✅ Validation Summary':'⚠️ Validation Issues'}</div>
                    <div style="display:flex;flex-direction:column;gap:6px;">
                        <div>${diff<=0?'✅':'❌'} Total votes ${diff<=0?'within':'exceed'} ballots issued</div>
                        <div>${srStatus==='VERIFIED'?'✅ Station verified by PO':'❌ Station not yet verified'}</div>
                        <div>${bs.length>0?'✅':'❌'} Booth results ${bs.length>0?'present':'not entered'}</div>
                    </div>
                </div>
            `;
        })
        .catch(() => { showToast('Network error','error'); closeModal(); });
}

function closeModal() {
    document.getElementById('stationModal').classList.remove('open');
}
document.getElementById('stationModal').addEventListener('click', function(e){
    if(e.target===this) closeModal();
});

// ===== COMPILE RESULT =====
function compileResult(constituencyId) {
    if(!confirm('⚠️ COMPILE CONSTITUENCY RESULT\n\nThis will:\n• Aggregate all verified station votes\n• Determine the leading candidate\n• Forward result to Returning Officer for approval\n• Result becomes read-only for ARO\n\nProceed?')) return;

    const btn = document.querySelector('.btn-compile');
    if(btn) { btn.disabled=true; btn.textContent='⏳ Compiling...'; }

    const fd = new FormData();
    fd.append('action','compile_result');
    fd.append('constituency_id', constituencyId);

    fetch(window.location.href, {method:'POST', body:fd})
        .then(r => r.json())
        .then(res => {
            showToast(res.message, res.success?'success':'error');
            if(res.success) setTimeout(() => location.reload(), 2000);
            else if(btn) { btn.disabled=false; btn.textContent='✔ Compile Constituency Result'; }
        })
        .catch(() => { showToast('Network error','error'); if(btn){btn.disabled=false;btn.textContent='✔ Compile Constituency Result';} });
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
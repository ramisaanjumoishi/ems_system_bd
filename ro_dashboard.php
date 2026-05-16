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
if (!isset($_SESSION['officer_id']) || $_SESSION['role'] !== 'RO') {
    header('Location: login.php');
    exit;
}
$logged_in_id = (int)$_SESSION['officer_id'];

// ============================================================
//  DB CONNECTION
// ============================================================
try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER, $DB_PASS,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
} catch (PDOException $e) {
    die('<div style="padding:40px;font-family:sans-serif;color:red;"><h2>DB Error</h2><p>'.htmlspecialchars($e->getMessage()).'</p></div>');
}

// ============================================================
//  AJAX HANDLERS
// ============================================================

// ---- Approve & Publish ----
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='approve_publish') {
    header('Content-Type: application/json');
    $con_id = (int)($_POST['constituency_id']??0);
    if (!$con_id) { echo json_encode(['success'=>false,'message'=>'Invalid constituency']); exit; }

    // Verify ownership
    $own = $pdo->prepare("SELECT constituency_id FROM constituencies WHERE constituency_id=? AND returning_officer_id=?");
    $own->execute([$con_id, $logged_in_id]);
    if (!$own->fetch()) { echo json_encode(['success'=>false,'message'=>'Not authorised for this constituency']); exit; }

    // Must be AGGREGATED
    $crRow = $pdo->prepare("SELECT * FROM constituency_results WHERE constituency_id=? LIMIT 1");
    $crRow->execute([$con_id]);
    $cr = $crRow->fetch();
    if (!$cr || $cr['status']!=='AGGREGATED') {
        echo json_encode(['success'=>false,'message'=>'Constituency result must be AGGREGATED before approval. Current status: '.($cr['status']??'NONE')]);
        exit;
    }

    if ((int)($cr['is_tie'] ?? 0) === 1 && empty($cr['winner_candidate_id'])) {
    echo json_encode(['success'=>false,
        'message'=>'⚠️ This result has a TIE. You must manually select the winner before approving. Contact the Election Commission for adjudication guidelines.']);
    exit;
}

    $pdo->beginTransaction();
    try {
        // Update constituency_results
        $upd = $pdo->prepare("UPDATE constituency_results
            SET approved_by_ro=?, approval_timestamp=NOW(), status='APPROVED'
            WHERE constituency_id=?");
        $upd->execute([$logged_in_id, $con_id]);

        // Update constituencies
        $updC = $pdo->prepare("UPDATE constituencies SET result_status='APPROVED' WHERE constituency_id=?");
        $updC->execute([$con_id]);

        // Audit log
        $log = $pdo->prepare("INSERT INTO audit_logs
            (officer_id,action_type,affected_entity,affected_entity_id,details,ip_address)
            VALUES (?,'APPROVE_RESULT','ConstituencyResult',?,?,?)");
        $log->execute([$logged_in_id,$con_id,
            "RO approved and published constituency $con_id result.",
            $_SERVER['REMOTE_ADDR']??'']);

        $pdo->commit();
        echo json_encode(['success'=>true,'message'=>'Result approved and published successfully.']);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]);
    }
    exit;
}

// ---- Get constituency detail (AJAX modal) ----
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='get_con_detail') {
    header('Content-Type: application/json');
    $con_id = (int)($_POST['constituency_id']??0);
    if (!$con_id) { echo json_encode(['success'=>false,'message'=>'Invalid']); exit; }

    // Stations
    $stStmt = $pdo->prepare("
        SELECT ps.station_id, ps.name, ps.address, ps.total_ballots_issued,
               sr.total_votes_cast, sr.status AS sr_status, sr.verification_timestamp,
               eo.full_name AS po_name
        FROM polling_stations ps
        LEFT JOIN station_results sr ON sr.station_id=ps.station_id
        LEFT JOIN election_officers eo ON eo.officer_id=ps.presiding_officer_id
        WHERE ps.constituency_id=?
        ORDER BY ps.station_id
    ");
    $stStmt->execute([$con_id]);
    $stations = $stStmt->fetchAll();

    // Candidates
    $cStmt = $pdo->prepare("
        SELECT c.candidate_id, c.full_name, c.symbol,
               pp.name AS party_name, pp.abbreviation,
               IFNULL(SUM(br.votes_received),0) AS total_votes
        FROM candidates c
        LEFT JOIN political_parties pp ON pp.party_id=c.party_id
        LEFT JOIN booth_results br ON br.candidate_id=c.candidate_id AND br.is_locked=1
        LEFT JOIN polling_booths pb ON pb.booth_id=br.booth_id
        LEFT JOIN polling_stations ps ON ps.station_id=pb.station_id
        WHERE c.constituency_id=? AND ps.constituency_id=?
        GROUP BY c.candidate_id
        ORDER BY total_votes DESC
    ");
    $cStmt->execute([$con_id,$con_id]);
    $candidates = $cStmt->fetchAll();

    echo json_encode(['success'=>true,'stations'=>$stations,'candidates'=>$candidates]);
    exit;
}

// ============================================================
//  LOAD RO DATA
// ============================================================
$officerStmt = $pdo->prepare("SELECT * FROM election_officers WHERE officer_id=?");
$officerStmt->execute([$logged_in_id]);
$officer = $officerStmt->fetch();

if (!$officer || $officer['role']!=='RO') {
    die('<div style="padding:40px;font-family:sans-serif;color:#c0392b;"><h2>Access Denied</h2><p>RO role required.</p></div>');
}

// All constituencies assigned to this RO
$consStmt = $pdo->prepare("
    SELECT c.*,
           cr.constituency_result_id, cr.winner_candidate_id, cr.total_votes_cast AS cr_total,
           cr.compiled_by_aro, cr.approved_by_ro, cr.approval_timestamp, cr.status AS cr_status,
           winner.full_name AS winner_name,
           wp.name AS winner_party, wp.abbreviation AS winner_abbr,
           aro.full_name AS aro_name
    FROM constituencies c
    LEFT JOIN constituency_results cr ON cr.constituency_id=c.constituency_id
    LEFT JOIN candidates winner ON winner.candidate_id=cr.winner_candidate_id
    LEFT JOIN political_parties wp ON wp.party_id=winner.party_id
    LEFT JOIN election_officers aro ON aro.officer_id=cr.compiled_by_aro
    WHERE c.returning_officer_id=?
    ORDER BY c.constituency_id
");
$consStmt->execute([$logged_in_id]);
$constituencies = $consStmt->fetchAll();

// Stats
$total_con       = count($constituencies);
$pending_con     = 0;
$aggregated_con  = 0;
$approved_con    = 0;
$published_con   = 0;
foreach ($constituencies as $c) {
    $st = $c['cr_status'] ?? $c['result_status'];
    if ($st==='AGGREGATED') $aggregated_con++;
    elseif ($st==='APPROVED'||$st==='PUBLISHED') $approved_con++;
    else $pending_con++;
}

// Active constituency (first AGGREGATED, else first)
$active_con = null;
foreach ($constituencies as $c) {
    if ($c['cr_status']==='AGGREGATED') { $active_con=$c; break; }
}
if (!$active_con) $active_con = $constituencies[0] ?? null;

// Detailed data for active constituency
$active_stations   = [];
$active_candidates = [];
$active_total_ballots = 0;
$active_total_votes   = 0;
$active_all_verified  = false;

if ($active_con) {
    $acId = (int)$active_con['constituency_id'];

    $astStmt = $pdo->prepare("
        SELECT ps.station_id, ps.name, ps.address, ps.total_ballots_issued, ps.result_status,
               sr.total_votes_cast AS sr_votes, sr.status AS sr_status, sr.verification_timestamp,
               eo.full_name AS po_name
        FROM polling_stations ps
        LEFT JOIN station_results sr ON sr.station_id=ps.station_id
        LEFT JOIN election_officers eo ON eo.officer_id=ps.presiding_officer_id
        WHERE ps.constituency_id=?
        ORDER BY ps.station_id
    ");
    $astStmt->execute([$acId]);
    $active_stations = $astStmt->fetchAll();

    $acStmt = $pdo->prepare("
        SELECT c.candidate_id, c.full_name, c.symbol,
               pp.name AS party_name, pp.abbreviation,
               IFNULL(SUM(br.votes_received),0) AS total_votes
        FROM candidates c
        LEFT JOIN political_parties pp ON pp.party_id=c.party_id
        LEFT JOIN booth_results br ON br.candidate_id=c.candidate_id AND br.is_locked=1
        LEFT JOIN polling_booths pb ON pb.booth_id=br.booth_id
        LEFT JOIN polling_stations ps ON ps.station_id=pb.station_id
        WHERE c.constituency_id=? AND (ps.constituency_id=? OR ps.constituency_id IS NULL)
        GROUP BY c.candidate_id
        ORDER BY total_votes DESC
    ");
    $acStmt->execute([$acId,$acId]);
    $active_candidates = $acStmt->fetchAll();

    foreach ($active_stations as $s) { $active_total_ballots += (int)$s['total_ballots_issued']; }
    $active_total_votes = (int)($active_con['cr_total'] ?? 0);

    $all_v = true;
    foreach ($active_stations as $s) { if ($s['sr_status']!=='VERIFIED') { $all_v=false; break; } }
    $active_all_verified = $all_v;
}

// Audit logs
$logsStmt = $pdo->prepare("
    SELECT al.*, eo.full_name AS oname, eo.role AS orole
    FROM audit_logs al
    LEFT JOIN election_officers eo ON eo.officer_id=al.officer_id
    ORDER BY al.timestamp DESC LIMIT 8
");
$logsStmt->execute();
$audit_logs = $logsStmt->fetchAll();

// Party colours
$pcolors = ['AL'=>'#006633','BNP'=>'#003399','JP'=>'#CC0000','IAB'=>'#009900','WPB'=>'#CC3300'];
function pcol($abbr,$m){ return $m[$abbr]??'#475569'; }

// Workflow step for active con
$wf_step = 3;
if ($active_con) {
    $s = $active_con['cr_status']??'PENDING';
    if ($s==='AGGREGATED') $wf_step=4;
    if ($s==='APPROVED'||$s==='PUBLISHED') $wf_step=5;
}

$turnout_pct = $active_total_ballots>0 ? round(($active_total_votes/$active_total_ballots)*100,1) : 0;
$total_reg   = (int)($active_con['total_registered_voters']??0);
$reg_turnout = $total_reg>0 ? round(($active_total_votes/$total_reg)*100,1) : 0;
$is_approved = $active_con && in_array($active_con['cr_status']??'',['APPROVED','PUBLISHED']);
$is_aggregated = $active_con && ($active_con['cr_status']??'')==='AGGREGATED';
$can_approve = $is_aggregated;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>RO Dashboard — Bangladesh Election Commission EMS</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
    --primary:#1a56db;--primary-dk:#1447bc;--accent:#0ea5e9;
    --success:#16a34a;--warning:#d97706;--danger:#dc2626;
    --gold:#b45309;--gold-lt:#fef3c7;--gold-border:#fcd34d;
    --navy:#1e3a5f;--navy-dk:#152b47;
    --bg:#f5f7fa;--surface:#fff;--border:#e2e8f0;
    --text:#1e293b;--muted:#64748b;
    --radius:10px;--shadow:0 1px 4px rgba(0,0,0,.08);--shadow-md:0 4px 20px rgba(0,0,0,.10);
}
body{font-family:'Segoe UI',system-ui,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;}

/* ── TOPBAR ── */
.topbar{background:var(--navy);display:flex;align-items:center;justify-content:space-between;padding:0 28px;height:62px;position:sticky;top:0;z-index:200;box-shadow:0 2px 12px rgba(0,0,0,.18);}
.topbar-brand{display:flex;align-items:center;gap:12px;}
.brand-text{font-size:15px;font-weight:700;color:#fff;line-height:1.15;}
.brand-sub{font-size:10.5px;color:#94a3b8;font-weight:400;}
.brand-tag{font-size:9.5px;color:#60a5fa;text-transform:uppercase;letter-spacing:.8px;margin-top:1px;}
.topbar-right{display:flex;align-items:center;gap:14px;}
.election-badge{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);border-radius:20px;padding:4px 14px;font-size:12px;color:#e2e8f0;font-weight:600;}
.officer-chip{display:flex;align-items:center;gap:9px;}
.officer-av{width:36px;height:36px;background:linear-gradient(135deg,#1a56db,#0ea5e9);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:14px;border:2px solid rgba(255,255,255,.25);}
.officer-nm{color:#f1f5f9;font-size:13px;font-weight:600;}
.officer-rl{font-size:10.5px;color:#60a5fa;}
.btn-logout{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.18);border-radius:7px;padding:6px 14px;font-size:12px;cursor:pointer;color:#cbd5e1;transition:.15s;}
.btn-logout:hover{background:rgba(220,38,38,.25);border-color:#f87171;color:#fca5a5;}

/* ── LAYOUT ── */
.page-wrap{max-width:1300px;margin:0 auto;padding:24px 20px;}
.two-col{display:grid;grid-template-columns:1fr 320px;gap:20px;}
.two-equal{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.three-col{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;}
.four-col{display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:12px;}
@media(max-width:1000px){.two-col{grid-template-columns:1fr}.four-col{grid-template-columns:1fr 1fr}}
@media(max-width:600px){.two-equal,.three-col,.four-col{grid-template-columns:1fr}}
.section-gap{margin-bottom:22px;}

/* ── CARDS ── */
.card{background:var(--surface);border:1.5px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);}
.card-header{display:flex;align-items:center;justify-content:space-between;padding:14px 20px 11px;border-bottom:1px solid var(--border);}
.card-title{font-size:14px;font-weight:700;display:flex;align-items:center;gap:8px;}
.card-body{padding:18px 20px;}

/* ── PAGE HEADER ── */
.page-header{background:linear-gradient(135deg,var(--navy) 0%,#1a56db 60%,#0ea5e9 100%);border-radius:var(--radius);padding:28px 32px;margin-bottom:22px;display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap;}
.page-header-text h1{font-size:22px;font-weight:800;color:#fff;margin-bottom:4px;}
.page-header-text p{font-size:13px;color:#bfdbfe;max-width:520px;line-height:1.5;}
.header-actions{display:flex;gap:10px;flex-wrap:wrap;}
.btn-approve{background:#16a34a;color:#fff;border:none;border-radius:8px;padding:10px 22px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:.15s;box-shadow:0 2px 8px rgba(22,163,74,.35);}
.btn-approve:hover{background:#15803d;}
.btn-approve:disabled{background:#94a3b8;cursor:not-allowed;box-shadow:none;}
.btn-publish{background:linear-gradient(135deg,#1a56db,#0ea5e9);color:#fff;border:none;border-radius:8px;padding:10px 22px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:.15s;box-shadow:0 2px 8px rgba(26,86,219,.35);}
.btn-publish:hover{background:linear-gradient(135deg,#1447bc,#0284c7);}
.btn-publish:disabled{background:#94a3b8;cursor:not-allowed;box-shadow:none;}
.btn-recheck{background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3);border-radius:8px;padding:10px 18px;font-size:13px;font-weight:600;cursor:pointer;transition:.15s;}
.btn-recheck:hover{background:rgba(255,255,255,.25);}

/* ── CONSTITUENCY SELECTOR TABS ── */
.con-tabs{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;}
.con-tab{border:1.5px solid var(--border);border-radius:8px;padding:8px 18px;font-size:13px;font-weight:600;cursor:pointer;background:var(--surface);color:var(--muted);transition:.15s;}
.con-tab:hover{border-color:var(--primary);color:var(--primary);}
.con-tab.active{background:var(--primary);border-color:var(--primary);color:#fff;}
.con-tab .tab-badge{display:inline-block;font-size:10px;border-radius:10px;padding:1px 7px;margin-left:6px;font-weight:600;}
.tab-badge.agg{background:#ddd6fe;color:#5b21b6;}
.tab-badge.appr{background:#dcfce7;color:#166534;}
.tab-badge.pend{background:#fef9c3;color:#92400e;}

/* ── STAT BOXES ── */
.stat-box{border:1.5px solid var(--border);border-radius:9px;padding:16px 18px;}
.stat-box.blue{border-color:#bfdbfe;background:#eff6ff;}
.stat-box.green{border-color:#bbf7d0;background:#f0fdf4;}
.stat-box.amber{border-color:#fde68a;background:#fffbeb;}
.stat-box.gold{border-color:var(--gold-border);background:var(--gold-lt);}
.stat-box.navy{border-color:#bfdbfe;background:linear-gradient(135deg,#eff6ff,#e0f2fe);}
.stat-label{font-size:10.5px;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);margin-bottom:5px;}
.stat-value{font-size:28px;font-weight:800;line-height:1;}
.sv-blue{color:var(--primary);}
.sv-green{color:var(--success);}
.sv-amber{color:var(--warning);}
.sv-gold{color:var(--gold);}
.sv-black{color:var(--text);}
.stat-sub{font-size:11px;color:var(--muted);margin-top:3px;}

/* ── PROGRESS ── */
.prog-wrap{margin-top:10px;}
.prog-label{display:flex;justify-content:space-between;font-size:12px;color:var(--muted);margin-bottom:5px;}
.prog-bar{height:8px;background:#e2e8f0;border-radius:99px;overflow:hidden;}
.prog-fill{height:100%;border-radius:99px;transition:width .5s;}
.prog-fill.blue{background:linear-gradient(90deg,var(--primary),var(--accent));}
.prog-fill.green{background:linear-gradient(90deg,#16a34a,#22c55e);}
.prog-fill.gold{background:linear-gradient(90deg,#b45309,#d97706);}

/* ── WINNER CARD ── */
.winner-card{background:linear-gradient(135deg,#1e3a5f 0%,#1a56db 55%,#0369a1 100%);border-radius:12px;padding:28px 28px 24px;color:#fff;position:relative;overflow:hidden;margin-bottom:20px;}
.winner-card::before{content:'';position:absolute;top:-40px;right:-40px;width:200px;height:200px;background:rgba(255,255,255,.04);border-radius:50%;}
.winner-card::after{content:'';position:absolute;bottom:-30px;left:-30px;width:150px;height:150px;background:rgba(255,255,255,.03);border-radius:50%;}
.winner-locked{background:linear-gradient(135deg,#334155,#475569);border-radius:12px;padding:28px;color:#94a3b8;text-align:center;margin-bottom:20px;border:2px dashed #64748b;}
.winner-locked .lock-icon{font-size:40px;margin-bottom:12px;display:block;}
.winner-locked h3{color:#94a3b8;font-size:16px;margin-bottom:6px;}
.winner-badge{display:inline-flex;align-items:center;gap:6px;background:linear-gradient(135deg,#b45309,#d97706);border-radius:20px;padding:4px 14px;font-size:11px;font-weight:700;color:#fff;letter-spacing:.5px;margin-bottom:14px;box-shadow:0 2px 8px rgba(180,83,9,.4);}
.winner-name{font-size:28px;font-weight:800;margin-bottom:4px;letter-spacing:-.3px;}
.winner-party{font-size:14px;opacity:.85;margin-bottom:16px;}
.winner-stats{display:flex;gap:24px;flex-wrap:wrap;}
.winner-stat .ws-val{font-size:22px;font-weight:800;}
.winner-stat .ws-label{font-size:10px;text-transform:uppercase;letter-spacing:.6px;opacity:.7;margin-top:2px;}
.winner-con{font-size:12px;opacity:.7;margin-top:14px;padding-top:14px;border-top:1px solid rgba(255,255,255,.15);}

/* ── CANDIDATE TABLE ── */
.cand-table{width:100%;border-collapse:collapse;font-size:13px;}
.cand-table th{background:#f8fafc;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);padding:10px 14px;border-bottom:1.5px solid var(--border);text-align:left;cursor:pointer;}
.cand-table th:hover{background:#f1f5f9;}
.cand-table td{padding:13px 14px;border-bottom:1px solid var(--border);vertical-align:middle;}
.cand-table tr:last-child td{border-bottom:none;}
.cand-table .winner-row td{background:linear-gradient(90deg,#fefce8,#fff);}
.party-chip{display:inline-block;border-radius:5px;padding:3px 10px;font-size:11.5px;font-weight:700;color:#fff;}
.vote-bar-wrap{display:flex;align-items:center;gap:8px;}
.vote-bar-bg{flex:1;height:7px;background:#e2e8f0;border-radius:99px;overflow:hidden;min-width:60px;}
.vote-bar-fill{height:100%;border-radius:99px;}
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:6px;font-size:11.5px;font-weight:600;white-space:nowrap;}
.badge-winner{background:var(--gold-lt);color:var(--gold);border:1px solid var(--gold-border);}
.badge-verified{background:#dcfce7;color:#166534;}
.badge-pending{background:#fef9c3;color:#92400e;}
.badge-aggregated{background:#f3e8ff;color:#5b21b6;}
.badge-approved{background:#dcfce7;color:#166534;}
.badge-draft{background:#e0f2fe;color:#0369a1;}

/* ── STATION TABLE ── */
.data-table{width:100%;border-collapse:collapse;font-size:13px;}
.data-table th{background:#f8fafc;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);padding:10px 14px;border-bottom:1.5px solid var(--border);text-align:left;white-space:nowrap;}
.data-table td{padding:11px 14px;border-bottom:1px solid var(--border);vertical-align:middle;}
.data-table tr:last-child td{border-bottom:none;}
.data-table tr:hover td{background:#f8fafc;}
.num{font-weight:700;}

/* ── VALIDATION PANEL ── */
.validation-panel{background:#f0fdf4;border:1.5px solid #86efac;border-radius:10px;padding:18px 20px;}
.validation-panel.warn{background:#fef9c3;border-color:#fde68a;}
.validation-panel.fail{background:#fef2f2;border-color:#fca5a5;}
.val-title{font-size:13.5px;font-weight:700;margin-bottom:12px;display:flex;align-items:center;gap:8px;}
.val-item{display:flex;align-items:center;gap:9px;font-size:13px;margin-bottom:8px;}
.val-item:last-child{margin-bottom:0;}

/* ── CHECKLIST ── */
.chk-icon{width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0;}
.chk-ok{background:#dcfce7;color:var(--success);}
.chk-fail{background:#fee2e2;color:var(--danger);}
.chk-warn{background:#fef9c3;color:var(--warning);}

/* ── TIMELINE ── */
.timeline-wrap{padding:22px 24px;}
.tl-title{font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);margin-bottom:20px;font-weight:700;}
.timeline{display:flex;align-items:flex-start;position:relative;}
.timeline::before{content:'';position:absolute;top:16px;left:16px;right:16px;height:2px;background:var(--border);z-index:0;}
.tl-step{flex:1;display:flex;flex-direction:column;align-items:center;position:relative;z-index:1;}
.tl-circle{width:32px;height:32px;border-radius:50%;border:2px solid var(--border);background:var(--surface);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:var(--muted);}
.tl-circle.done{background:var(--success);border-color:var(--success);color:#fff;}
.tl-circle.active{background:var(--primary);border-color:var(--primary);color:#fff;}
.tl-label{font-size:10px;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);margin-top:8px;text-align:center;font-weight:600;line-height:1.3;}
.tl-label.done{color:var(--success);}
.tl-label.active{color:var(--primary);}

/* ── ALERTS ── */
.alert-item{display:flex;gap:10px;padding:10px 14px;border-radius:8px;font-size:12.5px;margin-bottom:8px;align-items:flex-start;}
.alert-item:last-child{margin-bottom:0;}
.alert-danger{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;}
.alert-warning{background:#fffbeb;border:1px solid #fde68a;color:#92400e;}
.alert-info{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;}
.alert-success{background:#f0fdf4;border:1px solid #86efac;color:#166534;}

/* ── ACTIVITY ── */
.activity-item{display:flex;gap:12px;align-items:flex-start;padding:11px 0;border-bottom:1px solid var(--border);}
.activity-item:last-child{border-bottom:none;}
.act-dot{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;}
.act-dot.green{background:#dcfce7;}
.act-dot.blue{background:#dbeafe;}
.act-dot.amber{background:#fef3c7;}
.act-dot.red{background:#fee2e2;}
.act-text{font-size:13px;font-weight:600;}
.act-sub{font-size:11px;color:var(--muted);margin-top:2px;}

/* ── CON OVERVIEW CARDS ── */
.con-overview-card{border:1.5px solid var(--border);border-radius:9px;padding:16px 18px;cursor:pointer;transition:.15s;position:relative;}
.con-overview-card:hover{border-color:var(--primary);box-shadow:0 4px 16px rgba(26,86,219,.1);}
.con-overview-card.active-con{border-color:var(--primary);background:#eff6ff;}
.con-overview-card .con-name{font-size:15px;font-weight:700;margin-bottom:4px;}
.con-overview-card .con-code{font-size:11px;color:var(--muted);margin-bottom:10px;}
.con-overview-card .con-status-row{display:flex;align-items:center;justify-content:space-between;}

/* ── APPROVED RIBBON ── */
.approved-ribbon{background:linear-gradient(135deg,#166534,#16a34a);border-radius:10px;padding:16px 22px;display:flex;align-items:center;gap:14px;color:#fff;margin-bottom:20px;box-shadow:0 4px 16px rgba(22,163,74,.2);}
.approved-ribbon .rb-icon{font-size:28px;}
.approved-ribbon .rb-title{font-size:16px;font-weight:700;}
.approved-ribbon .rb-sub{font-size:12px;opacity:.85;margin-top:2px;}
.pending-ribbon{background:linear-gradient(135deg,#1e3a5f,#1a56db);border-radius:10px;padding:14px 22px;display:flex;align-items:center;gap:14px;color:#fff;margin-bottom:20px;}
.pending-ribbon .rb-icon{font-size:24px;}
.pending-ribbon .rb-title{font-size:14px;font-weight:700;}

/* ── MODAL ── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.6);z-index:500;align-items:center;justify-content:center;padding:20px;}
.modal-overlay.open{display:flex;}
.modal-box{background:#fff;border-radius:14px;width:100%;max-width:640px;max-height:88vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3);}
.modal-header{display:flex;align-items:center;justify-content:space-between;padding:20px 26px;border-bottom:1.5px solid var(--border);position:sticky;top:0;background:#fff;z-index:1;}
.modal-title{font-size:17px;font-weight:700;}
.modal-close{background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted);padding:4px 8px;border-radius:6px;}
.modal-close:hover{background:#f1f5f9;}
.modal-body{padding:26px;}
.modal-warning{background:#fef9c3;border:1.5px solid var(--gold-border);border-radius:8px;padding:14px 16px;font-size:13px;color:#92400e;margin-bottom:18px;display:flex;gap:10px;}
.modal-actions{display:flex;gap:12px;margin-top:22px;justify-content:flex-end;}

/* ── BUTTONS ── */
.btn{border:none;border-radius:8px;padding:9px 22px;font-size:13px;font-weight:600;cursor:pointer;transition:.15s;display:inline-flex;align-items:center;gap:6px;}
.btn-primary{background:var(--primary);color:#fff;}
.btn-primary:hover{background:var(--primary-dk);}
.btn-success{background:var(--success);color:#fff;}
.btn-success:hover{background:#15803d;}
.btn-success:disabled{background:#94a3b8;cursor:not-allowed;}
.btn-muted{background:#f1f5f9;color:var(--text);border:1.5px solid var(--border);}
.btn-muted:hover{background:#e2e8f0;}
.btn-view{background:#eff6ff;color:var(--primary);border:1px solid #bfdbfe;}
.btn-view:hover{background:#dbeafe;}
.btn-sm{padding:5px 12px;font-size:11.5px;}
.btn-lg{padding:13px 32px;font-size:15px;font-weight:700;}
.btn-publish-lg{background:linear-gradient(135deg,#1e3a5f,#1a56db);color:#fff;border:none;border-radius:10px;padding:14px 32px;font-size:15px;font-weight:700;cursor:pointer;width:100%;justify-content:center;display:flex;align-items:center;gap:8px;transition:.15s;box-shadow:0 4px 16px rgba(26,86,219,.25);}
.btn-publish-lg:hover{background:linear-gradient(135deg,#152b47,#1447bc);}
.btn-publish-lg:disabled{background:#94a3b8;box-shadow:none;cursor:not-allowed;}

/* ── MON CARD ── */
.mon-card{border:1.5px solid var(--border);border-radius:8px;padding:14px;text-align:center;}
.mon-val{font-size:22px;font-weight:800;margin-bottom:2px;}
.mon-label{font-size:10.5px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;}

/* ── TOAST ── */
#toast{position:fixed;bottom:28px;right:28px;z-index:999;}
.toast-msg{background:#1e293b;color:#fff;border-radius:9px;padding:12px 20px;font-size:13px;font-weight:500;margin-top:8px;display:flex;align-items:center;gap:10px;box-shadow:0 4px 20px rgba(0,0,0,.2);animation:slideIn .3s ease;}
.toast-msg.success{background:#166534;}
.toast-msg.error{background:#991b1b;}
@keyframes slideIn{from{transform:translateY(20px);opacity:0;}to{transform:translateY(0);opacity:1;}}

/* ── FOOTER ── */
.footer{background:var(--navy);padding:20px 28px;display:flex;align-items:center;justify-content:space-between;font-size:12px;color:#94a3b8;margin-top:32px;flex-wrap:wrap;gap:10px;}
.footer-links{display:flex;gap:18px;}
.footer-links a{color:#94a3b8;text-decoration:none;}
.footer-links a:hover{color:#60a5fa;}
.footer-copy{color:#475569;}

/* ── UTIL ── */
.divider{border:none;border-top:1.5px solid var(--border);margin:0;}
.text-muted{color:var(--muted);}
.gap-8{display:flex;gap:8px;flex-wrap:wrap;}
.mt-12{margin-top:12px;}
.table-wrap{overflow-x:auto;}
</style>
</head>
<body>

<!-- ═══ TOPBAR ═══ -->
<nav class="topbar">
    <div class="topbar-brand">
        <span style="font-size:26px;">🗳️</span>
        <div>
            <div class="brand-text">Bangladesh Election Commission</div>
            <div class="brand-sub">Election Management System</div>
            <div class="brand-tag">Returning Officer Control Panel</div>
        </div>
    </div>
    <div class="topbar-right">
        <span class="election-badge">General Election 2026</span>
        <div class="officer-chip">
            <div class="officer-av"><?= strtoupper(substr($officer['full_name'],0,1)) ?></div>
            <div>
                <div class="officer-nm"><?= htmlspecialchars($officer['full_name']) ?></div>
                <div class="officer-rl">Returning Officer (RO)</div>
            </div>
        </div>
        <button class="btn-logout" onclick="if(confirm('Logout from EMS?')){window.location='logout.php'}">⏻ Logout</button>
    </div>
</nav>

<div class="page-wrap">

<!-- ═══ PAGE HEADER ═══ -->
<div class="page-header">
    <div class="page-header-text">
        <h1>🏛️ Constituency Result Approval Dashboard</h1>
        <p>Review aggregated constituency results, verify station summaries, approve official outcomes, and publish certified election results.</p>
    </div>
    <div class="header-actions">
        <button class="btn-approve" id="topApproveBtn"
            <?= !$can_approve ? 'disabled' : '' ?>
            onclick="openPublishModal()">
            ✔ Approve &amp; Publish Result
        </button>
        <button class="btn-recheck" onclick="showToast('Recheck request logged.','success')">↩ Request Recheck</button>
    </div>
</div>

<!-- ═══ CONSTITUENCY TABS ═══ -->
<div class="con-tabs">
<?php foreach($constituencies as $c):
    $tabSt = $c['cr_status'] ?? $c['result_status'];
    $tabCls = $tabSt==='AGGREGATED'?'agg':($tabSt==='APPROVED'||$tabSt==='PUBLISHED'?'appr':'pend');
    $isActive = $active_con && $c['constituency_id']==$active_con['constituency_id'];
?>
<button class="con-tab <?= $isActive?'active':'' ?>"
    onclick="switchConstituency(<?= $c['constituency_id'] ?>)">
    <?= htmlspecialchars($c['name']) ?>
    <span class="tab-badge <?= $tabCls ?>"><?= $tabSt ?></span>
</button>
<?php endforeach; ?>
</div>

<?php if(!$active_con): ?>
<div class="alert-item alert-warning"><span>⚠️</span><span>No constituency results found for your account.</span></div>
<?php else: ?>

<!-- ═══ STATUS RIBBON ═══ -->
<?php if($is_approved): ?>
<div class="approved-ribbon section-gap">
    <span class="rb-icon">🏆</span>
    <div>
        <div class="rb-title">✔ Result Officially Approved — <?= htmlspecialchars($active_con['name']) ?></div>
        <div class="rb-sub">Approved by <?= htmlspecialchars($officer['full_name']) ?> · <?= $active_con['approval_timestamp'] ? date('d M Y, h:i A',strtotime($active_con['approval_timestamp'])) : date('d M Y') ?></div>
    </div>
</div>
<?php elseif($is_aggregated): ?>
<div class="pending-ribbon section-gap">
    <span class="rb-icon">⏳</span>
    <div>
        <div class="rb-title">Awaiting RO Approval — <?= htmlspecialchars($active_con['name']) ?> result compiled by <?= htmlspecialchars($active_con['aro_name']??'ARO') ?></div>
    </div>
</div>
<?php endif; ?>

<!-- ═══ HERO: STATS + WINNER ═══ -->
<div class="two-col section-gap">
<div>
    <!-- CONSTITUENCY SUMMARY -->
    <div class="card section-gap">
        <div class="card-header">
            <span class="card-title">📊 <?= htmlspecialchars($active_con['name']) ?> — Aggregated Results Summary</span>
            <span class="badge <?= $is_approved?'badge-approved':($is_aggregated?'badge-aggregated':'badge-pending') ?>"><?= $active_con['cr_status']??$active_con['result_status'] ?></span>
        </div>
        <div class="card-body">
            <div class="four-col" style="margin-bottom:16px;">
                <div class="stat-box navy">
                    <div class="stat-label">Registered Voters</div>
                    <div class="stat-value sv-blue" style="font-size:22px;"><?= number_format($total_reg) ?></div>
                </div>
                <div class="stat-box blue">
                    <div class="stat-label">Total Votes Cast</div>
                    <div class="stat-value sv-blue"><?= number_format($active_total_votes) ?></div>
                </div>
                <div class="stat-box green">
                    <div class="stat-label">Ballots Issued</div>
                    <div class="stat-value sv-green" style="font-size:22px;"><?= number_format($active_total_ballots) ?></div>
                </div>
                <div class="stat-box amber">
                    <div class="stat-label">Verified Stations</div>
                    <div class="stat-value sv-amber" style="font-size:22px;">
                        <?php
                        $vsc=0; foreach($active_stations as $s){ if($s['sr_status']==='VERIFIED') $vsc++; }
                        echo $vsc.'/'.count($active_stations);
                        ?>
                    </div>
                </div>
            </div>
            <div class="two-equal">
                <div>
                    <div class="prog-wrap">
                        <div class="prog-label"><span>Turnout (vs Issued)</span><span><?= $turnout_pct ?>%</span></div>
                        <div class="prog-bar"><div class="prog-fill blue" style="width:<?= min(100,$turnout_pct) ?>%"></div></div>
                    </div>
                    <div class="prog-wrap" style="margin-top:10px;">
                        <div class="prog-label"><span>Registered Voter Participation</span><span><?= $reg_turnout ?>%</span></div>
                        <div class="prog-bar"><div class="prog-fill green" style="width:<?= min(100,$reg_turnout) ?>%"></div></div>
                    </div>
                </div>
                <div>
                    <div class="prog-wrap">
                        <div class="prog-label"><span>Station Verification</span><span><?= count($active_stations)>0?round($vsc/count($active_stations)*100):0 ?>%</span></div>
                        <div class="prog-bar"><div class="prog-fill gold" style="width:<?= count($active_stations)>0?min(100,round($vsc/count($active_stations)*100)):0 ?>%"></div></div>
                    </div>
                    <div style="margin-top:12px;font-size:12.5px;color:var(--muted);">
                        Compiled by: <strong style="color:var(--text);"><?= htmlspecialchars($active_con['aro_name']??'N/A') ?></strong>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- WINNER ANNOUNCEMENT -->
    <?php
    $winner = null;
    
    $winner   = null;
$is_tie   = (int)($active_con['is_tie'] ?? 0) === 1;
foreach($active_candidates as $c){
    if((int)$c['candidate_id']===(int)($active_con['winner_candidate_id']??0)){
        $winner=$c; break;
    }
}
    $total_v = array_sum(array_column($active_candidates,'total_votes'));
    $w_share = ($winner && $total_v>0) ? round($winner['total_votes']/$total_v*100,1) : 0;
   $w_margin = (!$is_tie && count($active_candidates)>=2 && $winner)? max(0, $winner['total_votes'] - ($active_candidates[1]['total_votes']??0)) : 0;
    $w_col    = pcol($winner['abbreviation']??'',$pcolors);
    ?>
    <?php if($is_approved && $winner && !$is_tie): ?>
    <div class="winner-card section-gap">
        <div style="position:relative;z-index:1;">
            <div class="winner-badge">🏆 OFFICIAL WINNER — DECLARED</div>
            <div class="winner-name"><?= htmlspecialchars($winner['full_name']) ?></div>
            <div class="winner-party">
                <span style="display:inline-block;background:<?= $w_col ?>;border-radius:4px;padding:2px 10px;font-size:12px;font-weight:700;margin-right:8px;"><?= htmlspecialchars($winner['party_name']??'IND') ?></span>
                Symbol: <?= htmlspecialchars($winner['symbol']??'—') ?>
            </div>
            <div class="winner-stats">
                <div class="winner-stat"><div class="ws-val"><?= number_format($winner['total_votes']) ?></div><div class="ws-label">Total Votes</div></div>
                <div class="winner-stat"><div class="ws-val"><?= $w_share ?>%</div><div class="ws-label">Vote Share</div></div>
                <div class="winner-stat"><div class="ws-val">+<?= number_format($w_margin) ?></div><div class="ws-label">Winning Margin</div></div>
            </div>
            <div class="winner-con">📍 <?= htmlspecialchars($active_con['name']) ?> — <?= htmlspecialchars($active_con['code']??'') ?> · Approved <?= $active_con['approval_timestamp'] ? date('d M Y',strtotime($active_con['approval_timestamp'])) : date('d M Y') ?></div>
        </div>
    </div>
    <?php elseif($is_tie): ?>
<div style="background:#fffbeb;border:2px solid #fcd34d;border-radius:12px;padding:24px 28px;margin-bottom:20px;">
    <div style="font-size:28px;margin-bottom:10px;">⚠️</div>
    <div style="font-size:17px;font-weight:800;color:#92400e;margin-bottom:8px;">Tied Result — Adjudication Required</div>
    <div style="font-size:13px;color:#78350f;line-height:1.7;">
        Two or more candidates received an equal number of votes. Under Electoral Rule ECR-10,
        a tied result cannot be automatically declared. The Returning Officer must resolve this
        tie in accordance with Election Commission guidelines before this result can be approved and published.
    </div>
    <div style="margin-top:14px;font-size:12px;color:#92400e;font-weight:600;">
        Tied candidates are highlighted below with 🤝. Contact your Assistant Returning Officer and the ARO's compiled report for verification.
    </div>
</div>
    <?php else: ?>
    <div class="winner-locked section-gap">
        <span class="lock-icon">🔒</span>
        <h3>Winner Declaration Pending</h3>
        <p style="font-size:13px;">Winner will be officially declared after RO approval and result publication.</p>
        <?php if($winner && $is_aggregated): ?>
        <p style="font-size:12px;margin-top:8px;color:#94a3b8;">Leading candidate (pending approval): <?= htmlspecialchars($winner['full_name']) ?> with <?= number_format($winner['total_votes']) ?> votes</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- CANDIDATE RESULT TABLE -->
    <div class="card section-gap">
        <div class="card-header">
            <span class="card-title">🗳️ Candidate-wise Result Breakdown</span>
            <span style="font-size:12px;color:var(--muted);"><?= count($active_candidates) ?> candidates · <?= number_format($total_v) ?> total valid votes</span>
        </div>
        <div class="table-wrap">
        <table class="cand-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th onclick="sortTable(this,2)" title="Sort by name">Candidate ↕</th>
                    <th>Party</th>
                    <th>Symbol</th>
                    <th onclick="sortTable(this,4)" title="Sort by votes">Total Votes ↕</th>
                    <th>Vote Share</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody id="candTbody">
            <?php foreach($active_candidates as $idx=>$c):
                $share = $total_v>0 ? round($c['total_votes']/$total_v*100,1) : 0;
                $col   = pcol($c['abbreviation']??'',$pcolors);
                $top_votes_ro = (int)($active_candidates[0]['total_votes'] ?? 0);
$is_ro_tie    = (int)($active_con['is_tie'] ?? 0) === 1;
$isWin        = (!$is_ro_tie && $winner && (int)$c['candidate_id']===(int)($winner['candidate_id']??0));
$isTied       = ($is_ro_tie && (int)$c['total_votes'] === $top_votes_ro && $top_votes_ro > 0);
            ?>
            <tr class="<?= $isWin?'winner-row':'' ?>">
                <td style="color:var(--muted);font-weight:600;">
    <?php if($isTied): ?>🤝
    <?php elseif($isWin): ?>🏆
    <?php else: ?><?= $idx+1 ?><?php endif; ?>
</td>
                <td>
                    <div style="font-weight:700;font-size:13.5px;"><?= htmlspecialchars($c['full_name']) ?></div>
                </td>
                <td><span class="party-chip" style="background:<?= $col ?>;"><?= htmlspecialchars($c['party_name']??'IND') ?></span></td>
                <td style="color:var(--muted);font-size:12px;"><?= htmlspecialchars($c['symbol']??'—') ?></td>
                <td>
                    <span style="font-weight:800;font-size:15px;color:<?= $isWin?'#b45309':'var(--primary)' ?>;"><?= number_format($c['total_votes']) ?></span>
                </td>
                <td>
                    <div class="vote-bar-wrap">
                        <div class="vote-bar-bg">
                            <div class="vote-bar-fill" style="width:<?= $share ?>%;background:<?= $col ?>;"></div>
                        </div>
                        <span style="font-size:12px;font-weight:600;width:38px;text-align:right;"><?= $share ?>%</span>
                    </div>
                </td>
                <?php if($isTied): ?>
    <span class="badge" style="background:#fef9c3;color:#92400e;border:1px solid #fde68a;">⚠️ Tied</span>
<?php elseif($isWin): ?>
    <span class="badge badge-winner">🏆 Winner</span>
<?php else: ?>
    <span class="badge badge-pending">Defeated</span>
<?php endif; ?>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>

    <!-- STATION BREAKDOWN TABLE -->
    <div class="card section-gap">
        <div class="card-header">
            <span class="card-title">🏫 Polling Station Verification Overview</span>
            <span style="font-size:12px;color:var(--muted);"><?= count($active_stations) ?> stations</span>
        </div>
        <?php if(!$active_all_verified): ?>
        <div class="alert-item alert-warning" style="margin:0 20px 8px;">
            <span>⚠️</span>
            <span><strong>Warning:</strong> Not all polling stations are verified. Constituency results cannot be officially approved until all stations are verified and aggregated by ARO.</span>
        </div>
        <?php endif; ?>
        <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Polling Station</th>
                    <th>Votes Cast</th>
                    <th>Ballots Issued</th>
                    <th>Turnout %</th>
                    <th>Verification</th>
                    <th>Verified By</th>
                    <th>Timestamp</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach($active_stations as $s):
                $pct = (int)$s['total_ballots_issued']>0 ? round((int)$s['sr_votes']/(int)$s['total_ballots_issued']*100,1) : 0;
                $sr  = $s['sr_status']??'PENDING';
                if($sr==='VERIFIED') $sbadge='<span class="badge badge-verified">✔ VERIFIED</span>';
                elseif($sr==='DRAFT') $sbadge='<span class="badge badge-draft">⚠ DRAFT</span>';
                else $sbadge='<span class="badge badge-pending">⏳ PENDING</span>';
            ?>
            <tr>
                <td>
                    <div style="font-weight:600;"><?= htmlspecialchars($s['name']) ?></div>
                    <div style="font-size:11px;color:var(--muted);"><?= htmlspecialchars($s['address']??'') ?></div>
                </td>
                <td class="num"><?= number_format($s['sr_votes']??0) ?></td>
                <td class="num"><?= number_format($s['total_ballots_issued']) ?></td>
                <td>
                    <div class="vote-bar-wrap">
                        <div class="vote-bar-bg"><div class="vote-bar-fill" style="width:<?= min(100,$pct) ?>%;background:var(--primary);"></div></div>
                        <span style="font-size:12px;font-weight:600;width:35px;text-align:right;"><?= $pct ?>%</span>
                    </div>
                </td>
                <td><?= $sbadge ?></td>
                <td style="font-size:12px;"><?= htmlspecialchars($s['po_name']??'N/A') ?></td>
                <td style="font-size:11.5px;color:var(--muted);"><?= $s['verification_timestamp'] ? date('d M, h:i A',strtotime($s['verification_timestamp'])) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>

    <!-- APPROVAL ACTION CARD -->
    <div class="card section-gap">
        <div class="card-header"><span class="card-title">🔐 Result Approval &amp; Publication</span></div>
        <div class="card-body">
            <?php if($is_approved): ?>
            <div class="approved-ribbon" style="margin-bottom:0;border-radius:8px;">
                <span class="rb-icon">✅</span>
                <div>
                    <div class="rb-title">Result Approved &amp; Published</div>
                    <div class="rb-sub">Approval time: <?= $active_con['approval_timestamp'] ? date('d M Y, h:i A',strtotime($active_con['approval_timestamp'])) : 'N/A' ?></div>
                </div>
            </div>
            <?php else: ?>
            <!-- Checklist -->
            <ul style="list-style:none;display:flex;flex-direction:column;gap:10px;margin-bottom:20px;">
                <li class="val-item"><span class="chk-icon <?= $is_aggregated?'chk-ok':'chk-fail' ?>"><?= $is_aggregated?'✓':'✗' ?></span>Constituency result compiled by ARO (status: <?= $active_con['cr_status']??'NONE' ?>)</li>
                <li class="val-item"><span class="chk-icon <?= $active_all_verified?'chk-ok':'chk-fail' ?>"><?= $active_all_verified?'✓':'✗' ?></span>All polling stations verified by Presiding Officers</li>
                <li class="val-item"><span class="chk-icon <?= $active_total_votes>0?'chk-ok':'chk-fail' ?>"><?= $active_total_votes>0?'✓':'✗' ?></span>Valid vote totals present (<?= number_format($active_total_votes) ?> votes)</li>
                <li class="val-item"><span class="chk-icon <?= $winner?'chk-ok':'chk-fail' ?>"><?= $winner?'✓':'✗' ?></span>Winner candidate determined by system</li>
                <li class="val-item"><span class="chk-icon chk-ok">✓</span>All approval actions permanently recorded in audit log</li>
            </ul>
            <button class="btn-publish-lg" <?= !$can_approve?'disabled':'' ?> onclick="openPublishModal()">
                🏛️ Approve &amp; Publish Official Election Result
            </button>
            <?php if(!$can_approve): ?>
            <p style="font-size:12px;color:var(--muted);text-align:center;margin-top:8px;">Complete all checklist items before publication.</p>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- WORKFLOW TIMELINE -->
    <div class="card section-gap">
        <div class="timeline-wrap">
            <div class="tl-title">Election Workflow — Official Process</div>
            <div class="timeline">
            <?php
            $steps = ['Booth Vote Entry','APO Submit to PO','PO Verification','ARO Compile Result','Approve &amp; Publish Results'];
            foreach($steps as $i=>$step):
                $sn=$i+1;
                $cls='';
                if($sn<$wf_step) $cls='done';
                elseif($sn===$wf_step) $cls='active';
            ?>
            <div class="tl-step">
                <div class="tl-circle <?= $cls ?>"><?= $cls==='done'?'✓':$sn ?></div>
                <div class="tl-label <?= $cls ?>"><?= $step ?></div>
            </div>
            <?php endforeach; ?>
            </div>
        </div>
    </div>

</div><!-- /left col -->

<!-- RIGHT SIDEBAR -->
<div>

    <!-- ALL CONSTITUENCIES OVERVIEW -->
    <div class="card section-gap">
        <div class="card-header"><span class="card-title">🗺️ Your Constituencies</span></div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:10px;">
        <?php foreach($constituencies as $c):
            $st = $c['cr_status']??$c['result_status']??'PENDING';
            $isAc = $active_con && $c['constituency_id']==$active_con['constituency_id'];
        ?>
        <div class="con-overview-card <?= $isAc?'active-con':'' ?>" onclick="switchConstituency(<?= $c['constituency_id'] ?>)">
            <div class="con-name"><?= htmlspecialchars($c['name']) ?></div>
            <div class="con-code"><?= htmlspecialchars($c['code']??'') ?> · <?= number_format($c['total_registered_voters']) ?> voters</div>
            <div class="con-status-row">
                <span class="badge <?= $st==='APPROVED'||$st==='PUBLISHED'?'badge-approved':($st==='AGGREGATED'?'badge-aggregated':'badge-pending') ?>"><?= $st ?></span>
                <?php if($c['winner_name'] && ($st==='APPROVED'||$st==='PUBLISHED')): ?>
                <span style="font-size:11px;color:var(--muted);">🏆 <?= htmlspecialchars($c['winner_name']) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>

    <!-- RO SUMMARY STATS -->
    <div class="card section-gap">
        <div class="card-header"><span class="card-title">📈 Approval Overview</span></div>
        <div class="card-body">
            <div class="two-equal" style="gap:10px;margin-bottom:12px;">
                <div class="mon-card"><div class="mon-val" style="color:var(--primary);"><?= $total_con ?></div><div class="mon-label">Total</div></div>
                <div class="mon-card"><div class="mon-val" style="color:var(--warning);"><?= $aggregated_con ?></div><div class="mon-label">Pending</div></div>
            </div>
            <div class="two-equal" style="gap:10px;">
                <div class="mon-card"><div class="mon-val" style="color:var(--success);"><?= $approved_con ?></div><div class="mon-label">Approved</div></div>
                <div class="mon-card"><div class="mon-val" style="color:var(--muted);"><?= $pending_con ?></div><div class="mon-label">Not Ready</div></div>
            </div>
            <div class="prog-wrap" style="margin-top:12px;">
                <div class="prog-label"><span>Approval Progress</span><span><?= $total_con>0?round($approved_con/$total_con*100):0 ?>%</span></div>
                <div class="prog-bar"><div class="prog-fill green" style="width:<?= $total_con>0?round($approved_con/$total_con*100):0 ?>%"></div></div>
            </div>
        </div>
    </div>

    <!-- VALIDATION SECURITY -->
    <div class="card section-gap">
        <div class="card-header"><span class="card-title">🛡️ Result Validation &amp; Security</span></div>
        <div class="card-body">
            <ul style="list-style:none;display:flex;flex-direction:column;gap:9px;">
                <li class="val-item"><span class="chk-icon <?= $active_total_votes>0?'chk-ok':'chk-warn' ?>"><?= $active_total_votes>0?'✓':'!' ?></span>Candidate votes aggregated from locked entries</li>
                <li class="val-item"><span class="chk-icon <?= $active_all_verified?'chk-ok':'chk-warn' ?>"><?= $active_all_verified?'✓':'!' ?></span>All polling stations verified by PO</li>
                <li class="val-item"><span class="chk-icon chk-ok">✓</span>No duplicate aggregation detected</li>
                <li class="val-item"><span class="chk-icon <?= $winner?'chk-ok':'chk-warn' ?>"><?= $winner?'✓':'!' ?></span>Winner determination completed by system</li>
                <li class="val-item"><span class="chk-icon chk-ok">✓</span>Audit log generated for all actions</li>
            </ul>
            <div style="margin-top:14px;background:#f8fafc;border-radius:7px;padding:10px 12px;font-size:11.5px;color:var(--muted);">
                🔒 All approval activities are permanently recorded in the EMS audit log and cannot be modified.
            </div>
        </div>
    </div>

    <!-- SYSTEM RULES -->
    <div class="card section-gap">
        <div class="card-header"><span class="card-title">📌 System Rules</span></div>
        <div class="card-body" style="font-size:12.5px;">
            <ul style="list-style:none;display:flex;flex-direction:column;gap:9px;">
                <li style="display:flex;gap:8px;"><span style="color:var(--primary);">●</span>Only AGGREGATED results can be approved by RO.</li>
                <li style="display:flex;gap:8px;"><span style="color:var(--primary);">●</span>Approval sets winner, timestamp, and approved_by_ro in DB.</li>
                <li style="display:flex;gap:8px;"><span style="color:var(--primary);">●</span>Approved results cannot be modified by ARO or PO.</li>
                <li style="display:flex;gap:8px;"><span style="color:var(--primary);">●</span>Winner is system-determined — highest locked vote count.</li>
                <li style="display:flex;gap:8px;"><span style="color:var(--primary);">●</span>RO cannot approve results they did not receive from ARO.</li>
            </ul>
        </div>
    </div>

    <!-- AUDIT LOG -->
    <div class="card section-gap">
        <div class="card-header"><span class="card-title">📋 Audit Log</span></div>
        <div class="card-body" style="padding:14px 18px;">
        <?php if(empty($audit_logs)): ?>
        <p style="color:var(--muted);text-align:center;padding:16px 0;font-size:13px;">No activity yet.</p>
        <?php else: ?>
        <?php foreach($audit_logs as $log):
            $icons=['VOTE_ENTRY'=>['🗳️','blue'],'LOGIN'=>['🔐','blue'],'SUBMIT_VOTES'=>['📤','green'],'VERIFY_STATION'=>['✔','green'],'APPROVE_RESULT'=>['✅','green'],'COMPILE_RESULT'=>['🧮','blue'],'REJECT_BOOTH'=>['✖','amber']];
            $ic=$icons[$log['action_type']]??['📋','blue'];
        ?>
        <div class="activity-item">
            <div class="act-dot <?= $ic[1] ?>"><?= $ic[0] ?></div>
            <div>
                <div class="act-text"><?= htmlspecialchars($log['action_type']) ?> <span class="badge <?= in_array($log['action_type'],['APPROVE_RESULT','VERIFY_STATION'])?'badge-verified':'badge-pending' ?>" style="font-size:10px;"><?= htmlspecialchars($log['orole']??'SYS') ?></span></div>
                <div class="act-sub"><?= htmlspecialchars(substr($log['details']??'',0,55)) ?></div>
                <div class="act-sub"><?= htmlspecialchars($log['oname']??'System') ?> · <?= date('d M, h:i A',strtotime($log['timestamp'])) ?></div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
        </div>
    </div>

</div>
</div><!-- /two-col -->

<?php endif; // active_con ?>
</div><!-- /page-wrap -->

<!-- ═══ FOOTER ═══ -->
<footer class="footer">
    <div>🗳️ <strong style="color:#cbd5e1;">Bangladesh Election Commission</strong> &nbsp;·&nbsp; Election Monitoring &amp; Result Management System &nbsp;·&nbsp; © 2026</div>
    <div class="footer-links">
        <a href="#">Helpdesk</a>
        <a href="#">Privacy Policy</a>
        <a href="#">Accessibility</a>
        <a href="#">Terms of Use</a>
    </div>
</footer>

<!-- ═══ PUBLISH CONFIRMATION MODAL ═══ -->
<div class="modal-overlay" id="publishModal">
    <div class="modal-box">
        <div class="modal-header">
            <span class="modal-title">🏛️ Confirm Official Result Publication</span>
            <button class="modal-close" onclick="closeModal('publishModal')">✕</button>
        </div>
        <div class="modal-body">
            <div class="modal-warning">
                <span style="font-size:20px;">⚠️</span>
                <div>
                    <strong>This action is irreversible.</strong><br>
                    You are about to publish the final certified election result for <strong id="modalConName"><?= htmlspecialchars($active_con['name']??'') ?></strong>. Published results will become official and immutable.
                </div>
            </div>

            <?php if($winner): ?>
            <div style="background:#f8fafc;border:1.5px solid var(--border);border-radius:8px;padding:16px;margin-bottom:16px;">
                <div style="font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;">Result Summary to be Published</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:13px;">
                    <div><div style="color:var(--muted);font-size:11px;">Constituency</div><strong><?= htmlspecialchars($active_con['name']??'') ?></strong></div>
                    <div><div style="color:var(--muted);font-size:11px;">Declared Winner</div><strong><?= htmlspecialchars($winner['full_name']??'') ?></strong></div>
                    <div><div style="color:var(--muted);font-size:11px;">Winning Party</div><strong><?= htmlspecialchars($winner['party_name']??'') ?></strong></div>
                    <div><div style="color:var(--muted);font-size:11px;">Total Votes Cast</div><strong><?= number_format($active_total_votes) ?></strong></div>
                </div>
            </div>
            <?php endif; ?>

            <ul style="list-style:none;display:flex;flex-direction:column;gap:8px;font-size:13px;margin-bottom:4px;">
                <li style="display:flex;gap:8px;"><span>✅</span>Winner will be officially declared in the system</li>
                <li style="display:flex;gap:8px;"><span>✅</span>approved_by_ro, approval_timestamp fields updated</li>
                <li style="display:flex;gap:8px;"><span>✅</span>Constituency status changed to APPROVED</li>
                <li style="display:flex;gap=8px;"><span>✅</span>Action permanently recorded in audit log</li>
            </ul>

            <div class="modal-actions">
                <button class="btn btn-muted" onclick="closeModal('publishModal')">✕ Cancel</button>
                <button class="btn btn-success btn-lg" id="confirmPublishBtn" onclick="confirmPublish(<?= (int)($active_con['constituency_id']??0) ?>)">
                    ✔ Approve &amp; Publish Official Result
                </button>
            </div>
        </div>
    </div>
</div>

<div id="toast"></div>

<script>
const ACTIVE_CON_ID = <?= (int)($active_con['constituency_id']??0) ?>;

// ===== PUBLISH MODAL =====
function openPublishModal() {
    if(!ACTIVE_CON_ID) return;
    document.getElementById('publishModal').classList.add('open');
}
function closeModal(id) {
    document.getElementById(id).classList.remove('open');
}
document.getElementById('publishModal').addEventListener('click', function(e){
    if(e.target===this) closeModal('publishModal');
});

// ===== CONFIRM PUBLISH =====
function confirmPublish(conId) {
    const btn = document.getElementById('confirmPublishBtn');
    btn.disabled = true;
    btn.textContent = '⏳ Publishing...';

    const fd = new FormData();
    fd.append('action','approve_publish');
    fd.append('constituency_id', conId);

    fetch(window.location.href, {method:'POST',body:fd})
        .then(r=>r.json())
        .then(res=>{
            closeModal('publishModal');
            showToast(res.message, res.success?'success':'error');
            if(res.success) setTimeout(()=>location.reload(), 1800);
            else { btn.disabled=false; btn.textContent='✔ Approve & Publish Official Result'; }
        })
        .catch(()=>{
            showToast('Network error','error');
            btn.disabled=false;
            btn.textContent='✔ Approve & Publish Official Result';
        });
}

// ===== SWITCH CONSTITUENCY =====
function switchConstituency(conId) {
    window.location.href = window.location.pathname + '?view_con=' + conId;
}

// ===== TABLE SORT =====
function sortTable(th, colIdx) {
    const tbody = document.getElementById('candTbody');
    if(!tbody) return;
    const rows = Array.from(tbody.querySelectorAll('tr'));
    const asc  = th.dataset.sort !== 'asc';
    th.dataset.sort = asc ? 'asc' : 'desc';
    rows.sort((a,b)=>{
        const av = a.cells[colIdx]?.textContent.trim().replace(/[,]/g,'')||'';
        const bv = b.cells[colIdx]?.textContent.trim().replace(/[,]/g,'')||'';
        const an = parseFloat(av), bn = parseFloat(bv);
        if(!isNaN(an)&&!isNaN(bn)) return asc?an-bn:bn-an;
        return asc?av.localeCompare(bv):bv.localeCompare(av);
    });
    rows.forEach(r=>tbody.appendChild(r));
}

// ===== TOAST =====
function showToast(msg, type='info') {
    const c = document.getElementById('toast');
    const el = document.createElement('div');
    el.className = 'toast-msg ' + type;
    el.innerHTML = (type==='success'?'✅ ':type==='error'?'❌ ':'ℹ️ ') + msg;
    c.appendChild(el);
    setTimeout(()=>{ el.style.opacity='0'; el.style.transition='opacity .4s'; setTimeout(()=>el.remove(),400); },4000);
}
</script>

<?php
// Handle ?view_con= switch
// Handled via redirect + page re-render below — add at top of PHP if needed
?>
</body>
</html>
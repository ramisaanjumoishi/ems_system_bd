<?php
// ============================================================
//  DB CONFIG — adjust as needed
// ============================================================
$DB_HOST = 'localhost';
$DB_NAME = 'ems';
$DB_USER = 'root';
$DB_PASS = '';

// ============================================================
//  SESSION / AUTH (simple session-based; replace with proper
//  login system if needed)
// ============================================================
session_start();

if (!isset($_SESSION['officer_id']) || $_SESSION['role'] !== 'APO') {
    header('Location: home.php?error=invalid');
    exit;
}
$logged_in_id = (int)$_SESSION['officer_id'];

// ============================================================
//  DATABASE CONNECTION
// ============================================================
try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    die('<div style="padding:40px;font-family:sans-serif;color:red;"><h2>Database Connection Failed</h2><p>' . htmlspecialchars($e->getMessage()) . '</p><p>Please check your DB credentials at the top of this file.</p></div>');
}

// ============================================================
//  AJAX HANDLERS
// ============================================================

// ---- AJAX: Save as Draft ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_draft') {
    header('Content-Type: application/json');
    $votes   = $_POST['votes']   ?? [];
    $booth_id = (int)($_POST['booth_id'] ?? 0);
    $errors  = [];

    if (!$booth_id) { echo json_encode(['success'=>false,'message'=>'Invalid booth']); exit; }

    // Check not locked
    $locked = $pdo->prepare("SELECT is_locked FROM booth_results WHERE booth_id=? LIMIT 1");
    $locked->execute([$booth_id]);
    $row = $locked->fetch();
    if ($row && $row['is_locked']) {
        echo json_encode(['success'=>false,'message'=>'Results are locked and cannot be edited.']);
        exit;
    }

    $total_votes_to_save = 0;
    foreach ($votes as $candidate_id => $votes_received) {
        $total_votes_to_save += max(0, (int)$votes_received);
    }
    
    // Get booth ballots issued
    $bi = $pdo->prepare("SELECT ballots_issued FROM polling_booths WHERE booth_id=?");
    $bi->execute([$booth_id]);
    $boothRow = $bi->fetch();
    $ballots_issued = $boothRow ? (int)$boothRow['ballots_issued'] : 0;
    
    // Validate votes don't exceed ballots issued (even for draft)
    if ($ballots_issued > 0 && $total_votes_to_save > $ballots_issued) {
        echo json_encode(['success'=>false,'message'=>"Total votes ($total_votes_to_save) cannot exceed ballots issued ($ballots_issued)."]);
        exit;
    }

    $pdo->beginTransaction();
    try {
        foreach ($votes as $candidate_id => $votes_received) {
            $candidate_id   = (int)$candidate_id;
            $votes_received = max(0, (int)$votes_received);

            // Check if record exists
            $check = $pdo->prepare("SELECT booth_result_id FROM booth_results WHERE booth_id=? AND candidate_id=?");
            $check->execute([$booth_id, $candidate_id]);
            $existing = $check->fetch();

            if ($existing) {
                $upd = $pdo->prepare("UPDATE booth_results SET votes_received=?, entered_by_apo=?, entry_timestamp=NOW() WHERE booth_id=? AND candidate_id=?");
                $upd->execute([$votes_received, $logged_in_id, $booth_id, $candidate_id]);
            } else {
                $ins = $pdo->prepare("INSERT INTO booth_results (booth_id, candidate_id, votes_received, entered_by_apo, entry_timestamp, is_locked) VALUES (?,?,?,?,NOW(),0)");
                $ins->execute([$booth_id, $candidate_id, $votes_received, $logged_in_id]);
            }
        }
        $pdo->commit();
        echo json_encode(['success'=>true,'message'=>'Draft saved successfully at ' . date('h:i A')]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]);
    }
    exit;
}

// ---- AJAX: Submit (Lock) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_votes') {
    header('Content-Type: application/json');
    $votes    = $_POST['votes']   ?? [];
    $booth_id = (int)($_POST['booth_id'] ?? 0);

    if (!$booth_id) { echo json_encode(['success'=>false,'message'=>'Invalid booth']); exit; }

    // Check already locked
    $locked = $pdo->prepare("SELECT is_locked FROM booth_results WHERE booth_id=? LIMIT 1");
    $locked->execute([$booth_id]);
    $row = $locked->fetch();
    if ($row && $row['is_locked']) {
        echo json_encode(['success'=>false,'message'=>'Already submitted and locked.']);
        exit;
    }

    // Get booth ballots issued
    $bi = $pdo->prepare("SELECT ballots_issued FROM polling_booths WHERE booth_id=?");
    $bi->execute([$booth_id]);
    $boothRow = $bi->fetch();
    $ballots_issued = $boothRow ? (int)$boothRow['ballots_issued'] : 0;

    $total_votes = 0;
    foreach ($votes as $v) { $total_votes += max(0,(int)$v); }

    if ($ballots_issued > 0 && $total_votes > $ballots_issued) {
        echo json_encode(['success'=>false,'message'=>"Total votes ($total_votes) cannot exceed ballots issued ($ballots_issued)."]);
        exit;
    }

    $pdo->beginTransaction();
    try {
        foreach ($votes as $candidate_id => $votes_received) {
            $candidate_id   = (int)$candidate_id;
            $votes_received = max(0, (int)$votes_received);

            $check = $pdo->prepare("SELECT booth_result_id FROM booth_results WHERE booth_id=? AND candidate_id=?");
            $check->execute([$booth_id, $candidate_id]);
            $existing = $check->fetch();

            if ($existing) {
                $upd = $pdo->prepare("UPDATE booth_results SET votes_received=?, entered_by_apo=?, entry_timestamp=NOW(), is_locked=1 WHERE booth_id=? AND candidate_id=?");
                $upd->execute([$votes_received, $logged_in_id, $booth_id, $candidate_id]);
            } else {
                $ins = $pdo->prepare("INSERT INTO booth_results (booth_id, candidate_id, votes_received, entered_by_apo, entry_timestamp, is_locked) VALUES (?,?,?,?,NOW(),1)");
                $ins->execute([$booth_id, $candidate_id, $votes_received, $logged_in_id]);
            }
        }

        // ── NEW: Mark booth voters as voted (only those not already voted) ──
        $voterUpd = $pdo->prepare("
            UPDATE voters
            SET has_voted = 1,
                voted_at  = NOW()
            WHERE booth_id = ?
              AND has_voted = 0
        ");
        $voterUpd->execute([$booth_id]);
        $voters_marked = $voterUpd->rowCount();

        // Log the action
        $log = $pdo->prepare("INSERT INTO audit_logs (officer_id, action_type, affected_entity, affected_entity_id, details, ip_address) VALUES (?,?,?,?,?,?)");
        $log->execute([$logged_in_id, 'SUBMIT_VOTES', 'BoothResult', $booth_id, "APO submitted and locked votes for booth $booth_id. Marked $voters_marked voters as voted.", $_SERVER['REMOTE_ADDR'] ?? '']);

        $pdo->commit();
        echo json_encode(['success'=>true,'message'=>'Votes submitted and locked. Results sent to Presiding Officer.']);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]);
    }
    exit;
}
// ============================================================
//  LOAD APO DATA
// ============================================================
// Officer info
$stmt = $pdo->prepare("
    SELECT eo.*, ps.name AS station_name, ps.address AS station_address,
           ps.station_id, c.name AS constituency_name, c.constituency_id
    FROM election_officers eo
    LEFT JOIN polling_stations ps ON ps.station_id = eo.assigned_station_id
    LEFT JOIN constituencies c ON c.constituency_id = ps.constituency_id
    WHERE eo.officer_id = ?
");
$stmt->execute([$logged_in_id]);
$officer = $stmt->fetch();

if (!$officer || $officer['role'] !== 'APO') {
    die('<div style="padding:40px;font-family:sans-serif;color:#c0392b;"><h2>Access Denied</h2><p>This dashboard is only for APO officers. Add <code>?officer_id=4</code> to the URL to use the seeded APO account.</p></div>');
}

// Assigned Booth (first booth for this APO at their station)
$boothStmt = $pdo->prepare("
    SELECT pb.*, ps.name AS station_name, ps.address AS station_address,
           ps.constituency_id, ps.total_ballots_issued AS station_total_ballots
    FROM polling_booths pb
    JOIN polling_stations ps ON ps.station_id = pb.station_id
    WHERE pb.assistant_presiding_officer_id = ?
    LIMIT 1
");
$boothStmt->execute([$logged_in_id]);
$booth = $boothStmt->fetch();

// Candidates for the constituency of this booth
$candidates = [];
$booth_results_map = [];
$is_locked = false;
$total_votes_entered = 0;
$ballots_issued = 0;
$entry_start_time = null;
$submit_time = null;

if ($booth) {
    $ballots_issued = (int)$booth['ballots_issued'];

    // Get constituency from polling station
    $conStmt = $pdo->prepare("
        SELECT c.constituency_id, c.name AS constituency_name
        FROM polling_stations ps
        JOIN constituencies c ON c.constituency_id = ps.constituency_id
        WHERE ps.station_id = ?
    ");
    $conStmt->execute([$booth['station_id']]);
    $constituency = $conStmt->fetch();

    if ($constituency) {
        $candStmt = $pdo->prepare("
            SELECT c.candidate_id, c.full_name, c.symbol,
                   pp.name AS party_name, pp.abbreviation AS party_abbr
            FROM candidates c
            LEFT JOIN political_parties pp ON pp.party_id = c.party_id
            WHERE c.constituency_id = ?
            ORDER BY c.candidate_id
        ");
        $candStmt->execute([$constituency['constituency_id']]);
        $candidates = $candStmt->fetchAll();
    }

    // Load existing booth results
    $resStmt = $pdo->prepare("
        SELECT candidate_id, votes_received, is_locked, entry_timestamp
        FROM booth_results
        WHERE booth_id = ?
        ORDER BY entry_timestamp ASC
    ");
    $resStmt->execute([$booth['booth_id']]);
    $resRows = $resStmt->fetchAll();

    foreach ($resRows as $r) {
        $booth_results_map[$r['candidate_id']] = $r['votes_received'];
        if ($r['is_locked']) $is_locked = true;
        if (!$entry_start_time) $entry_start_time = $r['entry_timestamp'];
        if ($r['is_locked']) $submit_time = $r['entry_timestamp'];
    }

    foreach ($booth_results_map as $v) { $total_votes_entered += $v; }
}

$usage_pct = $ballots_issued > 0 ? round(($total_votes_entered / $ballots_issued) * 100, 1) : 0;
$difference = $total_votes_entered - $ballots_issued;

// Presiding Officer name for this station
$po_name = 'N/A';
if ($booth) {
    $poStmt = $pdo->prepare("SELECT full_name FROM election_officers WHERE officer_id=(SELECT presiding_officer_id FROM polling_stations WHERE station_id=?)");
    $poStmt->execute([$booth['station_id']]);
    $poRow = $poStmt->fetch();
    if ($poRow) $po_name = $poRow['full_name'];
}

// Registered voters in this booth
$reg_voters = 0;
if ($booth) {
    $rvStmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM voters WHERE booth_id=?");
    $rvStmt->execute([$booth['booth_id']]);
    $rvRow = $rvStmt->fetch();
    $reg_voters = $rvRow ? (int)$rvRow['cnt'] : 0;
}

// Timeline
$booth_assigned_time = '07:30 AM'; // Static for demo; could store in a new table
$vote_entry_started  = $entry_start_time ? date('h:i A', strtotime($entry_start_time)) : '--:--';
$submitted_time      = $submit_time ? date('h:i A', strtotime($submit_time)) : '--:--';

// Step logic: 1=booth assigned, 2=vote entry started, 3=submitted, 4=locked
$current_step = 1;
if ($entry_start_time) $current_step = 2;
if ($is_locked)        $current_step = 3;

// Party color map
$party_colors = [
    'AL'  => '#006633',
    'BNP' => '#003399',
    'JP'  => '#CC0000',
    'IAB' => '#009900',
    'WPB' => '#CC3300',
];
function party_color($abbr, $map) {
    return $map[$abbr] ?? '#555';
}
function party_icon($abbr) {
    $icons = ['AL'=>'🟢','BNP'=>'🔵','JP'=>'🔴','IAB'=>'🟩','WPB'=>'🟠'];
    return $icons[$abbr] ?? '⚪';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>APO Dashboard — Bangladesh Election Commission EMS</title>
<style>
/* ============================================================
   APO DASHBOARD — CSS
   Topbar & footer lifted from RO dashboard (dark navy).
   All vote-entry, booth, profile, timeline, toast classes
   remain APO-original and untouched.
   ============================================================ */

*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
    --primary:#1a56db;--primary-dk:#1447bc;--accent:#0ea5e9;
    --success:#16a34a;--warning:#d97706;--danger:#dc2626;
    --navy:#1e3a5f;--navy-dk:#152b47;
    --bg:#f5f7fa;--surface:#ffffff;--border:#e2e8f0;
    --text:#1e293b;--muted:#64748b;
    --radius:10px;--shadow:0 1px 4px rgba(0,0,0,.08);--shadow-md:0 4px 16px rgba(0,0,0,.10);
}
body{font-family:'Segoe UI',system-ui,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;}

/* ── TOPBAR (dark navy — from RO) ── */
.topbar{background:var(--navy);display:flex;align-items:center;justify-content:space-between;padding:0 28px;height:62px;position:sticky;top:0;z-index:100;box-shadow:0 2px 12px rgba(0,0,0,.18);}
.topbar-brand{display:flex;align-items:center;gap:12px;}
.topbar-brand .emblem{font-size:22px;}
.topbar-brand .brand-text{font-size:15px;font-weight:700;color:#fff;line-height:1.15;}
.topbar-brand .brand-sub{font-size:10.5px;color:#94a3b8;font-weight:400;}
.topbar-right{display:flex;align-items:center;gap:16px;font-size:13px;}

/* Election badge (frosted glass on dark) */
.topbar-election{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);border-radius:20px;padding:4px 14px;font-size:12px;color:#e2e8f0;font-weight:600;}

/* Officer chip — APO uses .topbar-officer wrapper */
.topbar-officer{display:flex;align-items:center;gap:9px;}
.officer-avatar{width:36px;height:36px;background:linear-gradient(135deg,#1a56db,#0ea5e9);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:14px;border:2px solid rgba(255,255,255,.25);}
.officer-info .name{color:#f1f5f9;font-size:13px;font-weight:600;}
.officer-info .role-badge{font-size:10.5px;color:#fff;background:rgba(22,163,74,.55);border-radius:8px;padding:1px 8px;border:1px solid rgba(22,163,74,.3);}

/* Logout button (from RO) */
.btn-logout{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.18);border-radius:7px;padding:6px 14px;font-size:12px;cursor:pointer;color:#cbd5e1;transition:.15s;}
.btn-logout:hover{background:rgba(220,38,38,.25);border-color:#f87171;color:#fca5a5;}

/* ── LAYOUT ── */
.page-wrap{max-width:1200px;margin:0 auto;padding:24px 20px;}
.two-col{display:grid;grid-template-columns:1fr 340px;gap:20px;}
@media(max-width:900px){.two-col{grid-template-columns:1fr}}

/* ── CARDS ── */
.card{background:var(--surface);border:1.5px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);}
.card-header{display:flex;align-items:center;justify-content:space-between;padding:14px 20px 10px;border-bottom:1px solid var(--border);}
.card-title{font-size:14px;font-weight:700;display:flex;align-items:center;gap:8px;}
.card-body{padding:18px 20px;}

/* ── OFFICER PROFILE ── */
.profile-card{padding:20px;display:flex;gap:20px;align-items:flex-start;margin-bottom:20px;}
.profile-avatar{width:70px;height:70px;border-radius:12px;background:linear-gradient(135deg,#1a56db 0%,#0ea5e9 100%);display:flex;align-items:center;justify-content:center;font-size:28px;color:#fff;font-weight:700;flex-shrink:0;}
.profile-details{flex:1;}
.profile-name{font-size:22px;font-weight:800;margin-bottom:2px;}
.profile-id{font-size:12px;color:var(--muted);margin-bottom:10px;}
.profile-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px 20px;font-size:12.5px;}
.profile-grid .label{color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.5px;margin-bottom:2px;}
.profile-grid .value{font-weight:600;}
.badge-booth{display:inline-block;background:#dbeafe;color:var(--primary);border-radius:20px;padding:3px 12px;font-size:12px;font-weight:600;}
.badge-active{display:inline-block;background:#dcfce7;color:var(--success);border-radius:20px;padding:3px 12px;font-size:12px;font-weight:600;margin-left:8px;}
.profile-status{display:flex;align-items:center;gap:10px;margin-bottom:8px;}

/* ── STAT BOXES ── */
.stats-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;padding:16px 20px;}
.stat-box{border:1.5px solid var(--border);border-radius:8px;padding:14px 16px;}
.stat-box.blue{border-color:#bfdbfe;background:#eff6ff;}
.stat-box.green{border-color:#bbf7d0;background:#f0fdf4;}
.stat-label{font-size:10.5px;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);margin-bottom:4px;display:flex;align-items:center;gap:5px;}
.stat-value{font-size:30px;font-weight:800;line-height:1;}
.stat-value.blue{color:var(--primary);}
.stat-value.black{color:var(--text);}

/* ── PROGRESS ── */
.progress-bar-wrap{padding:0 20px 16px;}
.progress-label{display:flex;justify-content:space-between;font-size:12px;color:var(--muted);margin-bottom:6px;}
.progress-bar{height:9px;background:#e2e8f0;border-radius:99px;overflow:hidden;}
.progress-fill{height:100%;border-radius:99px;background:linear-gradient(90deg,var(--primary),var(--accent));transition:width .4s;}
.validation-tag{margin:0 20px 16px;padding:8px 14px;border-radius:7px;font-size:12px;font-weight:600;}
.validation-tag.warning{background:#fef9c3;color:#92400e;border:1px solid #fde68a;}
.validation-tag.success{background:#dcfce7;color:#166534;border:1px solid #86efac;}
.validation-tag.locked{background:#f3e8ff;color:#6b21a8;border:1px solid #d8b4fe;}

/* ── VOTE ENTRY PANEL ── */
.panel-meta{padding:0 20px 8px;display:flex;align-items:center;justify-content:space-between;font-size:12px;color:var(--muted);}
.last-saved{display:flex;align-items:center;gap:5px;}
.warning-alert{margin:0 20px 12px;background:#fff7ed;border:1px solid #fdba74;border-radius:8px;padding:10px 14px;font-size:12.5px;color:#9a3412;display:flex;gap:8px;align-items:flex-start;}
.warning-alert .icon{font-size:16px;}

/* TABLE */
.vote-table{width:100%;border-collapse:collapse;font-size:13.5px;}
.vote-table th{background:#f8fafc;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);padding:10px 14px;border-bottom:1.5px solid var(--border);text-align:left;}
.vote-table th:last-child{text-align:center;}
.vote-table td{padding:11px 14px;border-bottom:1px solid var(--border);vertical-align:middle;}
.vote-table tr:last-child td{border-bottom:none;}
.vote-table tr:hover td{background:#f8fafc;}
.candidate-info{display:flex;align-items:center;gap:10px;}
.cand-icon{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;background:#f1f5f9;}
.cand-name{font-weight:600;font-size:13.5px;}
.cand-symbol{font-size:11px;color:var(--muted);}
.party-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:6px;font-size:11.5px;font-weight:600;color:#fff;}
.vote-input{width:120px;border:1.5px solid var(--border);border-radius:7px;padding:7px 12px;font-size:14px;font-weight:700;text-align:center;transition:.15s;outline:none;}
.vote-input:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(26,86,219,.12);}
.vote-input:disabled{background:#f1f5f9;color:var(--muted);cursor:not-allowed;border-color:#e2e8f0;}
.pct-cell{font-size:12px;color:var(--muted);text-align:center;min-width:70px;}
.total-row td{font-weight:700;background:#f8fafc;font-size:14px;}
.total-votes-val{color:var(--primary);font-size:18px;font-weight:800;}

/* ── ACTION BUTTONS (from RO sizing — 8px radius, polished) ── */
.action-bar{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-top:1px solid var(--border);flex-wrap:wrap;gap:10px;}
.action-bar-left{font-size:13px;color:var(--text);}
.action-bar-left .num{font-weight:800;font-size:16px;}
.diff{margin-left:8px;font-size:12px;}
.diff.over{color:var(--danger);}
.diff.under{color:var(--warning);}
.diff.exact{color:var(--success);}
.btn{border:none;border-radius:8px;padding:9px 22px;font-size:13px;font-weight:600;cursor:pointer;transition:.15s;display:inline-flex;align-items:center;gap:6px;}
.btn-draft{background:#f1f5f9;color:var(--text);border:1.5px solid var(--border);}
.btn-draft:hover{background:#e2e8f0;}
.btn-submit{background:var(--primary);color:#fff;}
.btn-submit:hover{background:var(--primary-dk);}
.btn-submit:disabled{background:#94a3b8;cursor:not-allowed;}
.btn-locked{background:#7c3aed;color:#fff;cursor:not-allowed;}

/* ── BOOTH META ── */
.meta-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;}
@media(max-width:700px){.meta-grid{grid-template-columns:1fr}}
.meta-item .meta-label{font-size:10.5px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin-bottom:4px;display:flex;align-items:center;gap:5px;}
.meta-item .meta-value{font-size:13.5px;font-weight:600;}
.status-chip{display:inline-block;border-radius:20px;padding:3px 12px;font-size:12px;font-weight:600;}
.status-chip.pending{background:#fef9c3;color:#92400e;}
.status-chip.progress{background:#dbeafe;color:#1e40af;}
.status-chip.done{background:#dcfce7;color:#166534;}
.print-bar{display:flex;gap:12px;margin-top:14px;}
.btn-sm{border:1.5px solid var(--border);border-radius:7px;padding:6px 14px;font-size:12px;font-weight:600;background:var(--surface);color:var(--text);cursor:pointer;display:inline-flex;align-items:center;gap:5px;}
.btn-sm:hover{background:#f1f5f9;}

/* ── PROGRESS TIMELINE (sidebar — unchanged from APO) ── */
.timeline-section{padding:20px;}
.timeline-title{font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);margin-bottom:18px;font-weight:700;}
.timeline{display:flex;align-items:flex-start;position:relative;}
.timeline::before{content:'';position:absolute;top:16px;left:16px;right:16px;height:2px;background:var(--border);z-index:0;}
.tl-step{flex:1;display:flex;flex-direction:column;align-items:center;position:relative;z-index:1;}
.tl-circle{width:32px;height:32px;border-radius:50%;border:2px solid var(--border);background:var(--surface);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:var(--muted);transition:.3s;}
.tl-circle.done{background:var(--success);border-color:var(--success);color:#fff;}
.tl-circle.active{background:var(--primary);border-color:var(--primary);color:#fff;}
.tl-label{font-size:10px;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);margin-top:8px;text-align:center;font-weight:600;}
.tl-label.done{color:var(--success);}
.tl-label.active{color:var(--primary);}
.tl-time{font-size:10px;color:var(--muted);margin-top:2px;text-align:center;}

/* ── TOAST ── */
#toast{position:fixed;bottom:28px;right:28px;z-index:999;}
.toast-msg{background:#1e293b;color:#fff;border-radius:9px;padding:12px 20px;font-size:13px;font-weight:500;margin-top:8px;display:flex;align-items:center;gap:10px;box-shadow:0 4px 20px rgba(0,0,0,.2);animation:slideIn .3s ease;}
.toast-msg.success{background:#166634;}
.toast-msg.error{background:#991b1b;}
@keyframes slideIn{from{transform:translateY(20px);opacity:0;}to{transform:translateY(0);opacity:1;}}

/* ── LOCKED OVERLAY ── */
.locked-banner{background:#f3e8ff;border:1.5px solid #c4b5fd;border-radius:8px;padding:10px 16px;font-size:13px;color:#5b21b6;font-weight:600;display:flex;align-items:center;gap:8px;margin-bottom:14px;}

/* ── FOOTER (dark navy — from RO) ── */
.footer{background:var(--navy);padding:20px 28px;display:flex;align-items:center;justify-content:space-between;font-size:12px;color:#94a3b8;margin-top:32px;flex-wrap:wrap;gap:10px;}
.footer-links{display:flex;gap:18px;}
.footer-links a{color:#94a3b8;text-decoration:none;}
.footer-links a:hover{color:#60a5fa;}

/* ── UTILITY ── */
.section-gap{margin-bottom:20px;}
.divider{border:none;border-top:1.5px solid var(--border);margin:0;}
.text-muted{color:var(--muted);}
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
        <div class="topbar-officer">
            <div class="officer-avatar"><?= strtoupper(substr($officer['full_name'],0,1)) ?></div>
            <div class="officer-info">
                <div class="name"><?= htmlspecialchars($officer['full_name']) ?></div>
                <span class="role-badge">APO · Active Duty</span>
            </div>
        </div>
        <button class="btn-logout" onclick="if(confirm('Logout from EMS?')){window.location='logout.php'}">⏻ Logout</button>
    </div>
</nav>

<!-- ===== MAIN CONTENT ===== -->
<div class="page-wrap">

    <!-- OFFICER PROFILE CARD -->
    <div class="card section-gap">
        <div class="profile-card">
            <div class="profile-avatar"><?= strtoupper(substr($officer['full_name'],0,1)) ?></div>
            <div class="profile-details">
                <div class="profile-status">
                    <span class="profile-name"><?= htmlspecialchars($officer['full_name']) ?></span>
                    <span class="badge-active">✔ Active Duty</span>
                </div>
                <div class="profile-id text-muted" style="font-size:12px;margin-bottom:10px;">ID: APO-2026-<?= str_pad($officer['officer_id'],4,'0',STR_PAD_LEFT) ?></div>
                <div class="profile-grid">
                    <div>
                        <div class="label">Constituency</div>
                        <div class="value"><?= htmlspecialchars($officer['constituency_name'] ?? 'N/A') ?></div>
                    </div>
                    <div>
                        <div class="label">Polling Station</div>
                        <div class="value"><?= htmlspecialchars($officer['station_name'] ?? 'N/A') ?></div>
                    </div>
                    <div>
                        <div class="label">Booth Assigned</div>
                        <div class="value">
                            <?php if($booth): ?>
                            <span class="badge-booth">Booth #<?= htmlspecialchars($booth['booth_number']) ?></span>
                            <?php else: ?><span class="text-muted">Not Assigned</span><?php endif; ?>
                        </div>
                    </div>
                    <div>
                        <div class="label">Official Shift</div>
                        <div class="value">08:00 AM — 05:00 PM</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="two-col">
        <!-- ===== LEFT COLUMN ===== -->
        <div>
            <!-- VOTE ENTRY PANEL -->
            <div class="card section-gap">
                <div class="card-header">
                    <span class="card-title">📋 Polling Booth Vote Entry Panel
                        <span style="font-size:11px;font-weight:400;color:var(--muted);">Manual verification of physical ballot counts per candidate</span>
                    </span>
                    <span class="last-saved text-muted" id="lastSaved">
                        <?= $is_locked ? '🔒 Submitted & Locked' : ($entry_start_time ? '💾 Last saved: ' . date('h:i A', strtotime($entry_start_time)) : 'Not saved yet') ?>
                    </span>
                </div>

                <?php if(!$booth): ?>
                <div class="card-body"><p class="text-muted" style="text-align:center;padding:30px 0;">No booth assigned to your account.</p></div>
                <?php else: ?>

                <?php if($is_locked): ?>
                <div style="padding:14px 20px 0;">
                    <div class="locked-banner">🔒 This booth's results have been submitted to the Presiding Officer and are now locked. No further edits are allowed.</div>
                </div>
                <?php elseif($ballots_issued > 0 && $total_votes_entered > $ballots_issued * 0.95): ?>
                <div class="warning-alert">
                    <span class="icon">⚠️</span>
                    <span><strong>Approaching ballots issued limit.</strong> Total entered votes are within 5% of the total issued ballots. Please double-check counting accuracy before submission.</span>
                </div>
                <?php endif; ?>

                <form id="voteForm">
                <input type="hidden" name="booth_id" id="boothId" value="<?= $booth['booth_id'] ?>">
                <table class="vote-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Candidate Details</th>
                            <th>Party Affiliation</th>
                            <th>Votes Received</th>
                            <th>% of Total</th>
                        </tr>
                    </thead>
                    <tbody id="voteTableBody">
                    <?php foreach($candidates as $idx => $cand):
                        $existing_votes = $booth_results_map[$cand['candidate_id']] ?? 0;
                        $pct = $total_votes_entered > 0 ? round(($existing_votes / $total_votes_entered)*100,1) : 0;
                        $color = party_color($cand['party_abbr'], $party_colors);
                    ?>
                    <tr>
                        <td style="color:var(--muted);font-weight:600;"><?= $idx+1 ?></td>
                        <td>
                            <div class="candidate-info">
                                <div class="cand-icon"><?= party_icon($cand['party_abbr']) ?></div>
                                <div>
                                    <div class="cand-name"><?= htmlspecialchars($cand['full_name']) ?></div>
                                    <div class="cand-symbol">Symbol: <?= htmlspecialchars($cand['symbol'] ?? 'N/A') ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="party-badge" style="background:<?= $color ?>;">
                                <?= htmlspecialchars($cand['party_name']) ?>
                            </span>
                        </td>
                        <td>
                            <input type="number" 
                                   class="vote-input" 
                                   name="votes[<?= $cand['candidate_id'] ?>]"
                                   data-candidate="<?= $cand['candidate_id'] ?>"
                                   value="<?= $existing_votes ?>"
                                   min="0"
                                   <?= $is_locked ? 'disabled' : '' ?>
                                   oninput="recalcTotals()">
                        </td>
                        <td class="pct-cell" id="pct_<?= $cand['candidate_id'] ?>"><?= $pct ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="total-row">
                            <td colspan="3" style="text-align:right;">TOTAL VOTES ENTERED</td>
                            <td><span class="total-votes-val" id="totalVotesDisplay"><?= $total_votes_entered ?></span></td>
                            <td class="pct-cell" id="totalPct">100%</td>
                        </tr>
                    </tfoot>
                </table>
                </form>

                <hr class="divider">
                <div class="action-bar">
                    <div class="action-bar-left">
                        <span>REGISTERED BALLOTS <span class="num"><?= $ballots_issued ?></span></span>
                        <span class="diff <?= $difference > 0 ? 'over' : ($difference < 0 ? 'under' : 'exact') ?>" id="diffDisplay">
                            DIFFERENCE <?= ($difference >= 0 ? '+' : '') . $difference ?>
                        </span>
                    </div>
                    <div style="display:flex;gap:10px;">
                        <?php if(!$is_locked): ?>
                        <button class="btn btn-draft" id="draftBtn" onclick="saveDraft()">💾 Save as Draft</button>
                        <button class="btn btn-submit" id="submitBtn" onclick="submitVotes()">✔ Submit Votes to PO</button>
                        <?php else: ?>
                        <button class="btn btn-locked" disabled>🔒 Submitted & Locked</button>
                        <?php endif; ?>
                    </div>
                </div>

                <?php endif; // booth check ?>
            </div>

            <!-- BOOTH LOCATION & METADATA -->
            <?php if($booth): ?>
            <div class="card section-gap">
                <div class="card-header">
                    <span class="card-title">📍 Booth Location &amp; Metadata</span>
                </div>
                <div class="card-body">
                    <div class="meta-grid">
                        <div class="meta-item">
                            <div class="meta-label">📍 Location Address</div>
                            <div class="meta-value"><?= htmlspecialchars($booth['station_address'] ?? 'N/A') ?></div>
                        </div>
                        <div class="meta-item">
                            <div class="meta-label">👥 Registered Voters</div>
                            <div class="meta-value"><?= number_format($reg_voters) ?> Total Voters</div>
                        </div>
                        <div class="meta-item">
                            <div class="meta-label">🔘 Official Status</div>
                            <div class="meta-value">
                                <?php if($is_locked): ?>
                                    <span class="status-chip done">SUBMITTED</span>
                                <?php elseif($entry_start_time): ?>
                                    <span class="status-chip progress">ENTRY IN PROGRESS</span>
                                <?php else: ?>
                                    <span class="status-chip pending">PENDING</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="print-bar">
                        <button class="btn-sm" onclick="window.print()">🖨️ Print Summary</button>
                        <button class="btn-sm" onclick="generatePDF()">📥 Download PDF</button>
                        <button class="btn-sm" style="margin-left:auto;color:var(--primary);">📞 Contact Presiding Officer</button>
                    </div>
                </div>
            </div>

            <!-- SUBMISSION PROGRESS LIFECYCLE -->
            <div class="card section-gap">
                <div class="card-body">
                    <div class="timeline-title">Submission Progress Lifecycle</div>
                    <div class="timeline">
                        <?php
                        $steps = [
                            ['label'=>'Booth Assigned',    'time'=>$booth_assigned_time],
                            ['label'=>'Vote Entry Started','time'=>$vote_entry_started],
                            ['label'=>'Submit Vote to PO', 'time'=>$submitted_time],
                            ['label'=>'Validation Pending','time'=>'--:--'],
                        ];
                        foreach($steps as $i => $step):
                            $stepNum = $i + 1;
                            $cls = '';
                            if ($stepNum < $current_step)       $cls = 'done';
                            elseif ($stepNum === $current_step) $cls = 'active';
                        ?>
                        <div class="tl-step">
                            <div class="tl-circle <?= $cls ?>">
                                <?= $cls === 'done' ? '✓' : $stepNum ?>
                            </div>
                            <div class="tl-label <?= $cls ?>"><?= $step['label'] ?></div>
                            <div class="tl-time"><?= $step['time'] ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- ===== RIGHT COLUMN ===== -->
        <div>
            <!-- BOOTH LIVE STATISTICS -->
            <div class="card section-gap">
                <div class="card-header">
                    <span class="card-title">📊 Booth Live Statistics</span>
                </div>
                <div class="stats-grid">
                    <div class="stat-box">
                        <div class="stat-label">🗳 Ballots Issued</div>
                        <div class="stat-value black"><?= number_format($ballots_issued) ?></div>
                    </div>
                    <div class="stat-box blue">
                        <div class="stat-label">✅ Votes Entered</div>
                        <div class="stat-value blue" id="liveVotesEntered"><?= number_format($total_votes_entered) ?></div>
                    </div>
                </div>
                <div class="progress-bar-wrap">
                    <div class="progress-label">
                        <span>Usage Capacity</span>
                        <span id="liveUsagePct"><?= $usage_pct ?>%</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" id="progressFill" style="width:<?= min(100,$usage_pct) ?>%"></div>
                    </div>
                </div>
                <div class="validation-tag <?= $is_locked ? 'locked' : ($total_votes_entered > 0 ? 'success' : 'warning') ?>">
                    <?php if($is_locked): ?>🔒 Submitted & Locked
                    <?php elseif($total_votes_entered > 0): ?>✅ Entry In Progress
                    <?php else: ?>⏳ Awaiting Entry<?php endif; ?>
                </div>

                <!-- Booth info detail -->
                <div style="padding:0 20px 16px;">
                    <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;font-weight:700;">Booth Details</div>
                    <table style="width:100%;font-size:12.5px;border-collapse:collapse;">
                        <tr><td style="color:var(--muted);padding:4px 0;">Booth Number</td><td style="font-weight:600;text-align:right;"><?= htmlspecialchars($booth['booth_number'] ?? 'N/A') ?></td></tr>
                        <tr><td style="color:var(--muted);padding:4px 0;">Station</td><td style="font-weight:600;text-align:right;"><?= htmlspecialchars($booth['station_name'] ?? 'N/A') ?></td></tr>
                        <tr><td style="color:var(--muted);padding:4px 0;">Presiding Officer</td><td style="font-weight:600;text-align:right;"><?= htmlspecialchars($po_name) ?></td></tr>
                        <tr><td style="color:var(--muted);padding:4px 0;">Candidates on Ballot</td><td style="font-weight:600;text-align:right;"><?= count($candidates) ?></td></tr>
                        <tr><td style="color:var(--muted);padding:4px 0;">Ballots Issued</td><td style="font-weight:600;text-align:right;"><?= number_format($ballots_issued) ?></td></tr>
                    </table>
                </div>
            </div>

            <!-- EMS SYSTEM RULES -->
            <div class="card section-gap">
                <div class="card-header">
                    <span class="card-title">📄 EMS System Rules</span>
                </div>
                <div class="card-body" style="font-size:12.5px;">
                    <ul style="list-style:none;display:flex;flex-direction:column;gap:8px;">
                        <li style="display:flex;gap:8px;"><span style="color:var(--primary);">●</span> Total candidate votes must equal ballots issued or less.</li>
                        <li style="display:flex;gap:8px;"><span style="color:var(--primary);">●</span> Duplicate entries are flagged by the central monitoring unit.</li>
                        <li style="display:flex;gap:8px;"><span style="color:var(--primary);">●</span> Once submitted, only the PO can unlock the data entry screen.</li>
                        <li style="display:flex;gap:8px;"><span style="color:var(--primary);">●</span> Assisted counts must be logged with specific serial IDs.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

</div><!-- /page-wrap -->

<!-- TOAST -->
<div id="toast"></div>

<script>
// ===== RECALC TOTALS ON INPUT =====
function recalcTotals() {
    const inputs = document.querySelectorAll('.vote-input:not([disabled])');
    const allInputs = document.querySelectorAll('.vote-input');
    let total = 0;
    allInputs.forEach(inp => { total += parseInt(inp.value) || 0; });

    // Update per-candidate percentages
    allInputs.forEach(inp => {
        const cid = inp.dataset.candidate;
        const pctEl = document.getElementById('pct_' + cid);
        if (pctEl) {
            const v = parseInt(inp.value) || 0;
            pctEl.textContent = total > 0 ? (v/total*100).toFixed(1) + '%' : '0%';
        }
    });

    document.getElementById('totalVotesDisplay').textContent = total.toLocaleString();
    document.getElementById('liveVotesEntered').textContent  = total.toLocaleString();

    const issued = <?= $ballots_issued ?>;
    const usagePct = issued > 0 ? Math.min(100, (total/issued*100)).toFixed(1) : 0;
    document.getElementById('liveUsagePct').textContent = usagePct + '%';
    document.getElementById('progressFill').style.width = usagePct + '%';

    const diff = total - issued;
    const diffEl = document.getElementById('diffDisplay');
    if(diffEl) {
        diffEl.textContent = 'DIFFERENCE ' + (diff >= 0 ? '+' : '') + diff;
        diffEl.className = 'diff ' + (diff > 0 ? 'over' : diff < 0 ? 'under' : 'exact');
    }
}

// ===== COLLECT VOTE DATA =====
function collectVotes() {
    const data = {};
    document.querySelectorAll('.vote-input').forEach(inp => {
        if (inp.name && inp.name.match(/votes\[(\d+)\]/)) {
            const match = inp.name.match(/votes\[(\d+)\]/);
            if (match) {
                data[match[1]] = inp.value || 0;
            }
        } else if (inp.dataset.candidate) {
            data[inp.dataset.candidate] = inp.value || 0;
        }
    });
    return data;
}
// ===== SAVE DRAFT =====
function saveDraft() {
    const total = parseInt(document.getElementById('totalVotesDisplay').textContent.replace(/,/g,'')) || 0;
    const issued = <?= $ballots_issued ?>;
    
    // Check if votes exceed ballots issued
    if(issued > 0 && total > issued) {
        showToast('Cannot save: Total votes (' + total + ') exceed ballots issued (' + issued + '). Please correct the vote counts.', 'error');
        return;
    }
    
    const btn = document.getElementById('draftBtn');
    btn.disabled = true;
    btn.textContent = '⏳ Saving...';
    const votes = collectVotes();
    const boothId = document.getElementById('boothId').value;
    const fd = new FormData();
    fd.append('action','save_draft');
    fd.append('booth_id', boothId);
    for(const [k,v] of Object.entries(votes)) { fd.append('votes['+k+']', v); }

    fetch(window.location.href, { method:'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            showToast(res.message, res.success ? 'success' : 'error');
            if(res.success) document.getElementById('lastSaved').textContent = '💾 Last saved: ' + new Date().toLocaleTimeString();
            else if(res.message.includes('exceed')) {
                // Reload to show fresh data if there's an inconsistency
                setTimeout(() => location.reload(), 1500);
            }
        })
        .catch(() => showToast('Network error', 'error'))
        .finally(() => { btn.disabled=false; btn.textContent='💾 Save as Draft'; });
}
// ===== SUBMIT VOTES =====
function submitVotes() {
    const total = parseInt(document.getElementById('totalVotesDisplay').textContent.replace(/,/g,'')) || 0;
    const issued = <?= $ballots_issued ?>;
    if(issued > 0 && total > issued) {
        showToast('Total votes (' + total + ') exceed ballots issued (' + issued + '). Cannot submit.', 'error');
        return;
    }
    if(!confirm('⚠️ Submit votes to the Presiding Officer?\n\nThis action will LOCK the entry panel permanently.\nThis cannot be undone.\n\nTotal votes: ' + total + '\nBallots issued: ' + issued)) return;

    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.textContent = '⏳ Submitting...';

    const votes = collectVotes();
    const boothId = document.getElementById('boothId').value;
    const fd = new FormData();
    fd.append('action','submit_votes');
    fd.append('booth_id', boothId);
    for(const [k,v] of Object.entries(votes)) { fd.append('votes['+k+']', v); }

    fetch(window.location.href, { method:'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            showToast(res.message, res.success ? 'success' : 'error');
            if(res.success) {
                setTimeout(() => location.reload(), 1500);
            } else {
                btn.disabled = false;
                btn.textContent = '✔ Submit Votes to PO';
            }
        })
        .catch(() => { showToast('Network error','error'); btn.disabled=false; btn.textContent='✔ Submit Votes to PO'; });
}

// ===== TOAST =====
function showToast(msg, type='info') {
    const container = document.getElementById('toast');
    const el = document.createElement('div');
    el.className = 'toast-msg ' + type;
    el.innerHTML = (type==='success'?'✅ ':type==='error'?'❌ ':'ℹ️ ') + msg;
    container.appendChild(el);
    setTimeout(() => { el.style.opacity='0'; el.style.transition='opacity .4s'; setTimeout(()=>el.remove(), 400); }, 3500);
}

// ===== PDF STUB =====
function generatePDF() { showToast('PDF generation would use server-side PDF library. Feature placeholder.','info'); }

// Init
recalcTotals();
</script>
</body>
</html>
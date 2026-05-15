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
if (!isset($_SESSION['officer_id']) || $_SESSION['role'] !== 'ADMIN') {
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $act = $_POST['ajax_action'];

    // ── DELETE STATION ──────────────────────────────────────
    if ($act === 'delete') {
        $id = (int)($_POST['station_id'] ?? 0);
        try {
            $pdo->prepare("DELETE FROM polling_stations WHERE station_id=?")->execute([$id]);
            $pdo->prepare("INSERT INTO audit_logs (officer_id,action_type,affected_entity,affected_entity_id,details,ip_address) VALUES (?,?,?,?,?,?)")
                ->execute([$logged_in_id,'DELETE_STATION','polling_stations',$id,"Deleted polling station ID $id",$_SERVER['REMOTE_ADDR']??'']);
            echo json_encode(['success'=>true,'message'=>'Polling station deleted successfully.']);
        } catch (PDOException $e) {
            echo json_encode(['success'=>false,'message'=>'Cannot delete: '.$e->getMessage()]);
        }
        exit;
    }

    // ── UPDATE STATION ──────────────────────────────────────
    if ($act === 'update') {
        $id      = (int)($_POST['station_id']??0);
        $name    = trim($_POST['name']??'');
        $addr    = trim($_POST['address']??'');
        $cid     = (int)($_POST['constituency_id']??0) ?: null;
        $po      = (int)($_POST['presiding_officer_id']??0) ?: null;
        $ballots = (int)($_POST['total_ballots_issued']??0);
        $status  = $_POST['result_status']??'PENDING';
        $allowed = ['PENDING','SUBMITTED','VERIFIED'];
        if (!in_array($status,$allowed)) $status='PENDING';

                if (!$name) {
            echo json_encode(['success'=>false,'message'=>'Station name is required.']); exit;
        }
        // === PO UNIQUENESS CONSTRAINT (exclude the station being edited) ===
        if ($po) {
            $chk = $pdo->prepare("SELECT station_id FROM polling_stations WHERE presiding_officer_id=? AND station_id != ?");
            $chk->execute([$po, $id]);
            if ($chk->fetch()) {
                echo json_encode(['success'=>false,'message'=>'This Presiding Officer is already assigned to another station.']); exit;
            }
        }
       
        // === BALLOT COUNT: for new station, voters aren't linked yet (no station_id exists)
        // so we can only block negative values here; JS warns user via the voter map ===
        if (!$form_error && $ballots < 0) {
            $form_error = 'Ballots issued cannot be negative.';
        }
        $vcStmt = $pdo->prepare("SELECT COUNT(*) FROM voters WHERE polling_station_id=?");
        $vcStmt->execute([$id]);
        $voterCount = (int)$vcStmt->fetchColumn();
        if ($voterCount > 0 && $ballots !== $voterCount) {
            echo json_encode(['success'=>false,'message'=>"Ballots issued must exactly equal the number of registered voters at this station ($voterCount). You entered: $ballots."]); exit;
        }
        try {
            $pdo->prepare("UPDATE polling_stations SET name=?,address=?,constituency_id=?,presiding_officer_id=?,total_ballots_issued=?,result_status=? WHERE station_id=?")
                ->execute([$name,$addr,$cid,$po,$ballots,$status,$id]);
            $pdo->prepare("INSERT INTO audit_logs (officer_id,action_type,affected_entity,affected_entity_id,details,ip_address) VALUES (?,?,?,?,?,?)")
                ->execute([$logged_in_id,'UPDATE_STATION','polling_stations',$id,"Updated polling station '$name'",$_SERVER['REMOTE_ADDR']??'']);
            echo json_encode(['success'=>true,'message'=>"Station '$name' updated successfully."]);
        } catch (PDOException $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }

    // ── GET BOOTHS FOR STATION ──────────────────────────────
    if ($act === 'get_booths') {
        $id = (int)($_POST['station_id']??0);
        $stmt = $pdo->prepare("
            SELECT pb.booth_id, pb.booth_number, pb.ballots_issued,
                   eo.full_name AS apo_name,
                   (SELECT COALESCE(SUM(br.votes_received),0) FROM booth_results br WHERE br.booth_id=pb.booth_id) AS total_votes,
                   (SELECT COUNT(*) FROM booth_results br WHERE br.booth_id=pb.booth_id) AS candidates_entered,
                   (SELECT MAX(br.is_locked) FROM booth_results br WHERE br.booth_id=pb.booth_id) AS is_locked
            FROM polling_booths pb
            LEFT JOIN election_officers eo ON eo.officer_id=pb.assistant_presiding_officer_id
            WHERE pb.station_id=?
            ORDER BY pb.booth_id
        ");
        $stmt->execute([$id]);
        echo json_encode(['success'=>true,'booths'=>$stmt->fetchAll()]);
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action.']); exit;
}

// ============================================================
//  CREATE NEW STATION (form POST)
// ============================================================
$form_success = $form_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_station'])) {
    $name    = trim($_POST['name']??'');
    $addr    = trim($_POST['address']??'');
    $cid     = (int)($_POST['constituency_id']??0) ?: null;
    $po      = (int)($_POST['presiding_officer_id']??0) ?: null;
    $ballots = (int)($_POST['total_ballots_issued']??0);
    $status  = $_POST['result_status']??'PENDING';
    $allowed = ['PENDING','SUBMITTED','VERIFIED'];
    if (!in_array($status,$allowed)) $status='PENDING';

    if (!$name) {
        $form_error = 'Station name is required.';
    } else {
        // === PO UNIQUENESS CONSTRAINT ===
        if ($po) {
            $chk = $pdo->prepare("SELECT station_id FROM polling_stations WHERE presiding_officer_id=?");
            $chk->execute([$po]);
            if ($chk->fetch()) {
                $form_error = 'This Presiding Officer is already assigned to another station. Each PO can only be assigned to one polling station.';
            }
        }

        if (!$form_error) {
            try {
                $pdo->prepare("INSERT INTO polling_stations (name,address,constituency_id,presiding_officer_id,total_ballots_issued,result_status) VALUES (?,?,?,?,?,?)")
                    ->execute([$name,$addr,$cid,$po,$ballots,$status]);
                $new_id = $pdo->lastInsertId();
                $pdo->prepare("INSERT INTO audit_logs (officer_id,action_type,affected_entity,affected_entity_id,details,ip_address) VALUES (?,?,?,?,?,?)")
                    ->execute([$logged_in_id,'CREATE_STATION','polling_stations',$new_id,"Created polling station '$name'",$_SERVER['REMOTE_ADDR']??'']);
                $form_success = "Polling Station <strong>$name</strong> created successfully.";
            } catch (PDOException $e) {
                $form_error = 'Error: '.$e->getMessage();
            }
        }
    }
}

// ============================================================
//  LOAD PAGE DATA
// ============================================================

// Admin info
$adminStmt = $pdo->prepare("SELECT * FROM election_officers WHERE officer_id=?");
$adminStmt->execute([$logged_in_id]);
$admin = $adminStmt->fetch();

// Dropdowns
$constituencies = $pdo->query("SELECT constituency_id,name,code FROM constituencies ORDER BY name")->fetchAll();
$pos = $pdo->query("SELECT officer_id,full_name FROM election_officers WHERE role='PO' AND is_active=1 ORDER BY full_name")->fetchAll();

// POs already assigned to a station: { officer_id => station_id }
$taken_po_map = $pdo->query("SELECT presiding_officer_id, station_id FROM polling_stations WHERE presiding_officer_id IS NOT NULL")->fetchAll(PDO::FETCH_KEY_PAIR);
$taken_po_ids = array_map('intval', array_keys($taken_po_map));
$js_taken_po_map = json_encode(array_map('intval', $taken_po_map));

// Voter count per station: { station_id => voter_count }
$voter_count_map = $pdo->query("
    SELECT ps.station_id, COUNT(v.voter_id) AS voter_count
    FROM polling_stations ps
    LEFT JOIN voters v ON v.polling_station_id = ps.station_id
    GROUP BY ps.station_id
")->fetchAll(PDO::FETCH_KEY_PAIR);
$js_voter_count_map = json_encode(array_map('intval', $voter_count_map));

// Search
// Search
$search = trim($_GET['q']??'');
$filter_const = (int)($_GET['const']??0);

$sqlBase = "
    SELECT ps.*,
           c.name  AS constituency_name,
           c.code  AS constituency_code,
           eo.full_name AS po_name,
           (SELECT COUNT(*) FROM polling_booths pb WHERE pb.station_id=ps.station_id) AS booth_count,
           (SELECT COALESCE(SUM(pb2.ballots_issued),0) FROM polling_booths pb2 WHERE pb2.station_id=ps.station_id) AS total_booth_ballots
    FROM polling_stations ps
    LEFT JOIN constituencies c ON c.constituency_id=ps.constituency_id
    LEFT JOIN election_officers eo ON eo.officer_id=ps.presiding_officer_id
";
$where = []; $params = [];

if ($search !== '') {
    $where[] = "(ps.name LIKE ? OR ps.address LIKE ?)";
    $params[] = '%'.$search.'%';
    $params[] = '%'.$search.'%';
}
if ($filter_const > 0) {
    $where[] = "ps.constituency_id=?";
    $params[] = $filter_const;
}
$sqlFull = $sqlBase . ($where ? " WHERE ".implode(" AND ",$where) : "") . " ORDER BY ps.station_id";
$stStmt = $pdo->prepare($sqlFull);
$stStmt->execute($params);
$stations = $stStmt->fetchAll();

// Stats
$total_stations  = $pdo->query("SELECT COUNT(*) FROM polling_stations")->fetchColumn();
$total_booths    = $pdo->query("SELECT COUNT(*) FROM polling_booths")->fetchColumn();
$verified_count  = $pdo->query("SELECT COUNT(*) FROM polling_stations WHERE result_status='VERIFIED'")->fetchColumn();
$submitted_count = $pdo->query("SELECT COUNT(*) FROM polling_stations WHERE result_status='SUBMITTED'OR result_status='VERIFIED'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Polling Station Management — EMS Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500;600&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<style>
/* ============================================================
   ROOT — identical to admin_dashboard + constituency_management
============================================================ */
:root {
    --navy:       #0a1628;
    --navy2:      #0f2044;
    --navy3:      #1a3060;
    --primary:    #1a56db;
    --primary2:   #1e40af;
    --accent:     #0ea5e9;
    --accent2:    #38bdf8;
    --gold:       #f59e0b;
    --gold2:      #fbbf24;
    --success:    #10b981;
    --success2:   #34d399;
    --danger:     #ef4444;
    --warning:    #f59e0b;
    --purple:     #8b5cf6;
    --teal:       #14b8a6;
    --surface:    #ffffff;
    --bg:         #f0f4f8;
    --bg2:        #e8eef5;
    --border:     #d1dae6;
    --border2:    #c4d0e0;
    --text:       #0f172a;
    --text2:      #334155;
    --muted:      #64748b;
    --muted2:     #94a3b8;
    --radius:     14px;
    --radius-sm:  8px;
    --shadow:     0 2px 16px rgba(10,22,40,.10);
    --shadow-lg:  0 8px 40px rgba(10,22,40,.18);
    --font-head:  'Syne', sans-serif;
    --font-body:  'DM Sans', sans-serif;
    --font-mono:  'IBM Plex Mono', monospace;  /* tighter numbers */
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { font-size: 15px; }
body {
    font-family: var(--font-body);
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}
a { text-decoration: none; color: inherit; }
button { cursor: pointer; font-family: var(--font-body); }

/* ============================================================
   TOPBAR
============================================================ */
.topbar {
    background: linear-gradient(135deg, var(--navy) 0%, var(--navy2) 60%, var(--navy3) 100%);
    padding: 0 32px;
    height: 66px;
    display: flex; align-items: center; justify-content: space-between;
    position: sticky; top: 0; z-index: 200;
    box-shadow: 0 2px 24px rgba(10,22,40,.35);
    border-bottom: 1px solid rgba(255,255,255,.08);
}
.topbar-brand { display: flex; align-items: center; gap: 14px; }
.brand-emblem {
    width: 40px; height: 40px;
    background: linear-gradient(135deg, var(--primary), var(--accent));
    border-radius: 10px; display: flex; align-items: center; justify-content: center;
    font-size: 20px; box-shadow: 0 2px 12px rgba(14,165,233,.3); flex-shrink: 0;
}
.brand-text { font-family: var(--font-head); font-size: 15px; font-weight: 700; color: #fff; letter-spacing: .2px; line-height: 1.2; }
.brand-sub  { font-size: 11px; color: rgba(255,255,255,.5); letter-spacing: .5px; text-transform: uppercase; }
.topbar-center { display: flex; align-items: center; gap: 8px; }
.live-chip {
    display: flex; align-items: center; gap: 6px;
    background: rgba(16,185,129,.15); border: 1px solid rgba(16,185,129,.3);
    border-radius: 20px; padding: 5px 14px;
    font-size: 11.5px; font-weight: 600; color: var(--success2);
    letter-spacing: .4px; text-transform: uppercase;
}
.live-dot {
    width: 7px; height: 7px; border-radius: 50%; background: var(--success2);
    animation: pulse-dot 1.6s infinite;
}
@keyframes pulse-dot { 0%,100%{opacity:1;transform:scale(1);} 50%{opacity:.5;transform:scale(1.4);} }
.election-tag {
    background: rgba(245,158,11,.12); border: 1px solid rgba(245,158,11,.28);
    border-radius: 20px; padding: 5px 14px;
    font-size: 11.5px; font-weight: 600; color: var(--gold2);
    letter-spacing: .4px; text-transform: uppercase;
}
.topbar-right { display: flex; align-items: center; gap: 14px; }
.admin-pill {
    display: flex; align-items: center; gap: 10px;
    background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.14);
    border-radius: 30px; padding: 5px 14px 5px 6px;
}
.admin-avatar {
    width: 32px; height: 32px; border-radius: 50%;
    background: linear-gradient(135deg, var(--primary), var(--accent));
    display: flex; align-items: center; justify-content: center;
    font-family: var(--font-head); font-size: 13px; font-weight: 700; color: #fff;
}
.admin-info .name     { font-size: 12.5px; font-weight: 600; color: #fff; line-height: 1.2; }
.admin-info .role-tag { font-size: 10px; color: var(--accent2); letter-spacing: .5px; text-transform: uppercase; font-weight: 500; }
.btn-logout {
    background: rgba(239,68,68,.12); border: 1px solid rgba(239,68,68,.25);
    color: #fca5a5; font-size: 12px; font-weight: 600;
    padding: 7px 16px; border-radius: 8px; transition: all .2s; letter-spacing: .3px;
}
.btn-logout:hover { background: rgba(239,68,68,.22); color: #fff; }

/* ============================================================
   SECONDARY NAVBAR
============================================================ */
.sub-nav {
    background: var(--surface);
    border-bottom: 2px solid var(--border);
    padding: 0 32px;
    display: flex; align-items: center; gap: 2px;
    overflow-x: auto;
    position: sticky; top: 66px; z-index: 100;
    box-shadow: 0 1px 8px rgba(10,22,40,.06);
}
.sub-nav-link {
    display: flex; align-items: center; gap: 6px;
    padding: 14px 16px;
    font-size: 12.5px; font-weight: 600;
    color: var(--muted);
    border-bottom: 2px solid transparent;
    margin-bottom: -2px; white-space: nowrap;
    transition: all .18s;
}
.sub-nav-link:hover { color: var(--primary); }
.sub-nav-link.active { color: var(--primary); border-bottom-color: var(--primary); background: rgba(26,86,219,.04); }

/* ============================================================
   PAGE WRAP
============================================================ */
.page-wrap {
    max-width: 1380px; margin: 0 auto;
    padding: 28px 28px 56px; width: 100%; flex: 1;
}

/* ============================================================
   PAGE HEADER
============================================================ */
.page-header {
    display: flex; align-items: flex-end; justify-content: space-between;
    margin-bottom: 24px; flex-wrap: wrap; gap: 14px;
}
.page-header-left { display: flex; align-items: center; gap: 16px; }
.page-header-icon {
    width: 52px; height: 52px;
    background: linear-gradient(135deg, var(--teal), var(--accent));
    border-radius: 14px; display: flex; align-items: center; justify-content: center;
    font-size: 24px; box-shadow: 0 4px 16px rgba(20,184,166,.28); flex-shrink: 0;
}
.page-title    { font-family: var(--font-head); font-size: 26px; font-weight: 800; color: var(--navy); line-height: 1.1; letter-spacing: -.3px; }
.page-subtitle { font-size: 13px; color: var(--muted); margin-top: 4px; }
.breadcrumb    { font-size: 11.5px; color: var(--muted2); margin-bottom: 4px; }
.breadcrumb a  { color: var(--primary); font-weight: 600; }
.breadcrumb a:hover { text-decoration: underline; }
.header-actions { display: flex; gap: 10px; flex-wrap: wrap; }

/* ============================================================
   BUTTONS
============================================================ */
.btn {
    display: inline-flex; align-items: center; gap: 7px;
    font-size: 13px; font-weight: 600; padding: 9px 18px;
    border-radius: var(--radius-sm); border: none;
    transition: all .2s; cursor: pointer; white-space: nowrap;
    font-family: var(--font-body); letter-spacing: .2px;
}
.btn-primary   { background: linear-gradient(135deg, var(--primary), var(--primary2)); color: #fff; box-shadow: 0 2px 10px rgba(26,86,219,.25); }
.btn-primary:hover   { transform: translateY(-1px); box-shadow: 0 4px 18px rgba(26,86,219,.35); }
.btn-teal      { background: linear-gradient(135deg, var(--teal), var(--accent)); color: #fff; box-shadow: 0 2px 10px rgba(14,165,233,.2); }
.btn-teal:hover      { transform: translateY(-1px); box-shadow: 0 4px 18px rgba(14,165,233,.3); }
.btn-secondary { background: var(--surface); color: var(--text2); border: 1.5px solid var(--border); }
.btn-secondary:hover { border-color: var(--primary); color: var(--primary); background: #eff6ff; }
.btn-danger    { background: rgba(239,68,68,.08); color: var(--danger); border: 1.5px solid rgba(239,68,68,.25); }
.btn-danger:hover    { background: var(--danger); color: #fff; }
.btn-success   { background: rgba(16,185,129,.1); color: var(--success); border: 1.5px solid rgba(16,185,129,.3); }
.btn-success:hover   { background: var(--success); color: #fff; }
.btn-info      { background: rgba(14,165,233,.1); color: var(--accent); border: 1.5px solid rgba(14,165,233,.3); }
.btn-info:hover      { background: var(--accent); color: #fff; }
.btn-sm  { font-size: 12px; padding: 6px 13px; }
.btn-xs  { font-size: 11px; padding: 4px 10px; border-radius: 6px; }

/* ============================================================
   STAT TILES — fixed font to IBM Plex Mono for numbers
============================================================ */
.stat-strip {
    display: grid; grid-template-columns: repeat(4,1fr);
    gap: 16px; margin-bottom: 22px;
}
@media(max-width:960px){ .stat-strip{grid-template-columns:repeat(2,1fr);} }

.stat-tile {
    background: var(--surface); border-radius: var(--radius);
    border: 1.5px solid var(--border); padding: 20px 22px;
    position: relative; overflow: hidden; transition: all .22s;
    animation: fadeUp .4s ease both;
}
.stat-tile:hover { transform: translateY(-3px); box-shadow: var(--shadow-lg); border-color: var(--border2); }
.stat-tile:nth-child(1){animation-delay:.05s}
.stat-tile:nth-child(2){animation-delay:.10s}
.stat-tile:nth-child(3){animation-delay:.15s}
.stat-tile:nth-child(4){animation-delay:.20s}

.st-icon {
    width: 44px; height: 44px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; margin-bottom: 12px;
}
.st-icon.blue   { background: #dbeafe; }
.st-icon.green  { background: #dcfce7; }
.st-icon.gold   { background: #fef3c7; }
.st-icon.teal   { background: #ccfbf1; }
.st-icon.purple { background: #ede9fe; }

/* NUMBER: IBM Plex Mono — compact, clear, not stretchy */
.st-value {
    font-family: var(--font-mono);
    font-size: 26px;
    font-weight: 600;
    letter-spacing: -.5px;
    line-height: 1;
    margin-bottom: 5px;
    color: var(--text);
}
.st-value.blue   { color: var(--primary); }
.st-value.green  { color: var(--success); }
.st-value.gold   { color: var(--gold); }
.st-value.teal   { color: var(--teal); }
.st-value.purple { color: var(--purple); }

.st-label { font-size: 11.5px; font-weight: 600; text-transform: uppercase; letter-spacing: .6px; color: var(--muted); }
.st-delta { position: absolute; top: 16px; right: 16px; font-size: 11px; font-weight: 600; padding: 3px 9px; border-radius: 20px; }
.st-delta.up   { background: #dcfce7; color: var(--success); }
.st-delta.info { background: #dbeafe; color: var(--primary); }
.st-delta.teal { background: #ccfbf1; color: var(--teal); }
.st-delta.gold { background: #fef3c7; color: var(--gold); }

/* ============================================================
   CARDS
============================================================ */
.card {
    background: var(--surface); border-radius: var(--radius);
    border: 1.5px solid var(--border); overflow: hidden;
    box-shadow: var(--shadow); margin-bottom: 22px;
}
.card-header {
    padding: 18px 24px 14px; display: flex; align-items: center;
    justify-content: space-between; border-bottom: 1px solid var(--border);
    background: #fafbfc; flex-wrap: wrap; gap: 10px;
}
.card-title { font-family: var(--font-head); font-size: 14.5px; font-weight: 700; color: var(--text); display: flex; align-items: center; gap: 8px; }
.card-body  { padding: 22px 24px; }
.card-badge { font-size: 11px; font-weight: 600; padding: 3px 10px; border-radius: 20px; }
.badge-blue  { background: #dbeafe; color: var(--primary); }
.badge-green { background: #dcfce7; color: var(--success); }
.badge-teal  { background: #ccfbf1; color: var(--teal); }
.count-pill {
    display: inline-flex; align-items: center; justify-content: center;
    background: var(--bg2); border: 1px solid var(--border);
    border-radius: 20px; font-size: 12px; font-weight: 700;
    color: var(--text2); padding: 2px 10px;
    font-family: var(--font-mono);
}

/* ============================================================
   FORM INPUTS
============================================================ */
.form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.form-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
@media(max-width:768px){ .form-grid-2,.form-grid-3{grid-template-columns:1fr;} }
.form-group  { display: flex; flex-direction: column; gap: 6px; }
.form-label  { font-size: 11.5px; font-weight: 700; color: var(--text2); letter-spacing: .4px; text-transform: uppercase; }
.form-label .req { color: var(--danger); margin-left: 2px; }
.form-control {
    padding: 10px 14px; border: 1.5px solid var(--border);
    border-radius: var(--radius-sm); font-size: 13.5px;
    font-family: var(--font-body); color: var(--text);
    background: var(--surface); transition: border-color .18s, box-shadow .18s;
    outline: none; width: 100%;
}
.form-control:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,86,219,.12); }
.form-control::placeholder { color: var(--muted2); }
.form-helper {
    font-size: 11.5px; color: var(--muted); margin-top: 16px;
    padding: 10px 14px; background: #f0fdf9;
    border-radius: var(--radius-sm); border-left: 3px solid var(--teal);
}

/* ============================================================
   SEARCH / FILTER BAR
============================================================ */
.filter-bar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.search-input-wrap { position: relative; flex: 1; min-width: 220px; }
.search-input-wrap .si { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: var(--muted2); font-size: 14px; pointer-events: none; }
.search-input-wrap input {
    width: 100%; padding: 10px 14px 10px 38px;
    border: 1.5px solid var(--border); border-radius: var(--radius-sm);
    font-size: 13.5px; font-family: var(--font-body); color: var(--text);
    background: var(--surface); outline: none; transition: border-color .18s, box-shadow .18s;
}
.search-input-wrap input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,86,219,.10); }
.filter-select {
    padding: 10px 14px; border: 1.5px solid var(--border);
    border-radius: var(--radius-sm); font-size: 13.5px;
    font-family: var(--font-body); color: var(--text);
    background: var(--surface); outline: none; min-width: 190px;
    transition: border-color .18s;
}
.filter-select:focus { border-color: var(--primary); }

/* ============================================================
   MANAGEMENT TABLE
============================================================ */
.table-wrap { overflow-x: auto; }
.mgmt-table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
.mgmt-table thead tr { background: #f1f5f9; }
.mgmt-table th {
    padding: 11px 16px; text-align: left;
    font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .6px; color: var(--muted);
    border-bottom: 2px solid var(--border); white-space: nowrap;
}
.mgmt-table td { padding: 13px 16px; border-bottom: 1px solid var(--border); vertical-align: middle; }
.mgmt-table tbody tr { transition: background .15s; }
.mgmt-table tbody tr:hover { background: #f8fafc; }
.mgmt-table tbody tr:last-child td { border-bottom: none; }

/* Editing row */
.editing-row { background: #eff6ff !important; outline: 2px solid var(--primary); outline-offset: -1px; }
.editing-row td { background: #eff6ff; }
.edit-input {
    padding: 7px 10px; border: 1.5px solid var(--primary);
    border-radius: 6px; font-size: 13px; font-family: var(--font-body);
    color: var(--text); width: 100%; outline: none; min-width: 90px;
    box-shadow: 0 0 0 2px rgba(26,86,219,.12);
}
.edit-select {
    padding: 7px 10px; border: 1.5px solid var(--primary);
    border-radius: 6px; font-size: 13px; font-family: var(--font-body);
    color: var(--text); outline: none; min-width: 110px;
    box-shadow: 0 0 0 2px rgba(26,86,219,.12); background: #fff;
}

/* Cell styles */
.cell-main  { font-weight: 700; color: var(--text); font-size: 13.5px; }
.cell-sub   { font-size: 11px; color: var(--muted); margin-top: 2px; }
.id-badge   { font-family: var(--font-mono); font-size: 12px; font-weight: 600; background: var(--bg2); border: 1px solid var(--border); border-radius: 6px; padding: 3px 8px; color: var(--text2); }
.const-tag  { font-size: 11.5px; font-weight: 700; background: #dbeafe; color: var(--primary); border-radius: 6px; padding: 3px 9px; letter-spacing: .3px; }
/* Numbers in table: mono font, compact */
.num-cell   { font-family: var(--font-mono); font-size: 14px; font-weight: 600; color: var(--text); letter-spacing: -.3px; }
.num-sub    { font-size: 10.5px; color: var(--muted); margin-top: 1px; font-family: var(--font-body); }

/* Booth expand badge */
.booth-badge {
    display: inline-flex; align-items: center; gap: 5px;
    background: #ccfbf1; color: #0f766e;
    border: 1px solid #99f6e4; border-radius: 6px;
    font-size: 11.5px; font-weight: 700; padding: 3px 9px;
    cursor: pointer; transition: all .18s;
}
.booth-badge:hover { background: var(--teal); color: #fff; border-color: var(--teal); }

/* Status pills */
.status-pill {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 12px; border-radius: 20px;
    font-size: 11.5px; font-weight: 700; letter-spacing: .3px; white-space: nowrap;
}
.sp-verified  { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
.sp-submitted { background: #dbeafe; color: #1e40af; border: 1px solid #93c5fd; }
.sp-pending   { background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; }

.action-row { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }

/* ============================================================
   EXPAND PANEL — Polling Booths
============================================================ */
.expand-row { display: none; }
.expand-row.open { display: table-row; }
.expand-cell { padding: 0 !important; border-bottom: 2px solid var(--teal) !important; }
.expand-inner {
    background: linear-gradient(180deg, #f0fdf9 0%, #f7fffd 100%);
    border-top: 1px solid #99f6e4;
    padding: 20px 24px;
    animation: expandDown .22s ease;
}
@keyframes expandDown { from{opacity:0;transform:translateY(-8px);} to{opacity:1;transform:translateY(0);} }

.expand-header {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 14px; flex-wrap: wrap; gap: 10px;
}
.expand-title {
    font-family: var(--font-head); font-size: 13.5px; font-weight: 700; color: var(--navy);
    display: flex; align-items: center; gap: 8px;
}
.hierarchy-note {
    font-size: 11.5px; color: #0f766e; background: #ccfbf1;
    border: 1px solid #99f6e4; border-radius: 6px; padding: 5px 12px;
    display: inline-flex; align-items: center; gap: 6px;
}

.booth-table { width: 100%; border-collapse: collapse; font-size: 13px; border-radius: var(--radius-sm); overflow: hidden; border: 1.5px solid #99f6e4; }
.booth-table thead tr { background: #ccfbf1; }
.booth-table th {
    padding: 10px 14px; text-align: left;
    font-size: 10.5px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .5px; color: #0f766e;
    border-bottom: 1.5px solid #99f6e4;
}
.booth-table td { padding: 11px 14px; border-bottom: 1px solid #d1fae5; vertical-align: middle; }
.booth-table tbody tr:hover { background: #f0fdf9; }
.booth-table tbody tr:last-child td { border-bottom: none; }
.booth-num   { font-family: var(--font-mono); font-weight: 600; font-size: 13px; color: var(--text); }
.booth-votes { font-family: var(--font-mono); font-weight: 600; font-size: 13px; color: var(--primary); }
.locked-badge   { background: #fee2e2; color: var(--danger); border: 1px solid #fca5a5; border-radius: 20px; padding: 3px 10px; font-size: 11px; font-weight: 700; }
.unlocked-badge { background: #f1f5f9; color: var(--muted); border: 1px solid var(--border); border-radius: 20px; padding: 3px 10px; font-size: 11px; font-weight: 700; }

/* ============================================================
   PAGINATION
============================================================ */
.pagination-bar {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 24px; border-top: 1px solid var(--border);
    font-size: 12.5px; color: var(--muted); flex-wrap: wrap; gap: 10px;
}
.pagination { display: flex; gap: 4px; }
.pag-btn {
    width: 32px; height: 32px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; font-weight: 600; cursor: pointer;
    background: var(--surface); border: 1.5px solid var(--border); color: var(--text2);
    transition: all .18s;
}
.pag-btn:hover  { border-color: var(--primary); color: var(--primary); background: #eff6ff; }
.pag-btn.active { background: var(--primary); color: #fff; border-color: var(--primary); }
.pag-btn.disabled { opacity: .4; pointer-events: none; }

/* ============================================================
   DELETE MODAL
============================================================ */
.modal-overlay {
    position: fixed; inset: 0;
    background: rgba(10,22,40,.55); backdrop-filter: blur(3px);
    z-index: 500; display: none;
    align-items: center; justify-content: center;
}
.modal-overlay.open { display: flex; }
.modal-box {
    background: var(--surface); border-radius: 18px;
    padding: 32px 28px; max-width: 420px; width: 90%;
    box-shadow: var(--shadow-lg); animation: popIn .22s ease;
}
@keyframes popIn { from{opacity:0;transform:scale(.93);} to{opacity:1;transform:scale(1);} }
.modal-icon   { font-size: 44px; text-align: center; margin-bottom: 14px; }
.modal-title  { font-family: var(--font-head); font-size: 18px; font-weight: 800; color: var(--text); text-align: center; margin-bottom: 10px; }
.modal-body   { font-size: 13.5px; color: var(--muted); text-align: center; line-height: 1.6; margin-bottom: 24px; }
.modal-actions { display: flex; gap: 10px; justify-content: center; }

/* ============================================================
   TOAST
============================================================ */
#toast { position: fixed; bottom: 28px; right: 28px; z-index: 999; display: flex; flex-direction: column; gap: 8px; }
.toast-msg {
    background: #1e293b; color: #fff; border-radius: 10px;
    padding: 12px 20px; font-size: 13px; font-weight: 500;
    display: flex; align-items: center; gap: 10px;
    box-shadow: 0 4px 20px rgba(0,0,0,.2); animation: slideIn .3s ease;
}
.toast-msg.success { background: #166534; }
.toast-msg.error   { background: #991b1b; }
.toast-msg.warning { background: #92400e; }
@keyframes slideIn { from{transform:translateY(20px);opacity:0;} to{transform:translateY(0);opacity:1;} }

/* ============================================================
   ALERTS
============================================================ */
.alert { padding: 12px 16px; border-radius: var(--radius-sm); font-size: 13.5px; font-weight: 500; margin-bottom: 18px; display: flex; align-items: center; gap: 10px; }
.alert-success { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
.alert-error   { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }

/* ============================================================
   FOOTER
============================================================ */
.footer {
    background: var(--navy); padding: 18px 32px;
    display: flex; align-items: center; justify-content: space-between;
    font-size: 12px; color: #64748b;
    margin-top: auto; flex-wrap: wrap; gap: 8px;
}
.footer-links { display: flex; gap: 18px; }
.footer-links a { color: #64748b; text-decoration: none; transition: color .2s; }
.footer-links a:hover { color: #60a5fa; }

/* ============================================================
   ANIMATIONS
============================================================ */
@keyframes fadeUp { from{opacity:0;transform:translateY(18px);} to{opacity:1;transform:translateY(0);} }
</style>
</head>
<body>

<!-- ═══════════════════════════════
     TOPBAR
═══════════════════════════════ -->
<nav class="topbar">
    <div class="topbar-brand">
        <div class="brand-emblem">🗳️</div>
        <div>
            <div class="brand-text">Bangladesh Election Commission</div>
            <div class="brand-sub">Election Management System &nbsp;·&nbsp; v2.6.0</div>
        </div>
    </div>
    <div class="topbar-center">
        <div class="live-chip"><div class="live-dot"></div>System Live</div>
        <div class="election-tag">⚡ General Election 2026</div>
    </div>
    <div class="topbar-right">
        <div class="admin-pill">
            <div class="admin-avatar"><?= strtoupper(substr($admin['full_name'],0,1)) ?></div>
            <div class="admin-info">
                <div class="name"><?= htmlspecialchars($admin['full_name']) ?></div>
                <div class="role-tag">System Administrator</div>
            </div>
        </div>
        <button class="btn-logout" onclick="if(confirm('Logout from EMS?')){window.location='../logout.php'}">⏻ Logout</button>
    </div>
</nav>

<!-- ═══════════════════════════════
     SECONDARY NAVBAR
═══════════════════════════════ -->
<nav class="sub-nav">
    <a class="sub-nav-link" href="admin_dashboard.php">🏠 Dashboard</a>
    <a class="sub-nav-link" href="constituency_management.php">🗺️ Constituencies</a>
    <a class="sub-nav-link active" href="polling_station_management.php">🏫 Polling Stations</a>
    <a class="sub-nav-link" href="booth_management.php">🚪 Booth Management</a>
    <a class="sub-nav-link" href="candidate_management.php">🎖️ Candidates</a>
    <a class="sub-nav-link" href="party_management.php">🏳️ Political Parties</a>
    <a class="sub-nav-link" href="officer_management.php">👮 Officers</a>
    <a class="sub-nav-link " href="voter_management.php">🧑‍🤝‍🧑 Voters</a>
    
</nav>

<!-- ═══════════════════════════════
     PAGE CONTENT
═══════════════════════════════ -->
<div class="page-wrap">

    <!-- PAGE HEADER -->
    <div class="page-header">
        <div class="page-header-left">
            <div class="page-header-icon">🏫</div>
            <div>
                <div class="breadcrumb"><a href="admin_dashboard.php">Dashboard</a> / Polling Station Management</div>
                <div class="page-title">Polling Station Management</div>
                <div class="page-subtitle">Manage polling stations, presiding officers, ballot issuance, and assigned booths.</div>
            </div>
        </div>
        <div class="header-actions">
            <button class="btn btn-teal" onclick="toggleForm()">➕ Add Station</button>
            <button class="btn btn-secondary" onclick="location.reload()">🔄 Refresh</button>
            <a href="admin_dashboard.php" class="btn btn-secondary">← Dashboard</a>
        </div>
    </div>

    <!-- STAT STRIP -->
    <div class="stat-strip">
        <div class="stat-tile">
            <div class="st-delta info">Total</div>
            <div class="st-icon blue">🏫</div>
            <div class="st-value blue"><?= number_format((int)$total_stations) ?></div>
            <div class="st-label">Polling Stations</div>
        </div>
        <div class="stat-tile">
            <div class="st-delta teal">All Active</div>
            <div class="st-icon teal">🚪</div>
            <div class="st-value teal"><?= number_format((int)$total_booths) ?></div>
            <div class="st-label">Polling Booths</div>
        </div>
        <div class="stat-tile">
            <div class="st-delta up">Cleared</div>
            <div class="st-icon green">✅</div>
            <div class="st-value green"><?= number_format((int)$verified_count) ?></div>
            <div class="st-label">Stations Verified</div>
        </div>
        <div class="stat-tile">
            <div class="st-delta gold">Awaiting</div>
            <div class="st-icon gold">📤</div>
            <div class="st-value gold"><?= number_format((int)$submitted_count) ?></div>
            <div class="st-label">Results Submitted</div>
        </div>
    </div>

    <!-- PHP FEEDBACK -->
    <?php if ($form_success): ?>
    <div class="alert alert-success">✅ <?= $form_success ?></div>
    <?php endif; ?>
    <?php if ($form_error): ?>
    <div class="alert alert-error">❌ <?= htmlspecialchars($form_error) ?></div>
    <?php endif; ?>

    <!-- ADD STATION FORM -->
    <div class="card" id="addFormCard" style="display:none; border-color: var(--teal);">
        <div class="card-header">
            <span class="card-title">🏫 Add New Polling Station</span>
            <button class="btn btn-sm btn-secondary" onclick="toggleForm()">✕ Close</button>
        </div>
        <div class="card-body">
            <form method="POST" action="" onsubmit="return validateAddForm()">
                <div class="form-grid-3" style="margin-bottom:16px;">
                    <div class="form-group">
                        <label class="form-label">Station Name <span class="req">*</span></label>
                        <input type="text" name="name" class="form-control" placeholder="e.g. Dhanmondi Govt. School" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Constituency</label>
                        <select name="constituency_id" class="form-control">
                            <option value="">— Select Constituency —</option>
                            <?php foreach ($constituencies as $con): ?>
                            <option value="<?= $con['constituency_id'] ?>"><?= htmlspecialchars($con['name']) ?> (<?= htmlspecialchars($con['code']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Presiding Officer</label>
                        <select name="presiding_officer_id" class="form-control">
                            <option value="">— Not Assigned —</option>
                            <?php foreach ($pos as $po):
                                $is_taken = in_array((int)$po['officer_id'], $taken_po_ids);
                            ?>
                            <option value="<?= $po['officer_id'] ?>" <?= $is_taken ? 'data-taken="1"' : '' ?>>
                                <?= htmlspecialchars($po['full_name']) ?><?= $is_taken ? ' ⚠ Already Assigned' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-grid-3" style="margin-bottom:18px;">
                    <div class="form-group" style="grid-column: span 2;">
                        <label class="form-label">Address</label>
                        <input type="text" name="address" class="form-control" placeholder="e.g. Road 12, Dhanmondi, Dhaka">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Total Ballots Issued</label>
                        <input type="number" name="total_ballots_issued" class="form-control" placeholder="e.g. 1000" min="0">
                    </div>
                </div>
                <div class="form-grid-2" style="margin-bottom:18px;">
                    <div class="form-group">
                        <label class="form-label">Result Status</label>
                        <select name="result_status" class="form-control">
                            <option value="PENDING">PENDING</option>
                            <option value="SUBMITTED">SUBMITTED</option>
                            <option value="VERIFIED">VERIFIED</option>
                        </select>
                    </div>
                </div>
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <button type="submit" name="create_station" class="btn btn-teal">💾 Save Station</button>
                    <button type="reset" class="btn btn-secondary">↺ Reset Form</button>
                </div>
                <div class="form-helper">💡 Each polling station must belong to one constituency. Assign a Presiding Officer (PO) to enable result submission and verification.</div>
            </form>
        </div>
    </div>

    <!-- SEARCH & FILTER BAR -->
    <div class="card">
        <div class="card-body" style="padding:16px 22px;">
            <form method="GET" action="">
                <div class="filter-bar">
                    <div class="search-input-wrap">
                        <span class="si">🔍</span>
                        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                               placeholder="Search by station name or address…">
                    </div>
                    <select name="const" class="filter-select">
                        <option value="">All Constituencies</option>
                        <?php foreach ($constituencies as $con): ?>
                        <option value="<?= $con['constituency_id'] ?>" <?= $filter_const == $con['constituency_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($con['name']) ?> (<?= htmlspecialchars($con['code']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-primary">Search</button>
                    <?php if ($search || $filter_const): ?>
                    <a href="polling_station_management.php" class="btn btn-secondary">✕ Clear</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- MAIN TABLE CARD -->
    <div class="card">
        <div class="card-header">
            <span class="card-title">🏫 Polling Station Records
                <?php if ($search || $filter_const): ?>
                <span style="font-size:12px;font-weight:400;color:var(--muted);">Filtered results</span>
                <?php endif; ?>
            </span>
            <span class="count-pill"><?= count($stations) ?> found</span>
        </div>

        <div class="table-wrap">
            <table class="mgmt-table" id="stationTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Station Name</th>
                        <th>Constituency</th>
                        <th>Presiding Officer</th>
                        <th>Address</th>
                        <th>Ballots</th>
                        <th>Booths</th>
                        <th>Status</th>
                        <th style="min-width:250px;">Actions</th>
                    </tr>
                </thead>
                <tbody>

                <?php if (empty($stations)): ?>
                <tr>
                    <td colspan="9" style="text-align:center;padding:40px;color:var(--muted);">
                        No polling stations found<?= ($search||$filter_const) ? ' for this filter' : '' ?>.
                    </td>
                </tr>
                <?php endif; ?>

                <?php foreach ($stations as $st):
                    $sid    = (int)$st['station_id'];
                    $status = $st['result_status'];
                    $spClass = ['VERIFIED'=>'sp-verified','SUBMITTED'=>'sp-submitted','PENDING'=>'sp-pending'][$status] ?? 'sp-pending';
                    $spDot   = ['VERIFIED'=>'✅','SUBMITTED'=>'📤','PENDING'=>'⏳'][$status] ?? '⏳';
                ?>

                <!-- DISPLAY ROW -->
                <tr class="data-row" id="row-<?= $sid ?>">
                    <td><span class="id-badge">#<?= $sid ?></span></td>
                    <td>
                        <div class="cell-main"><?= htmlspecialchars($st['name']) ?></div>
                    </td>
                    <td>
                        <?php if ($st['constituency_name']): ?>
                        <span class="const-tag"><?= htmlspecialchars($st['constituency_code']) ?></span>
                        <div class="cell-sub" style="margin-top:4px;"><?= htmlspecialchars($st['constituency_name']) ?></div>
                        <?php else: ?>
                        <span style="color:var(--muted2);font-size:12px;">— Not Set —</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($st['po_name']): ?>
                        <div class="cell-main" style="font-size:13px;"><?= htmlspecialchars($st['po_name']) ?></div>
                        <div class="cell-sub">Presiding Officer</div>
                        <?php else: ?>
                        <span style="color:var(--muted2);font-size:12px;">— None —</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="font-size:12.5px;color:var(--text2);max-width:160px;line-height:1.4;">
                            <?= $st['address'] ? htmlspecialchars($st['address']) : '<span style="color:var(--muted2);">—</span>' ?>
                        </div>
                    </td>
                    <td>
                        <div class="num-cell"><?= number_format((int)$st['total_ballots_issued']) ?></div>
                        <div class="num-sub">issued</div>
                    </td>
                    <td>
                        <span class="booth-badge" onclick="toggleBooths(<?= $sid ?>)" id="boothBadge-<?= $sid ?>">
                            🚪 <?= (int)$st['booth_count'] ?> Booths ▾
                        </span>
                    </td>
                    <td>
                        <span class="status-pill <?= $spClass ?>"><?= $spDot ?> <?= $status ?></span>
                    </td>
                    <td>
                        <div class="action-row">
                            <button class="btn btn-sm btn-secondary" onclick="startEdit(<?= $sid ?>)">✏️ Edit</button>
                            <button class="btn btn-sm btn-danger" onclick="confirmDelete(<?= $sid ?>, '<?= htmlspecialchars(addslashes($st['name'])) ?>')">🗑️</button>
                            <button class="btn btn-sm btn-info" onclick="toggleBooths(<?= $sid ?>)">🚪 Booths</button>
                        </div>
                    </td>
                </tr>

                <!-- EDIT ROW -->
                <tr class="editing-row" id="editrow-<?= $sid ?>" style="display:none;">
                    <td><span class="id-badge">#<?= $sid ?></span></td>
                    <td>
                        <input class="edit-input" id="edit-name-<?= $sid ?>" value="<?= htmlspecialchars($st['name']) ?>" placeholder="Station name" style="min-width:150px;">
                    </td>
                    <td>
                        <select class="edit-select" id="edit-cid-<?= $sid ?>">
                            <option value="">— None —</option>
                            <?php foreach ($constituencies as $con): ?>
                            <option value="<?= $con['constituency_id'] ?>" <?= $con['constituency_id']==$st['constituency_id']?'selected':'' ?>>
                                <?= htmlspecialchars($con['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <select class="edit-select" id="edit-po-<?= $sid ?>">
                            <option value="">— None —</option>
                            <?php foreach ($pos as $po):
                                $pid = (int)$po['officer_id'];
                                // Taken by a DIFFERENT station (own current assignment is allowed)
                                $taken_by_other = isset($taken_po_map[$pid]) && (int)$taken_po_map[$pid] !== $sid;
                            ?>
                            <option value="<?= $pid ?>"
                                <?= $pid == $st['presiding_officer_id'] ? 'selected' : '' ?>
                                <?= $taken_by_other ? 'disabled' : '' ?>>
                                <?= htmlspecialchars($po['full_name']) ?><?= $taken_by_other ? ' ⚠ Already Assigned' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <input class="edit-input" id="edit-addr-<?= $sid ?>" value="<?= htmlspecialchars($st['address']??'') ?>" placeholder="Address" style="min-width:140px;">
                    </td>
                    <td>
                        <?php $vc = (int)($voter_count_map[$sid] ?? 0); ?>
                        <input class="edit-input" type="number" id="edit-ballots-<?= $sid ?>"
                               value="<?= (int)$st['total_ballots_issued'] ?>"
                               min="<?= $vc > 0 ? $vc : 0 ?>"
                               max="<?= $vc > 0 ? $vc : '' ?>"
                               data-voters="<?= $vc ?>"
                               style="min-width:90px;"
                               title="<?= $vc > 0 ? "Must be exactly $vc (registered voters)" : 'No voters linked yet' ?>">
                    </td>
                    <td>
                        <span class="booth-badge">🚪 <?= (int)$st['booth_count'] ?></span>
                    </td>
                    <td>
                        <select class="edit-select" id="edit-status-<?= $sid ?>">
                            <?php foreach (['PENDING','SUBMITTED','VERIFIED'] as $s): ?>
                            <option value="<?= $s ?>" <?= $s===$status?'selected':'' ?>><?= $s ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <div class="action-row">
                            <button class="btn btn-sm btn-success" onclick="saveEdit(<?= $sid ?>)">💾 Save</button>
                            <button class="btn btn-sm btn-secondary" onclick="cancelEdit(<?= $sid ?>)">✕ Cancel</button>
                        </div>
                    </td>
                </tr>

                <!-- EXPAND ROW — Polling Booths -->
                <tr class="expand-row" id="expand-<?= $sid ?>">
                    <td class="expand-cell" colspan="9">
                        <div class="expand-inner">
                            <div class="expand-header">
                                <div class="expand-title">
                                    🚪 Polling Booths under <strong><?= htmlspecialchars($st['name']) ?></strong>
                                </div>
                                <span class="hierarchy-note">ℹ️ Constituency → Polling Station → Booth</span>
                            </div>
                            <div id="booths-content-<?= $sid ?>">
                                <div style="text-align:center;padding:24px;color:var(--muted);font-size:13px;">Loading booths…</div>
                            </div>
                            <div style="margin-top:10px;font-size:11.5px;color:var(--muted);">
                                📌 Each polling booth belongs to exactly one polling station. To manage booths in detail, visit Booth Management.
                            </div>
                        </div>
                    </td>
                </tr>

                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- PAGINATION -->
        <div class="pagination-bar">
            <div>Showing <strong><?= count($stations) ?></strong> polling station records<?= ($search||$filter_const) ? ' (filtered)' : '' ?></div>
            <div class="pagination">
                <div class="pag-btn disabled">‹</div>
                <div class="pag-btn active">1</div>
                <div class="pag-btn disabled">›</div>
            </div>
        </div>
    </div>

</div><!-- /page-wrap -->

<!-- ═══════════════════════════════
     DELETE MODAL
═══════════════════════════════ -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal-box">
        <div class="modal-icon">⚠️</div>
        <div class="modal-title">Delete Polling Station?</div>
        <div class="modal-body">
            Are you sure you want to remove <strong id="deleteStName">this station</strong>?<br>
            Deleting it will also affect linked <strong>polling booths</strong>, <strong>booth results</strong>, and <strong>station result records</strong>. This action <strong>cannot be undone.</strong>
        </div>
        <div class="modal-actions">
            <button class="btn btn-danger" id="confirmDeleteBtn">🗑️ Confirm Delete</button>
            <button class="btn btn-secondary" onclick="closeDeleteModal()">✕ Cancel</button>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════
     FOOTER
═══════════════════════════════ -->
<footer class="footer">
    <div>🗳️ EMS Admin &nbsp;·&nbsp; Polling Station Management &nbsp;·&nbsp; © 2026 Bangladesh Election Commission. All rights reserved.</div>
    <div class="footer-links">
        <a href="#">Privacy Policy</a>
        <a href="#">Security Audit</a>
        <a href="#">Help &amp; Documentation</a>
        <a href="#">Contact Support</a>
    </div>
</footer>

<div id="toast"></div>

<script>
const TAKEN_PO_MAP = <?= $js_taken_po_map ?>;
const VOTER_COUNT_MAP = <?= $js_voter_count_map ?>;
// ── TOAST ───────────────────────────────────────────────────
function showToast(msg, type = 'info') {
    const container = document.getElementById('toast');
    const el = document.createElement('div');
    el.className = 'toast-msg ' + type;
    const icons = { success:'✅ ', error:'❌ ', warning:'⚠️ ', info:'ℹ️ ' };
    el.innerHTML = (icons[type] || 'ℹ️ ') + msg;
    container.appendChild(el);
    setTimeout(() => { el.style.opacity = '0'; el.style.transition = 'opacity .4s'; setTimeout(() => el.remove(), 400); }, 4000);
}

// ── ADD FORM VALIDATION ─────────────────────────────────────
function validateAddForm() {
    const po      = parseInt(document.querySelector('select[name="presiding_officer_id"]').value) || 0;
    const ballots = parseInt(document.querySelector('input[name="total_ballots_issued"]').value) || 0;

    if (po && TAKEN_PO_MAP.hasOwnProperty(po)) {
        showToast('⚠️ This Presiding Officer is already assigned to another station.', 'warning');
        return false;
    }
    // For add form: no station_id yet, so we can't look up voters.station_id.
    // Warn if ballots seems unreasonably large (>0 sanity check only; server enforces on edit).
    if (ballots < 0) {
        showToast('⚠️ Ballots issued cannot be negative.', 'warning');
        return false;
    }
    return true;
}

// ── ADD FORM TOGGLE ─────────────────────────────────────────
function toggleForm() {
    const card = document.getElementById('addFormCard');
    const open = card.style.display !== 'none';
    card.style.display = open ? 'none' : 'block';
    if (!open) card.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ── INLINE EDIT ─────────────────────────────────────────────
function startEdit(sid) {
    document.getElementById('row-' + sid).style.display = 'none';
    document.getElementById('editrow-' + sid).style.display = 'table-row';
    closeBooths(sid);
}
function cancelEdit(sid) {
    document.getElementById('editrow-' + sid).style.display = 'none';
    document.getElementById('row-' + sid).style.display = 'table-row';
}
function saveEdit(sid) {
    const name    = document.getElementById('edit-name-'    + sid).value.trim();
    const addr    = document.getElementById('edit-addr-'    + sid).value.trim();
    const cid     = document.getElementById('edit-cid-'     + sid).value;
    const po      = document.getElementById('edit-po-'      + sid).value;
    const ballots = document.getElementById('edit-ballots-' + sid).value;
    const status  = document.getElementById('edit-status-'  + sid).value;

   if (!name) { showToast('Station name is required.', 'warning'); return; }

    // PO uniqueness check: taken by a different station?
    const poVal = parseInt(po) || 0;
    if (poVal && TAKEN_PO_MAP.hasOwnProperty(poVal) && TAKEN_PO_MAP[poVal] !== sid) {
        showToast('⚠️ This Presiding Officer is already assigned to another station.', 'warning');
        return;
    }

    const ballotsVal = parseInt(ballots) || 0;
    const voterCount = VOTER_COUNT_MAP[sid] || 0;
    if (voterCount > 0 && ballotsVal !== voterCount) {
        showToast(`⚠️ Ballots issued must exactly equal registered voters at this station (${voterCount}). You entered: ${ballotsVal}.`, 'warning');
        return;
    }

    const fd = new FormData();
    fd.append('ajax_action',           'update');
    fd.append('station_id',            sid);
    fd.append('name',                  name);
    fd.append('address',               addr);
    fd.append('constituency_id',       cid);
    fd.append('presiding_officer_id',  po);
    fd.append('total_ballots_issued',  ballots);
    fd.append('result_status',         status);

    fetch(window.location.href, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                showToast(res.message, 'success');
                setTimeout(() => location.reload(), 900);
            } else {
                showToast(res.message, 'error');
            }
        })
        .catch(() => showToast('Network error.', 'error'));
}

// ── DELETE ──────────────────────────────────────────────────
let pendingDeleteId = null;

function confirmDelete(sid, name) {
    pendingDeleteId = sid;
    document.getElementById('deleteStName').textContent = name;
    document.getElementById('deleteModal').classList.add('open');
}
function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('open');
    pendingDeleteId = null;
}
document.getElementById('confirmDeleteBtn').addEventListener('click', () => {
    if (!pendingDeleteId) return;
    const fd = new FormData();
    fd.append('ajax_action', 'delete');
    fd.append('station_id',  pendingDeleteId);
    closeDeleteModal();

    fetch(window.location.href, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                showToast(res.message, 'success');
                ['row-','editrow-','expand-'].forEach(pfx => {
                    const el = document.getElementById(pfx + pendingDeleteId);
                    if (el) el.remove();
                });
            } else {
                showToast(res.message, 'error');
            }
        })
        .catch(() => showToast('Network error.', 'error'));
});
document.getElementById('deleteModal').addEventListener('click', function(e) {
    if (e.target === this) closeDeleteModal();
});

// ── BOOTH EXPAND ─────────────────────────────────────────────
const loadedBooths = {};

function toggleBooths(sid) {
    const row   = document.getElementById('expand-' + sid);
    const badge = document.getElementById('boothBadge-' + sid);
    const isOpen = row.classList.contains('open');

    // close all
    document.querySelectorAll('.expand-row.open').forEach(r => {
        r.classList.remove('open');
        const id = r.id.replace('expand-', '');
        const b  = document.getElementById('boothBadge-' + id);
        if (b) b.innerHTML = b.innerHTML.replace('▴', '▾');
    });

    if (isOpen) return;

    row.classList.add('open');
    if (badge) badge.innerHTML = badge.innerHTML.replace('▾', '▴');

    if (!loadedBooths[sid]) loadBooths(sid);
}

function closeBooths(sid) {
    const row = document.getElementById('expand-' + sid);
    if (row) row.classList.remove('open');
    const badge = document.getElementById('boothBadge-' + sid);
    if (badge) badge.innerHTML = badge.innerHTML.replace('▴', '▾');
}

function loadBooths(sid) {
    const fd = new FormData();
    fd.append('ajax_action', 'get_booths');
    fd.append('station_id',  sid);

    fetch(window.location.href, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            loadedBooths[sid] = true;
            const container = document.getElementById('booths-content-' + sid);
            if (!res.success) { container.innerHTML = '<p style="color:var(--danger);padding:12px;">Error loading booths.</p>'; return; }
            if (!res.booths.length) {
                container.innerHTML = '<p style="color:var(--muted);font-size:13px;padding:16px 0;text-align:center;">No polling booths assigned to this station yet.</p>';
                return;
            }

            let html = `
            <table class="booth-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Booth No.</th>
                        <th>Asst. Presiding Officer</th>
                        <th>Ballots Issued</th>
                        <th>Votes Entered</th>
                        <th>Candidates Entered</th>
                        <th>Locked</th>
                    </tr>
                </thead>
                <tbody>`;

            res.booths.forEach(b => {
                const locked = parseInt(b.is_locked) === 1;
                html += `<tr>
                    <td><span class="id-badge">#${b.booth_id}</span></td>
                    <td><span class="booth-num">Booth ${escHtml(b.booth_number)}</span></td>
                    <td>${b.apo_name ? escHtml(b.apo_name) : '<span style="color:var(--muted2);">— None —</span>'}</td>
                    <td><span class="booth-num">${Number(b.ballots_issued).toLocaleString()}</span></td>
                    <td><span class="booth-votes">${Number(b.total_votes).toLocaleString()}</span></td>
                    <td><span style="font-family:var(--font-mono);font-size:13px;">${b.candidates_entered}</span></td>
                    <td>${locked
                        ? '<span class="locked-badge">🔒 Locked</span>'
                        : '<span class="unlocked-badge">🔓 Open</span>'}</td>
                </tr>`;
            });

            html += '</tbody></table>';
            container.innerHTML = html;
        })
        .catch(() => {
            document.getElementById('booths-content-' + sid).innerHTML =
                '<p style="color:var(--danger);padding:12px;">Network error. Could not load booths.</p>';
        });
}

function escHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── AUTO-OPEN form if PHP form error ───────────────────────
<?php if ($form_error): ?>
document.getElementById('addFormCard').style.display = 'block';
<?php endif; ?>
</script>
</body>
</html>
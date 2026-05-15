<?php
// ============================================================
//  DB CONFIG
// ============================================================
$DB_HOST = 'localhost';
$DB_NAME = 'ems';
$DB_USER = 'root';
$DB_PASS = '';

session_start();

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

    // ── DELETE OFFICER ──────────────────────────────────────
    if ($act === 'delete') {
        $id = (int)($_POST['officer_id'] ?? 0);
        if ($id === $logged_in_id) {
            echo json_encode(['success'=>false,'message'=>'You cannot delete your own account.']); exit;
        }
        try {
            $pdo->prepare("DELETE FROM election_officers WHERE officer_id=?")->execute([$id]);
            $pdo->prepare("INSERT INTO audit_logs (officer_id,action_type,affected_entity,affected_entity_id,details,ip_address) VALUES (?,?,?,?,?,?)")
                ->execute([$logged_in_id,'DELETE_OFFICER','election_officers',$id,"Deleted officer ID $id",$_SERVER['REMOTE_ADDR']??'']);
            echo json_encode(['success'=>true,'message'=>'Officer deleted successfully.']);
        } catch (PDOException $e) {
            echo json_encode(['success'=>false,'message'=>'Cannot delete: '.$e->getMessage()]);
        }
        exit;
    }

    // ── UPDATE OFFICER ──────────────────────────────────────
    if ($act === 'update') {
        $id         = (int)($_POST['officer_id'] ?? 0);
        $full_name  = trim($_POST['full_name'] ?? '');
        $national_id= trim($_POST['national_id'] ?? '');
        $role       = trim($_POST['role'] ?? '');
        $username   = trim($_POST['username'] ?? '');
        $password   = trim($_POST['password'] ?? '');
        $station_id = (int)($_POST['assigned_station_id'] ?? 0) ?: null;
        $const_id   = (int)($_POST['assigned_constituency_id'] ?? 0) ?: null;
        $is_active  = (int)($_POST['is_active'] ?? 1);

        $allowed_roles = ['APO','PO','ARO','RO','ADMIN'];
        if (!$full_name || !in_array($role, $allowed_roles)) {
            echo json_encode(['success'=>false,'message'=>'Full name and valid role are required.']); exit;
        }
        // For APO/PO: station matters. For ARO/RO: constituency matters.
       // For APO/PO: station matters. For ARO/RO: constituency matters.
        if (in_array($role, ['ARO','RO'])) { $station_id = null; }
        if (in_array($role, ['APO','PO']))  { $const_id   = null; }
        if ($role === 'ADMIN') { $station_id = null; $const_id = null; }

        // === ASSIGNMENT UNIQUENESS CONSTRAINTS (exclude the officer being edited) ===
        if ($role === 'PO' && $station_id) {
            $chk = $pdo->prepare("SELECT officer_id FROM election_officers WHERE role='PO' AND assigned_station_id=? AND officer_id != ?");
            $chk->execute([$station_id, $id]);
            if ($chk->fetch()) {
                echo json_encode(['success'=>false,'message'=>'This station already has a Presiding Officer. Each station can only have one PO.']); exit;
            }
        }
        if ($role === 'ARO' && $const_id) {
            $chk = $pdo->prepare("SELECT officer_id FROM election_officers WHERE role='ARO' AND assigned_constituency_id=? AND officer_id != ?");
            $chk->execute([$const_id, $id]);
            if ($chk->fetch()) {
                echo json_encode(['success'=>false,'message'=>'This constituency already has an ARO. Each constituency can only have one ARO.']); exit;
            }
        }
        if ($role === 'RO' && $const_id) {
            $chk = $pdo->prepare("SELECT officer_id FROM election_officers WHERE role='RO' AND assigned_constituency_id=? AND officer_id != ?");
            $chk->execute([$const_id, $id]);
            if ($chk->fetch()) {
                echo json_encode(['success'=>false,'message'=>'This constituency already has an RO. Each constituency can only have one RO.']); exit;
            }
        }

        try {
            if ($password !== '') {
                $pdo->prepare("UPDATE election_officers SET full_name=?,national_id=?,role=?,username=?,password_hash=?,assigned_station_id=?,assigned_constituency_id=?,is_active=? WHERE officer_id=?")
                    ->execute([$full_name,$national_id,$role,$username,$password,$station_id,$const_id,$is_active,$id]);
            } else {
                $pdo->prepare("UPDATE election_officers SET full_name=?,national_id=?,role=?,username=?,assigned_station_id=?,assigned_constituency_id=?,is_active=? WHERE officer_id=?")
                    ->execute([$full_name,$national_id,$role,$username,$station_id,$const_id,$is_active,$id]);
            }
            $pdo->prepare("INSERT INTO audit_logs (officer_id,action_type,affected_entity,affected_entity_id,details,ip_address) VALUES (?,?,?,?,?,?)")
                ->execute([$logged_in_id,'UPDATE_OFFICER','election_officers',$id,"Updated officer '$full_name' (role: $role)",$_SERVER['REMOTE_ADDR']??'']);
            echo json_encode(['success'=>true,'message'=>"Officer '$full_name' updated successfully."]);
        } catch (PDOException $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action.']); exit;
}

// ============================================================
//  CREATE NEW OFFICER (form POST)
// ============================================================
$form_success = $form_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_officer'])) {
    $full_name   = trim($_POST['full_name'] ?? '');
    $national_id = trim($_POST['national_id'] ?? '');
    $role        = trim($_POST['role'] ?? '');
    $username    = trim($_POST['username'] ?? '');
    $password    = trim($_POST['password'] ?? '');
    $station_id  = (int)($_POST['assigned_station_id'] ?? 0) ?: null;
    $const_id    = (int)($_POST['assigned_constituency_id'] ?? 0) ?: null;

    $allowed_roles = ['APO','PO','ARO','RO','ADMIN'];

    if (!$full_name) {
        $form_error = 'Full name is required.';
    } elseif (!in_array($role, $allowed_roles)) {
        $form_error = 'Please select a valid role.';
    } elseif (!$username) {
        $form_error = 'Username is required.';
    } elseif (!$password) {
        $form_error = 'Password is required.';
  } else {
        if (in_array($role, ['ARO','RO'])) { $station_id = null; }
        if (in_array($role, ['APO','PO']))  { $const_id   = null; }
        if ($role === 'ADMIN') { $station_id = null; $const_id = null; }

        // === ASSIGNMENT UNIQUENESS CONSTRAINTS ===
        if ($role === 'PO' && $station_id) {
            $chk = $pdo->prepare("SELECT officer_id FROM election_officers WHERE role='PO' AND assigned_station_id=?");
            $chk->execute([$station_id]);
            if ($chk->fetch()) {
                $form_error = 'This station already has a Presiding Officer assigned. Each station can only have one PO.';
            }
        }
        if ($role === 'ARO' && $const_id) {
            $chk = $pdo->prepare("SELECT officer_id FROM election_officers WHERE role='ARO' AND assigned_constituency_id=?");
            $chk->execute([$const_id]);
            if ($chk->fetch()) {
                $form_error = 'This constituency already has an ARO assigned. Each constituency can only have one ARO.';
            }
        }
        if ($role === 'RO' && $const_id) {
            $chk = $pdo->prepare("SELECT officer_id FROM election_officers WHERE role='RO' AND assigned_constituency_id=?");
            $chk->execute([$const_id]);
            if ($chk->fetch()) {
                $form_error = 'This constituency already has an RO assigned. Each constituency can only have one RO.';
            }
        }

        if (!$form_error) {
            try {
                $pdo->prepare("INSERT INTO election_officers (full_name,national_id,role,username,password_hash,assigned_station_id,assigned_constituency_id,is_active) VALUES (?,?,?,?,?,?,?,1)")
                    ->execute([$full_name,$national_id,$role,$username,$password,$station_id,$const_id]);
                $new_id = $pdo->lastInsertId();
                $pdo->prepare("INSERT INTO audit_logs (officer_id,action_type,affected_entity,affected_entity_id,details,ip_address) VALUES (?,?,?,?,?,?)")
                    ->execute([$logged_in_id,'CREATE_OFFICER','election_officers',$new_id,"Created officer '$full_name' (role: $role)",$_SERVER['REMOTE_ADDR']??'']);
                $form_success = "Officer <strong>$full_name</strong> ($role) created successfully.";
            } catch (PDOException $e) {
                $form_error = 'Error: '.$e->getMessage();
            }
        }
    }
}

// ============================================================
//  LOAD PAGE DATA
// ============================================================
$adminStmt = $pdo->prepare("SELECT * FROM election_officers WHERE officer_id=?");
$adminStmt->execute([$logged_in_id]);
$admin = $adminStmt->fetch();

// Dropdowns for form
$constituencies = $pdo->query("SELECT constituency_id, name, code FROM constituencies ORDER BY name")->fetchAll();
$stations       = $pdo->query("SELECT station_id, name FROM polling_stations ORDER BY name")->fetchAll();

// Load already-taken assignments for constraint enforcement
// Stations that already have a PO assigned (officer_id excluded on edit)
$taken_po_stations  = $pdo->query("SELECT assigned_station_id FROM election_officers WHERE role='PO' AND assigned_station_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
// Constituencies that already have an ARO assigned
$taken_aro_consts   = $pdo->query("SELECT assigned_constituency_id FROM election_officers WHERE role='ARO' AND assigned_constituency_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
// Constituencies that already have an RO assigned
$taken_ro_consts    = $pdo->query("SELECT assigned_constituency_id FROM election_officers WHERE role='RO' AND assigned_constituency_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
// For each station: which officer_id is the current PO (to allow keeping own assignment on edit)
$po_station_map     = $pdo->query("SELECT assigned_station_id, officer_id FROM election_officers WHERE role='PO' AND assigned_station_id IS NOT NULL")->fetchAll(PDO::FETCH_KEY_PAIR);
// For each constituency: ARO officer_id
$aro_const_map      = $pdo->query("SELECT assigned_constituency_id, officer_id FROM election_officers WHERE role='ARO' AND assigned_constituency_id IS NOT NULL")->fetchAll(PDO::FETCH_KEY_PAIR);
// For each constituency: RO officer_id
$ro_const_map       = $pdo->query("SELECT assigned_constituency_id, officer_id FROM election_officers WHERE role='RO' AND assigned_constituency_id IS NOT NULL")->fetchAll(PDO::FETCH_KEY_PAIR);

// Search & filter
$search      = trim($_GET['q'] ?? '');
$filter_role = trim($_GET['role'] ?? '');

$sqlBase = "
    SELECT eo.*,
           ps.name  AS station_name,
           c.name   AS constituency_name,
           c.code   AS constituency_code
    FROM election_officers eo
    LEFT JOIN polling_stations ps ON ps.station_id = eo.assigned_station_id
    LEFT JOIN constituencies   c  ON c.constituency_id = eo.assigned_constituency_id
";
$where = []; $params = [];

if ($search !== '') {
    $where[]  = "(eo.full_name LIKE ? OR eo.username LIKE ? OR eo.national_id LIKE ?)";
    $params[] = '%'.$search.'%';
    $params[] = '%'.$search.'%';
    $params[] = '%'.$search.'%';
}
if ($filter_role !== '') {
    $where[]  = "eo.role = ?";
    $params[] = $filter_role;
}

$sqlFull = $sqlBase . ($where ? " WHERE ".implode(" AND ", $where) : "") . " ORDER BY eo.role, eo.officer_id";
$stmt = $pdo->prepare($sqlFull);
$stmt->execute($params);
$officers = $stmt->fetchAll();

// Stats — one query each for clarity
$total_apo   = $pdo->query("SELECT COUNT(*) FROM election_officers WHERE role='APO'")->fetchColumn();
$total_po    = $pdo->query("SELECT COUNT(*) FROM election_officers WHERE role='PO'")->fetchColumn();
$total_aro   = $pdo->query("SELECT COUNT(*) FROM election_officers WHERE role='ARO'")->fetchColumn();
$total_ro    = $pdo->query("SELECT COUNT(*) FROM election_officers WHERE role='RO'")->fetchColumn();
$total_all   = $pdo->query("SELECT COUNT(*) FROM election_officers")->fetchColumn();
$total_active= $pdo->query("SELECT COUNT(*) FROM election_officers WHERE is_active=1")->fetchColumn();

// Build JS-safe constraint data
$js_taken_po  = json_encode(array_map('intval', $taken_po_stations));
$js_taken_aro = json_encode(array_map('intval', $taken_aro_consts));
$js_taken_ro  = json_encode(array_map('intval', $taken_ro_consts));
// Maps: station_id => officer_id, const_id => officer_id (for edit: own assignment is allowed)
$js_po_map    = json_encode(array_map('intval', $po_station_map));
$js_aro_map   = json_encode(array_map('intval', $aro_const_map));
$js_ro_map    = json_encode(array_map('intval', $ro_const_map));
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Officer Management — EMS Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500;600&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<style>
/* ============================================================
   ROOT
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
    --font-mono:  'IBM Plex Mono', monospace;
}
*,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
html { font-size:15px; }
body { font-family:var(--font-body); background:var(--bg); color:var(--text); min-height:100vh; display:flex; flex-direction:column; }
a { text-decoration:none; color:inherit; }
button { cursor:pointer; font-family:var(--font-body); }

/* ── TOPBAR ── */
.topbar {
    background: linear-gradient(135deg, var(--navy) 0%, var(--navy2) 60%, var(--navy3) 100%);
    padding: 0 32px; height: 66px;
    display: flex; align-items: center; justify-content: space-between;
    position: sticky; top: 0; z-index: 200;
    box-shadow: 0 2px 24px rgba(10,22,40,.35);
    border-bottom: 1px solid rgba(255,255,255,.08);
}
.topbar-brand { display:flex; align-items:center; gap:14px; }
.brand-emblem {
    width:40px; height:40px;
    background: linear-gradient(135deg, var(--primary), var(--accent));
    border-radius:10px; display:flex; align-items:center; justify-content:center;
    font-size:20px; box-shadow:0 2px 12px rgba(14,165,233,.3); flex-shrink:0;
}
.brand-text { font-family:var(--font-head); font-size:15px; font-weight:700; color:#fff; letter-spacing:.2px; line-height:1.2; }
.brand-sub  { font-size:11px; color:rgba(255,255,255,.5); letter-spacing:.5px; text-transform:uppercase; }
.topbar-center { display:flex; align-items:center; gap:8px; }
.live-chip {
    display:flex; align-items:center; gap:6px;
    background:rgba(16,185,129,.15); border:1px solid rgba(16,185,129,.3);
    border-radius:20px; padding:5px 14px;
    font-size:11.5px; font-weight:600; color:var(--success2);
    letter-spacing:.4px; text-transform:uppercase;
}
.live-dot { width:7px; height:7px; border-radius:50%; background:var(--success2); animation:pulse-dot 1.6s infinite; }
@keyframes pulse-dot { 0%,100%{opacity:1;transform:scale(1);} 50%{opacity:.5;transform:scale(1.4);} }
.election-tag {
    background:rgba(245,158,11,.12); border:1px solid rgba(245,158,11,.28);
    border-radius:20px; padding:5px 14px;
    font-size:11.5px; font-weight:600; color:var(--gold2);
    letter-spacing:.4px; text-transform:uppercase;
}
.topbar-right { display:flex; align-items:center; gap:14px; }
.admin-pill {
    display:flex; align-items:center; gap:10px;
    background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.14);
    border-radius:30px; padding:5px 14px 5px 6px;
}
.admin-avatar {
    width:32px; height:32px; border-radius:50%;
    background:linear-gradient(135deg, var(--primary), var(--accent));
    display:flex; align-items:center; justify-content:center;
    font-family:var(--font-head); font-size:13px; font-weight:700; color:#fff;
}
.admin-info .name     { font-size:12.5px; font-weight:600; color:#fff; line-height:1.2; }
.admin-info .role-tag { font-size:10px; color:var(--accent2); letter-spacing:.5px; text-transform:uppercase; font-weight:500; }
.btn-logout {
    background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.25);
    color:#fca5a5; font-size:12px; font-weight:600;
    padding:7px 16px; border-radius:8px; transition:all .2s; letter-spacing:.3px;
}
.btn-logout:hover { background:rgba(239,68,68,.22); color:#fff; }

/* ── SUB NAV ── */
.sub-nav {
    background:var(--surface); border-bottom:2px solid var(--border);
    padding:0 32px; display:flex; align-items:center; gap:2px;
    overflow-x:auto; position:sticky; top:66px; z-index:100;
    box-shadow:0 1px 8px rgba(10,22,40,.06);
}
.sub-nav-link {
    display:flex; align-items:center; gap:6px;
    padding:14px 16px; font-size:12.5px; font-weight:600; color:var(--muted);
    border-bottom:2px solid transparent; margin-bottom:-2px;
    white-space:nowrap; transition:all .18s;
}
.sub-nav-link:hover { color:var(--primary); }
.sub-nav-link.active { color:var(--primary); border-bottom-color:var(--primary); background:rgba(26,86,219,.04); }

/* ── PAGE WRAP ── */
.page-wrap { max-width:1380px; margin:0 auto; padding:28px 28px 56px; width:100%; flex:1; }

/* ── PAGE HEADER ── */
.page-header { display:flex; align-items:flex-end; justify-content:space-between; margin-bottom:24px; flex-wrap:wrap; gap:14px; }
.page-header-left { display:flex; align-items:center; gap:16px; }
.page-header-icon {
    width:52px; height:52px;
    background:linear-gradient(135deg, var(--purple), var(--primary));
    border-radius:14px; display:flex; align-items:center; justify-content:center;
    font-size:24px; box-shadow:0 4px 16px rgba(139,92,246,.28); flex-shrink:0;
}
.page-title    { font-family:var(--font-head); font-size:26px; font-weight:800; color:var(--navy); line-height:1.1; letter-spacing:-.3px; }
.page-subtitle { font-size:13px; color:var(--muted); margin-top:4px; }
.breadcrumb    { font-size:11.5px; color:var(--muted2); margin-bottom:4px; }
.breadcrumb a  { color:var(--primary); font-weight:600; }
.breadcrumb a:hover { text-decoration:underline; }
.header-actions { display:flex; gap:10px; flex-wrap:wrap; }

/* ── BUTTONS ── */
.btn {
    display:inline-flex; align-items:center; gap:7px;
    font-size:13px; font-weight:600; padding:9px 18px;
    border-radius:var(--radius-sm); border:none;
    transition:all .2s; cursor:pointer; white-space:nowrap;
    font-family:var(--font-body); letter-spacing:.2px;
}
.btn-primary   { background:linear-gradient(135deg, var(--primary), var(--primary2)); color:#fff; box-shadow:0 2px 10px rgba(26,86,219,.25); }
.btn-primary:hover   { transform:translateY(-1px); box-shadow:0 4px 18px rgba(26,86,219,.35); }
.btn-purple    { background:linear-gradient(135deg, var(--purple), var(--primary)); color:#fff; box-shadow:0 2px 10px rgba(139,92,246,.25); }
.btn-purple:hover    { transform:translateY(-1px); box-shadow:0 4px 18px rgba(139,92,246,.35); }
.btn-secondary { background:var(--surface); color:var(--text2); border:1.5px solid var(--border); }
.btn-secondary:hover { border-color:var(--primary); color:var(--primary); background:#eff6ff; }
.btn-danger    { background:rgba(239,68,68,.08); color:var(--danger); border:1.5px solid rgba(239,68,68,.25); }
.btn-danger:hover    { background:var(--danger); color:#fff; }
.btn-success   { background:rgba(16,185,129,.1); color:var(--success); border:1.5px solid rgba(16,185,129,.3); }
.btn-success:hover   { background:var(--success); color:#fff; }
.btn-sm  { font-size:12px; padding:6px 13px; }
.btn-xs  { font-size:11px; padding:4px 10px; border-radius:6px; }

/* ── STAT TILES ── */
.stat-strip { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:22px; }
@media(max-width:960px){ .stat-strip{grid-template-columns:repeat(2,1fr);} }
.stat-tile {
    background:var(--surface); border-radius:var(--radius);
    border:1.5px solid var(--border); padding:20px 22px;
    position:relative; overflow:hidden; transition:all .22s;
    animation:fadeUp .4s ease both;
}
.stat-tile:hover { transform:translateY(-3px); box-shadow:var(--shadow-lg); border-color:var(--border2); }
.st-icon { width:44px; height:44px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:20px; margin-bottom:12px; }
.st-icon.blue   { background:#dbeafe; }
.st-icon.green  { background:#dcfce7; }
.st-icon.gold   { background:#fef3c7; }
.st-icon.teal   { background:#ccfbf1; }
.st-icon.purple { background:#ede9fe; }
.st-icon.red    { background:#fee2e2; }
.st-value { font-family:var(--font-mono); font-size:26px; font-weight:600; letter-spacing:-.5px; line-height:1; margin-bottom:5px; color:var(--text); }
.st-value.blue   { color:var(--primary); }
.st-value.green  { color:var(--success); }
.st-value.gold   { color:var(--gold); }
.st-value.teal   { color:var(--teal); }
.st-value.purple { color:var(--purple); }
.st-value.red    { color:var(--danger); }
.st-label { font-size:11.5px; font-weight:600; text-transform:uppercase; letter-spacing:.6px; color:var(--muted); }
.st-delta { position:absolute; top:16px; right:16px; font-size:11px; font-weight:600; padding:3px 9px; border-radius:20px; }
.st-delta.blue   { background:#dbeafe; color:var(--primary); }
.st-delta.purple { background:#ede9fe; color:var(--purple); }
.st-delta.up     { background:#dcfce7; color:var(--success); }
.st-delta.gold   { background:#fef3c7; color:var(--gold); }
.st-delta.teal   { background:#ccfbf1; color:var(--teal); }
.st-delta.red    { background:#fee2e2; color:var(--danger); }

/* ── CARDS ── */
.card { background:var(--surface); border-radius:var(--radius); border:1.5px solid var(--border); overflow:hidden; box-shadow:var(--shadow); margin-bottom:22px; }
.card-header { padding:18px 24px 14px; display:flex; align-items:center; justify-content:space-between; border-bottom:1px solid var(--border); background:#fafbfc; flex-wrap:wrap; gap:10px; }
.card-title  { font-family:var(--font-head); font-size:14.5px; font-weight:700; color:var(--text); display:flex; align-items:center; gap:8px; }
.card-body   { padding:22px 24px; }
.count-pill  { display:inline-flex; align-items:center; justify-content:center; background:var(--bg2); border:1px solid var(--border); border-radius:20px; font-size:12px; font-weight:700; color:var(--text2); padding:2px 10px; font-family:var(--font-mono); }

/* ── FORM ── */
.form-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
.form-grid-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:16px; }
@media(max-width:768px){ .form-grid-2,.form-grid-3{grid-template-columns:1fr;} }
.form-group  { display:flex; flex-direction:column; gap:6px; }
.form-label  { font-size:11.5px; font-weight:700; color:var(--text2); letter-spacing:.4px; text-transform:uppercase; }
.form-label .req { color:var(--danger); margin-left:2px; }
.form-control {
    padding:10px 14px; border:1.5px solid var(--border);
    border-radius:var(--radius-sm); font-size:13.5px;
    font-family:var(--font-body); color:var(--text);
    background:var(--surface); transition:border-color .18s, box-shadow .18s;
    outline:none; width:100%;
}
.form-control:focus { border-color:var(--primary); box-shadow:0 0 0 3px rgba(26,86,219,.12); }
.form-control::placeholder { color:var(--muted2); }
.form-helper {
    font-size:11.5px; color:var(--muted); margin-top:16px;
    padding:10px 14px; background:#f5f3ff;
    border-radius:var(--radius-sm); border-left:3px solid var(--purple);
}
/* Role-conditional hint */
.role-hint {
    font-size:12px; padding:8px 14px; border-radius:var(--radius-sm);
    margin-top:6px; display:none;
}
.role-hint.show  { display:block; }
.role-hint.apo-po { background:#eff6ff; color:var(--primary2); border:1px solid #bfdbfe; }
.role-hint.aro-ro { background:#f5f3ff; color:#5b21b6; border:1px solid #ddd6fe; }
.role-hint.admin  { background:#f0fdf4; color:#166534; border:1px solid #86efac; }

/* ── SEARCH / FILTER BAR ── */
.filter-bar { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.search-input-wrap { position:relative; flex:1; min-width:220px; }
.search-input-wrap .si { position:absolute; left:13px; top:50%; transform:translateY(-50%); color:var(--muted2); font-size:14px; pointer-events:none; }
.search-input-wrap input {
    width:100%; padding:10px 14px 10px 38px;
    border:1.5px solid var(--border); border-radius:var(--radius-sm);
    font-size:13.5px; font-family:var(--font-body); color:var(--text);
    background:var(--surface); outline:none; transition:border-color .18s, box-shadow .18s;
}
.search-input-wrap input:focus { border-color:var(--primary); box-shadow:0 0 0 3px rgba(26,86,219,.10); }
.filter-select {
    padding:10px 14px; border:1.5px solid var(--border);
    border-radius:var(--radius-sm); font-size:13.5px;
    font-family:var(--font-body); color:var(--text);
    background:var(--surface); outline:none; min-width:180px; transition:border-color .18s;
}
.filter-select:focus { border-color:var(--primary); }

/* ── TABLE ── */
.table-wrap { overflow-x:auto; }
.mgmt-table { width:100%; border-collapse:collapse; font-size:13.5px; }
.mgmt-table thead tr { background:#f1f5f9; }
.mgmt-table th { padding:11px 16px; text-align:left; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.6px; color:var(--muted); border-bottom:2px solid var(--border); white-space:nowrap; }
.mgmt-table td { padding:13px 16px; border-bottom:1px solid var(--border); vertical-align:middle; }
.mgmt-table tbody tr { transition:background .15s; }
.mgmt-table tbody tr:hover { background:#f8fafc; }
.mgmt-table tbody tr:last-child td { border-bottom:none; }

/* Edit row */
.editing-row { background:#eff6ff !important; outline:2px solid var(--primary); outline-offset:-1px; }
.editing-row td { background:#eff6ff; }
.edit-input {
    padding:7px 10px; border:1.5px solid var(--primary);
    border-radius:6px; font-size:13px; font-family:var(--font-body);
    color:var(--text); width:100%; outline:none; min-width:90px;
    box-shadow:0 0 0 2px rgba(26,86,219,.12);
}
.edit-select {
    padding:7px 10px; border:1.5px solid var(--primary);
    border-radius:6px; font-size:13px; font-family:var(--font-body);
    color:var(--text); outline:none; min-width:110px;
    box-shadow:0 0 0 2px rgba(26,86,219,.12); background:#fff;
}

/* Cell helpers */
.cell-main { font-weight:700; color:var(--text); font-size:13.5px; }
.cell-sub  { font-size:11px; color:var(--muted); margin-top:2px; }
.id-badge  { font-family:var(--font-mono); font-size:12px; font-weight:600; background:var(--bg2); border:1px solid var(--border); border-radius:6px; padding:3px 8px; color:var(--text2); }
.num-cell  { font-family:var(--font-mono); font-size:13.5px; font-weight:600; color:var(--text); }

/* Role badge */
.role-pill { display:inline-flex; align-items:center; gap:4px; padding:4px 10px; border-radius:20px; font-size:11.5px; font-weight:700; letter-spacing:.3px; white-space:nowrap; }
.rp-apo   { background:#dbeafe; color:#1e40af; border:1px solid #93c5fd; }
.rp-po    { background:#ede9fe; color:#5b21b6; border:1px solid #c4b5fd; }
.rp-aro   { background:#ccfbf1; color:#0f766e; border:1px solid #5eead4; }
.rp-ro    { background:#fef3c7; color:#92400e; border:1px solid #fcd34d; }
.rp-admin { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }

/* Active/inactive */
.status-pill { display:inline-flex; align-items:center; gap:5px; padding:4px 12px; border-radius:20px; font-size:11.5px; font-weight:700; white-space:nowrap; }
.sp-active   { background:#dcfce7; color:#166534; border:1px solid #86efac; }
.sp-inactive { background:#f1f5f9; color:#64748b; border:1px solid #cbd5e1; }

.action-row { display:flex; align-items:center; gap:6px; flex-wrap:wrap; }

/* ── PAGINATION ── */
.pagination-bar { display:flex; align-items:center; justify-content:space-between; padding:14px 24px; border-top:1px solid var(--border); font-size:12.5px; color:var(--muted); flex-wrap:wrap; gap:10px; }
.pagination { display:flex; gap:4px; }
.pag-btn { width:32px; height:32px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:600; cursor:pointer; background:var(--surface); border:1.5px solid var(--border); color:var(--text2); transition:all .18s; }
.pag-btn:hover  { border-color:var(--primary); color:var(--primary); background:#eff6ff; }
.pag-btn.active { background:var(--primary); color:#fff; border-color:var(--primary); }
.pag-btn.disabled { opacity:.4; pointer-events:none; }

/* ── DELETE MODAL ── */
.modal-overlay { position:fixed; inset:0; background:rgba(10,22,40,.55); backdrop-filter:blur(3px); z-index:500; display:none; align-items:center; justify-content:center; }
.modal-overlay.open { display:flex; }
.modal-box { background:var(--surface); border-radius:18px; padding:32px 28px; max-width:420px; width:90%; box-shadow:var(--shadow-lg); animation:popIn .22s ease; }
@keyframes popIn { from{opacity:0;transform:scale(.93);} to{opacity:1;transform:scale(1);} }
.modal-icon    { font-size:44px; text-align:center; margin-bottom:14px; }
.modal-title   { font-family:var(--font-head); font-size:18px; font-weight:800; color:var(--text); text-align:center; margin-bottom:10px; }
.modal-body    { font-size:13.5px; color:var(--muted); text-align:center; line-height:1.6; margin-bottom:24px; }
.modal-actions { display:flex; gap:10px; justify-content:center; }

/* ── TOAST ── */
#toast { position:fixed; bottom:28px; right:28px; z-index:999; display:flex; flex-direction:column; gap:8px; }
.toast-msg { background:#1e293b; color:#fff; border-radius:10px; padding:12px 20px; font-size:13px; font-weight:500; display:flex; align-items:center; gap:10px; box-shadow:0 4px 20px rgba(0,0,0,.2); animation:slideIn .3s ease; }
.toast-msg.success { background:#166534; }
.toast-msg.error   { background:#991b1b; }
.toast-msg.warning { background:#92400e; }
@keyframes slideIn { from{transform:translateY(20px);opacity:0;} to{transform:translateY(0);opacity:1;} }

/* ── ALERTS ── */
.alert { padding:12px 16px; border-radius:var(--radius-sm); font-size:13.5px; font-weight:500; margin-bottom:18px; display:flex; align-items:center; gap:10px; }
.alert-success { background:#dcfce7; color:#166534; border:1px solid #86efac; }
.alert-error   { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }

/* ── FOOTER ── */
.footer { background:var(--navy); padding:18px 32px; display:flex; align-items:center; justify-content:space-between; font-size:12px; color:#64748b; margin-top:auto; flex-wrap:wrap; gap:8px; }
.footer-links { display:flex; gap:18px; }
.footer-links a { color:#64748b; text-decoration:none; transition:color .2s; }
.footer-links a:hover { color:#60a5fa; }

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
    <a class="sub-nav-link" href="polling_station_management.php">🏫 Polling Stations</a>
    <a class="sub-nav-link" href="booth_management.php">🚪 Booth Management</a>
    <a class="sub-nav-link" href="candidate_management.php">🎖️ Candidates</a>
    <a class="sub-nav-link" href="party_management.php">🏳️ Political Parties</a>
    <a class="sub-nav-link active" href="officer_management.php">👮 Officers</a>
    <a class="sub-nav-link " href="voter_management.php">🧑‍🤝‍🧑 Voters</a>
</nav>

<!-- ═══════════════════════════════
     PAGE CONTENT
═══════════════════════════════ -->
<div class="page-wrap">

    <!-- PAGE HEADER -->
    <div class="page-header">
        <div class="page-header-left">
            <div class="page-header-icon">👮</div>
            <div>
                <div class="breadcrumb"><a href="admin_dashboard.php">Dashboard</a> / Officer Management</div>
                <div class="page-title">Election Officer Management</div>
                <div class="page-subtitle">Register and manage APOs, Presiding Officers, AROs, Returning Officers, and Admins.</div>
            </div>
        </div>
        <div class="header-actions">
            <button class="btn btn-purple" onclick="toggleForm()">➕ Add Officer</button>
            <button class="btn btn-secondary" onclick="location.reload()">🔄 Refresh</button>
            <a href="admin_dashboard.php" class="btn btn-secondary">← Dashboard</a>
        </div>
    </div>

    <!-- STAT STRIP -->
    <div class="stat-strip">
        <div class="stat-tile">
            <div class="st-delta blue">APO</div>
            <div class="st-icon blue">🖊️</div>
            <div class="st-value blue"><?= number_format((int)$total_apo) ?></div>
            <div class="st-label">Asst. Presiding Officers</div>
        </div>
        <div class="stat-tile">
            <div class="st-delta purple">PO</div>
            <div class="st-icon purple">🏛️</div>
            <div class="st-value purple"><?= number_format((int)$total_po) ?></div>
            <div class="st-label">Presiding Officers</div>
        </div>
        <div class="stat-tile">
            <div class="st-delta teal">ARO</div>
            <div class="st-icon teal">📊</div>
            <div class="st-value teal"><?= number_format((int)$total_aro) ?></div>
            <div class="st-label">Asst. Returning Officers</div>
        </div>
        <div class="stat-tile">
            <div class="st-delta gold">RO</div>
            <div class="st-icon gold">🏆</div>
            <div class="st-value gold"><?= number_format((int)$total_ro) ?></div>
            <div class="st-label">Returning Officers</div>
        </div>
    </div>

    <!-- PHP FEEDBACK -->
    <?php if ($form_success): ?>
    <div class="alert alert-success">✅ <?= $form_success ?></div>
    <?php endif; ?>
    <?php if ($form_error): ?>
    <div class="alert alert-error">❌ <?= htmlspecialchars($form_error) ?></div>
    <?php endif; ?>

    <!-- ADD OFFICER FORM -->
    <div class="card" id="addFormCard" style="display:none; border-color:var(--purple);">
        <div class="card-header">
            <span class="card-title">👮 Register New Election Officer</span>
            <button class="btn btn-sm btn-secondary" onclick="toggleForm()">✕ Close</button>
        </div>
        <div class="card-body">
            <form method="POST" action="" id="addOfficerForm" onsubmit="return validateAddForm()">
                <div class="form-grid-3" style="margin-bottom:16px;">
                    <div class="form-group">
                        <label class="form-label">Full Name <span class="req">*</span></label>
                        <input type="text" name="full_name" class="form-control" placeholder="e.g. Farhan Ahmed" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">National ID</label>
                        <input type="text" name="national_id" class="form-control" placeholder="e.g. 1992038475620">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Role <span class="req">*</span></label>
                        <select name="role" id="formRole" class="form-control" onchange="handleRoleChange(this.value,'form')" required>
                            <option value="">— Select Role —</option>
                            <option value="APO">APO — Assistant Presiding Officer</option>
                            <option value="PO">PO — Presiding Officer</option>
                            <option value="ARO">ARO — Assistant Returning Officer</option>
                            <option value="RO">RO — Returning Officer</option>
                            <option value="ADMIN">ADMIN — System Administrator</option>
                        </select>
                    </div>
                </div>

                <div class="form-grid-3" style="margin-bottom:16px;">
                    <div class="form-group" id="formStationGroup">
                        <label class="form-label">Assigned Polling Station <span style="color:var(--muted);font-weight:400;">(APO / PO)</span></label>
                        <select name="assigned_station_id" id="formStation" class="form-control">
                            <option value="">— Not Applicable / Not Assigned —</option>
                           <?php foreach ($stations as $s): 
                                $is_taken_po = in_array($s['station_id'], $taken_po_stations);
                            ?>
                            <option value="<?= $s['station_id'] ?>" <?= $is_taken_po ? 'data-taken-po="1"' : '' ?>>
                                <?= htmlspecialchars($s['name']) ?><?= $is_taken_po ? ' ⚠ PO Assigned' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" id="formConstGroup">
                        <label class="form-label">Assigned Constituency <span style="color:var(--muted);font-weight:400;">(ARO / RO)</span></label>
                        <select name="assigned_constituency_id" id="formConst" class="form-control">
                            <option value="">— Not Applicable / Not Assigned —</option>
                           <?php foreach ($constituencies as $c): 
                                $cid = $c['constituency_id'];
                                $taken_aro = in_array($cid, $taken_aro_consts);
                                $taken_ro  = in_array($cid, $taken_ro_consts);
                            ?>
                            <option value="<?= $cid ?>"
                                <?= $taken_aro ? 'data-taken-aro="1"' : '' ?>
                                <?= $taken_ro  ? 'data-taken-ro="1"'  : '' ?>>
                                <?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['code']) ?>)<?= $taken_aro ? ' ⚠ ARO Assigned' : '' ?><?= $taken_ro ? ' ⚠ RO Assigned' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <!-- dynamic hint label populated by JS -->
                        <div id="formRoleHint" class="role-hint"></div>
                    </div>
                </div>

                <div class="form-grid-3" style="margin-bottom:18px;">
                    <div class="form-group">
                        <label class="form-label">Username <span class="req">*</span></label>
                        <input type="text" name="username" class="form-control" placeholder="e.g. farhan.apo01" required autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Password <span class="req">*</span></label>
                        <input type="password" name="password" class="form-control" placeholder="Set initial password" required autocomplete="new-password">
                    </div>
                </div>

                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <button type="submit" name="create_officer" class="btn btn-purple">💾 Save Officer</button>
                    <button type="reset" class="btn btn-secondary" onclick="resetFormHints()">↺ Reset Form</button>
                </div>
                <div class="form-helper">
                    💡 <strong>APO / PO</strong> — assign a Polling Station. &nbsp;
                    <strong>ARO / RO</strong> — assign a Constituency. &nbsp;
                    <strong>ADMIN</strong> — no station or constituency assignment needed.
                </div>
            </form>
        </div>
    </div>

    <!-- SEARCH & FILTER -->
    <div class="card">
        <div class="card-body" style="padding:16px 22px;">
            <form method="GET" action="">
                <div class="filter-bar">
                    <div class="search-input-wrap">
                        <span class="si">🔍</span>
                        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search by name, username, or National ID…">
                    </div>
                    <select name="role" class="filter-select">
                        <option value="">All Roles</option>
                        <?php foreach (['APO','PO','ARO','RO','ADMIN'] as $r): ?>
                        <option value="<?= $r ?>" <?= $filter_role===$r?'selected':'' ?>><?= $r ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-primary">Search</button>
                    <?php if ($search || $filter_role): ?>
                    <a href="officer_management.php" class="btn btn-secondary">✕ Clear</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- OFFICER TABLE -->
    <div class="card">
        <div class="card-header">
            <span class="card-title">👮 Election Officer Records
                <?php if ($search || $filter_role): ?>
                <span style="font-size:12px;font-weight:400;color:var(--muted);">Filtered results</span>
                <?php endif; ?>
            </span>
            <span class="count-pill"><?= count($officers) ?> found</span>
        </div>

        <div class="table-wrap">
            <table class="mgmt-table" id="officerTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Full Name</th>
                        <th>Role</th>
                        <th>Username</th>
                        <th>National ID</th>
                        <th>Assigned To</th>
                        <th>Status</th>
                        <th style="min-width:160px;">Actions</th>
                    </tr>
                </thead>
                <tbody>

                <?php if (empty($officers)): ?>
                <tr>
                    <td colspan="8" style="text-align:center;padding:40px;color:var(--muted);">
                        No officers found<?= ($search||$filter_role) ? ' for this filter' : '' ?>.
                    </td>
                </tr>
                <?php endif; ?>

                <?php foreach ($officers as $of):
                    $oid       = (int)$of['officer_id'];
                    $role      = $of['role'];
                    $rpClass   = ['APO'=>'rp-apo','PO'=>'rp-po','ARO'=>'rp-aro','RO'=>'rp-ro','ADMIN'=>'rp-admin'][$role] ?? 'rp-apo';
                    $rpIcon    = ['APO'=>'✍️','PO'=>'🏛️','ARO'=>'📊','RO'=>'🏆','ADMIN'=>'⚙️'][$role] ?? '👤';
                    $isActive  = (int)$of['is_active'];
                    // Assignment label
                    if (in_array($role,['APO','PO'])) {
                        $assignLabel = $of['station_name'] ? '🏫 '.htmlspecialchars($of['station_name']) : '<span style="color:var(--muted2);">— None —</span>';
                        $assignSub   = 'Polling Station';
                    } elseif (in_array($role,['ARO','RO'])) {
                        $assignLabel = $of['constituency_name'] ? '🗺️ '.htmlspecialchars($of['constituency_name']) : '<span style="color:var(--muted2);">— None —</span>';
                        $assignSub   = 'Constituency';
                    } else {
                        $assignLabel = '<span style="color:var(--muted2);">System-wide</span>';
                        $assignSub   = 'All Areas';
                    }
                ?>

                <!-- DISPLAY ROW -->
                <tr class="data-row" id="row-<?= $oid ?>">
                    <td><span class="id-badge">#<?= $oid ?></span></td>
                    <td>
                        <div class="cell-main"><?= htmlspecialchars($of['full_name']) ?></div>
                    </td>
                    <td>
                        <span class="role-pill <?= $rpClass ?>"><?= $rpIcon ?> <?= $role ?></span>
                    </td>
                    <td>
                        <span class="num-cell" style="font-size:13px;"><?= htmlspecialchars($of['username']) ?></span>
                    </td>
                    <td>
                        <span class="num-cell" style="font-size:12.5px;"><?= $of['national_id'] ? htmlspecialchars($of['national_id']) : '<span style="color:var(--muted2);">—</span>' ?></span>
                    </td>
                    <td>
                        <div class="cell-main" style="font-size:12.5px;"><?= $assignLabel ?></div>
                        <div class="cell-sub"><?= $assignSub ?></div>
                    </td>
                    <td>
                        <span class="status-pill <?= $isActive ? 'sp-active' : 'sp-inactive' ?>">
                            <?= $isActive ? '✅ Active' : '⏸ Inactive' ?>
                        </span>
                    </td>
                    <td>
                        <div class="action-row">
                            <button class="btn btn-sm btn-secondary" onclick="startEdit(<?= $oid ?>)">✏️ Edit</button>
                            <?php if ($oid !== $logged_in_id): ?>
                            <button class="btn btn-sm btn-danger" onclick="confirmDelete(<?= $oid ?>, '<?= htmlspecialchars(addslashes($of['full_name'])) ?>')">🗑️</button>
                            <?php else: ?>
                            <span style="font-size:11px;color:var(--muted2);">You</span>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>

                <!-- EDIT ROW -->
                <tr class="editing-row" id="editrow-<?= $oid ?>" style="display:none;">
                    <td><span class="id-badge">#<?= $oid ?></span></td>
                    <td>
                        <input class="edit-input" id="edit-name-<?= $oid ?>" value="<?= htmlspecialchars($of['full_name']) ?>" placeholder="Full name" style="min-width:140px;">
                    </td>
                    <td>
                        <select class="edit-select" id="edit-role-<?= $oid ?>" onchange="handleRoleChange(this.value, 'edit', <?= $oid ?>)">
                            <?php foreach (['APO','PO','ARO','RO','ADMIN'] as $r): ?>
                            <option value="<?= $r ?>" <?= $r===$role?'selected':'' ?>><?= $r ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <input class="edit-input" id="edit-username-<?= $oid ?>" value="<?= htmlspecialchars($of['username']) ?>" placeholder="Username" style="min-width:110px;">
                    </td>
                    <td>
                        <input class="edit-input" id="edit-nid-<?= $oid ?>" value="<?= htmlspecialchars($of['national_id']??'') ?>" placeholder="National ID" style="min-width:120px;">
                    </td>
                    <td>
                        <!-- Station dropdown (APO/PO) -->
                        <select class="edit-select" id="edit-station-<?= $oid ?>" style="min-width:150px;<?= in_array($role,['ARO','RO','ADMIN'])?'display:none;':'' ?>">
                            <option value="">— None —</option>
                           <?php foreach ($stations as $s): 
                                $sid = $s['station_id'];
                                // "taken by another PO" = taken AND that PO is not the officer being edited
                                $taken_by_other = isset($po_station_map[$sid]) && $po_station_map[$sid] != $oid;
                            ?>
                            <option value="<?= $sid ?>"
                                <?= $sid == $of['assigned_station_id'] ? 'selected' : '' ?>
                                <?= $taken_by_other ? 'data-taken-po="1" disabled' : '' ?>>
                                <?= htmlspecialchars($s['name']) ?><?= $taken_by_other ? ' ⚠ PO Assigned' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <!-- Constituency dropdown (ARO/RO) -->
                        <select class="edit-select" id="edit-const-<?= $oid ?>" style="min-width:150px;<?= in_array($role,['APO','PO','ADMIN'])?'display:none;':'' ?>">
                            <option value="">— None —</option>
                           <?php foreach ($constituencies as $c): 
                                $cid = $c['constituency_id'];
                                $aro_taken_other = isset($aro_const_map[$cid]) && $aro_const_map[$cid] != $oid;
                                $ro_taken_other  = isset($ro_const_map[$cid])  && $ro_const_map[$cid]  != $oid;
                                // Disable if taken by the same role as this officer (checked in JS too)
                                $disable_edit = ($role === 'ARO' && $aro_taken_other) || ($role === 'RO' && $ro_taken_other);
                            ?>
                            <option value="<?= $cid ?>"
                                <?= $cid == $of['assigned_constituency_id'] ? 'selected' : '' ?>
                                <?= $aro_taken_other ? 'data-taken-aro="1"' : '' ?>
                                <?= $ro_taken_other  ? 'data-taken-ro="1"'  : '' ?>
                                <?= $disable_edit    ? 'disabled'            : '' ?>>
                                <?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['code']) ?>)<?= $aro_taken_other ? ' ⚠ ARO Assigned' : '' ?><?= $ro_taken_other ? ' ⚠ RO Assigned' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <!-- ADMIN placeholder -->
                        <span id="edit-admin-label-<?= $oid ?>" style="font-size:12px;color:var(--muted2);<?= $role!=='ADMIN'?'display:none;':'' ?>">System-wide</span>
                    </td>
                    <td>
                        <select class="edit-select" id="edit-active-<?= $oid ?>">
                            <option value="1" <?= $isActive?'selected':'' ?>>Active</option>
                            <option value="0" <?= !$isActive?'selected':'' ?>>Inactive</option>
                        </select>
                        <br><small style="color:var(--muted);font-size:10.5px;margin-top:4px;display:block;">New password (blank = keep):</small>
                        <input class="edit-input" type="password" id="edit-pass-<?= $oid ?>" placeholder="New password" autocomplete="new-password" style="min-width:120px;margin-top:4px;">
                    </td>
                    <td>
                        <div class="action-row">
                            <button class="btn btn-sm btn-success" onclick="saveEdit(<?= $oid ?>)">💾 Save</button>
                            <button class="btn btn-sm btn-secondary" onclick="cancelEdit(<?= $oid ?>)">✕</button>
                        </div>
                    </td>
                </tr>

                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- PAGINATION -->
        <div class="pagination-bar">
            <div>Showing <strong><?= count($officers) ?></strong> officer record<?= count($officers)!==1?'s':'' ?><?= ($search||$filter_role) ? ' (filtered)' : '' ?></div>
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
        <div class="modal-title">Delete Officer?</div>
        <div class="modal-body">
            Are you sure you want to remove <strong id="deleteOfficerName">this officer</strong>?<br>
            Their account will be permanently deleted. This action <strong>cannot be undone.</strong>
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
    <div>🗳️ EMS Admin &nbsp;·&nbsp; Officer Management &nbsp;·&nbsp; © 2026 Bangladesh Election Commission. All rights reserved.</div>
    <div class="footer-links">
        <a href="#">Privacy Policy</a>
        <a href="#">Security Audit</a>
        <a href="#">Help &amp; Documentation</a>
        <a href="#">Contact Support</a>
    </div>
</footer>

<div id="toast"></div>

<script>

// ── ASSIGNMENT CONSTRAINT DATA (from PHP) ──────────────────
const TAKEN_PO_STATIONS  = <?= $js_taken_po  ?>;  // station_ids already having a PO
const TAKEN_ARO_CONSTS   = <?= $js_taken_aro ?>;  // constituency_ids already having an ARO
const TAKEN_RO_CONSTS    = <?= $js_taken_ro  ?>;  // constituency_ids already having an RO
const PO_STATION_MAP     = <?= $js_po_map    ?>;  // { station_id: officer_id }
const ARO_CONST_MAP      = <?= $js_aro_map   ?>;  // { const_id: officer_id }
const RO_CONST_MAP       = <?= $js_ro_map    ?>;  // { const_id: officer_id }
// ── TOAST ──────────────────────────────────────────────────
function showToast(msg, type = 'info') {
    const container = document.getElementById('toast');
    const el = document.createElement('div');
    el.className = 'toast-msg ' + type;
    const icons = { success:'✅ ', error:'❌ ', warning:'⚠️ ', info:'ℹ️ ' };
    el.innerHTML = (icons[type] || 'ℹ️ ') + msg;
    container.appendChild(el);
    setTimeout(() => { el.style.opacity='0'; el.style.transition='opacity .4s'; setTimeout(()=>el.remove(),400); }, 4000);
}

// ── ADD FORM CLIENT-SIDE VALIDATION ────────────────────────
function validateAddForm() {
    const role    = document.getElementById('formRole').value;
    const station = parseInt(document.getElementById('formStation').value) || 0;
    const constit = parseInt(document.getElementById('formConst').value)   || 0;

    if (role === 'PO' && station) {
        if (TAKEN_PO_STATIONS.includes(station)) {
            showToast('⚠️ This station already has a Presiding Officer assigned.', 'warning');
            return false;
        }
    }
    if (role === 'ARO' && constit) {
        if (TAKEN_ARO_CONSTS.includes(constit)) {
            showToast('⚠️ This constituency already has an ARO assigned.', 'warning');
            return false;
        }
    }
    if (role === 'RO' && constit) {
        if (TAKEN_RO_CONSTS.includes(constit)) {
            showToast('⚠️ This constituency already has an RO assigned.', 'warning');
            return false;
        }
    }
    return true; // allow submit
}
// ── ADD FORM TOGGLE ────────────────────────────────────────
function toggleForm() {
    const card = document.getElementById('addFormCard');
    const open = card.style.display !== 'none';
    card.style.display = open ? 'none' : 'block';
    if (!open) card.scrollIntoView({ behavior:'smooth', block:'start' });
}
function resetFormHints() {
    document.getElementById('formRoleHint').className = 'role-hint';
    document.getElementById('formRoleHint').textContent = '';
}

// ── ROLE CHANGE HANDLER ────────────────────────────────────
// context: 'form' | 'edit'
// eid: officer_id (only used when context='edit')
function handleRoleChange(role, context, eid) {
    if (context === 'form') {
        const hint     = document.getElementById('formRoleHint');
        const stGrp    = document.getElementById('formStationGroup');
        const constGrp = document.getElementById('formConstGroup');

        if (role === 'APO' || role === 'PO') {
            hint.className = 'role-hint apo-po show';
            hint.textContent = role === 'APO'
                ? '✍️ APO: assign a Polling Station. The APO enters vote counts for their booth.'
                : '🏛️ PO: assign a Polling Station. The PO verifies and locks the station result.';
            stGrp.style.opacity = '1'; constGrp.style.opacity = '.4';
        } else if (role === 'ARO' || role === 'RO') {
            hint.className = 'role-hint aro-ro show';
            hint.textContent = role === 'ARO'
                ? '📊 ARO: assign a Constituency. The ARO consolidates all station results.'
                : '🏆 RO: assign a Constituency. The RO approves and publishes the final result.';
            stGrp.style.opacity = '.4'; constGrp.style.opacity = '1';
        } else if (role === 'ADMIN') {
            hint.className = 'role-hint admin show';
            hint.textContent = '⚙️ ADMIN: system-wide access. No station or constituency assignment needed.';
            stGrp.style.opacity = '.4'; constGrp.style.opacity = '.4';
        } else {
            hint.className = 'role-hint';
            hint.textContent = '';
            stGrp.style.opacity = '1'; constGrp.style.opacity = '1';
        }
    } else {
        // Edit row — toggle which dropdown is visible
        const stEl    = document.getElementById('edit-station-' + eid);
        const constEl = document.getElementById('edit-const-'   + eid);
        const admLbl  = document.getElementById('edit-admin-label-' + eid);

        stEl.style.display    = 'none';
        constEl.style.display = 'none';
        admLbl.style.display  = 'none';

        if (role === 'APO' || role === 'PO')   { stEl.style.display    = 'block'; }
        else if (role === 'ARO' || role === 'RO') { constEl.style.display = 'block'; }
        else if (role === 'ADMIN')              { admLbl.style.display  = 'inline'; }
    }
}

// ── INLINE EDIT ────────────────────────────────────────────
function startEdit(oid) {
    document.getElementById('row-'     + oid).style.display = 'none';
    document.getElementById('editrow-' + oid).style.display = 'table-row';
}
function cancelEdit(oid) {
    document.getElementById('editrow-' + oid).style.display = 'none';
    document.getElementById('row-'     + oid).style.display = 'table-row';
}
function saveEdit(oid) {
    const name     = document.getElementById('edit-name-'    + oid).value.trim();
    const role     = document.getElementById('edit-role-'    + oid).value;
    const username = document.getElementById('edit-username-'+ oid).value.trim();
    const nid      = document.getElementById('edit-nid-'     + oid).value.trim();
    const station  = document.getElementById('edit-station-' + oid).value;
    const constit  = document.getElementById('edit-const-'   + oid).value;
    const active   = document.getElementById('edit-active-'  + oid).value;
    const pass     = document.getElementById('edit-pass-'    + oid).value;

    if (!name)     { showToast('Full name is required.', 'warning'); return; }
    if (!username) { showToast('Username is required.', 'warning'); return; }

    // === ASSIGNMENT UNIQUENESS (client-side pre-check) ===
    const stationVal = parseInt(station) || 0;
    const constitVal = parseInt(constit) || 0;

    if (role === 'PO' && stationVal) {
        const takenBy = PO_STATION_MAP[stationVal];
        if (takenBy && takenBy !== oid) {
            showToast('⚠️ This station already has a Presiding Officer assigned.', 'warning'); return;
        }
    }
    if (role === 'ARO' && constitVal) {
        const takenBy = ARO_CONST_MAP[constitVal];
        if (takenBy && takenBy !== oid) {
            showToast('⚠️ This constituency already has an ARO assigned.', 'warning'); return;
        }
    }
    if (role === 'RO' && constitVal) {
        const takenBy = RO_CONST_MAP[constitVal];
        if (takenBy && takenBy !== oid) {
            showToast('⚠️ This constituency already has an RO assigned.', 'warning'); return;
        }
    }

    const fd = new FormData();
    fd.append('ajax_action',               'update');
    fd.append('officer_id',                oid);
    fd.append('full_name',                 name);
    fd.append('national_id',               nid);
    fd.append('role',                      role);
    fd.append('username',                  username);
    fd.append('password',                  pass);
    fd.append('assigned_station_id',       station);
    fd.append('assigned_constituency_id',  constit);
    fd.append('is_active',                 active);

    fetch(window.location.href, { method:'POST', body:fd })
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

// ── DELETE ─────────────────────────────────────────────────
let pendingDeleteId = null;

function confirmDelete(oid, name) {
    pendingDeleteId = oid;
    document.getElementById('deleteOfficerName').textContent = name;
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
    fd.append('officer_id',  pendingDeleteId);
    closeDeleteModal();

    fetch(window.location.href, { method:'POST', body:fd })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                showToast(res.message, 'success');
                ['row-','editrow-'].forEach(pfx => {
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

function escHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Auto-open form on PHP error
<?php if ($form_error): ?>
document.getElementById('addFormCard').style.display = 'block';
<?php endif; ?>
</script>
</body>
</html>
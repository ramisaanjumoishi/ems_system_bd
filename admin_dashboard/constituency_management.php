<?php
// ============================================================
//  DB CONFIG — match admin_dashboard.php exactly
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
//  AJAX HANDLERS (JSON responses)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $act = $_POST['ajax_action'];

    // ── DELETE ─────────────────────────────────────────────
    if ($act === 'delete') {
        $id = (int)($_POST['constituency_id'] ?? 0);
        try {
            $pdo->prepare("DELETE FROM constituencies WHERE constituency_id=?")->execute([$id]);
            // Audit log
            $pdo->prepare("INSERT INTO audit_logs (officer_id,action_type,affected_entity,affected_entity_id,details,ip_address) VALUES (?,?,?,?,?,?)")
                ->execute([$logged_in_id,'DELETE_CONSTITUENCY','constituencies',$id,"Deleted constituency ID $id",$_SERVER['REMOTE_ADDR']??'']);
            echo json_encode(['success'=>true,'message'=>'Constituency deleted successfully.']);
        } catch (PDOException $e) {
            echo json_encode(['success'=>false,'message'=>'Cannot delete: '.$e->getMessage()]);
        }
        exit;
    }

    // ── UPDATE ─────────────────────────────────────────────
    if ($act === 'update') {
        $id    = (int)($_POST['constituency_id']??0);
        $name  = trim($_POST['name']??'');
        $code  = trim($_POST['code']??'');
        $ro    = (int)($_POST['returning_officer_id']??0) ?: null;
        $voters= (int)($_POST['total_registered_voters']??0);
        $status= $_POST['result_status']??'PENDING';
        $allowed = ['PENDING','AGGREGATED','APPROVED','PUBLISHED'];
        if (!in_array($status,$allowed)) $status='PENDING';

             if (!$name || !$code) {
            echo json_encode(['success'=>false,'message'=>'Name and Code are required.']); exit;
        }
        // === RO UNIQUENESS CONSTRAINT (exclude the constituency being edited) ===
        if ($ro) {
            $chk = $pdo->prepare("SELECT constituency_id FROM constituencies WHERE returning_officer_id=? AND constituency_id != ?");
            $chk->execute([$ro, $id]);
            if ($chk->fetch()) {
                echo json_encode(['success'=>false,'message'=>'This Returning Officer is already assigned to another constituency.']); exit;
            }
        }
        try {
            $pdo->prepare("UPDATE constituencies SET name=?,code=?,returning_officer_id=?,total_registered_voters=?,result_status=? WHERE constituency_id=?")
                ->execute([$name,$code,$ro,$voters,$status,$id]);
            $pdo->prepare("INSERT INTO audit_logs (officer_id,action_type,affected_entity,affected_entity_id,details,ip_address) VALUES (?,?,?,?,?,?)")
                ->execute([$logged_in_id,'UPDATE_CONSTITUENCY','constituencies',$id,"Updated constituency '$name'",$_SERVER['REMOTE_ADDR']??'']);
            echo json_encode(['success'=>true,'message'=>"Constituency '$name' updated."]);
        } catch (PDOException $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }

    // ── GET POLLING STATIONS FOR CONSTITUENCY ──────────────
    if ($act === 'get_stations') {
        $id = (int)($_POST['constituency_id']??0);
        $stations = $pdo->prepare("
            SELECT ps.station_id, ps.name, ps.address, ps.result_status, ps.total_ballots_issued,
                   eo.full_name AS officer_name
            FROM polling_stations ps
            LEFT JOIN election_officers eo ON eo.officer_id = ps.presiding_officer_id
            WHERE ps.constituency_id=?
            ORDER BY ps.station_id
        ");
        $stations->execute([$id]);
        echo json_encode(['success'=>true,'stations'=>$stations->fetchAll()]);
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action.']); exit;
}

// ============================================================
//  CREATE NEW CONSTITUENCY (form POST)
// ============================================================
$form_success = $form_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_constituency'])) {
    $name   = trim($_POST['name']??'');
    $code   = trim($_POST['code']??'');
    $ro     = (int)($_POST['returning_officer_id']??0) ?: null;
    $voters = (int)($_POST['total_registered_voters']??0);
    $status = $_POST['result_status']??'PENDING';
    $allowed = ['PENDING','AGGREGATED','APPROVED','PUBLISHED'];
    if (!in_array($status,$allowed)) $status='PENDING';

    if (!$name || !$code) {
        $form_error = 'Constituency Name and Code are required.';
    } else {
        // === RO UNIQUENESS CONSTRAINT ===
        if ($ro) {
            $chk = $pdo->prepare("SELECT constituency_id FROM constituencies WHERE returning_officer_id=?");
            $chk->execute([$ro]);
            if ($chk->fetch()) {
                $form_error = 'This Returning Officer is already assigned to another constituency. Each RO can only be assigned to one constituency.';
            }
        }

        if (!$form_error) {
            try {
                $pdo->prepare("INSERT INTO constituencies (name,code,returning_officer_id,total_registered_voters,result_status) VALUES (?,?,?,?,?)")
                    ->execute([$name,$code,$ro,$voters,$status]);
                $new_id = $pdo->lastInsertId();
                $pdo->prepare("INSERT INTO audit_logs (officer_id,action_type,affected_entity,affected_entity_id,details,ip_address) VALUES (?,?,?,?,?,?)")
                    ->execute([$logged_in_id,'CREATE_CONSTITUENCY','constituencies',$new_id,"Created constituency '$name' ($code)",$_SERVER['REMOTE_ADDR']??'']);
                $form_success = "Constituency <strong>$name</strong> created successfully.";
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
$admin = $pdo->prepare("SELECT * FROM election_officers WHERE officer_id=?");
$admin->execute([$logged_in_id]);
$admin = $admin->fetch();

// Returning officers list
$ros = $pdo->query("SELECT officer_id,full_name FROM election_officers WHERE role='RO' AND is_active=1 ORDER BY full_name")->fetchAll();

// ROs already assigned to a constituency: { officer_id => constituency_id }
$taken_ro_map = $pdo->query("SELECT returning_officer_id, constituency_id FROM constituencies WHERE returning_officer_id IS NOT NULL")->fetchAll(PDO::FETCH_KEY_PAIR);
$taken_ro_ids = array_map('intval', array_keys($taken_ro_map)); // just the officer_ids
$js_taken_ro_map = json_encode(array_map('intval', $taken_ro_map)); // for JS

// ROs already assigned to a constituency: { officer_id => constituency_id }
$taken_ro_map = $pdo->query("SELECT returning_officer_id, constituency_id FROM constituencies WHERE returning_officer_id IS NOT NULL")->fetchAll(PDO::FETCH_KEY_PAIR);
$taken_ro_ids = array_map('intval', array_keys($taken_ro_map)); // just the officer_ids
$js_taken_ro_map = json_encode(array_map('intval', $taken_ro_map)); // for JS

// Search
$search = trim($_GET['q']??'');
$sqlBase = "
    SELECT c.*, eo.full_name AS ro_name,
           (SELECT COUNT(*) FROM polling_stations ps WHERE ps.constituency_id=c.constituency_id) AS station_count
    FROM constituencies c
    LEFT JOIN election_officers eo ON eo.officer_id=c.returning_officer_id
";
if ($search !== '') {
    $likeSearch = '%'.$search.'%';
    $constStmt  = $pdo->prepare($sqlBase." WHERE c.name LIKE ? OR c.code LIKE ? ORDER BY c.constituency_id");
    $constStmt->execute([$likeSearch,$likeSearch]);
} else {
    $constStmt = $pdo->query($sqlBase." ORDER BY c.constituency_id");
}
$constituencies = $constStmt->fetchAll();

// Stats
$total_const    = $pdo->query("SELECT COUNT(*) FROM constituencies")->fetchColumn();
$total_stations = $pdo->query("SELECT COUNT(*) FROM polling_stations")->fetchColumn();
$pending_count  = $pdo->query("SELECT COUNT(*) FROM constituencies WHERE result_status='PENDING'")->fetchColumn();
$approved_count = $pdo->query("SELECT COUNT(*) FROM constituencies WHERE result_status IN('APPROVED','PUBLISHED')")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Constituency Management — EMS Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500;600&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<style>
/* ============================================================
   ROOT — IDENTICAL TO admin_dashboard.php
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
   TOPBAR — exact copy from admin_dashboard.php
============================================================ */
.topbar {
    background: linear-gradient(135deg, var(--navy) 0%, var(--navy2) 60%, var(--navy3) 100%);
    padding: 0 32px;
    height: 66px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky; top: 0; z-index: 200;
    box-shadow: 0 2px 24px rgba(10,22,40,.35);
    border-bottom: 1px solid rgba(255,255,255,.08);
}
.topbar-brand { display: flex; align-items: center; gap: 14px; }
.brand-emblem {
    width: 40px; height: 40px;
    background: linear-gradient(135deg, var(--primary), var(--accent));
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px;
    box-shadow: 0 2px 12px rgba(14,165,233,.3);
    flex-shrink: 0;
}
.brand-text { font-family: var(--font-head); font-size: 15px; font-weight: 700; color: #fff; letter-spacing: .2px; line-height: 1.2; }
.brand-sub  { font-size: 11px; color: rgba(255,255,255,.5); letter-spacing: .5px; text-transform: uppercase; font-weight: 400; }
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
.admin-info .name      { font-size: 12.5px; font-weight: 600; color: #fff; line-height: 1.2; }
.admin-info .role-tag  { font-size: 10px; color: var(--accent2); letter-spacing: .5px; text-transform: uppercase; font-weight: 500; }
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
    display: flex;
    align-items: center;
    gap: 2px;
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
    margin-bottom: -2px;
    white-space: nowrap;
    transition: all .18s;
}
.sub-nav-link:hover { color: var(--primary); }
.sub-nav-link.active { color: var(--primary); border-bottom-color: var(--primary); background: rgba(26,86,219,.04); }

/* ============================================================
   PAGE WRAP
============================================================ */
.page-wrap {
    max-width: 1380px;
    margin: 0 auto;
    padding: 28px 28px 56px;
    width: 100%;
    flex: 1;
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
    background: linear-gradient(135deg, var(--primary), var(--accent));
    border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    font-size: 24px;
    box-shadow: 0 4px 16px rgba(26,86,219,.28);
    flex-shrink: 0;
}
.page-title { font-family: var(--font-head); font-size: 26px; font-weight: 800; color: var(--navy); line-height: 1.1; letter-spacing: -.3px; }
.page-subtitle { font-size: 13px; color: var(--muted); margin-top: 4px; }
.breadcrumb { font-size: 11.5px; color: var(--muted2); margin-bottom: 4px; }
.breadcrumb a { color: var(--primary); font-weight: 600; }
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
.btn-primary {
    background: linear-gradient(135deg, var(--primary), var(--primary2));
    color: #fff;
    box-shadow: 0 2px 10px rgba(26,86,219,.25);
}
.btn-primary:hover  { transform: translateY(-1px); box-shadow: 0 4px 18px rgba(26,86,219,.35); }
.btn-secondary      { background: var(--surface); color: var(--text2); border: 1.5px solid var(--border); }
.btn-secondary:hover{ border-color: var(--primary); color: var(--primary); background: #eff6ff; }
.btn-danger         { background: rgba(239,68,68,.08); color: var(--danger); border: 1.5px solid rgba(239,68,68,.25); }
.btn-danger:hover   { background: var(--danger); color: #fff; }
.btn-success        { background: rgba(16,185,129,.1); color: var(--success); border: 1.5px solid rgba(16,185,129,.3); }
.btn-success:hover  { background: var(--success); color: #fff; }
.btn-info           { background: rgba(14,165,233,.1); color: var(--accent); border: 1.5px solid rgba(14,165,233,.3); font-size: 12px; padding: 7px 14px; }
.btn-info:hover     { background: var(--accent); color: #fff; }
.btn-sm             { font-size: 12px; padding: 6px 13px; }
.btn-xs             { font-size: 11px; padding: 4px 10px; border-radius: 6px; }

/* ============================================================
   STAT TILES
============================================================ */
.stat-strip { display: grid; grid-template-columns: repeat(4,1fr); gap: 16px; margin-bottom: 22px; }
@media(max-width:960px){ .stat-strip{grid-template-columns:repeat(2,1fr);} }
.stat-tile {
    background: var(--surface);
    border-radius: var(--radius);
    border: 1.5px solid var(--border);
    padding: 20px 22px;
    position: relative; overflow: hidden;
    transition: all .22s;
    animation: fadeUp .4s ease both;
}
.stat-tile:hover { transform: translateY(-3px); box-shadow: var(--shadow-lg); border-color: var(--border2); }
.st-icon {
    width: 44px; height: 44px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; margin-bottom: 14px;
}
.st-icon.blue   { background: #dbeafe; }
.st-icon.green  { background: #dcfce7; }
.st-icon.gold   { background: #fef3c7; }
.st-icon.purple { background: #ede9fe; }
.st-value { font-family: var(--font-mono); font-size: 26px; font-weight: 600; color: var(--text); line-height: 1; margin-bottom: 5px; letter-spacing: -.5px; }
.st-value.blue   { color: var(--primary); }
.st-value.green  { color: var(--success); }
.st-value.gold   { color: var(--gold); }
.st-value.purple { color: var(--purple); }
.st-label { font-size: 11.5px; font-weight: 600; text-transform: uppercase; letter-spacing: .6px; color: var(--muted); }
.st-delta { position: absolute; top: 16px; right: 16px; font-size: 11px; font-weight: 600; padding: 3px 9px; border-radius: 20px; }
.st-delta.up   { background: #dcfce7; color: var(--success); }
.st-delta.info { background: #dbeafe; color: var(--primary); }
.st-delta.warn { background: #fef3c7; color: var(--gold); }
.st-delta.red  { background: #fee2e2; color: var(--danger); }

/* ============================================================
   CARDS
============================================================ */
.card {
    background: var(--surface);
    border-radius: var(--radius);
    border: 1.5px solid var(--border);
    overflow: hidden;
    box-shadow: var(--shadow);
    margin-bottom: 22px;
}
.card-header {
    padding: 18px 24px 14px;
    display: flex; align-items: center; justify-content: space-between;
    border-bottom: 1px solid var(--border);
    background: #fafbfc; flex-wrap: wrap; gap: 10px;
}
.card-title {
    font-family: var(--font-head); font-size: 14.5px; font-weight: 700; color: var(--text);
    display: flex; align-items: center; gap: 8px;
}
.card-body { padding: 22px 24px; }
.card-badge {
    font-size: 11px; font-weight: 600; padding: 3px 10px; border-radius: 20px;
}
.badge-blue  { background: #dbeafe; color: var(--primary); }
.badge-green { background: #dcfce7; color: var(--success); }
.badge-gold  { background: #fef3c7; color: var(--gold); }
.count-pill {
    display: inline-flex; align-items: center; justify-content: center;
    background: var(--bg2); border: 1px solid var(--border);
    border-radius: 20px; font-size: 12px; font-weight: 700;
    color: var(--text2); padding: 2px 10px; font-family: var(--font-head);
}

/* ============================================================
   FORM INPUTS
============================================================ */
.form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.form-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
@media(max-width:768px){ .form-grid-2,.form-grid-3{grid-template-columns:1fr;} }
.form-group { display: flex; flex-direction: column; gap: 6px; }
.form-label {
    font-size: 11.5px; font-weight: 700; color: var(--text2);
    letter-spacing: .4px; text-transform: uppercase;
}
.form-label .req { color: var(--danger); margin-left: 2px; }
.form-control {
    padding: 10px 14px;
    border: 1.5px solid var(--border);
    border-radius: var(--radius-sm);
    font-size: 13.5px; font-family: var(--font-body); color: var(--text);
    background: var(--surface);
    transition: border-color .18s, box-shadow .18s;
    outline: none; width: 100%;
}
.form-control:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,86,219,.12); }
.form-control::placeholder { color: var(--muted2); }
.form-helper { font-size: 11.5px; color: var(--muted); margin-top: 16px; padding: 10px 14px; background: #f1f5f9; border-radius: var(--radius-sm); border-left: 3px solid var(--accent); }

/* ============================================================
   SEARCH BAR
============================================================ */
.search-bar {
    display: flex; align-items: center; gap: 10px;
    flex-wrap: wrap;
}
.search-input-wrap { position: relative; flex: 1; min-width: 220px; }
.search-input-wrap .search-icon {
    position: absolute; left: 13px; top: 50%; transform: translateY(-50%);
    color: var(--muted2); font-size: 14px; pointer-events: none;
}
.search-input-wrap input {
    width: 100%; padding: 10px 14px 10px 38px;
    border: 1.5px solid var(--border); border-radius: var(--radius-sm);
    font-size: 13.5px; font-family: var(--font-body); color: var(--text);
    background: var(--surface); outline: none; transition: border-color .18s, box-shadow .18s;
}
.search-input-wrap input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,86,219,.10); }

/* ============================================================
   TABLE
============================================================ */
.table-wrap { overflow-x: auto; }
.mgmt-table {
    width: 100%; border-collapse: collapse; font-size: 13.5px;
}
.mgmt-table thead tr { background: #f1f5f9; }
.mgmt-table th {
    padding: 11px 16px;
    text-align: left; font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .6px; color: var(--muted);
    border-bottom: 2px solid var(--border); white-space: nowrap;
}
.mgmt-table td {
    padding: 13px 16px;
    border-bottom: 1px solid var(--border);
    vertical-align: middle;
}
.mgmt-table tbody tr { transition: background .15s; }
.mgmt-table tbody tr:hover { background: #f8fafc; }
.mgmt-table tbody tr:last-child td { border-bottom: none; }

/* Editing row highlight */
.mgmt-table tbody tr.editing-row { background: #eff6ff !important; outline: 2px solid var(--primary); outline-offset: -1px; }
.mgmt-table tbody tr.editing-row td { background: #eff6ff; }

.edit-input {
    padding: 7px 10px; border: 1.5px solid var(--primary);
    border-radius: 6px; font-size: 13px; font-family: var(--font-body);
    color: var(--text); width: 100%; outline: none; min-width: 80px;
    box-shadow: 0 0 0 2px rgba(26,86,219,.12);
}
.edit-select {
    padding: 7px 10px; border: 1.5px solid var(--primary);
    border-radius: 6px; font-size: 13px; font-family: var(--font-body);
    color: var(--text); outline: none; min-width: 100px;
    box-shadow: 0 0 0 2px rgba(26,86,219,.12); background: #fff;
}

.cell-main   { font-weight: 700; color: var(--text); font-size: 13.5px; }
.cell-sub    { font-size: 11px; color: var(--muted); margin-top: 2px; }
.id-badge    { font-family: var(--font-mono); font-size: 12px; font-weight: 600; background: var(--bg2); border: 1px solid var(--border); border-radius: 6px; padding: 3px 8px; color: var(--text2); }
.code-badge  { font-family: var(--font-mono); font-size: 12px; font-weight: 600; background: #dbeafe; color: var(--primary); border-radius: 6px; padding: 3px 9px; letter-spacing: .4px; }
.voter-count { font-family: var(--font-mono); font-size: 15px; font-weight: 600; color: var(--text); }

/* Status pills */
.status-pill {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 12px; border-radius: 20px;
    font-size: 11.5px; font-weight: 700; letter-spacing: .3px; white-space: nowrap;
}
.sp-published  { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
.sp-approved   { background: #dbeafe; color: #1e40af; border: 1px solid #93c5fd; }
.sp-aggregated { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
.sp-pending    { background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; }

/* Action buttons row */
.action-row { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }

/* Station count badge */
.station-badge {
    display: inline-flex; align-items: center; gap: 5px;
    background: #e0f2fe; color: #0369a1;
    border: 1px solid #bae6fd; border-radius: 6px;
    font-size: 11.5px; font-weight: 700; padding: 3px 9px; cursor: pointer;
    transition: all .18s;
}
.station-badge:hover { background: var(--accent); color: #fff; border-color: var(--accent); }

/* ============================================================
   EXPAND PANEL (polling stations under constituency)
============================================================ */
.expand-row { display: none; }
.expand-row.open { display: table-row; }
.expand-cell {
    padding: 0 !important; border-bottom: 2px solid var(--primary) !important;
}
.expand-inner {
    background: linear-gradient(180deg, #f0f7ff 0%, #f8faff 100%);
    border-top: 1px solid #c7dffb;
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
    font-size: 11.5px; color: var(--accent); background: #e0f2fe;
    border: 1px solid #bae6fd; border-radius: 6px; padding: 5px 12px;
    display: inline-flex; align-items: center; gap: 6px;
}

.station-table {
    width: 100%; border-collapse: collapse; font-size: 13px;
    border-radius: var(--radius-sm); overflow: hidden;
    border: 1.5px solid #c7dffb;
}
.station-table thead tr { background: #dbeafe; }
.station-table th {
    padding: 10px 14px; text-align: left;
    font-size: 10.5px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .5px; color: var(--primary2);
    border-bottom: 1.5px solid #bfdbfe;
}
.station-table td { padding: 11px 14px; border-bottom: 1px solid #e0eeff; vertical-align: middle; }
.station-table tbody tr:hover { background: #eff6ff; }
.station-table tbody tr:last-child td { border-bottom: none; }
.station-table .st-name { font-weight: 600; color: var(--text); }
.station-table .st-addr { font-size: 11.5px; color: var(--muted); }

/* Verified/Pending/Submitted station status */
.ss-verified  { background: #dcfce7; color: #166534; border: 1px solid #86efac; border-radius: 20px; padding: 3px 10px; font-size: 11px; font-weight: 700; }
.ss-submitted { background: #dbeafe; color: #1e40af; border: 1px solid #93c5fd; border-radius: 20px; padding: 3px 10px; font-size: 11px; font-weight: 700; }
.ss-pending   { background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; border-radius: 20px; padding: 3px 10px; font-size: 11px; font-weight: 700; }

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
    background: var(--surface); border: 1.5px solid var(--border);
    color: var(--text2); transition: all .18s;
}
.pag-btn:hover  { border-color: var(--primary); color: var(--primary); background: #eff6ff; }
.pag-btn.active { background: var(--primary); color: #fff; border-color: var(--primary); }
.pag-btn.disabled { opacity: .4; pointer-events: none; }

/* ============================================================
   MODAL — DELETE CONFIRM
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
    box-shadow: var(--shadow-lg);
    animation: popIn .22s ease;
}
@keyframes popIn { from{opacity:0;transform:scale(.93);} to{opacity:1;transform:scale(1);} }
.modal-icon { font-size: 44px; text-align: center; margin-bottom: 14px; }
.modal-title { font-family: var(--font-head); font-size: 18px; font-weight: 800; color: var(--text); text-align: center; margin-bottom: 10px; }
.modal-body  { font-size: 13.5px; color: var(--muted); text-align: center; line-height: 1.6; margin-bottom: 24px; }
.modal-body strong { color: var(--danger); }
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
   ALERTS (PHP form feedback)
============================================================ */
.alert {
    padding: 12px 16px; border-radius: var(--radius-sm);
    font-size: 13.5px; font-weight: 500; margin-bottom: 18px;
    display: flex; align-items: center; gap: 10px;
}
.alert-success { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
.alert-error   { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }

/* ============================================================
   FOOTER
============================================================ */
.footer {
    background: var(--navy);
    padding: 18px 32px;
    display: flex; align-items: center; justify-content: space-between;
    font-size: 12px; color: #64748b;
    margin-top: auto; flex-wrap: wrap; gap: 8px;
}
.footer-links { display: flex; gap: 18px; }
.footer-links a { color: #64748b; text-decoration: none; transition: color .2s; }
.footer-links a:hover { color: #60a5fa; }
.divider { border: none; border-top: 1.5px solid var(--border); margin: 0; }

/* ============================================================
   ANIMATIONS
============================================================ */
@keyframes fadeUp { from{opacity:0;transform:translateY(18px);} to{opacity:1;transform:translateY(0);} }
.stat-tile:nth-child(1){animation:fadeUp .4s ease .05s both;}
.stat-tile:nth-child(2){animation:fadeUp .4s ease .10s both;}
.stat-tile:nth-child(3){animation:fadeUp .4s ease .15s both;}
.stat-tile:nth-child(4){animation:fadeUp .4s ease .20s both;}
</style>
</head>
<body>

<!-- ═══════════════════════════════════════════════
     TOPBAR  (identical to admin_dashboard.php)
═══════════════════════════════════════════════ -->
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

<!-- ═══════════════════════════════════════════════
     SECONDARY NAVBAR
═══════════════════════════════════════════════ -->
<nav class="sub-nav">
    <a class="sub-nav-link" href="admin_dashboard.php">🏠 Dashboard</a>
    <a class="sub-nav-link active" href="constituency_management.php">🗺️ Constituencies</a>
    <a class="sub-nav-link" href="polling_station_management.php">🏫 Polling Stations</a>
    <a class="sub-nav-link" href="booth_management.php">🚪 Booth Management</a>
    <a class="sub-nav-link" href="candidate_management.php">🎖️ Candidates</a>
    <a class="sub-nav-link" href="party_management.php">🏳️ Political Parties</a>
    <a class="sub-nav-link" href="officer_management.php">👮 Officers</a>
    <a class="sub-nav-link " href="voter_management.php">🧑‍🤝‍🧑 Voters</a>
  
</nav>

<!-- ═══════════════════════════════════════════════
     PAGE CONTENT
═══════════════════════════════════════════════ -->
<div class="page-wrap">

    <!-- ── PAGE HEADER ─────────────────────────── -->
    <div class="page-header">
        <div class="page-header-left">
            <div class="page-header-icon">🗺️</div>
            <div>
                <div class="breadcrumb"><a href="admin_dashboard.php">Dashboard</a> / Constituency Management</div>
                <div class="page-title">Constituency Management</div>
                <div class="page-subtitle">Manage election regions, assigned officers, and linked polling stations.</div>
            </div>
        </div>
        <div class="header-actions">
            <button class="btn btn-secondary" onclick="toggleForm()">➕ Add Constituency</button>
            <button class="btn btn-secondary" onclick="location.reload()">🔄 Refresh</button>
            <a href="admin_dashboard.php" class="btn btn-secondary">← Back to Dashboard</a>
        </div>
    </div>

    <!-- ── STAT STRIP ──────────────────────────── -->
    <div class="stat-strip">
        <div class="stat-tile">
            <div class="st-delta info">Total</div>
            <div class="st-icon blue">🗺️</div>
            <div class="st-value blue"><?= number_format((int)$total_const) ?></div>
            <div class="st-label">Constituencies</div>
        </div>
        <div class="stat-tile">
            <div class="st-delta info">All</div>
            <div class="st-icon green">🏫</div>
            <div class="st-value green"><?= number_format((int)$total_stations) ?></div>
            <div class="st-label">Polling Stations</div>
        </div>
        <div class="stat-tile">
            <div class="st-delta up">Cleared</div>
            <div class="st-icon gold">✅</div>
            <div class="st-value gold"><?= number_format((int)$approved_count) ?></div>
            <div class="st-label">Approved / Published</div>
        </div>
        <div class="stat-tile">
            <div class="st-delta red">Awaiting</div>
            <div class="st-icon purple">⏳</div>
            <div class="st-value purple"><?= number_format((int)$pending_count) ?></div>
            <div class="st-label">Pending Results</div>
        </div>
    </div>

    <!-- ── PHP FEEDBACK ────────────────────────── -->
    <?php if ($form_success): ?>
    <div class="alert alert-success">✅ <?= $form_success ?></div>
    <?php endif; ?>
    <?php if ($form_error): ?>
    <div class="alert alert-error">❌ <?= htmlspecialchars($form_error) ?></div>
    <?php endif; ?>

    <!-- ── ADD CONSTITUENCY FORM ───────────────── -->
    <div class="card" id="addFormCard" style="display:none; border-color: var(--primary);">
        <div class="card-header">
            <span class="card-title">➕ Add New Constituency</span>
            <button class="btn btn-sm btn-secondary" onclick="toggleForm()">✕ Close</button>
        </div>
        <div class="card-body">
            <form method="POST" action="" onsubmit="return validateAddForm()">
                <div class="form-grid-3" style="margin-bottom:16px;">
                    <div class="form-group">
                        <label class="form-label">Constituency Name <span class="req">*</span></label>
                        <input type="text" name="name" class="form-control" placeholder="e.g. Dhaka-1" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Code <span class="req">*</span></label>
                        <input type="text" name="code" class="form-control" placeholder="e.g. DHK-01" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Returning Officer</label>
                        <select name="returning_officer_id" class="form-control">
                            <option value="">— Not Assigned —</option>
                            <?php foreach ($ros as $ro):
                                $is_taken = in_array((int)$ro['officer_id'], $taken_ro_ids);
                            ?>
                            <option value="<?= $ro['officer_id'] ?>" <?= $is_taken ? 'data-taken="1"' : '' ?>>
                                <?= htmlspecialchars($ro['full_name']) ?><?= $is_taken ? ' ⚠ Already Assigned' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-grid-2" style="margin-bottom:18px;">
                    <div class="form-group">
                        <label class="form-label">Total Registered Voters</label>
                        <input type="number" name="total_registered_voters" class="form-control" placeholder="e.g. 250000" min="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Result Status</label>
                        <select name="result_status" class="form-control">
                            <option value="PENDING">PENDING</option>
                            <option value="AGGREGATED">AGGREGATED</option>
                            <option value="APPROVED">APPROVED</option>
                            <option value="PUBLISHED">PUBLISHED</option>
                        </select>
                    </div>
                </div>
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <button type="submit" name="create_constituency" class="btn btn-primary">💾 Save Constituency</button>
                    <button type="reset" class="btn btn-secondary">↺ Reset Form</button>
                </div>
                <div class="form-helper">💡 Each constituency must have a unique code and should be assigned a Returning Officer for result compilation.</div>
            </form>
        </div>
    </div>

    <!-- ── SEARCH BAR ──────────────────────────── -->
    <div class="card">
        <div class="card-body" style="padding:16px 22px;">
            <form method="GET" action="">
                <div class="search-bar">
                    <div class="search-input-wrap">
                        <span class="search-icon">🔍</span>
                        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                               placeholder="Search by constituency name or code…">
                    </div>
                    <button type="submit" class="btn btn-primary">Search</button>
                    <?php if ($search): ?>
                    <a href="constituency_management.php" class="btn btn-secondary">✕ Clear</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- ── MAIN TABLE CARD ─────────────────────── -->
    <div class="card">
        <div class="card-header">
            <span class="card-title">🗺️ Constituency Records
                <?php if ($search): ?>
                <span style="font-size:12px;font-weight:400;color:var(--muted);">Search: "<?= htmlspecialchars($search) ?>"</span>
                <?php endif; ?>
            </span>
            <span class="count-pill"><?= count($constituencies) ?> found</span>
        </div>

        <div class="table-wrap">
            <table class="mgmt-table" id="constTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Constituency</th>
                        <th>Code</th>
                        <th>Returning Officer</th>
                        <th>Reg. Voters</th>
                        <th>Stations</th>
                        <th>Status</th>
                        <th style="min-width:260px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($constituencies)): ?>
                <tr>
                    <td colspan="8" style="text-align:center;padding:40px;color:var(--muted);">
                        No constituencies found<?= $search ? ' for "'.$search.'"' : '' ?>.
                    </td>
                </tr>
                <?php endif; ?>

                <?php foreach ($constituencies as $c):
                    $cid    = (int)$c['constituency_id'];
                    $status = $c['result_status'];
                    $spill  = [
                        'PUBLISHED'  => 'sp-published',
                        'APPROVED'   => 'sp-approved',
                        'AGGREGATED' => 'sp-aggregated',
                        'PENDING'    => 'sp-pending',
                    ][$status] ?? 'sp-pending';
                    $statusDot = [
                        'PUBLISHED'  => '🟢',
                        'APPROVED'   => '🔵',
                        'AGGREGATED' => '🟡',
                        'PENDING'    => '⚪',
                    ][$status] ?? '⚪';
                ?>
                <!-- DISPLAY ROW -->
                <tr class="data-row" id="row-<?= $cid ?>">
                    <td><span class="id-badge">#<?= $cid ?></span></td>
                    <td>
                        <div class="cell-main"><?= htmlspecialchars($c['name']) ?></div>
                    </td>
                    <td><span class="code-badge"><?= htmlspecialchars($c['code']) ?></span></td>
                    <td>
                        <?php if ($c['ro_name']): ?>
                        <div class="cell-main" style="font-size:13px;"><?= htmlspecialchars($c['ro_name']) ?></div>
                        <div class="cell-sub">Returning Officer</div>
                        <?php else: ?>
                        <span style="color:var(--muted2);font-size:12.5px;">— Not Assigned —</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="voter-count"><?= number_format((int)$c['total_registered_voters']) ?></div>
                        <div class="cell-sub">voters</div>
                    </td>
                    <td>
                        <span class="station-badge" onclick="toggleStations(<?= $cid ?>)" id="stBadge-<?= $cid ?>">
                            🏫 <?= (int)$c['station_count'] ?> Stations ▾
                        </span>
                    </td>
                    <td>
                        <span class="status-pill <?= $spill ?>"><?= $statusDot ?> <?= $status ?></span>
                    </td>
                    <td>
                        <div class="action-row">
                            <button class="btn btn-sm btn-secondary" onclick="startEdit(<?= $cid ?>)">✏️ Edit</button>
                            <button class="btn btn-sm btn-danger" onclick="confirmDelete(<?= $cid ?>, '<?= htmlspecialchars(addslashes($c['name'])) ?>')">🗑️</button>
                            <button class="btn btn-sm btn-info" onclick="toggleStations(<?= $cid ?>)">🏫 Stations</button>
                        </div>
                    </td>
                </tr>

                <!-- EDIT ROW (hidden initially) -->
                <tr class="edit-row editing-row" id="editrow-<?= $cid ?>" style="display:none;">
                    <td><span class="id-badge">#<?= $cid ?></span></td>
                    <td>
                        <input class="edit-input" id="edit-name-<?= $cid ?>" value="<?= htmlspecialchars($c['name']) ?>" placeholder="Constituency name" style="min-width:160px;">
                    </td>
                    <td>
                        <input class="edit-input" id="edit-code-<?= $cid ?>" value="<?= htmlspecialchars($c['code']) ?>" placeholder="Code" style="min-width:90px;">
                    </td>
                    <td>
                        <select class="edit-select" id="edit-ro-<?= $cid ?>">
                            <option value="">— None —</option>
                            <?php foreach ($ros as $ro):
                                $rid = (int)$ro['officer_id'];
                                // Taken by a DIFFERENT constituency (own current assignment is allowed)
                                $taken_by_other = isset($taken_ro_map[$rid]) && (int)$taken_ro_map[$rid] !== $cid;
                            ?>
                            <option value="<?= $rid ?>"
                                <?= $rid == $c['returning_officer_id'] ? 'selected' : '' ?>
                                <?= $taken_by_other ? 'disabled' : '' ?>>
                                <?= htmlspecialchars($ro['full_name']) ?><?= $taken_by_other ? ' ⚠ Already Assigned' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <input class="edit-input" type="number" id="edit-voters-<?= $cid ?>" value="<?= (int)$c['total_registered_voters'] ?>" min="0" style="min-width:100px;">
                    </td>
                    <td>
                        <span class="station-badge">🏫 <?= (int)$c['station_count'] ?></span>
                    </td>
                    <td>
                        <select class="edit-select" id="edit-status-<?= $cid ?>">
                            <?php foreach (['PENDING','AGGREGATED','APPROVED','PUBLISHED'] as $s): ?>
                            <option value="<?= $s ?>" <?= $s === $status ? 'selected' : '' ?>><?= $s ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <div class="action-row">
                            <button class="btn btn-sm btn-success" onclick="saveEdit(<?= $cid ?>)">💾 Save</button>
                            <button class="btn btn-sm btn-secondary" onclick="cancelEdit(<?= $cid ?>)">✕ Cancel</button>
                        </div>
                    </td>
                </tr>

                <!-- EXPAND ROW (polling stations) -->
                <tr class="expand-row" id="expand-<?= $cid ?>">
                    <td class="expand-cell" colspan="8">
                        <div class="expand-inner" id="expand-inner-<?= $cid ?>">
                            <div class="expand-header">
                                <div class="expand-title">
                                    🏫 Polling Stations under <strong><?= htmlspecialchars($c['name']) ?></strong>
                                </div>
                                <span class="hierarchy-note">ℹ️ Constituency → Polling Station → Booth</span>
                            </div>
                            <div id="stations-content-<?= $cid ?>">
                                <div style="text-align:center;padding:24px;color:var(--muted);font-size:13px;">Loading stations…</div>
                            </div>
                            <div style="margin-top:10px;font-size:11.5px;color:var(--muted);">
                                📌 Each polling station belongs to only one constituency. To manage booths, visit Booth Management.
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
            <div>Showing <strong><?= count($constituencies) ?></strong> constituency records<?= $search ? ' matching <em>"'.htmlspecialchars($search).'"</em>' : '' ?></div>
            <div class="pagination">
                <div class="pag-btn disabled">‹</div>
                <div class="pag-btn active">1</div>
                <div class="pag-btn disabled">›</div>
            </div>
        </div>
    </div>

</div><!-- /page-wrap -->

<!-- ═══════════════════════════════════════════════
     DELETE CONFIRM MODAL
═══════════════════════════════════════════════ -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal-box">
        <div class="modal-icon">⚠️</div>
        <div class="modal-title">Delete Constituency?</div>
        <div class="modal-body">
            Are you sure you want to remove <strong id="deleteConstName">this constituency</strong>?<br>
            Deleting it may affect linked polling stations, booths, candidates, and election result data. This action <strong>cannot be undone.</strong>
        </div>
        <div class="modal-actions">
            <button class="btn btn-danger" id="confirmDeleteBtn">🗑️ Confirm Delete</button>
            <button class="btn btn-secondary" onclick="closeDeleteModal()">✕ Cancel</button>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════
     FOOTER
═══════════════════════════════════════════════ -->
<footer class="footer">
    <div>🗳️ EMS Admin &nbsp;·&nbsp; Constituency Management &nbsp;·&nbsp; © 2026 Bangladesh Election Commission. All rights reserved.</div>
    <div class="footer-links">
        <a href="#">Privacy Policy</a>
        <a href="#">Security Audit</a>
        <a href="#">Help &amp; Documentation</a>
        <a href="#">Contact Support</a>
    </div>
</footer>

<!-- TOAST CONTAINER -->
<div id="toast"></div>

<script>

const TAKEN_RO_MAP = <?= $js_taken_ro_map ?>;
// ─────────────────────────────────────────────────────────────
//  TOAST
// ─────────────────────────────────────────────────────────────
function showToast(msg, type = 'info') {
    const container = document.getElementById('toast');
    const el = document.createElement('div');
    el.className = 'toast-msg ' + type;
    const icons = { success:'✅ ', error:'❌ ', warning:'⚠️ ', info:'ℹ️ ' };
    el.innerHTML = (icons[type]||'ℹ️ ') + msg;
    container.appendChild(el);
    setTimeout(() => { el.style.opacity='0'; el.style.transition='opacity .4s'; setTimeout(()=>el.remove(),400); }, 4000);
}
// ─────────────────────────────────────────────────────────────
//  ADD FORM VALIDATION
// ─────────────────────────────────────────────────────────────
function validateAddForm() {
    const ro = parseInt(document.querySelector('select[name="returning_officer_id"]').value) || 0;
    if (ro && TAKEN_RO_MAP.hasOwnProperty(ro)) {
        showToast('⚠️ This Returning Officer is already assigned to another constituency.', 'warning');
        return false;
    }
    return true;
}

// ─────────────────────────────────────────────────────────────
//  ADD FORM TOGGLE
// ─────────────────────────────────────────────────────────────
function toggleForm() {
    const card = document.getElementById('addFormCard');
    const visible = card.style.display !== 'none';
    card.style.display = visible ? 'none' : 'block';
    if (!visible) card.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ─────────────────────────────────────────────────────────────
//  INLINE EDIT
// ─────────────────────────────────────────────────────────────
function startEdit(cid) {
    document.getElementById('row-' + cid).style.display = 'none';
    const er = document.getElementById('editrow-' + cid);
    er.style.display = 'table-row';
    // Close expand row if open
    closeStations(cid);
}

function cancelEdit(cid) {
    document.getElementById('editrow-' + cid).style.display = 'none';
    document.getElementById('row-' + cid).style.display = 'table-row';
}

function saveEdit(cid) {
    const name   = document.getElementById('edit-name-'   + cid).value.trim();
    const code   = document.getElementById('edit-code-'   + cid).value.trim();
    const ro     = document.getElementById('edit-ro-'     + cid).value;
    const voters = document.getElementById('edit-voters-' + cid).value;
    const status = document.getElementById('edit-status-' + cid).value;

   if (!name || !code) { showToast('Name and Code are required.','warning'); return; }

    // RO uniqueness check: taken by a different constituency?
    const roVal = parseInt(ro) || 0;
    if (roVal && TAKEN_RO_MAP.hasOwnProperty(roVal) && TAKEN_RO_MAP[roVal] !== cid) {
        showToast('⚠️ This Returning Officer is already assigned to another constituency.', 'warning');
        return;
    }

    const fd = new FormData();
    fd.append('ajax_action',           'update');
    fd.append('constituency_id',       cid);
    fd.append('name',                  name);
    fd.append('code',                  code);
    fd.append('returning_officer_id',  ro);
    fd.append('total_registered_voters', voters);
    fd.append('result_status',         status);

    fetch(window.location.href, { method:'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                showToast(res.message, 'success');
                setTimeout(() => location.reload(), 900);
            } else {
                showToast(res.message, 'error');
            }
        })
        .catch(() => showToast('Network error.','error'));
}

// ─────────────────────────────────────────────────────────────
//  DELETE
// ─────────────────────────────────────────────────────────────
let pendingDeleteId = null;

function confirmDelete(cid, name) {
    pendingDeleteId = cid;
    document.getElementById('deleteConstName').textContent = name;
    document.getElementById('deleteModal').classList.add('open');
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('open');
    pendingDeleteId = null;
}

document.getElementById('confirmDeleteBtn').addEventListener('click', () => {
    if (!pendingDeleteId) return;
    const fd = new FormData();
    fd.append('ajax_action',     'delete');
    fd.append('constituency_id', pendingDeleteId);
    closeDeleteModal();

    fetch(window.location.href, { method:'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                showToast(res.message, 'success');
                // Remove rows from DOM immediately
                const ids = [
                    'row-' + pendingDeleteId,
                    'editrow-' + pendingDeleteId,
                    'expand-' + pendingDeleteId
                ];
                ids.forEach(id => { const el=document.getElementById(id); if(el) el.remove(); });
            } else {
                showToast(res.message, 'error');
            }
        })
        .catch(() => showToast('Network error.','error'));
});

// Close modal on overlay click
document.getElementById('deleteModal').addEventListener('click', function(e) {
    if (e.target === this) closeDeleteModal();
});

// ─────────────────────────────────────────────────────────────
//  POLLING STATION EXPAND
// ─────────────────────────────────────────────────────────────
const loadedStations = {};

function toggleStations(cid) {
    const row    = document.getElementById('expand-' + cid);
    const badge  = document.getElementById('stBadge-' + cid);
    const isOpen = row.classList.contains('open');

    // Close all others first
    document.querySelectorAll('.expand-row.open').forEach(r => {
        r.classList.remove('open');
        const id = r.id.replace('expand-', '');
        const b  = document.getElementById('stBadge-' + id);
        if (b) b.innerHTML = b.innerHTML.replace('▴','▾');
    });

    if (isOpen) return; // was open, now closed

    row.classList.add('open');
    if (badge) badge.innerHTML = badge.innerHTML.replace('▾','▴');

    // Lazy load
    if (!loadedStations[cid]) {
        loadStations(cid);
    }
}

function closeStations(cid) {
    const row = document.getElementById('expand-' + cid);
    if (row) row.classList.remove('open');
    const badge = document.getElementById('stBadge-' + cid);
    if (badge) badge.innerHTML = badge.innerHTML.replace('▴','▾');
}

function loadStations(cid) {
    const fd = new FormData();
    fd.append('ajax_action', 'get_stations');
    fd.append('constituency_id', cid);

    fetch(window.location.href, { method:'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            loadedStations[cid] = true;
            const container = document.getElementById('stations-content-' + cid);
            if (!res.success) { container.innerHTML='<p style="color:var(--danger);padding:12px;">Error loading stations.</p>'; return; }
            if (!res.stations.length) {
                container.innerHTML = '<p style="color:var(--muted);font-size:13px;padding:16px 0;text-align:center;">No polling stations assigned to this constituency yet.</p>';
                return;
            }

            const statusClass = { 'VERIFIED':'ss-verified','SUBMITTED':'ss-submitted','PENDING':'ss-pending' };
            const statusIcon  = { 'VERIFIED':'✅','SUBMITTED':'📤','PENDING':'⏳' };

            let html = `
            <table class="station-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Polling Station Name</th>
                        <th>Address</th>
                        <th>Presiding Officer</th>
                        <th>Verification Status</th>
                        <th>Ballots Issued</th>
                    </tr>
                </thead>
                <tbody>`;

            res.stations.forEach(s => {
                const sc = statusClass[s.result_status] || 'ss-pending';
                const si = statusIcon[s.result_status]  || '⏳';
                html += `
                <tr>
                    <td><span class="id-badge">#${s.station_id}</span></td>
                    <td><div class="st-name">${escHtml(s.name)}</div></td>
                    <td><div class="st-addr">${escHtml(s.address||'—')}</div></td>
                    <td>${s.officer_name ? escHtml(s.officer_name) : '<span style="color:var(--muted2);">— None —</span>'}</td>
                    <td><span class="${sc}">${si} ${escHtml(s.result_status)}</span></td>
                    <td><strong style="font-family:var(--font-head);">${Number(s.total_ballots_issued).toLocaleString()}</strong></td>
                </tr>`;
            });

            html += '</tbody></table>';
            container.innerHTML = html;
        })
        .catch(() => {
            document.getElementById('stations-content-' + cid).innerHTML =
                '<p style="color:var(--danger);padding:12px;">Network error. Could not load stations.</p>';
        });
}

function escHtml(str) {
    if (!str) return '';
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ─────────────────────────────────────────────────────────────
//  AUTO-OPEN form if there was a form error
// ─────────────────────────────────────────────────────────────
<?php if ($form_error): ?>
document.getElementById('addFormCard').style.display = 'block';
<?php endif; ?>
</script>
</body>
</html>
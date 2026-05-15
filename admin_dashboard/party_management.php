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

    // ── DELETE PARTY ────────────────────────────────────────
    if ($act === 'delete') {
        $id = (int)($_POST['party_id'] ?? 0);
        // Check if party has candidates
        $cCount = $pdo->prepare("SELECT COUNT(*) FROM candidates WHERE party_id=?");
        $cCount->execute([$id]);
        if ((int)$cCount->fetchColumn() > 0) {
            echo json_encode(['success'=>false,'message'=>'Cannot delete: this party has candidates. Remove all candidates first.']);
            exit;
        }
        try {
            $pdo->prepare("DELETE FROM political_parties WHERE party_id=?")->execute([$id]);
            $pdo->prepare("INSERT INTO audit_logs (officer_id,action_type,affected_entity,affected_entity_id,details,ip_address) VALUES (?,?,?,?,?,?)")
                ->execute([$logged_in_id,'DELETE_PARTY','political_parties',$id,"Deleted political party ID $id",$_SERVER['REMOTE_ADDR']??'']);
            echo json_encode(['success'=>true,'message'=>'Political party deleted successfully.']);
        } catch (PDOException $e) {
            echo json_encode(['success'=>false,'message'=>'Cannot delete: '.$e->getMessage()]);
        }
        exit;
    }

    // ── UPDATE PARTY ────────────────────────────────────────
    if ($act === 'update') {
        $id      = (int)($_POST['party_id']??0);
        $name    = trim($_POST['name']??'');
        $abbr    = trim($_POST['abbreviation']??'');
        $reg     = trim($_POST['registration_number']??'');

        if (!$name) { echo json_encode(['success'=>false,'message'=>'Party name is required.']); exit; }

        // Check unique abbreviation
        if ($abbr) {
            $dupChk = $pdo->prepare("SELECT party_id FROM political_parties WHERE abbreviation=? AND party_id!=?");
            $dupChk->execute([$abbr, $id]);
            if ($dupChk->fetch()) { echo json_encode(['success'=>false,'message'=>"Abbreviation '$abbr' is already used by another party."]); exit; }
        }
        // Check unique registration
        if ($reg) {
            $regChk = $pdo->prepare("SELECT party_id FROM political_parties WHERE registration_number=? AND party_id!=?");
            $regChk->execute([$reg, $id]);
            if ($regChk->fetch()) { echo json_encode(['success'=>false,'message'=>"Registration number '$reg' already exists."]); exit; }
        }

        try {
            $pdo->prepare("UPDATE political_parties SET name=?,abbreviation=?,registration_number=? WHERE party_id=?")
                ->execute([$name,$abbr,$reg,$id]);
            $pdo->prepare("INSERT INTO audit_logs (officer_id,action_type,affected_entity,affected_entity_id,details,ip_address) VALUES (?,?,?,?,?,?)")
                ->execute([$logged_in_id,'UPDATE_PARTY','political_parties',$id,"Updated party '$name'",$_SERVER['REMOTE_ADDR']??'']);
            echo json_encode(['success'=>true,'message'=>"Party '$name' updated successfully."]);
        } catch (PDOException $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }

    // ── GET CANDIDATES FOR PARTY ────────────────────────────
    if ($act === 'get_candidates') {
        $id = (int)($_POST['party_id']??0);
        $stmt = $pdo->prepare("
            SELECT c.candidate_id, c.full_name, c.national_id, c.symbol,
                   con.name AS constituency_name, con.code AS constituency_code,
                   IFNULL(SUM(br.votes_received),0) AS total_votes,
                   MAX(CASE WHEN br.is_locked=1 THEN 1 ELSE 0 END) AS has_locked
            FROM candidates c
            LEFT JOIN constituencies con ON con.constituency_id = c.constituency_id
            LEFT JOIN booth_results br ON br.candidate_id = c.candidate_id AND br.is_locked=1
            WHERE c.party_id = ?
            GROUP BY c.candidate_id
            ORDER BY c.candidate_id
        ");
        $stmt->execute([$id]);
        echo json_encode(['success'=>true,'candidates'=>$stmt->fetchAll()]);
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action.']); exit;
}

// ============================================================
//  CREATE NEW PARTY (form POST)
// ============================================================
$form_success = $form_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_party'])) {
    $name = trim($_POST['name']??'');
    $abbr = trim($_POST['abbreviation']??'');
    $reg  = trim($_POST['registration_number']??'');

    if (!$name) {
        $form_error = 'Party name is required.';
    } elseif (!$reg) {
        $form_error = 'Registration number is required.';
    } else {
        // Unique checks
        $dupAbbr = $abbr ? $pdo->prepare("SELECT party_id FROM political_parties WHERE abbreviation=?") : null;
        $abbrExists = false;
        if ($dupAbbr) { $dupAbbr->execute([$abbr]); $abbrExists = (bool)$dupAbbr->fetch(); }
        $dupReg = $pdo->prepare("SELECT party_id FROM political_parties WHERE registration_number=?");
        $dupReg->execute([$reg]);
        $regExists = (bool)$dupReg->fetch();

        if ($abbrExists) {
            $form_error = "Abbreviation '$abbr' is already used by another party.";
        } elseif ($regExists) {
            $form_error = "Registration number '$reg' already exists.";
        } else {
            try {
                $pdo->prepare("INSERT INTO political_parties (name,abbreviation,registration_number) VALUES (?,?,?)")
                    ->execute([$name,$abbr,$reg]);
                $new_id = $pdo->lastInsertId();
                $pdo->prepare("INSERT INTO audit_logs (officer_id,action_type,affected_entity,affected_entity_id,details,ip_address) VALUES (?,?,?,?,?,?)")
                    ->execute([$logged_in_id,'CREATE_PARTY','political_parties',$new_id,"Created party '$name'",$_SERVER['REMOTE_ADDR']??'']);
                $form_success = "Political Party <strong>$name</strong> created successfully.";
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

// Search
$search = trim($_GET['q']??'');

// Parties with stats
$sqlBase = "
    SELECT pp.*,
           COUNT(DISTINCT c.candidate_id) AS candidate_count,
           IFNULL(SUM(br.votes_received),0) AS total_votes
    FROM political_parties pp
    LEFT JOIN candidates c ON c.party_id = pp.party_id
    LEFT JOIN booth_results br ON br.candidate_id = c.candidate_id AND br.is_locked=1
";
$where = []; $params = [];
if ($search !== '') {
    $where[] = "(pp.name LIKE ? OR pp.abbreviation LIKE ? OR pp.registration_number LIKE ?)";
    $params[] = '%'.$search.'%';
    $params[] = '%'.$search.'%';
    $params[] = '%'.$search.'%';
}
$sqlFull = $sqlBase . ($where ? " WHERE ".implode(" AND ",$where) : "") . " GROUP BY pp.party_id ORDER BY pp.party_id";
$ptStmt = $pdo->prepare($sqlFull);
$ptStmt->execute($params);
$parties = $ptStmt->fetchAll();

// Stats
$total_parties     = (int)$pdo->query("SELECT COUNT(*) FROM political_parties")->fetchColumn();
$total_candidates  = (int)$pdo->query("SELECT COUNT(*) FROM candidates")->fetchColumn();
$total_votes_all   = (int)$pdo->query("SELECT IFNULL(SUM(votes_received),0) FROM booth_results WHERE is_locked=1")->fetchColumn();
$con_count         = (int)$pdo->query("SELECT COUNT(DISTINCT constituency_id) FROM candidates")->fetchColumn();

// Party colours (consistent)
$party_colors = [
    'AL' =>['bg'=>'#dcfce7','text'=>'#166534','border'=>'#86efac','dot'=>'#16a34a'],
    'BNP'=>['bg'=>'#dbeafe','text'=>'#1e40af','border'=>'#93c5fd','dot'=>'#1a56db'],
    'JP' =>['bg'=>'#fee2e2','text'=>'#991b1b','border'=>'#fca5a5','dot'=>'#dc2626'],
    'IAB'=>['bg'=>'#dcfce7','text'=>'#065f46','border'=>'#6ee7b7','dot'=>'#059669'],
    'WPB'=>['bg'=>'#fef3c7','text'=>'#92400e','border'=>'#fcd34d','dot'=>'#d97706'],
];
function getPartyColor($abbr, $map, $part='bg') {
    return $map[$abbr][$part] ?? ($part==='bg'?'#f1f5f9':($part==='text'?'#475569':($part==='border'?'#cbd5e1':'#64748b')));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Party Management — EMS Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500;600&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<style>
/* ============================================================
   ROOT — identical to polling_station_management
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
.live-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--success2); animation: pulse-dot 1.6s infinite; }
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
.page-wrap { max-width: 1380px; margin: 0 auto; padding: 28px 28px 56px; width: 100%; flex: 1; }

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
    background: linear-gradient(135deg, var(--purple), var(--primary));
    border-radius: 14px; display: flex; align-items: center; justify-content: center;
    font-size: 24px; box-shadow: 0 4px 16px rgba(139,92,246,.28); flex-shrink: 0;
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
.btn-purple    { background: linear-gradient(135deg, var(--purple), var(--primary)); color: #fff; box-shadow: 0 2px 10px rgba(139,92,246,.25); }
.btn-purple:hover    { transform: translateY(-1px); box-shadow: 0 4px 18px rgba(139,92,246,.35); }
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
   STAT TILES
============================================================ */
.stat-strip { display: grid; grid-template-columns: repeat(4,1fr); gap: 16px; margin-bottom: 22px; }
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

.st-icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; margin-bottom: 12px; }
.st-icon.blue   { background: #dbeafe; }
.st-icon.green  { background: #dcfce7; }
.st-icon.gold   { background: #fef3c7; }
.st-icon.purple { background: #ede9fe; }
.st-icon.teal   { background: #ccfbf1; }

.st-value { font-family: var(--font-mono); font-size: 26px; font-weight: 600; letter-spacing: -.5px; line-height: 1; margin-bottom: 5px; color: var(--text); }
.st-value.blue   { color: var(--primary); }
.st-value.green  { color: var(--success); }
.st-value.gold   { color: var(--gold); }
.st-value.purple { color: var(--purple); }
.st-value.teal   { color: var(--teal); }

.st-label { font-size: 11.5px; font-weight: 600; text-transform: uppercase; letter-spacing: .6px; color: var(--muted); }
.st-delta { position: absolute; top: 16px; right: 16px; font-size: 11px; font-weight: 600; padding: 3px 9px; border-radius: 20px; }
.st-delta.up     { background: #dcfce7; color: var(--success); }
.st-delta.info   { background: #dbeafe; color: var(--primary); }
.st-delta.teal   { background: #ccfbf1; color: var(--teal); }
.st-delta.gold   { background: #fef3c7; color: var(--gold); }
.st-delta.purple { background: #ede9fe; color: var(--purple); }

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
.form-grid-4 { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 16px; }
@media(max-width:768px){ .form-grid-2,.form-grid-3,.form-grid-4{grid-template-columns:1fr;} }
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
    padding: 10px 14px; background: #f5f3ff;
    border-radius: var(--radius-sm); border-left: 3px solid var(--purple);
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

/* Cell styles */
.cell-main  { font-weight: 700; color: var(--text); font-size: 13.5px; }
.cell-sub   { font-size: 11px; color: var(--muted); margin-top: 2px; }
.id-badge   { font-family: var(--font-mono); font-size: 12px; font-weight: 600; background: var(--bg2); border: 1px solid var(--border); border-radius: 6px; padding: 3px 8px; color: var(--text2); }
.num-cell   { font-family: var(--font-mono); font-size: 14px; font-weight: 600; color: var(--text); letter-spacing: -.3px; }
.num-sub    { font-size: 10.5px; color: var(--muted); margin-top: 1px; font-family: var(--font-body); }

/* Party abbreviation badge */
.abbr-badge {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 42px; padding: 4px 10px;
    border-radius: 7px; font-size: 12px; font-weight: 800;
    font-family: var(--font-mono); letter-spacing: .5px;
    border: 1.5px solid;
}

/* Candidate expand badge */
.cand-badge {
    display: inline-flex; align-items: center; gap: 5px;
    background: #ede9fe; color: var(--purple);
    border: 1px solid #c4b5fd; border-radius: 6px;
    font-size: 11.5px; font-weight: 700; padding: 3px 9px;
    cursor: pointer; transition: all .18s;
}
.cand-badge:hover { background: var(--purple); color: #fff; border-color: var(--purple); }

.action-row { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }

/* ============================================================
   EXPAND PANEL — Candidates under Party
============================================================ */
.expand-row { display: none; }
.expand-row.open { display: table-row; }
.expand-cell { padding: 0 !important; border-bottom: 2px solid var(--purple) !important; }
.expand-inner {
    background: linear-gradient(180deg, #f5f3ff 0%, #faf8ff 100%);
    border-top: 1px solid #c4b5fd;
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
    font-size: 11.5px; color: #5b21b6; background: #ede9fe;
    border: 1px solid #c4b5fd; border-radius: 6px; padding: 5px 12px;
    display: inline-flex; align-items: center; gap: 6px;
}

.cand-table { width: 100%; border-collapse: collapse; font-size: 13px; border-radius: var(--radius-sm); overflow: hidden; border: 1.5px solid #c4b5fd; }
.cand-table thead tr { background: #ede9fe; }
.cand-table th {
    padding: 10px 14px; text-align: left;
    font-size: 10.5px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .5px; color: #5b21b6;
    border-bottom: 1.5px solid #c4b5fd;
}
.cand-table td { padding: 11px 14px; border-bottom: 1px solid #ddd6fe; vertical-align: middle; }
.cand-table tbody tr:hover { background: #f5f3ff; }
.cand-table tbody tr:last-child td { border-bottom: none; }
.cand-name  { font-weight: 700; font-size: 13px; color: var(--text); }
.cand-votes { font-family: var(--font-mono); font-weight: 600; font-size: 13px; color: var(--primary); }
.locked-badge   { background: #dcfce7; color: var(--success); border: 1px solid #86efac; border-radius: 20px; padding: 3px 10px; font-size: 11px; font-weight: 700; }
.unlocked-badge { background: #f1f5f9; color: var(--muted); border: 1px solid var(--border); border-radius: 20px; padding: 3px 10px; font-size: 11px; font-weight: 700; }
.con-chip { font-size: 11px; font-weight: 700; background: #dbeafe; color: var(--primary); border-radius: 5px; padding: 2px 8px; }

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
    <a class="sub-nav-link" href="polling_station_management.php">🏫 Polling Stations</a>
    <a class="sub-nav-link" href="booth_management.php">🚪 Booth Management</a>
    <a class="sub-nav-link" href="candidate_management.php">🎖️ Candidates</a>
    <a class="sub-nav-link active" href="party_management.php">🏳️ Political Parties</a>
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
            <div class="page-header-icon">🏳️</div>
            <div>
                <div class="breadcrumb"><a href="admin_dashboard.php">Dashboard</a> / Political Party Management</div>
                <div class="page-title">Political Party Management</div>
                <div class="page-subtitle">Manage registered political parties, abbreviations, registration numbers, and their candidate roster.</div>
            </div>
        </div>
        <div class="header-actions">
            <button class="btn btn-purple" onclick="toggleForm()">➕ Add Party</button>
            <button class="btn btn-secondary" onclick="location.reload()">🔄 Refresh</button>
            <a href="admin_dashboard.php" class="btn btn-secondary">← Dashboard</a>
        </div>
    </div>

    <!-- STAT STRIP -->
    <div class="stat-strip">
        <div class="stat-tile">
            <div class="st-delta info">Registered</div>
            <div class="st-icon purple">🏳️</div>
            <div class="st-value purple"><?= number_format($total_parties) ?></div>
            <div class="st-label">Political Parties</div>
        </div>
        <div class="stat-tile">
            <div class="st-delta teal">All Parties</div>
            <div class="st-icon teal">🎖️</div>
            <div class="st-value teal"><?= number_format($total_candidates) ?></div>
            <div class="st-label">Total Candidates</div>
        </div>
        <div class="stat-tile">
            <div class="st-delta gold">Locked Votes</div>
            <div class="st-icon gold">🗳️</div>
            <div class="st-value gold"><?= number_format($total_votes_all) ?></div>
            <div class="st-label">Total Votes Cast</div>
        </div>
        <div class="stat-tile">
            <div class="st-delta up">Active</div>
            <div class="st-icon green">🗺️</div>
            <div class="st-value green"><?= number_format($con_count) ?></div>
            <div class="st-label">Constituencies Contested</div>
        </div>
    </div>

    <!-- PHP FEEDBACK -->
    <?php if ($form_success): ?>
    <div class="alert alert-success">✅ <?= $form_success ?></div>
    <?php endif; ?>
    <?php if ($form_error): ?>
    <div class="alert alert-error">❌ <?= htmlspecialchars($form_error) ?></div>
    <?php endif; ?>

    <!-- ADD PARTY FORM -->
    <div class="card" id="addFormCard" style="display:none; border-color: var(--purple);">
        <div class="card-header">
            <span class="card-title">🏳️ Register New Political Party</span>
            <button class="btn btn-sm btn-secondary" onclick="toggleForm()">✕ Close</button>
        </div>
        <div class="card-body">
            <form method="POST" action="">
                <div class="form-grid-4" style="margin-bottom:18px;">
                    <div class="form-group" style="grid-column: span 2;">
                        <label class="form-label">Party Full Name <span class="req">*</span></label>
                        <input type="text" name="name" class="form-control"
                               placeholder="e.g. Bangladesh Awami League"
                               value="<?= htmlspecialchars($_POST['name']??'') ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Abbreviation</label>
                        <input type="text" name="abbreviation" class="form-control"
                               placeholder="e.g. AL" maxlength="20"
                               value="<?= htmlspecialchars($_POST['abbreviation']??'') ?>"
                               style="font-family:var(--font-mono);font-weight:700;letter-spacing:1px;">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Registration No. <span class="req">*</span></label>
                        <input type="text" name="registration_number" class="form-control"
                               placeholder="e.g. REG006"
                               value="<?= htmlspecialchars($_POST['registration_number']??'') ?>"
                               style="font-family:var(--font-mono);" required>
                    </div>
                </div>
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <button type="submit" name="create_party" class="btn btn-purple">💾 Register Party</button>
                    <button type="reset" class="btn btn-secondary">↺ Reset Form</button>
                </div>
                <div class="form-helper">💡 Abbreviation must be unique across all parties. Registration number is issued by the Election Commission and must be unique. Candidates can be assigned to this party after creation from Candidate Management.</div>
            </form>
        </div>
    </div>

    <!-- SEARCH BAR -->
    <div class="card">
        <div class="card-body" style="padding:16px 22px;">
            <form method="GET" action="">
                <div class="filter-bar">
                    <div class="search-input-wrap">
                        <span class="si">🔍</span>
                        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                               placeholder="Search by party name, abbreviation, or registration number…">
                    </div>
                    <button type="submit" class="btn btn-primary">Search</button>
                    <?php if ($search): ?>
                    <a href="party_management.php" class="btn btn-secondary">✕ Clear</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- MAIN TABLE CARD -->
    <div class="card">
        <div class="card-header">
            <span class="card-title">🏳️ Registered Political Parties
                <?php if ($search): ?>
                <span style="font-size:12px;font-weight:400;color:var(--muted);">Filtered results</span>
                <?php endif; ?>
            </span>
            <span class="count-pill"><?= count($parties) ?> found</span>
        </div>

        <div class="table-wrap">
            <table class="mgmt-table" id="partyTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Party Name</th>
                        <th>Abbreviation</th>
                        <th>Reg. Number</th>
                        <th>Candidates</th>
                        <th>Total Votes</th>
                        <th style="min-width:230px;">Actions</th>
                    </tr>
                </thead>
                <tbody>

                <?php if (empty($parties)): ?>
                <tr>
                    <td colspan="7" style="text-align:center;padding:40px;color:var(--muted);">
                        No political parties found<?= $search ? ' for this search' : '' ?>.
                    </td>
                </tr>
                <?php endif; ?>

                <?php foreach ($parties as $pt):
                    $pid   = (int)$pt['party_id'];
                    $abbr  = $pt['abbreviation'] ?? '';
                    $bgCol = getPartyColor($abbr, $party_colors, 'bg');
                    $txCol = getPartyColor($abbr, $party_colors, 'text');
                    $bdCol = getPartyColor($abbr, $party_colors, 'border');
                ?>

                <!-- DISPLAY ROW -->
                <tr class="data-row" id="row-<?= $pid ?>">
                    <td><span class="id-badge">#<?= $pid ?></span></td>
                    <td>
                        <div class="cell-main"><?= htmlspecialchars($pt['name']) ?></div>
                        <div class="cell-sub">Registered Political Party</div>
                    </td>
                    <td>
                        <?php if ($abbr): ?>
                        <span class="abbr-badge" style="background:<?= $bgCol ?>;color:<?= $txCol ?>;border-color:<?= $bdCol ?>;">
                            <?= htmlspecialchars($abbr) ?>
                        </span>
                        <?php else: ?>
                        <span style="color:var(--muted2);font-size:12px;">— None —</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span style="font-family:var(--font-mono);font-size:13px;font-weight:600;color:var(--text2);">
                            <?= htmlspecialchars($pt['registration_number']) ?>
                        </span>
                    </td>
                    <td>
                        <span class="cand-badge" onclick="toggleCandidates(<?= $pid ?>)" id="candBadge-<?= $pid ?>">
                            🎖️ <?= (int)$pt['candidate_count'] ?> Candidates ▾
                        </span>
                    </td>
                    <td>
                        <div class="num-cell"><?= number_format((int)$pt['total_votes']) ?></div>
                        <div class="num-sub">locked votes</div>
                    </td>
                    <td>
                        <div class="action-row">
                            <button class="btn btn-sm btn-secondary" onclick="startEdit(<?= $pid ?>)">✏️ Edit</button>
                            <button class="btn btn-sm btn-danger" onclick="confirmDelete(<?= $pid ?>, '<?= htmlspecialchars(addslashes($pt['name'])) ?>', <?= (int)$pt['candidate_count'] ?>)">🗑️</button>
                            <button class="btn btn-sm btn-info" onclick="toggleCandidates(<?= $pid ?>)">🎖️ Candidates</button>
                        </div>
                    </td>
                </tr>

                <!-- EDIT ROW -->
                <tr class="editing-row" id="editrow-<?= $pid ?>" style="display:none;">
                    <td><span class="id-badge">#<?= $pid ?></span></td>
                    <td>
                        <input class="edit-input" id="edit-name-<?= $pid ?>"
                               value="<?= htmlspecialchars($pt['name']) ?>"
                               placeholder="Party full name" style="min-width:200px;">
                    </td>
                    <td>
                        <input class="edit-input" id="edit-abbr-<?= $pid ?>"
                               value="<?= htmlspecialchars($abbr) ?>"
                               placeholder="e.g. AL" maxlength="20"
                               style="min-width:70px;font-family:var(--font-mono);font-weight:700;text-transform:uppercase;">
                    </td>
                    <td>
                        <input class="edit-input" id="edit-reg-<?= $pid ?>"
                               value="<?= htmlspecialchars($pt['registration_number']) ?>"
                               placeholder="REG00X"
                               style="min-width:100px;font-family:var(--font-mono);">
                    </td>
                    <td>
                        <span class="cand-badge" style="cursor:default;">🎖️ <?= (int)$pt['candidate_count'] ?></span>
                    </td>
                    <td>
                        <div class="num-cell"><?= number_format((int)$pt['total_votes']) ?></div>
                    </td>
                    <td>
                        <div class="action-row">
                            <button class="btn btn-sm btn-success" onclick="saveEdit(<?= $pid ?>)">💾 Save</button>
                            <button class="btn btn-sm btn-secondary" onclick="cancelEdit(<?= $pid ?>)">✕ Cancel</button>
                        </div>
                    </td>
                </tr>

                <!-- EXPAND ROW — Candidates under Party -->
                <tr class="expand-row" id="expand-<?= $pid ?>">
                    <td class="expand-cell" colspan="7">
                        <div class="expand-inner">
                            <div class="expand-header">
                                <div class="expand-title">
                                    🎖️ Candidates under
                                    <strong><?= htmlspecialchars($pt['name']) ?></strong>
                                    <?php if ($abbr): ?>
                                    <span class="abbr-badge" style="background:<?= $bgCol ?>;color:<?= $txCol ?>;border-color:<?= $bdCol ?>;font-size:11px;">
                                        <?= htmlspecialchars($abbr) ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                                <span class="hierarchy-note">ℹ️ Party → Candidate → Constituency</span>
                            </div>
                            <div id="cands-content-<?= $pid ?>">
                                <div style="text-align:center;padding:24px;color:var(--muted);font-size:13px;">Loading candidates…</div>
                            </div>
                            <div style="margin-top:10px;font-size:11.5px;color:var(--muted);">
                                📌 Each candidate is assigned to exactly one party and one constituency. Manage candidates in detail via Candidate Management.
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
            <div>Showing <strong><?= count($parties) ?></strong> political party records<?= $search ? ' (filtered)' : '' ?></div>
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
        <div class="modal-title">Delete Political Party?</div>
        <div class="modal-body">
            Are you sure you want to remove <strong id="deletePtName">this party</strong>?<br>
            <span id="deleteWarnCands"></span>
            This action <strong>cannot be undone.</strong>
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
    <div>🗳️ EMS Admin &nbsp;·&nbsp; Political Party Management &nbsp;·&nbsp; © 2026 Bangladesh Election Commission. All rights reserved.</div>
    <div class="footer-links">
        <a href="#">Privacy Policy</a>
        <a href="#">Security Audit</a>
        <a href="#">Help &amp; Documentation</a>
        <a href="#">Contact Support</a>
    </div>
</footer>

<div id="toast"></div>

<script>
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

// ── ADD FORM TOGGLE ─────────────────────────────────────────
function toggleForm() {
    const card = document.getElementById('addFormCard');
    const open = card.style.display !== 'none';
    card.style.display = open ? 'none' : 'block';
    if (!open) card.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ── INLINE EDIT ─────────────────────────────────────────────
function startEdit(pid) {
    document.getElementById('row-' + pid).style.display = 'none';
    document.getElementById('editrow-' + pid).style.display = 'table-row';
    closeCandidates(pid);
}
function cancelEdit(pid) {
    document.getElementById('editrow-' + pid).style.display = 'none';
    document.getElementById('row-' + pid).style.display = 'table-row';
}
function saveEdit(pid) {
    const name = document.getElementById('edit-name-' + pid).value.trim();
    const abbr = document.getElementById('edit-abbr-' + pid).value.trim().toUpperCase();
    const reg  = document.getElementById('edit-reg-'  + pid).value.trim();

    if (!name) { showToast('Party name is required.', 'warning'); return; }

    const fd = new FormData();
    fd.append('ajax_action',         'update');
    fd.append('party_id',            pid);
    fd.append('name',                name);
    fd.append('abbreviation',        abbr);
    fd.append('registration_number', reg);

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

function confirmDelete(pid, name, candCount) {
    pendingDeleteId = pid;
    document.getElementById('deletePtName').textContent = name;
    const warnEl = document.getElementById('deleteWarnCands');
    if (candCount > 0) {
        warnEl.innerHTML = `<span style="color:#991b1b;font-weight:600;">⚠️ This party has ${candCount} candidate(s). You must remove all candidates first before deleting the party.</span><br><br>`;
        document.getElementById('confirmDeleteBtn').disabled = true;
        document.getElementById('confirmDeleteBtn').style.opacity = '.5';
    } else {
        warnEl.innerHTML = '';
        document.getElementById('confirmDeleteBtn').disabled = false;
        document.getElementById('confirmDeleteBtn').style.opacity = '1';
    }
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
    fd.append('party_id',   pendingDeleteId);
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

// ── CANDIDATE EXPAND ─────────────────────────────────────────
const loadedCands = {};

function toggleCandidates(pid) {
    const row   = document.getElementById('expand-' + pid);
    const badge = document.getElementById('candBadge-' + pid);
    const isOpen = row.classList.contains('open');

    // close all
    document.querySelectorAll('.expand-row.open').forEach(r => {
        r.classList.remove('open');
        const id = r.id.replace('expand-', '');
        const b  = document.getElementById('candBadge-' + id);
        if (b) b.innerHTML = b.innerHTML.replace('▴', '▾');
    });

    if (isOpen) return;

    row.classList.add('open');
    if (badge) badge.innerHTML = badge.innerHTML.replace('▾', '▴');

    if (!loadedCands[pid]) loadCandidates(pid);
}

function closeCandidates(pid) {
    const row = document.getElementById('expand-' + pid);
    if (row) row.classList.remove('open');
    const badge = document.getElementById('candBadge-' + pid);
    if (badge) badge.innerHTML = badge.innerHTML.replace('▴', '▾');
}

function loadCandidates(pid) {
    const fd = new FormData();
    fd.append('ajax_action', 'get_candidates');
    fd.append('party_id',    pid);

    fetch(window.location.href, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            loadedCands[pid] = true;
            const container = document.getElementById('cands-content-' + pid);
            if (!res.success) {
                container.innerHTML = '<p style="color:var(--danger);padding:12px;">Error loading candidates.</p>';
                return;
            }
            if (!res.candidates.length) {
                container.innerHTML = '<p style="color:var(--muted);font-size:13px;padding:16px 0;text-align:center;">No candidates registered under this party yet.</p>';
                return;
            }

            let html = `
            <table class="cand-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Candidate Name</th>
                        <th>National ID</th>
                        <th>Symbol</th>
                        <th>Constituency</th>
                        <th>Total Votes</th>
                        <th>Result Status</th>
                    </tr>
                </thead>
                <tbody>`;

            res.candidates.forEach(c => {
                const hasLocked = parseInt(c.has_locked) === 1;
                html += `<tr>
                    <td><span class="id-badge">#${c.candidate_id}</span></td>
                    <td><span class="cand-name">${escHtml(c.full_name)}</span></td>
                    <td><span style="font-family:var(--font-mono);font-size:12px;color:var(--muted);">${escHtml(c.national_id)}</span></td>
                    <td><span style="font-size:12px;color:var(--text2);">${c.symbol ? escHtml(c.symbol) : '<span style="color:var(--muted2);">—</span>'}</span></td>
                    <td>
                        ${c.constituency_name
                            ? `<span class="con-chip">${escHtml(c.constituency_code)}</span>
                               <span style="font-size:12px;color:var(--text2);margin-left:5px;">${escHtml(c.constituency_name)}</span>`
                            : '<span style="color:var(--muted2);font-size:12px;">— None —</span>'}
                    </td>
                    <td><span class="cand-votes">${Number(c.total_votes).toLocaleString()}</span></td>
                    <td>${hasLocked
                        ? '<span class="locked-badge">✅ Results Locked</span>'
                        : '<span class="unlocked-badge">⏳ Pending</span>'}</td>
                </tr>`;
            });

            html += '</tbody></table>';
            container.innerHTML = html;
        })
        .catch(() => {
            document.getElementById('cands-content-' + pid).innerHTML =
                '<p style="color:var(--danger);padding:12px;">Network error. Could not load candidates.</p>';
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
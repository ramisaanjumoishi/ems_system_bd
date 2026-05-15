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
//  AJAX: Live results refresh
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_live_results') {
    header('Content-Type: application/json');
    $liveStmt = $pdo->query("
        SELECT c.name AS constituency, c.code, c.result_status,
               cand.full_name AS winner_name, pp.name AS party_name, pp.abbreviation,
               cr.total_votes_cast, cr.status AS result_status_cr,
               cr.approval_timestamp
        FROM constituencies c
        LEFT JOIN constituency_results cr ON cr.constituency_id = c.constituency_id
        LEFT JOIN candidates cand ON cand.candidate_id = cr.winner_candidate_id
        LEFT JOIN political_parties pp ON pp.party_id = cand.party_id
        ORDER BY c.constituency_id
    ");
    echo json_encode(['success' => true, 'results' => $liveStmt->fetchAll()]);
    exit;
}

// ============================================================
//  LOAD DASHBOARD DATA
// ============================================================

// Admin info
$adminStmt = $pdo->prepare("SELECT * FROM election_officers WHERE officer_id=?");
$adminStmt->execute([$logged_in_id]);
$admin = $adminStmt->fetch();

// ---- STAT CARDS ----
$stats = [];

// Constituencies
$r = $pdo->query("SELECT COUNT(*) as cnt FROM constituencies")->fetch();
$stats['constituencies'] = (int)$r['cnt'];

// Polling Stations
$r = $pdo->query("SELECT COUNT(*) as cnt FROM polling_stations")->fetch();
$stats['stations'] = (int)$r['cnt'];

// Polling Booths
$r = $pdo->query("SELECT COUNT(*) as cnt FROM polling_booths")->fetch();
$stats['booths'] = (int)$r['cnt'];

// Candidates
$r = $pdo->query("SELECT COUNT(*) as cnt FROM candidates")->fetch();
$stats['candidates'] = (int)$r['cnt'];

// Political Parties
$r = $pdo->query("SELECT COUNT(*) as cnt FROM political_parties")->fetch();
$stats['parties'] = (int)$r['cnt'];

// Election Officers
$r = $pdo->query("SELECT COUNT(*) as cnt FROM election_officers WHERE role != 'ADMIN'")->fetch();
$stats['officers'] = (int)$r['cnt'];

// Voters
$r = $pdo->query("SELECT COUNT(*) as cnt FROM voters")->fetch();
$stats['voters'] = (int)$r['cnt'];

// Voted
$r = $pdo->query("SELECT COUNT(*) as cnt FROM voters WHERE has_voted=1")->fetch();
$stats['voted'] = (int)$r['cnt'];

// Verified stations
$r = $pdo->query("SELECT COUNT(*) as cnt FROM polling_stations WHERE result_status='VERIFIED'")->fetch();
$stats['verified_stations'] = (int)$r['cnt'];

// Published results
$r = $pdo->query("SELECT COUNT(*) as cnt FROM constituency_results WHERE status='APPROVED'")->fetch();
$stats['published'] = (int)$r['cnt'];

// Calculate Results Published Percentage based on APPROVED + PUBLISHED status
$approved_published = $pdo->query("
    SELECT COUNT(*) as cnt FROM constituencies 
    WHERE result_status IN ('APPROVED', 'PUBLISHED')
")->fetch();
$stats['approved_published'] = (int)$approved_published['cnt'];

// Calculate percentage for progress bar
$results_published_pct = $stats['constituencies'] > 0 
    ? round(($stats['approved_published'] / $stats['constituencies']) * 100) 
    : 0;

$turnout_pct = $stats['voters'] > 0 ? round(($stats['voted'] / $stats['voters']) * 100, 1) : 0;
$station_pct = $stats['stations'] > 0 ? round(($stats['verified_stations'] / $stats['stations']) * 100) : 0;

// ---- LIVE RESULTS TABLE ----
$liveStmt = $pdo->query("
    SELECT c.constituency_id, c.name AS constituency, c.code, c.result_status,
           cand.full_name AS winner_name, pp.name AS party_name, pp.abbreviation,
           cr.total_votes_cast, cr.status AS result_status_cr,
           cr.approval_timestamp,
           (SELECT SUM(v2.total_votes_cast) FROM constituency_results v2) as grand_total
    FROM constituencies c
    LEFT JOIN constituency_results cr ON cr.constituency_id = c.constituency_id
    LEFT JOIN candidates cand ON cand.candidate_id = cr.winner_candidate_id
    LEFT JOIN political_parties pp ON pp.party_id = cand.party_id
    ORDER BY c.constituency_id
");
$live_results = $liveStmt->fetchAll();

$grand_total_votes = $pdo->query("SELECT IFNULL(SUM(total_votes_cast),0) as t FROM constituency_results")->fetch()['t'];

// ---- OFFICER ROLE BREAKDOWN ----
$roleStmt = $pdo->query("SELECT role, COUNT(*) as cnt FROM election_officers WHERE role != 'ADMIN' GROUP BY role ORDER BY FIELD(role,'RO','ARO','PO','APO')");
$role_breakdown = $roleStmt->fetchAll();

// ---- RECENT AUDIT LOGS ----
$logsStmt = $pdo->query("
    SELECT al.*, eo.full_name AS officer_name, eo.role AS officer_role
    FROM audit_logs al
    LEFT JOIN election_officers eo ON eo.officer_id = al.officer_id
    ORDER BY al.timestamp DESC
    LIMIT 10
");
$audit_logs = $logsStmt->fetchAll();

// ---- CONSTITUENCY STATUS BREAKDOWN ----
$constBreakdown = $pdo->query("
    SELECT result_status, COUNT(*) as cnt FROM constituencies GROUP BY result_status
")->fetchAll(PDO::FETCH_KEY_PAIR);

// ---- STATION STATUS ----
$stBreakdown = $pdo->query("
    SELECT result_status, COUNT(*) as cnt FROM polling_stations GROUP BY result_status
")->fetchAll(PDO::FETCH_KEY_PAIR);

$party_colors = [
    'AL'  => '#006633',
    'BNP' => '#003399',
    'JP'  => '#CC0000',
    'IAB' => '#009900',
    'WPB' => '#CC3300',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard — Bangladesh Election Commission EMS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
/* ============================================================
   CSS VARIABLES & RESET
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
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky;
    top: 0;
    z-index: 100;
    box-shadow: 0 2px 24px rgba(10,22,40,.35);
    border-bottom: 1px solid rgba(255,255,255,.08);
}
.topbar-brand {
    display: flex;
    align-items: center;
    gap: 14px;
}
.brand-emblem {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, var(--primary), var(--accent));
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    box-shadow: 0 2px 12px rgba(14,165,233,.3);
    flex-shrink: 0;
}
.brand-text {
    font-family: var(--font-head);
    font-size: 15px;
    font-weight: 700;
    color: #fff;
    letter-spacing: .2px;
    line-height: 1.2;
}
.brand-sub {
    font-size: 11px;
    color: rgba(255,255,255,.5);
    letter-spacing: .5px;
    text-transform: uppercase;
    font-weight: 400;
}
.topbar-center {
    display: flex;
    align-items: center;
    gap: 8px;
}
.live-chip {
    display: flex;
    align-items: center;
    gap: 6px;
    background: rgba(16,185,129,.15);
    border: 1px solid rgba(16,185,129,.3);
    border-radius: 20px;
    padding: 5px 14px;
    font-size: 11.5px;
    font-weight: 600;
    color: var(--success2);
    letter-spacing: .4px;
    text-transform: uppercase;
}
.live-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: var(--success2);
    animation: pulse-dot 1.6s infinite;
}
@keyframes pulse-dot {
    0%,100%{opacity:1;transform:scale(1);}
    50%{opacity:.5;transform:scale(1.4);}
}
.election-tag {
    background: rgba(245,158,11,.12);
    border: 1px solid rgba(245,158,11,.28);
    border-radius: 20px;
    padding: 5px 14px;
    font-size: 11.5px;
    font-weight: 600;
    color: var(--gold2);
    letter-spacing: .4px;
    text-transform: uppercase;
}
.topbar-right {
    display: flex;
    align-items: center;
    gap: 14px;
}
.admin-pill {
    display: flex;
    align-items: center;
    gap: 10px;
    background: rgba(255,255,255,.08);
    border: 1px solid rgba(255,255,255,.14);
    border-radius: 30px;
    padding: 5px 14px 5px 6px;
}
.admin-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--primary), var(--accent));
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: var(--font-head);
    font-size: 13px;
    font-weight: 700;
    color: #fff;
}
.admin-info .name {
    font-size: 12.5px;
    font-weight: 600;
    color: #fff;
    line-height: 1.2;
}
.admin-info .role-tag {
    font-size: 10px;
    color: var(--accent2);
    letter-spacing: .5px;
    text-transform: uppercase;
    font-weight: 500;
}
.btn-logout {
    background: rgba(239,68,68,.12);
    border: 1px solid rgba(239,68,68,.25);
    color: #fca5a5;
    font-size: 12px;
    font-weight: 600;
    padding: 7px 16px;
    border-radius: 8px;
    transition: all .2s;
    letter-spacing: .3px;
}
.btn-logout:hover { background: rgba(239,68,68,.22); color: #fff; }

/* ============================================================
   PAGE WRAP
============================================================ */
.page-wrap {
    max-width: 1380px;
    margin: 0 auto;
    padding: 28px 28px 48px;
    width: 100%;
    flex: 1;
}

/* ============================================================
   PAGE HEADER
============================================================ */
.page-header {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    margin-bottom: 28px;
    flex-wrap: wrap;
    gap: 14px;
}
.page-title {
    font-family: var(--font-head);
    font-size: 28px;
    font-weight: 800;
    color: var(--navy);
    line-height: 1.1;
    letter-spacing: -.3px;
}
.page-subtitle {
    font-size: 13px;
    color: var(--muted);
    margin-top: 4px;
    font-weight: 400;
}
.header-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}
.btn-primary {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    background: linear-gradient(135deg, var(--primary), var(--primary2));
    color: #fff;
    font-size: 13px;
    font-weight: 600;
    padding: 10px 20px;
    border-radius: var(--radius-sm);
    border: none;
    transition: all .2s;
    box-shadow: 0 2px 10px rgba(26,86,219,.25);
    letter-spacing: .2px;
}
.btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 18px rgba(26,86,219,.35); }
.btn-secondary {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    background: var(--surface);
    color: var(--text2);
    font-size: 13px;
    font-weight: 600;
    padding: 10px 20px;
    border-radius: var(--radius-sm);
    border: 1.5px solid var(--border);
    transition: all .2s;
}
.btn-secondary:hover { border-color: var(--primary); color: var(--primary); background: #eff6ff; }

/* ============================================================
   GRID LAYOUTS
============================================================ */
.grid-4 { display: grid; grid-template-columns: repeat(4,1fr); gap: 16px; }
.grid-3 { display: grid; grid-template-columns: repeat(3,1fr); gap: 16px; }
.grid-2 { display: grid; grid-template-columns: repeat(2,1fr); gap: 20px; }
.grid-main { display: grid; grid-template-columns: 1fr 360px; gap: 20px; }
@media(max-width:1200px){ .grid-4{grid-template-columns:repeat(2,1fr);} .grid-main{grid-template-columns:1fr;} }
@media(max-width:720px){ .grid-4{grid-template-columns:1fr;} .grid-3{grid-template-columns:1fr;} .grid-2{grid-template-columns:1fr;} }

.section-gap { margin-bottom: 22px; }

/* ============================================================
   STAT TILES
============================================================ */
.stat-tile {
    background: var(--surface);
    border-radius: var(--radius);
    border: 1.5px solid var(--border);
    padding: 20px 22px;
    position: relative;
    overflow: hidden;
    transition: all .22s;
    cursor: default;
}
.stat-tile::before {
    content: '';
    position: absolute;
    inset: 0;
    opacity: 0;
    transition: opacity .22s;
}
.stat-tile:hover { transform: translateY(-3px); box-shadow: var(--shadow-lg); border-color: var(--border2); }
.stat-tile:hover::before { opacity: 1; }

.st-icon {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    margin-bottom: 14px;
}
.st-icon.blue   { background: #dbeafe; }
.st-icon.green  { background: #dcfce7; }
.st-icon.gold   { background: #fef3c7; }
.st-icon.purple { background: #ede9fe; }
.st-icon.cyan   { background: #cffafe; }
.st-icon.red    { background: #fee2e2; }

.st-value {
    font-family: var(--font-head);
    font-size: 30px;
    font-weight: 800;
    color: var(--text);
    line-height: 1;
    margin-bottom: 5px;
    letter-spacing: -.5px;
}
.st-value.blue   { color: var(--primary); }
.st-value.green  { color: var(--success); }
.st-value.gold   { color: var(--gold); }
.st-value.purple { color: var(--purple); }
.st-value.cyan   { color: var(--accent); }
.st-value.red    { color: var(--danger); }

.st-label {
    font-size: 11.5px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .6px;
    color: var(--muted);
}
.st-delta {
    position: absolute;
    top: 16px;
    right: 16px;
    font-size: 11px;
    font-weight: 600;
    padding: 3px 9px;
    border-radius: 20px;
}
.st-delta.up   { background: #dcfce7; color: var(--success); }
.st-delta.info { background: #dbeafe; color: var(--primary); }
.st-delta.warn { background: #fef3c7; color: var(--gold); }

/* ============================================================
   CARDS
============================================================ */
.card {
    background: var(--surface);
    border-radius: var(--radius);
    border: 1.5px solid var(--border);
    overflow: hidden;
    box-shadow: var(--shadow);
}
.card-header {
    padding: 18px 22px 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid var(--border);
    background: #fafbfc;
}
.card-title {
    font-family: var(--font-head);
    font-size: 14.5px;
    font-weight: 700;
    color: var(--text);
    display: flex;
    align-items: center;
    gap: 8px;
}
.card-badge {
    font-size: 11px;
    font-weight: 600;
    padding: 3px 10px;
    border-radius: 20px;
}
.badge-live  { background: rgba(16,185,129,.12); color: var(--success); border: 1px solid rgba(16,185,129,.25); }
.badge-blue  { background: #dbeafe; color: var(--primary); }
.badge-gold  { background: #fef3c7; color: var(--gold); }
.card-body   { padding: 20px 22px; }

/* ============================================================
   ENTITY MANAGEMENT CARDS
============================================================ */
.entity-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
}
@media(max-width:900px){ .entity-grid{ grid-template-columns: 1fr 1fr; } }
@media(max-width:560px){ .entity-grid{ grid-template-columns: 1fr; } }

.entity-card {
    border: 1.5px solid var(--border);
    border-radius: var(--radius);
    padding: 20px 20px 16px;
    display: flex;
    flex-direction: column;
    gap: 0;
    transition: all .22s;
    position: relative;
    overflow: hidden;
    background: var(--surface);
}
.entity-card::after {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    border-radius: var(--radius) var(--radius) 0 0;
    background: linear-gradient(90deg, var(--primary), var(--accent));
    opacity: 0;
    transition: opacity .22s;
}
.entity-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-lg); border-color: var(--border2); }
.entity-card:hover::after { opacity: 1; }

.ec-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    margin-bottom: 10px;
}
.ec-icon {
    width: 46px;
    height: 46px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    flex-shrink: 0;
}
.ec-count {
    font-family: var(--font-head);
    font-size: 28px;
    font-weight: 800;
    color: var(--text);
    line-height: 1;
}
.ec-label {
    font-family: var(--font-head);
    font-size: 13.5px;
    font-weight: 700;
    color: var(--text);
    margin-bottom: 3px;
}
.ec-desc {
    font-size: 11.5px;
    color: var(--muted);
    margin-bottom: 16px;
    line-height: 1.5;
}
.ec-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    width: 100%;
    background: linear-gradient(135deg, var(--navy), var(--navy3));
    color: #fff;
    font-size: 12.5px;
    font-weight: 600;
    padding: 9px 16px;
    border-radius: var(--radius-sm);
    border: none;
    transition: all .2s;
    letter-spacing: .2px;
    cursor: pointer;
}
.ec-btn:hover { background: linear-gradient(135deg, var(--primary2), var(--primary)); box-shadow: 0 3px 14px rgba(26,86,219,.3); }

/* ============================================================
   LIVE RESULTS TABLE
============================================================ */
.results-table-wrap {
    overflow-x: auto;
}
.results-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13.5px;
}
.results-table thead tr {
    background: #f1f5f9;
}
.results-table th {
    padding: 11px 16px;
    text-align: left;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .6px;
    color: var(--muted);
    border-bottom: 2px solid var(--border);
    white-space: nowrap;
}
.results-table td {
    padding: 14px 16px;
    border-bottom: 1px solid var(--border);
    vertical-align: middle;
}
.results-table tbody tr {
    transition: background .15s;
}
.results-table tbody tr:hover { background: #f8fafc; }
.results-table tbody tr:last-child td { border-bottom: none; }

.constituency-cell strong { font-size: 14px; font-weight: 700; display: block; margin-bottom: 2px; }
.constituency-cell span   { font-size: 11px; color: var(--muted); }
.winner-cell { display: flex; align-items: center; gap: 8px; }
.winner-avatar {
    width: 32px; height: 32px; border-radius: 50%;
    background: linear-gradient(135deg, #e0e7ff, #c7d2fe);
    display: flex; align-items: center; justify-content: center;
    font-size: 12px; font-weight: 700; color: var(--primary2);
    flex-shrink: 0;
}
.winner-name  { font-weight: 600; font-size: 13.5px; }
.party-pill {
    display: inline-flex;
    align-items: center;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
    color: #fff;
    letter-spacing: .2px;
}
.votes-cell { font-family: var(--font-head); font-size: 16px; font-weight: 700; color: var(--text); }
.votes-pct  { font-size: 11px; color: var(--muted); font-weight: 400; margin-top: 1px; }

.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 11.5px;
    font-weight: 700;
    letter-spacing: .3px;
    white-space: nowrap;
}
.sp-published  { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
.sp-approved   { background: #dbeafe; color: #1e40af; border: 1px solid #93c5fd; }
.sp-aggregated { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
.sp-pending    { background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; }

/* ============================================================
   PROGRESS BAR
============================================================ */
.prog-wrap { margin-bottom: 12px; }
.prog-label { display: flex; justify-content: space-between; font-size: 12px; font-weight: 500; color: var(--text2); margin-bottom: 5px; }
.prog-bar   { height: 7px; background: var(--bg2); border-radius: 99px; overflow: hidden; }
.prog-fill  { height: 100%; border-radius: 99px; transition: width .7s ease; }
.prog-fill.blue   { background: linear-gradient(90deg, var(--primary), var(--accent)); }
.prog-fill.green  { background: linear-gradient(90deg, var(--success), var(--success2)); }
.prog-fill.gold   { background: linear-gradient(90deg, #d97706, var(--gold2)); }

/* ============================================================
   OFFICER ROLE BREAKDOWN
============================================================ */
.role-rows { display: flex; flex-direction: column; gap: 10px; }
.role-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 14px;
    background: #f8fafc;
    border-radius: var(--radius-sm);
    border: 1px solid var(--border);
}
.role-dot {
    width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0;
}
.role-name { font-size: 12.5px; font-weight: 600; color: var(--text2); flex: 1; }
.role-badge {
    font-size: 11px;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 20px;
    font-family: var(--font-head);
}

/* ============================================================
   ACTIVITY LOG
============================================================ */
.activity-list { 
    display: flex; 
    flex-direction: column; 
    width: 100%;
}
.activity-item {
    display: grid;
    grid-template-columns: 40px 140px 1fr 1fr 100px;
    gap: 16px;
    align-items: center;
    padding: 14px 0;
    border-bottom: 1px solid var(--border);
    width: 100%;
}
.activity-item:last-child { border-bottom: none; }
.act-dot {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    flex-shrink: 0;
}


.act-dot.green  { background: #dcfce7; }
.act-dot.blue   { background: #dbeafe; }
.act-dot.amber  { background: #fef3c7; }
.act-dot.red    { background: #fee2e2; }
.act-dot.purple { background: #ede9fe; }

.act-main { font-size: 12.5px; font-weight: 600; color: var(--text); line-height: 1.4; }
.act-sub  { font-size: 11px; color: var(--muted); margin-top: 2px; }

/* ============================================================
   SUMMARY RING STATS
============================================================ */
.ring-row {
    display: grid;
    grid-template-columns: repeat(2,1fr);
    gap: 12px;
}
.ring-card {
    border: 1.5px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 14px;
    text-align: center;
    background: #fafbfc;
}
.ring-val  { font-family: var(--font-head); font-size: 24px; font-weight: 800; line-height: 1; margin-bottom: 3px; }
.ring-lbl  { font-size: 10.5px; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; color: var(--muted); }

/* ============================================================
   TOAST
============================================================ */
#toast { position: fixed; bottom: 28px; right: 28px; z-index: 999; }
.toast-msg {
    background: #1e293b; color: #fff; border-radius: 10px;
    padding: 12px 20px; font-size: 13px; font-weight: 500;
    margin-top: 8px; display: flex; align-items: center; gap: 10px;
    box-shadow: 0 4px 20px rgba(0,0,0,.2); animation: slideIn .3s ease;
}
.toast-msg.success { background: #166534; }
.toast-msg.error   { background: #991b1b; }
@keyframes slideIn { from{transform:translateY(20px);opacity:0;} to{transform:translateY(0);opacity:1;} }

/* ============================================================
   FOOTER
============================================================ */
.footer {
    background: var(--navy);
    padding: 18px 32px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 12px;
    color: #64748b;
    margin-top: auto;
    flex-wrap: wrap;
    gap: 8px;
}
.footer-links { display: flex; gap: 18px; }
.footer-links a { color: #64748b; text-decoration: none; transition: color .2s; }
.footer-links a:hover { color: #60a5fa; }

/* ============================================================
   DIVIDER
============================================================ */
.divider { border: none; border-top: 1.5px solid var(--border); margin: 0; }

/* Pill counts */
.count-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
    color: var(--text2);
    padding: 2px 10px;
    font-family: var(--font-head);
}

/* Staggered entrance */
@keyframes fadeUp {
    from { opacity:0; transform:translateY(18px); }
    to   { opacity:1; transform:translateY(0); }
}
.stat-tile { animation: fadeUp .4s ease both; }
.stat-tile:nth-child(1){animation-delay:.05s}
.stat-tile:nth-child(2){animation-delay:.10s}
.stat-tile:nth-child(3){animation-delay:.15s}
.stat-tile:nth-child(4){animation-delay:.20s}
.entity-card { animation: fadeUp .4s ease both; }
.entity-card:nth-child(1){animation-delay:.10s}
.entity-card:nth-child(2){animation-delay:.15s}
.entity-card:nth-child(3){animation-delay:.20s}
.entity-card:nth-child(4){animation-delay:.25s}
.entity-card:nth-child(5){animation-delay:.30s}
.entity-card:nth-child(6){animation-delay:.35s}
/* Responsive activity log */
@media (max-width: 900px) {
    .activity-item {
        grid-template-columns: 35px 120px 1fr 1fr 80px;
        gap: 10px;
    }
    .act-main { font-size: 11px; }
    .act-sub { font-size: 10px; }
}

@media (max-width: 700px) {
    .activity-item {
        grid-template-columns: 30px 1fr;
        gap: 10px;
        padding: 16px 0;
    }
    .activity-item > div:nth-child(2),
    .activity-item > div:nth-child(3),
    .activity-item > div:nth-child(4),
    .activity-item > div:nth-child(5) {
        grid-column: 2;
    }
    .activity-item > div:nth-child(3) { grid-row: 2; }
    .activity-item > div:nth-child(4) { grid-row: 3; }
    .activity-item > div:nth-child(5) { grid-row: 4; }
    
    .activity-list .activity-item .status-badge {
        display: inline-block;
        width: auto;
    }
}
</style>
</head>
<body>

<!-- ===== TOPBAR ===== -->
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

<!-- ===== MAIN ===== -->
<div class="page-wrap">

    <!-- PAGE HEADER -->
    <div class="page-header">
        <div>
            <div class="page-title">Administration Dashboard</div>
            <div class="page-subtitle">Managing national election readiness for <strong>General Election 2026</strong> &nbsp;·&nbsp; Full audit transparency enabled</div>
        </div>
        <div class="header-actions">
            <a href="voter_management.php" class="btn-primary">⚙️ Manage Voters</a>
        </div>
    </div>

    <!-- ===== TOP STAT ROW ===== -->
    <div class="grid-4 section-gap">

        <div class="stat-tile">
            <div class="st-delta info">All Validated</div>
            <div class="st-icon blue">🗺️</div>
            <div class="st-value blue"><?= number_format($stats['constituencies']) ?></div>
            <div class="st-label">Constituencies</div>
        </div>

        <div class="stat-tile">
            <div class="st-delta info"><?= $stats['verified_stations'] ?> Verified</div>
            <div class="st-icon green">🏫</div>
            <div class="st-value green"><?= number_format($stats['stations']) ?></div>
            <div class="st-label">Polling Stations</div>
        </div>

        <div class="stat-tile">
            <div class="st-delta up">Active</div>
            <div class="st-icon cyan">🏟️</div>
            <div class="st-value cyan"><?= number_format($stats['booths']) ?></div>
            <div class="st-label">Polling Booths</div>
        </div>

        <div class="stat-tile">
            <div class="st-delta warn">Turnout <?= $turnout_pct ?>%</div>
            <div class="st-icon gold">👥</div>
            <div class="st-value gold"><?= number_format($stats['voters']) ?></div>
            <div class="st-label">Registered Voters</div>
        </div>

    </div><!-- /grid-4 -->

    <!-- SECOND STAT ROW -->
    <div class="grid-4 section-gap">

        <div class="stat-tile">
            <div class="st-delta info">Verification In Progress</div>
            <div class="st-icon blue">🎖️</div>
            <div class="st-value blue"><?= number_format($stats['candidates']) ?></div>
            <div class="st-label">Candidates</div>
        </div>

        <div class="stat-tile">
            <div class="st-delta info">Active Registry</div>
            <div class="st-icon purple">🏳️</div>
            <div class="st-value purple"><?= number_format($stats['parties']) ?></div>
            <div class="st-label">Political Parties</div>
        </div>

        <div class="stat-tile">
            <div class="st-delta up"><?= $stats['officers'] ?> Active</div>
            <div class="st-icon green">👮</div>
            <div class="st-value green"><?= number_format($stats['officers']) ?></div>
            <div class="st-label">Election Officers</div>
        </div>

        <div class="stat-tile">
            <div class="st-delta <?= $stats['published'] > 0 ? 'up' : 'info' ?>"><?= $stats['published'] ?> Published</div>
            <div class="st-icon gold">📋</div>
            <div class="st-value gold"><?= number_format($stats['published']) ?></div>
            <div class="st-label">Results Published</div>
        </div>

    </div><!-- /grid-4 -->

    <!-- ===== ENTITY MANAGEMENT ===== -->
    <div class="card section-gap">
        <div class="card-header">
            <span class="card-title">🗂️ Entity Management Portals</span>
            <span class="card-badge badge-blue">6 Modules</span>
        </div>
        <div class="card-body">
            <div class="entity-grid">

                <!-- Constituencies -->
                <div class="entity-card">
                    <div class="ec-top">
                        <div class="ec-icon blue">🗺️</div>
                        <div class="ec-count"><?= $stats['constituencies'] ?></div>
                    </div>
                    <div class="ec-label">Constituencies</div>
                    <div class="ec-desc">Regional boundaries, voter density mapping &amp; officer assignment</div>
                    <a href="constituency_management.php" class="ec-btn">🔍 View &amp; Manage →</a>
                </div>

                <!-- Polling Stations -->
                <div class="entity-card">
                    <div class="ec-top">
                        <div class="ec-icon green">🏫</div>
                        <div class="ec-count"><?= $stats['stations'] ?></div>
                    </div>
                    <div class="ec-label">Polling Stations</div>
                    <div class="ec-desc">Physical locations, capacity audits &amp; PO assignments</div>
                    <a href="polling_station_management.php" class="ec-btn">🔍 View &amp; Manage →</a>
                </div>

                <!-- Polling Booths -->
                <div class="entity-card">
                    <div class="ec-top">
                        <div class="ec-icon cyan">🏟️</div>
                        <div class="ec-count"><?= $stats['booths'] ?></div>
                    </div>
                    <div class="ec-label">Polling Booths</div>
                    <div class="ec-desc">Booth-level management, APO assignments &amp; ballot issuance</div>
                    <a href="booth_management.php" class="ec-btn">🔍 View &amp; Manage →</a>
                </div>

                <!-- Candidates -->
                <div class="entity-card">
                    <div class="ec-top">
                        <div class="ec-icon blue">🎖️</div>
                        <div class="ec-count"><?= $stats['candidates'] ?></div>
                    </div>
                    <div class="ec-label">Candidates</div>
                    <div class="ec-desc">Nomination verification, party affiliation &amp; symbol allocation</div>
                    <a href="candidate_management.php" class="ec-btn">🔍 View &amp; Manage →</a>
                </div>

                <!-- Political Parties -->
                <div class="entity-card">
                    <div class="ec-top">
                        <div class="ec-icon purple">🏳️</div>
                        <div class="ec-count"><?= $stats['parties'] ?></div>
                    </div>
                    <div class="ec-label">Political Parties</div>
                    <div class="ec-desc">Symbol allocation, legal status &amp; registration management</div>
                    <a href="party_management.php" class="ec-btn">🔍 View &amp; Manage →</a>
                </div>

                <!-- Election Officers -->
                <div class="entity-card">
                    <div class="ec-top">
                        <div class="ec-icon gold">👮</div>
                        <div class="ec-count"><?= $stats['officers'] ?></div>
                    </div>
                    <div class="ec-label">Election Officers</div>
                    <div class="ec-desc">Personnel deployment, role assignment &amp; training status</div>
                    <a href="officer_management.php" class="ec-btn">🔍 View &amp; Manage →</a>
                </div>

            </div>
        </div>
    </div>

    <!-- ===== LIVE RESULTS + SIDEBAR ===== -->
    <div class="grid-main section-gap">

        <!-- LEFT: LIVE RESULTS TABLE -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">📡 Live Results Dashboard
                    <span style="font-size:11.5px;font-weight:400;color:var(--muted);">Preliminary &amp; approved constituency-level results</span>
                </span>
                <span class="card-badge badge-live">● LIVE DATA</span>
            </div>

            <!-- Table controls -->
            <div style="padding:14px 22px 0;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                <div style="font-size:12.5px;color:var(--muted);">
                    Showing <strong><?= count($live_results) ?></strong> constituencies
                </div>
                <div style="display:flex;gap:8px;align-items:center;">
                    <input type="text" id="resultsSearch" placeholder="Search results…"
                        oninput="filterResults(this.value)"
                        style="border:1.5px solid var(--border);border-radius:7px;padding:7px 14px;font-size:12.5px;font-family:var(--font-body);outline:none;width:200px;transition:border .2s;"
                        onfocus="this.style.borderColor='var(--primary)'" onblur="this.style.borderColor='var(--border)'">
                    <button class="btn-secondary" style="padding:7px 14px;font-size:12px;" onclick="refreshResults()">🔄 Refresh</button>
                </div>
            </div>

            <div class="results-table-wrap" style="padding:14px 0 0;">
                <table class="results-table" id="resultsTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Constituency ↑</th>
                            <th>Winning Candidate ↓</th>
                            <th>Party ↓</th>
                            <th>Total Votes ↓</th>
                            <th>Status ↓</th>
                        </tr>
                    </thead>
                    <tbody id="resultsBody">
                    <?php foreach($live_results as $i => $row):
                        $abbr = $row['abbreviation'] ?? '';
                        $pc   = $party_colors[$abbr] ?? '#475569';
                        $rc   = $row['result_status_cr'] ?? $row['result_status'];
                        $pct  = ($grand_total_votes > 0 && $row['total_votes_cast'] > 0)
                            ? round(($row['total_votes_cast'] / $grand_total_votes) * 100, 1)
                            : 0;
                        $status_label = match($rc) {
                            'PUBLISHED'  => ['sp-published',  '✔ Published'],
                            'APPROVED'   => ['sp-approved',   '✔ Approved'],
                            'AGGREGATED' => ['sp-aggregated', '⚙ Aggregated'],
                            default      => ['sp-pending',    '⏳ Pending'],
                        };
                    ?>
                    <tr>
                        <td style="color:var(--muted);font-weight:600;font-size:13px;"><?= $i+1 ?></td>
                        <td>
                            <div class="constituency-cell">
                                <strong><?= htmlspecialchars($row['constituency']) ?></strong>
                                <span>📍 Constituency · <?= htmlspecialchars($row['code']) ?></span>
                            </div>
                        </td>
                        <td>
                            <?php if($row['winner_name']): ?>
                            <div class="winner-cell">
                                <div class="winner-avatar"><?= strtoupper(substr($row['winner_name'],0,1)) ?></div>
                                <div class="winner-name">🏆 <?= htmlspecialchars($row['winner_name']) ?></div>
                            </div>
                            <?php else: ?>
                            <span style="color:var(--muted);font-size:12px;">— Pending —</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if($row['party_name']): ?>
                            <span class="party-pill" style="background:<?= $pc ?>;"><?= htmlspecialchars($row['party_name']) ?></span>
                            <?php else: ?>
                            <span style="color:var(--muted);font-size:12px;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="votes-cell"><?= number_format((int)$row['total_votes_cast']) ?></div>
                            <div class="votes-pct"><?= $pct ?>% of total</div>
                        </td>
                        <td>
                            <span class="status-pill <?= $status_label[0] ?>"><?= $status_label[1] ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div style="padding:12px 22px;background:#f8fafc;border-top:1px solid var(--border);font-size:12px;color:var(--muted);">
                Showing 1 to <?= count($live_results) ?> of <?= count($live_results) ?> constituencies
                &nbsp;·&nbsp; Last updated: <?= date('d M Y, h:i A') ?>
            </div>
        </div>

        <!-- RIGHT SIDEBAR -->
        <div style="display:flex;flex-direction:column;gap:20px;">

            <!-- Election Progress -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title">📊 Election Progress</span>
                    <span class="card-badge badge-blue">Live</span>
                </div>
                <div class="card-body">
                    <div class="prog-wrap">
                        <div class="prog-label">
                            <span>Station Verification</span>
                            <span style="font-weight:700;color:var(--primary);"><?= $station_pct ?>%</span>
                        </div>
                        <div class="prog-bar"><div class="prog-fill blue" style="width:<?= $station_pct ?>%"></div></div>
                    </div>
                    <div class="prog-wrap" style="margin-bottom:0;">
                        <div class="prog-label">
                            <span>Results Published</span>
                            <span style="font-weight:700;color:var(--gold);"><?= $results_published_pct ?>%</span>
                        </div>
                        <div class="prog-bar"><div class="prog-fill gold" style="width:<?= $results_published_pct ?>%"></div></div>
                    </div>

                    <hr class="divider" style="margin:18px 0;">

                    <div class="ring-row">
                        <div class="ring-card">
                            <div class="ring-val" style="color:var(--primary);"><?= $stats['verified_stations'] ?>/<?= $stats['stations'] ?></div>
                            <div class="ring-lbl">Stations Verified</div>
                        </div>
                        <div class="ring-card">
                            <div class="ring-val" style="color:var(--gold);"><?= ($constBreakdown['APPROVED'] ?? 0) + ($constBreakdown['PUBLISHED'] ?? 0) ?></div>
                            <div class="ring-lbl">Results Approved</div>
                        </div>
                        <div class="ring-card">
                            <div class="ring-val" style="color:var(--purple);"><?= $constBreakdown['PENDING'] ?? 0 ?></div>
                            <div class="ring-lbl">Const. Pending</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Officer Role Breakdown -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title">👮 Officer Roles</span>
                    <span class="count-pill"><?= $stats['officers'] ?> total</span>
                </div>
                <div class="card-body" style="padding-bottom:14px;">
                    <div class="role-rows">
                    <?php
                    $role_cfg = [
                        'RO'  => ['#1a56db', '#dbeafe', '#1e40af'],
                        'ARO' => ['#0ea5e9', '#cffafe', '#0369a1'],
                        'PO'  => ['#10b981', '#dcfce7', '#166534'],
                        'APO' => ['#8b5cf6', '#ede9fe', '#5b21b6'],
                    ];
                    foreach($role_breakdown as $rb):
                        $cfg = $role_cfg[$rb['role']] ?? ['#64748b','#f1f5f9','#334155'];
                        $role_full = ['RO'=>'Returning Officer','ARO'=>'Asst. Returning Officer','PO'=>'Presiding Officer','APO'=>'Asst. Presiding Officer'][$rb['role']] ?? $rb['role'];
                    ?>
                    <div class="role-row">
                        <div class="role-dot" style="background:<?= $cfg[0] ?>;"></div>
                        <div style="flex:1;">
                            <div class="role-name"><?= $role_full ?></div>
                            <div style="font-size:10.5px;color:var(--muted);"><?= $rb['role'] ?> Level</div>
                        </div>
                        <div class="role-badge" style="background:<?= $cfg[1] ?>;color:<?= $cfg[2] ?>;"><?= $rb['cnt'] ?></div>
                    </div>
                    <?php endforeach; ?>
                    </div>
                </div>
            </div>

        </div><!-- /sidebar -->
    </div><!-- /grid-main -->

    <!-- ===== ACTIVITY LOG ===== -->
    <div class="card section-gap">
        <div class="card-header">
            <span class="card-title">🕒 Recent System Events
                <span style="font-size:11.5px;font-weight:400;color:var(--muted);">Real-time audit log of administrator activities</span>
            </span>
            <a href="audit_logs.php" style="font-size:12.5px;font-weight:600;color:var(--primary);">View Full Log →</a>
        </div>
        <div class="card-body" style="padding:0 22px 8px;">
            <div style="display:grid;grid-template-columns:40px 140px 1fr 1fr 100px;gap:16px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);padding:12px 0;border-bottom:2px solid var(--border);">
    <div></div>
    <div>Timestamp</div>
    <div>Actor</div>
    <div>Action</div>
    <div>Status</div>
</div>
            <div class="activity-list">
            <?php
            $action_cfg = [
                'VOTE_ENTRY'     => ['🗳️','blue',   'Success'],
                'SUBMIT_VOTES'   => ['📤','green',  'Success'],
                'VERIFY_RESULT'  => ['✔', 'green',  'Success'],
                'APPROVE_RESULT' => ['🏆','green',  'Success'],
                'COMPILE_RESULT' => ['📊','blue',   'Success'],
                'REJECT_BOOTH'   => ['✖', 'amber',  'Warning'],
                'LOGIN'          => ['🔐','purple', 'Success'],
                'VERIFY_STATION' => ['✔', 'green',  'Success'],
            ];
          foreach($audit_logs as $log):
    $cfg = $action_cfg[$log['action_type']] ?? ['📋','blue','Info'];
    $statusColor = ['Success'=>'#166534','Warning'=>'#92400e','Info'=>'#1e40af'][$cfg[2]];
    $statusBg    = ['Success'=>'#dcfce7','Warning'=>'#fef3c7','Info'=>'#dbeafe'][$cfg[2]];
?>
<div class="activity-item">
    <div class="act-dot <?= $cfg[1] ?>"><?= $cfg[0] ?></div>
    <div>
        <div class="act-main"><?= date('M d, H:i', strtotime($log['timestamp'])) ?></div>
        <div class="act-sub"><?= date('Y', strtotime($log['timestamp'])) ?></div>
    </div>
    <div>
        <div class="act-main"><?= htmlspecialchars($log['officer_name'] ?? 'System') ?></div>
        <div class="act-sub"><?= htmlspecialchars($log['officer_role'] ?? '') ?></div>
    </div>
    <div>
        <div class="act-main"><?= htmlspecialchars($log['action_type']) ?></div>
        <div class="act-sub"><?= htmlspecialchars(substr($log['details'] ?? '', 0, 50)) ?>...</div>
    </div>
    <div>
        <span class="status-badge" style="background:<?= $statusBg ?>;color:<?= $statusColor ?>;">
            <?= $cfg[2] ?>
        </span>
    </div>
</div>
<?php endforeach; ?>
            <?php if(empty($audit_logs)): ?>
            <p style="color:var(--muted);font-size:13px;text-align:center;padding:24px 0;">No activity recorded yet.</p>
            <?php endif; ?>
            </div>
        </div>
    </div>

</div><!-- /page-wrap -->

<!-- ===== FOOTER ===== -->
<footer class="footer">
    <div>🗳️ EMS Admin &nbsp;·&nbsp; © 2026 Bangladesh Election Commission. All rights reserved.</div>
    <div class="footer-links">
        <a href="#">Privacy Policy</a>
        <a href="#">Security Audit</a>
        <a href="#">Help &amp; Documentation</a>
        <a href="#">Contact Support</a>
    </div>
</footer>

<!-- TOAST -->
<div id="toast"></div>

<script>
// ===== TOAST =====
function showToast(msg, type='info') {
    const container = document.getElementById('toast');
    const el = document.createElement('div');
    el.className = 'toast-msg ' + type;
    el.innerHTML = (type==='success'?'✅ ':type==='error'?'❌ ':type==='warning'?'⚠️ ':'ℹ️ ') + msg;
    container.appendChild(el);
    setTimeout(() => { el.style.opacity='0'; el.style.transition='opacity .4s'; setTimeout(()=>el.remove(),400); }, 4000);
}

// ===== FILTER RESULTS TABLE =====
function filterResults(q) {
    const rows = document.querySelectorAll('#resultsBody tr');
    const lq = q.toLowerCase();
    rows.forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(lq) ? '' : 'none';
    });
}

// ===== REFRESH LIVE RESULTS =====
function refreshResults() {
    const btn = document.querySelector('button[onclick="refreshResults()"]');
    if(btn) btn.textContent = '⏳ Refreshing…';
    const fd = new FormData();
    fd.append('action','get_live_results');
    fetch(window.location.href, {method:'POST', body:fd})
        .then(r => r.json())
        .then(res => {
            showToast('Live results refreshed.','success');
            if(btn) btn.textContent = '🔄 Refresh';
        })
        .catch(() => { showToast('Refresh failed.','error'); if(btn) btn.textContent='🔄 Refresh'; });
}

// ===== AUTO-REFRESH EVERY 60 SECONDS =====
setInterval(refreshResults, 60000);
</script>
</body>
</html>
<?php
/* =========================================
   Bangladesh Election Commission — EMS
   Homepage  |  index.php
   PHP 8.2 + MariaDB 10.4
   ========================================= */

/* ── DB CONFIG ─────────────────────────── */
define('DB_HOST', '127.0.0.1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'ems');

/* ── DB CONNECTION ─────────────────────── */
function db(): mysqli {
    static $conn = null;
    if ($conn === null) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($conn->connect_error) {
            // Graceful fallback — demo data shown instead
            return $conn; // will be checked per query
        }
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

/* ── HELPER ────────────────────────────── */
function q(string $sql, array $params = [], string $types = ''): array {
    $conn = db();
    if ($conn->connect_error) return [];
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    if ($params) $stmt->bind_param($types ?: str_repeat('s', count($params)), ...$params);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function q1(string $sql, array $params = [], string $types = ''): array {
    $rows = q($sql, $params, $types);
    return $rows[0] ?? [];
}

/* ── FETCH LIVE STATS ──────────────────── */
$totalConstituencies = q1("SELECT COUNT(*) AS n FROM constituencies")['n'] ?? 3;
$totalStations       = q1("SELECT COUNT(*) AS n FROM polling_stations")['n'] ?? 3;
$totalVoters         = q1("SELECT COUNT(*) AS n FROM voters")['n'] ?? 4;
$publishedResults    = q1("SELECT COUNT(*) AS n FROM constituency_results WHERE status='APPROVED' OR status='PUBLISHED'")['n'] ?? 3;

/* ── FETCH LIVE RESULTS TABLE ──────────── */
/* ── FETCH LIVE RESULTS TABLE ──────────── */
$liveResultsRaw = q("
    SELECT
        c.name            AS constituency,
        cand.full_name    AS winner,
        pp.name           AS party,
        cr.total_votes_cast,
        cr.status,
        c.total_registered_voters
    FROM constituency_results cr
    JOIN constituencies    c    ON c.constituency_id    = cr.constituency_id
    JOIN candidates        cand ON cand.candidate_id    = cr.winner_candidate_id
    LEFT JOIN political_parties pp ON pp.party_id       = cand.party_id
    WHERE cr.status = 'APPROVED' OR cr.status = 'PUBLISHED'
    ORDER BY cr.constituency_result_id
    
");

// Calculate turnout rate in PHP
$liveResults = [];
foreach ($liveResultsRaw as $row) {
    $turnout = ($row['total_registered_voters'] > 0) 
        ? round(($row['total_votes_cast'] / $row['total_registered_voters']) * 100, 1)
        : 0;
    
    $row['turnout_rate'] = $turnout;
    $liveResults[] = $row;
}

/* ── VOTER SEARCH (POST) ───────────────── */
$voterResult  = null;
$searchError  = '';
$searchDone   = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search_voter'])) {
    $searchDone = true;
    $voterCode  = trim($_POST['voter_code']  ?? '');
    $nid        = trim($_POST['national_id'] ?? '');

    if ($voterCode === '' && $nid === '') {
        $searchError = 'Please enter a Voter Code or National ID.';
    } else {
        $sql = "
            SELECT
                v.*,
                c.name   AS constituency_name,
                ps.name  AS station_name,
                ps.address AS station_address,
                pb.booth_number
            FROM voters v
            LEFT JOIN constituencies   c  ON c.constituency_id   = v.constituency_id
            LEFT JOIN polling_stations ps ON ps.station_id       = v.polling_station_id
            LEFT JOIN polling_booths   pb ON pb.booth_id         = v.booth_id
            WHERE ";

        if ($voterCode !== '') {
            $voterResult = q1($sql . "v.voter_code = ?", [$voterCode], 's');
        } else {
            $voterResult = q1($sql . "v.national_id = ?",  [$nid],       's');
        }

        if (!$voterResult) {
            $searchError = 'No voter record found. Please check your details.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Bangladesh Election Commission — EMS</title>

<!-- Google Fonts -->
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<!-- Bootstrap 5 -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<!-- DataTables CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
<!-- Font Awesome 6 -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"/>

<style>
/* ══════════════════════════════════════
   ROOT & RESET
══════════════════════════════════════ */
:root{
    --blue:      #0c3276;
    --blue2:     #1452a8;
    --blue3:     #1e6ee0;
    --gold:      #c9a84c;
    --gold2:     #f0c96b;
    --cream:     #fafbff;
    --border:    #dce6f5;
    --red:       #c0392b;
    --green:     #1a7a4e;
    --dark:      #060f24;
    --text:      #1a2035;
    --muted:     #6b7a99;
    --card-bg:   #ffffff;
    --radius:    16px;
    --shadow:    0 8px 32px rgba(12,50,118,.10);
    --shadow-lg: 0 20px 60px rgba(12,50,118,.16);
}

*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }

body {
    font-family: 'DM Sans', sans-serif;
    background: var(--cream);
    color: var(--text);
    overflow-x: hidden;
}

a { text-decoration:none; color:inherit; }

/* ══════════════════════════════════════
   NOISE OVERLAY (subtle texture)
══════════════════════════════════════ */
body::before {
    content:'';
    position:fixed; inset:0;
    background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.75' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.03'/%3E%3C/svg%3E");
    pointer-events:none;
    z-index:0;
}

/* ══════════════════════════════════════
   TOP TICKER BAR
══════════════════════════════════════ */
.ticker-bar {
    background: var(--dark);
    color: rgba(255,255,255,.75);
    font-size: 12px;
    padding: 7px 0;
    overflow: hidden;
    white-space: nowrap;
    border-bottom: 1px solid rgba(255,255,255,.08);
}
.ticker-inner {
    display: inline-block;
    animation: ticker 30s linear infinite;
    padding-left: 100%;
}
.ticker-item { margin-right: 60px; }
.ticker-item i { color: var(--gold2); margin-right:6px; }
@keyframes ticker {
    from { transform: translateX(0); }
    to   { transform: translateX(-100%); }
}

/* ══════════════════════════════════════
   MASTHEAD
══════════════════════════════════════ */
.masthead {
    background: linear-gradient(135deg, var(--dark) 0%, var(--blue) 100%);
    padding: 18px 0;
    position: relative;
    overflow: hidden;
    z-index:1;
}
.masthead::after {
    content:'';
    position:absolute;
    right:-80px; top:-80px;
    width:340px; height:340px;
    background: radial-gradient(circle, rgba(201,168,76,.18) 0%, transparent 70%);
    pointer-events:none;
}

.logo-ring {
    width:56px; height:56px;
    background: linear-gradient(135deg, var(--gold), var(--gold2));
    border-radius:50%;
    display:flex; align-items:center; justify-content:center;
    font-size:24px; color:var(--dark);
    box-shadow: 0 4px 16px rgba(201,168,76,.4);
    flex-shrink:0;
}

.brand-block { line-height:1.25; }
.brand-gov   { font-size:10px; letter-spacing:.08em; color:rgba(255,255,255,.55); text-transform:uppercase; }
.brand-name  { font-family:'Playfair Display',serif; font-size:18px; color:#fff; font-weight:700; }
.brand-sub   { font-size:11px; color:var(--gold2); }

.masthead-actions { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }

.pill-badge {
    border: 1px solid rgba(255,255,255,.25);
    background: rgba(255,255,255,.1);
    color:rgba(255,255,255,.85);
    padding:6px 14px; border-radius:50px;
    font-size:12px; font-weight:600;
    backdrop-filter:blur(4px);
}
.gold-pill {
    background: linear-gradient(90deg, var(--gold), var(--gold2));
    color:var(--dark); border:none;
    font-weight:700; font-size:12px;
}

.officer-login-btn {
    background: #fff;
    color: var(--blue);
    border:none; padding:9px 20px;
    border-radius:10px; font-size:13px;
    font-weight:700; cursor:pointer;
    transition:.2s;
    box-shadow: 0 4px 12px rgba(0,0,0,.15);
}
.officer-login-btn:hover { transform:translateY(-1px); box-shadow:0 6px 18px rgba(0,0,0,.2); }

/* ══════════════════════════════════════
   NAVBAR
══════════════════════════════════════ */
.main-nav {
    background:#fff;
    border-bottom: 2px solid var(--border);
    position: sticky; top:0; z-index:1000;
    box-shadow: 0 2px 12px rgba(12,50,118,.06);
}
.nav-link-custom {
    color: var(--muted) !important;
    font-weight: 500;
    font-size: 13.5px;
    padding: 14px 18px !important;
    transition: .2s;
    border-bottom: 2px solid transparent;
    margin-bottom:-2px;
}
.nav-link-custom:hover,
.nav-link-custom.active {
    color: var(--blue) !important;
    font-weight: 700;
    border-bottom-color: var(--gold);
}

/* ══════════════════════════════════════
   HERO
══════════════════════════════════════ */
.hero {
    padding: 80px 0 60px;
    background: linear-gradient(160deg, #ffffff 0%, #edf3ff 60%, #dce9ff 100%);
    position:relative; overflow:hidden; z-index:1;
}
.hero::before {
    content:'';
    position:absolute; left:-120px; top:-120px;
    width:500px; height:500px;
    border-radius:50%;
    background: radial-gradient(circle, rgba(30,110,224,.07) 0%, transparent 70%);
}
.hero::after {
    content:'';
    position:absolute; right:-80px; bottom:-80px;
    width:380px; height:380px;
    border-radius:50%;
    background: radial-gradient(circle, rgba(201,168,76,.08) 0%, transparent 70%);
}

.hero-eyebrow {
    display:inline-flex; align-items:center; gap:8px;
    background: rgba(12,50,118,.08);
    color:var(--blue); padding:7px 16px;
    border-radius:50px; font-size:12px; font-weight:700;
    letter-spacing:.06em; margin-bottom:22px;
    border:1px solid rgba(12,50,118,.12);
}
.hero-eyebrow i { color:var(--gold); }

.hero h1 {
    font-family: 'Playfair Display', serif;
    font-size: clamp(36px,5vw,62px);
    line-height: 1.1;
    color: var(--dark);
    font-weight: 800;
    margin-bottom: 22px;
}
.hero h1 em {
    font-style:normal;
    background: linear-gradient(90deg, var(--blue2), var(--blue3));
    -webkit-background-clip:text; -webkit-text-fill-color:transparent;
}

.hero-desc {
    color: var(--muted);
    font-size: 17px;
    line-height: 1.75;
    max-width: 540px;
    margin-bottom: 32px;
}

.hero-btn {
    display:inline-flex; align-items:center; gap:9px;
    padding:14px 26px; border-radius:12px;
    font-weight:700; font-size:14px;
    cursor:pointer; border:none; transition:.25s;
}
.hero-btn-primary {
    background: linear-gradient(135deg, var(--blue2), var(--blue3));
    color:#fff;
    box-shadow: 0 8px 24px rgba(20,82,168,.35);
}
.hero-btn-primary:hover { transform:translateY(-2px); box-shadow:0 12px 30px rgba(20,82,168,.45); color:#fff; }

.hero-btn-outline {
    background:#fff;
    color:var(--blue);
    border:2px solid var(--blue);
}
.hero-btn-outline:hover { background:var(--blue); color:#fff; transform:translateY(-2px); }

/* Hero image card */
.hero-visual {
    background:#fff;
    border-radius:24px;
    padding:20px;
    box-shadow: var(--shadow-lg);
    position:relative;
    border:1px solid var(--border);
}
.hero-visual img { width:100%; border-radius:16px; display:block; }
.hero-visual-badge {
    position:absolute;
    bottom:-16px; left:50%; transform:translateX(-50%);
    background:linear-gradient(90deg, var(--blue), var(--blue2));
    color:#fff; padding:10px 24px; border-radius:50px;
    font-size:13px; font-weight:700; white-space:nowrap;
    box-shadow:0 6px 18px rgba(12,50,118,.3);
    border:2px solid #fff;
}

/* ══════════════════════════════════════
   SECTION LABEL
══════════════════════════════════════ */
.section-label {
    display:inline-flex; align-items:center; gap:8px;
    font-size:11px; font-weight:700; letter-spacing:.12em;
    color:var(--blue); text-transform:uppercase;
    margin-bottom:10px;
}
.section-label::before {
    content:'';
    display:block; width:28px; height:3px;
    background:var(--gold); border-radius:2px;
}

.section-heading {
    font-family:'Playfair Display',serif;
    font-size:clamp(26px,4vw,40px);
    font-weight:800; color:var(--dark);
    line-height:1.2;
    margin-bottom:12px;
}

/* ══════════════════════════════════════
   VOTER SEARCH SECTION
══════════════════════════════════════ */
.search-section { padding:70px 0; z-index:1; position:relative; }

.search-form-card {
    background:#fff;
    border-radius:var(--radius);
    padding:34px;
    box-shadow: var(--shadow);
    border:1px solid var(--border);
    height:100%;
}

.input-group-ems label {
    font-size:12px; font-weight:700; color:var(--muted);
    letter-spacing:.06em; text-transform:uppercase;
    display:block; margin-bottom:8px;
}
.input-group-ems input {
    width:100%; border:1.5px solid var(--border);
    border-radius:10px; padding:13px 16px;
    font-size:14px; font-family:'DM Sans',sans-serif;
    color:var(--text); outline:none; transition:.2s;
    background:#fafbff;
}
.input-group-ems input:focus {
    border-color:var(--blue); background:#fff;
    box-shadow:0 0 0 4px rgba(12,50,118,.08);
}
.input-group-ems input::placeholder { color:#b0bace; }

.or-divider {
    text-align:center; position:relative; margin:18px 0;
    color:var(--muted); font-size:12px; font-weight:600;
}
.or-divider::before, .or-divider::after {
    content:''; position:absolute; top:50%;
    width:42%; height:1px; background:var(--border);
}
.or-divider::before { left:0; }
.or-divider::after  { right:0; }

.btn-search-ems {
    width:100%; padding:14px;
    background:linear-gradient(135deg, var(--blue2), var(--blue3));
    color:#fff; border:none; border-radius:10px;
    font-size:14px; font-weight:700; cursor:pointer;
    transition:.25s; font-family:'DM Sans',sans-serif;
    display:flex; align-items:center; justify-content:center; gap:8px;
}
.btn-search-ems:hover { transform:translateY(-2px); box-shadow:0 8px 22px rgba(20,82,168,.35); }

.btn-clear-ems {
    width:100%; padding:13px;
    background:#fff; color:var(--muted);
    border:1.5px solid var(--border); border-radius:10px;
    font-size:14px; font-weight:600; cursor:pointer;
    transition:.2s; font-family:'DM Sans',sans-serif;
}
.btn-clear-ems:hover { border-color:#b0bace; color:var(--text); }

.search-hint {
    font-size:12px; color:var(--muted); margin-top:14px;
    background:#f5f8ff; border-radius:8px; padding:10px 14px;
    border-left:3px solid var(--blue3);
}

/* ── Result Panel ── */
.result-panel {
    background:#fff;
    border-radius:var(--radius);
    box-shadow: var(--shadow);
    border:1px solid var(--border);
    min-height:420px;
    overflow:hidden;
    height:100%;
    display:flex; flex-direction:column;
}

.result-empty {
    flex:1; display:flex; flex-direction:column;
    align-items:center; justify-content:center;
    padding:50px 30px; text-align:center;
    color:var(--muted);
}
.result-empty .empty-icon {
    width:90px; height:90px;
    background:linear-gradient(135deg,#edf3ff,#dce6ff);
    border-radius:50%; display:flex; align-items:center; justify-content:center;
    font-size:38px; color:var(--blue); margin:0 auto 20px;
    border:2px dashed var(--border);
}

.voter-result-header {
    background:linear-gradient(135deg, var(--blue), var(--blue2));
    color:#fff; padding:24px 28px;
    display:flex; align-items:center; justify-content:space-between;
    flex-wrap:wrap; gap:12px;
}
.voter-avatar {
    width:52px; height:52px;
    background:rgba(255,255,255,.2);
    border-radius:14px; display:flex;
    align-items:center; justify-content:center;
    font-size:22px;
}
.voter-name-block .name { font-size:17px; font-weight:700; }
.voter-name-block .code { font-size:12px; opacity:.7; margin-top:3px; }

.status-badge-eligible {
    background:rgba(26,122,78,.2);
    color:#a4ffcd; border:1px solid rgba(164,255,205,.3);
    padding:6px 14px; border-radius:50px;
    font-size:11px; font-weight:700; letter-spacing:.05em;
}
.status-badge-voted {
    background:rgba(192,57,43,.2);
    color:#ffb3ae; border:1px solid rgba(255,179,174,.3);
    padding:6px 14px; border-radius:50px;
    font-size:11px; font-weight:700; letter-spacing:.05em;
}

.voter-result-body { padding:24px; flex:1; }

.info-tile {
    background:#f7f9ff;
    border:1px solid var(--border);
    border-radius:12px; padding:14px 16px;
    height:100%;
}
.info-tile .tile-label {
    font-size:10px; font-weight:700; color:var(--muted);
    letter-spacing:.1em; text-transform:uppercase; margin-bottom:6px;
}
.info-tile .tile-value {
    font-size:15px; font-weight:700; color:var(--text);
}
.info-tile .tile-icon {
    font-size:22px; color:var(--blue); margin-bottom:10px;
}

.voting-schedule-box {
    background: linear-gradient(90deg, #edf4ff, #f3f8ff);
    border-left:4px solid var(--blue3);
    border-radius:10px; padding:14px 16px;
    margin-top:16px;
    display:flex; align-items:center; gap:12px;
    font-size:13px; color:var(--blue); font-weight:600;
}

/* ── Error alert ── */
.alert-ems {
    background:#fff1f0; border:1.5px solid #ffccc7;
    color:#a8071a; border-radius:10px;
    padding:14px 18px; font-size:14px; margin-bottom:0;
    display:flex; align-items:center; gap:10px;
}

/* ══════════════════════════════════════
   STATS STRIP
══════════════════════════════════════ */
.stats-strip {
    background: linear-gradient(135deg, var(--dark) 0%, var(--blue) 100%);
    padding: 48px 0; position:relative; z-index:1;
}
.stats-strip::before {
    content:'';
    position:absolute; inset:0;
    background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.03'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
}

.stat-item { text-align:center; position:relative; }
.stat-item::after {
    content:'';
    position:absolute; right:0; top:20%;
    height:60%; width:1px;
    background:rgba(255,255,255,.15);
}
.stat-item:last-child::after { display:none; }

.stat-number {
    font-family:'Playfair Display',serif;
    font-size: clamp(28px,4vw,44px);
    font-weight:800; color:#fff; line-height:1;
    margin-bottom:6px;
}
.stat-number span { color:var(--gold2); }
.stat-lbl { font-size:13px; color:rgba(255,255,255,.6); font-weight:500; }
.stat-icon {
    width:48px; height:48px;
    background:rgba(255,255,255,.1);
    border-radius:12px; display:flex; align-items:center; justify-content:center;
    font-size:20px; color:var(--gold2);
    margin:0 auto 14px;
    border:1px solid rgba(255,255,255,.15);
}

/* ══════════════════════════════════════
   FEATURES
══════════════════════════════════════ */
.features-section { padding:80px 0; background:#fff; position:relative; z-index:1; }

.feature-card {
    background:#f7f9ff;
    border:1px solid var(--border);
    border-radius:20px; padding:32px;
    text-align:center; height:100%;
    transition:.3s;
    position:relative; overflow:hidden;
}
.feature-card::before {
    content:'';
    position:absolute; bottom:0; left:0; right:0; height:4px;
    background:linear-gradient(90deg, var(--blue2), var(--gold));
    transform:scaleX(0); transition:.3s; transform-origin:left;
}
.feature-card:hover { transform:translateY(-6px); box-shadow:var(--shadow-lg); background:#fff; }
.feature-card:hover::before { transform:scaleX(1); }

.feature-icon-wrap {
    width:72px; height:72px;
    background:linear-gradient(135deg, var(--blue2), var(--blue3));
    border-radius:20px; display:flex; align-items:center; justify-content:center;
    font-size:28px; color:#fff;
    margin:0 auto 20px;
    box-shadow:0 8px 24px rgba(20,82,168,.28);
}
.feature-card h5 { font-weight:700; font-size:16px; margin-bottom:10px; color:var(--dark); }
.feature-card p  { color:var(--muted); font-size:14px; line-height:1.65; }

/* ══════════════════════════════════════
   LIVE RESULTS TABLE
══════════════════════════════════════ */
.results-section {
    padding: 80px 0;
    position: relative;
    z-index: 1;
    background: linear-gradient(180deg, #f5f7fa 0%, #eef2ff 100%);
}

.results-card {
    background: #fff;
    border-radius: 20px;
    border: none;
    box-shadow: 0 8px 40px rgba(26, 54, 126, 0.13);
    overflow: hidden;
}

.results-card-header {
    padding: 24px 28px;
    background: linear-gradient(135deg, #1e3a5f 0%, #1a56db 60%, #0ea5e9 100%);
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
}
.results-card-header h4 {
    font-weight: 800;
    font-size: 18px;
    color: #fff;
    margin: 0;
}
.results-card-header small {
    color: rgba(255,255,255,.65);
    font-size: 12px;
}
.live-dot {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: rgba(255,255,255,.15);
    color: #fff;
    border: 1px solid rgba(255,255,255,.3);
    padding: 6px 14px;
    border-radius: 50px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .5px;
}
.live-dot i { animation: pulse 1.2s ease infinite; color: #4ade80; }
@keyframes pulse { 0%,100%{opacity:1;} 50%{opacity:.3;} }

.table-ems thead th {
    background: #f0f5ff;
    border-bottom: 2px solid #dbeafe;
    font-size: 11px;
    font-weight: 700;
    color: #1a56db;
    letter-spacing: .08em;
    text-transform: uppercase;
    padding: 14px 20px;
}
.table-ems tbody td {
    padding: 16px 20px;
    vertical-align: middle;
    border-bottom: 1px solid #f0f4fb;
    font-size: 14px;
}
.table-ems tbody tr:last-child td { border-bottom: none; }
.table-ems tbody tr:hover td {
    background: linear-gradient(90deg, #fefce8, #f0f9ff);
    transition: background .2s;
}

/* Winner row gets gold left border */
.table-ems tbody tr:first-child td:first-child {
    border-left: 4px solid #d97706;
}
.table-ems tbody tr:first-child td {
    background: linear-gradient(90deg, #fffbeb, #fff);
}

.winner-name {
    font-weight: 700;
    color: #1e293b;
    font-size: 14.5px;
}
.winner-name::before {
    content: '🏆 ';
    font-size: 13px;
}

.party-tag {
    display: inline-block;
    background: linear-gradient(135deg, #1e3a5f, #1a56db);
    color: #fff;
    padding: 4px 12px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: .3px;
}

.votes-count {
    font-weight: 800;
    color: #1a56db;
    font-size: 15px;
}

.status-pill-approved {
    background: linear-gradient(135deg, #166534, #16a34a);
    color: #fff;
    border: none;
    padding: 5px 14px;
    border-radius: 50px;
    font-size: 11px;
    font-weight: 700;
    box-shadow: 0 2px 8px rgba(22,163,74,.25);
}
.status-pill-pending {
    background: #fef9ec;
    color: #b77f00;
    border: 1px solid #fde8a0;
    padding: 5px 12px;
    border-radius: 50px;
    font-size: 11px;
    font-weight: 700;
}
.votes-count { font-weight:600; color:var(--muted); font-size:13px; }

/* ══════════════════════════════════════
   OFFICER LOGIN MODAL
══════════════════════════════════════ */
.modal-login .modal-content {
    border-radius:20px; border:none;
    box-shadow:0 30px 80px rgba(0,0,0,.2);
}
.modal-login .modal-header {
    background:linear-gradient(135deg, var(--dark), var(--blue));
    border-radius:20px 20px 0 0; border:none;
    padding:24px 28px;
}
.modal-login .modal-title {
    color:#fff; font-family:'Playfair Display',serif;
    font-size:20px; font-weight:800;
}
.modal-login .btn-close { filter:invert(1) opacity(.7); }
.modal-login .modal-body { padding:32px 28px; }

.modal-input-group { margin-bottom:20px; }
.modal-input-group label {
    font-size:12px; font-weight:700; color:var(--muted);
    letter-spacing:.06em; text-transform:uppercase; display:block; margin-bottom:8px;
}
.modal-input-group input {
    width:100%; border:1.5px solid var(--border);
    border-radius:10px; padding:13px 16px;
    font-size:14px; font-family:'DM Sans',sans-serif;
    color:var(--text); outline:none; transition:.2s;
}
.modal-input-group input:focus {
    border-color:var(--blue);
    box-shadow:0 0 0 4px rgba(12,50,118,.08);
}

.btn-login-submit {
    width:100%; padding:14px;
    background:linear-gradient(135deg, var(--blue2), var(--blue3));
    color:#fff; border:none; border-radius:10px;
    font-size:15px; font-weight:700; cursor:pointer;
    transition:.25s; font-family:'DM Sans',sans-serif;
}
.btn-login-submit:hover { transform:translateY(-2px); box-shadow:0 8px 22px rgba(20,82,168,.35); }

/* ══════════════════════════════════════
   FOOTER
══════════════════════════════════════ */
footer {
    background:var(--dark);
    color:rgba(255,255,255,.75);
    padding:52px 0 0;
    position:relative; z-index:1;
}
.footer-brand { font-family:'Playfair Display',serif; font-size:18px; color:#fff; margin-bottom:10px; }
.footer-tagline { font-size:13px; line-height:1.7; color:rgba(255,255,255,.5); }
.footer-heading { font-size:12px; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:var(--gold2); margin-bottom:14px; }
.footer-link { display:block; color:rgba(255,255,255,.55); font-size:13px; margin-bottom:8px; transition:.2s; }
.footer-link:hover { color:#fff; padding-left:4px; }
.footer-divider { border-top:1px solid rgba(255,255,255,.08); margin-top:40px; padding:18px 0; text-align:center; font-size:12px; color:rgba(255,255,255,.3); }

/* ══════════════════════════════════════
   ANIMATIONS
══════════════════════════════════════ */
.fade-up {
    opacity:0; transform:translateY(28px);
    animation:fadeUp .6s ease forwards;
}
@keyframes fadeUp {
    to { opacity:1; transform:translateY(0); }
}
.delay-1 { animation-delay:.1s; }
.delay-2 { animation-delay:.2s; }
.delay-3 { animation-delay:.3s; }
.delay-4 { animation-delay:.4s; }

/* ══════════════════════════════════════
   RESPONSIVE
══════════════════════════════════════ */
@media(max-width:991px){
    .hero { text-align:center; }
    .hero-desc { margin:0 auto 28px; }
    .hero-buttons { justify-content:center; }
    .hero-visual { margin-top:48px; }
    .stat-item::after { display:none; }
}
@media(max-width:767px){
    .masthead-actions { gap:6px; }
    .pill-badge { display:none; }
}
/* Turnout Rate Badge Styles */
.turnout-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: #eff6ff;
    color: #1a56db;
    border: 1px solid #bfdbfe;
    padding: 5px 12px;
    border-radius: 50px;
    font-size: 12px;
    font-weight: 700;
}

/* Optional: Different color for high turnout (>70%) */
.turnout-badge.high {
    background: linear-gradient(135deg, #1a7a4e, #239b64);
    color: white;
    border-color: #2e8b57;
}
.turnout-badge.high i {
    color: #ffd966;
}

/* Optional: Progress bar style instead of badge (alternative) */
.turnout-bar {
    display: flex;
    align-items: center;
    gap: 8px;
}
.turnout-bar-progress {
    width: 80px;
    height: 6px;
    background: #e2e8f0;
    border-radius: 10px;
    overflow: hidden;
}
.turnout-bar-fill {
    height: 100%;
    background: linear-gradient(90deg, #1452a8, #1e6ee0);
    border-radius: 10px;
    transition: width 0.3s ease;
}
.turnout-bar-fill.high {
    background: linear-gradient(90deg, #1a7a4e, #239b64);
}
.turnout-percent {
    font-weight: 700;
    font-size: 13px;
    color: #1a2035;
}
/* ═══════════════════════════════════════════════════════════════
   DATATABLES CUSTOM STYLING — EMS Dashboard Theme
   Matches existing blue/gold color scheme
═══════════════════════════════════════════════════════════════ */

/* DataTables wrapper */
.dataTables_wrapper {
    padding: 0;
    font-family: 'DM Sans', sans-serif;
}

/* ─────────── TOP CONTROLS (Search & Show entries) ─────────── */
.dataTables_length {
    margin-bottom: 20px;
    font-size: 13px;
    color: var(--muted);
}

.dataTables_length label {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-weight: 500;
}

.dataTables_length select {
    background: #fafbff;
    border: 1.5px solid var(--border);
    border-radius: 10px;
    padding: 8px 28px 8px 12px;
    font-size: 13px;
    font-weight: 600;
    color: var(--text);
    cursor: pointer;
    font-family: 'DM Sans', sans-serif;
    transition: all 0.2s ease;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%236b7a99' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 10px center;
}

.dataTables_length select:focus {
    border-color: var(--blue);
    outline: none;
    box-shadow: 0 0 0 3px rgba(12,50,118,.1);
}

.dataTables_filter {
    margin-bottom: 20px;
}

.dataTables_filter label {
    display: inline-flex;
    align-items: center;
    gap: 12px;
    font-size: 13px;
    font-weight: 500;
    color: var(--muted);
}

.dataTables_filter input {
    background: #fafbff;
    border: 1.5px solid var(--border);
    border-radius: 10px;
    padding: 9px 16px;
    font-size: 13px;
    font-family: 'DM Sans', sans-serif;
    color: var(--text);
    width: 260px;
    transition: all 0.2s ease;
}

.dataTables_filter input:focus {
    border-color: var(--blue);
    outline: none;
    box-shadow: 0 0 0 3px rgba(12,50,118,.1);
    background: #fff;
}

.dataTables_filter input::placeholder {
    color: #b0bace;
}

/* ─────────── TABLE STYLES (overrides) ─────────── */
.table-ems.dataTable {
    margin-bottom: 20px !important;
    border-collapse: separate;
    border-spacing: 0;
}

.table-ems.dataTable thead th {
    background: #f7f9ff;
    border-bottom: 2px solid var(--border);
    font-size: 11px;
    font-weight: 700;
    color: var(--muted);
    letter-spacing: 0.08em;
    text-transform: uppercase;
    padding: 14px 20px;
    cursor: pointer;
    transition: background 0.2s ease;
}

.table-ems.dataTable thead th:hover {
    background: #eef2fc;
}

/* Sorting icons */
.table-ems.dataTable thead .sorting {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='%236b7a99' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 8px center;
}

.table-ems.dataTable thead .sorting_asc {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='%231452a8' stroke-width='2'%3E%3Cpolyline points='18 15 12 9 6 15'%3E%3C/polyline%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 8px center;
    color: var(--blue);
}

.table-ems.dataTable thead .sorting_desc {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='%231452a8' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 8px center;
    color: var(--blue);
}

.table-ems.dataTable tbody td {
    padding: 16px 20px;
    vertical-align: middle;
    border-bottom: 1px solid #f0f4fb;
    font-size: 14px;
}

.table-ems.dataTable tbody tr:hover td {
    background: #f9fbff;
}

/* ─────────── PAGINATION (1 2 3 ... Next) ─────────── */
.dataTables_paginate {
    margin-top: 24px;
    display: flex;
    justify-content: flex-end;
    gap: 6px;
}

.paginate_button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 38px;
    height: 38px;
    padding: 0 12px;
    background: #fff;
    border: 1.5px solid var(--border);
    border-radius: 10px;
    font-size: 13px;
    font-weight: 600;
    color: var(--muted);
    cursor: pointer;
    transition: all 0.2s ease;
    font-family: 'DM Sans', sans-serif;
}

.paginate_button:hover:not(.disabled):not(.current) {
    background: #f7f9ff;
    border-color: var(--blue2);
    color: var(--blue2);
    transform: translateY(-1px);
}

.paginate_button.current {
    background: linear-gradient(135deg, var(--blue2), var(--blue3));
    border-color: var(--blue2);
    color: #fff;
    cursor: default;
    box-shadow: 0 2px 8px rgba(20,82,168,.3);
}

.paginate_button.disabled {
    opacity: 0.4;
    cursor: not-allowed;
    background: #fafbff;
}

/* Previous/Next buttons with arrows */
.paginate_button.previous,
.paginate_button.next {
    gap: 6px;
}

.paginate_button.previous:before {
    content: '←';
    margin-right: 4px;
    font-weight: normal;
}

.paginate_button.next:after {
    content: '→';
    margin-left: 4px;
    font-weight: normal;
}

/* ─────────── INFO TEXT (Showing X to Y of Z) ─────────── */
.dataTables_info {
    margin-top: 24px;
    font-size: 13px;
    color: var(--muted);
    font-weight: 500;
    display: inline-block;
}

/* ─────────── Responsive Layout (Top controls side by side) ─────────── */
@media (min-width: 768px) {
    .dataTables_wrapper .row:first-child {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
    }
    
    .dataTables_length {
        float: left;
    }
    
    .dataTables_filter {
        float: right;
    }
    
    .dataTables_length,
    .dataTables_filter {
        margin-bottom: 24px;
    }
    
    .dataTables_wrapper .row:last-child {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
    }
    
    .dataTables_info {
        float: left;
    }
    
    .dataTables_paginate {
        float: right;
    }
}

/* Mobile responsive */
@media (max-width: 767px) {
    .dataTables_length,
    .dataTables_filter {
        float: none;
        width: 100%;
        margin-bottom: 16px;
    }
    
    .dataTables_filter input {
        width: 100%;
    }
    
    .dataTables_info,
    .dataTables_paginate {
        float: none;
        text-align: center;
        margin-top: 16px;
    }
    
    .dataTables_paginate {
        justify-content: center;
    }
    
    .paginate_button {
        min-width: 34px;
        height: 34px;
        font-size: 12px;
        padding: 0 8px;
    }
}

/* Loading overlay (while DataTables initializes) */
.dataTables_processing {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: rgba(255,255,255,0.95);
    padding: 16px 28px;
    border-radius: 40px;
    box-shadow: var(--shadow);
    color: var(--blue);
    font-weight: 600;
    font-size: 14px;
    z-index: 100;
    border: 1px solid var(--border);
}

/* Empty state styling */
.dataTables_empty {
    text-align: center;
    padding: 60px 20px !important;
    color: var(--muted);
    font-size: 14px;
}

/* Highlight for search matches (optional) */
.highlight {
    background-color: rgba(201,168,76,0.2);
    border-radius: 4px;
    padding: 0 2px;
}
</style>
</head>
<body>

<!-- ══════════════════════════════════════
     TICKER BAR
══════════════════════════════════════ -->
<div class="ticker-bar">
    <span class="ticker-inner">
        <span class="ticker-item"><i class="fa-solid fa-circle-dot"></i> General Election 2026 — Voter Registration Deadline: 31 March 2026</span>
        <span class="ticker-item"><i class="fa-solid fa-circle-dot"></i> Polling Day: 15 April 2026 — 08:00 AM to 04:00 PM</span>
        <span class="ticker-item"><i class="fa-solid fa-circle-dot"></i> Results are being tabulated — <?= (int)$publishedResults ?> Constituencies Approved</span>
        <span class="ticker-item"><i class="fa-solid fa-circle-dot"></i> Officer Helpline: 105 &nbsp;|&nbsp; Public Portal: ems.gov.bd</span>
        <span class="ticker-item"><i class="fa-solid fa-circle-dot"></i> Carry your NID/Voter Card to the polling station</span>
    </span>
</div>

<!-- ══════════════════════════════════════
     MASTHEAD
══════════════════════════════════════ -->
<div class="masthead">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">

            <div class="d-flex align-items-center gap-3">
                <div class="logo-ring"><i class="fa-solid fa-landmark"></i></div>
                <div class="brand-block">
                    <div class="brand-gov">Government of the People's Republic of Bangladesh</div>
                    <div class="brand-name">Bangladesh Election Commission</div>
                    <div class="brand-sub"><i class="fa-solid fa-circle-dot me-1" style="font-size:9px"></i>Election Management System (EMS)</div>
                </div>
            </div>

            <div class="masthead-actions">
                <span class="pill-badge gold-pill"><i class="fa-solid fa-bolt me-1"></i>General Election 2026</span>
                <span class="pill-badge"><i class="fa-solid fa-globe me-1"></i>Public Portal</span>
                <button class="officer-login-btn" data-bs-toggle="modal" data-bs-target="#loginModal">
                    <i class="fa-solid fa-lock me-1"></i> Officer Login
                </button>
            </div>

        </div>
    </div>
</div>

<!-- ══════════════════════════════════════
     NAVBAR
══════════════════════════════════════ -->
<nav class="main-nav navbar navbar-expand-lg">
    <div class="container">
        <button class="navbar-toggler border-0" data-bs-toggle="collapse" data-bs-target="#navMenu">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navMenu">
            <ul class="navbar-nav">
                <li class="nav-item"><a class="nav-link-custom active nav-link" href="#">Home</a></li>
                <li class="nav-item"><a class="nav-link-custom nav-link" href="#search">Find Polling Station</a></li>
                <li class="nav-item"><a class="nav-link-custom nav-link" href="#results">Election Results</a></li>
                <li class="nav-item"><a class="nav-link-custom nav-link" href="#features">System Features</a></li>
                <li class="nav-item"><a class="nav-link-custom nav-link" href="#footer">About EMS</a></li>
                <li class="nav-item"><a class="nav-link-custom nav-link" href="mailto:support@ems.gov.bd">Help</a></li>
            </ul>
        </div>
    </div>
</nav>

<!-- ══════════════════════════════════════
     HERO
══════════════════════════════════════ -->
<section class="hero">
    <div class="container">
        <div class="row align-items-center">

            <div class="col-lg-6 fade-up">
                <div class="hero-eyebrow">
                    <i class="fa-solid fa-shield-halved"></i>
                    Official National Election Platform
                </div>
                <h1>
                    National<br>
                    <em>Election Management</em><br>
                    System
                </h1>
                <p class="hero-desc">
                    Secure voter services, polling station management, and
                    real-time result monitoring for all citizens of the
                    People's Republic of Bangladesh.
                </p>
                <div class="d-flex flex-wrap gap-3 hero-buttons">
                    <a href="#search" class="hero-btn hero-btn-primary">
                        <i class="fa-solid fa-location-dot"></i>
                        Find My Polling Station
                    </a>
                    <a href="#results" class="hero-btn hero-btn-outline">
                        <i class="fa-solid fa-chart-column"></i>
                        View Election Results
                    </a>
                </div>
            </div>

            <div class="col-lg-6 fade-up delay-2">
                <div class="hero-visual">
                    <img src="https://images.unsplash.com/photo-1529107386315-e1a2ed48a620?q=80&w=1200&auto=format&fit=crop"
                         alt="Election polling">
                    <div class="hero-visual-badge">
                        <i class="fa-solid fa-circle-check me-2"></i>
                        Polling Day: 15 April 2026
                    </div>
                </div>
            </div>

        </div>
    </div>
</section>

<!-- ══════════════════════════════════════
     VOTER SEARCH SECTION
══════════════════════════════════════ -->
<section class="search-section" id="search">
    <div class="container">

        <div class="text-center mb-5 fade-up">
            <div class="section-label mx-auto" style="justify-content:center;">Voter Services</div>
            <h2 class="section-heading">Find Your Polling Station</h2>
            <p class="text-muted">Enter your Voter Code or National ID to find your assigned polling center and booth.</p>
        </div>

        <div class="row g-4">

            <!-- SEARCH FORM -->
            <div class="col-lg-5 fade-up delay-1">
                <div class="search-form-card">
                    <div class="d-flex align-items-center gap-3 mb-4">
                        <div class="feature-icon-wrap" style="width:48px;height:48px;font-size:20px;flex-shrink:0;">
                            <i class="fa-solid fa-magnifying-glass"></i>
                        </div>
                        <div>
                            <h5 class="mb-0" style="font-weight:800;font-size:16px;">Voter Lookup</h5>
                            <p class="mb-0 text-muted" style="font-size:13px;">Search by Voter Code or National ID</p>
                        </div>
                    </div>

                    <form method="POST" action="#search">
                        <div class="input-group-ems mb-3">
                            <label for="voter_code"><i class="fa-solid fa-id-card me-1"></i> Voter Code</label>
                            <input type="text" id="voter_code" name="voter_code"
                                   placeholder="e.g. VTR-2026-00412"
                                   value="<?= htmlspecialchars($_POST['voter_code'] ?? '') ?>">
                        </div>

                        <div class="or-divider">OR</div>

                        <div class="input-group-ems mb-4">
                            <label for="national_id"><i class="fa-solid fa-fingerprint me-1"></i> National ID (NID)</label>
                            <input type="text" id="national_id" name="national_id"
                                   placeholder="e.g. V001"
                                   value="<?= htmlspecialchars($_POST['national_id'] ?? '') ?>">
                        </div>

                        <div class="row g-2">
                            <div class="col-7">
                                <button type="submit" name="search_voter" class="btn-search-ems">
                                    <i class="fa-solid fa-magnifying-glass"></i> Search Database
                                </button>
                            </div>
                            <div class="col-5">
                                <a href="#search" class="btn-clear-ems d-flex align-items-center justify-content-center gap-2" style="text-decoration:none;">
                                    <i class="fa-solid fa-rotate-left"></i> Clear
                                </a>
                            </div>
                        </div>

                        <input type="hidden" name="search_voter" value="1">
                    </form>

                    <div class="search-hint">
                        <i class="fa-solid fa-circle-info me-1"></i>
                        Your information must match your official voter registration record exactly.
                    </div>
                </div>
            </div>

            <!-- RESULT PANEL -->
            <div class="col-lg-7 fade-up delay-2">
                <div class="result-panel">

                <?php if (!$searchDone): ?>

                    <div class="result-empty">
                        <div class="empty-icon"><i class="fa-solid fa-id-card"></i></div>
                        <h5 style="font-weight:800;margin-bottom:10px;">Search for a Voter</h5>
                        <p style="font-size:14px;max-width:320px;margin:0 auto;">
                            Enter your Voter Code or National ID on the left to view your
                            polling center, booth assignment, and eligibility status.
                        </p>
                    </div>

                <?php elseif ($searchError): ?>

                    <div class="result-empty">
                        <div class="empty-icon" style="background:linear-gradient(135deg,#fff0ef,#ffe0de);color:var(--red);">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                        </div>
                        <h5 style="font-weight:800;margin-bottom:10px;color:var(--red);">Not Found</h5>
                        <p style="font-size:14px;max-width:320px;margin:0 auto;"><?= htmlspecialchars($searchError) ?></p>
                    </div>

                <?php else:
                    $v = $voterResult;
                    $eligible = !$v['has_voted'];
                ?>

                    <div class="voter-result-header">
                        <div class="d-flex align-items-center gap-14" style="gap:14px;">
                            <div class="voter-avatar"><i class="fa-solid fa-user"></i></div>
                            <div class="voter-name-block">
                                <div class="name"><?= htmlspecialchars($v['full_name']) ?></div>
                                <div class="code"><?= htmlspecialchars($v['voter_code']) ?></div>
                            </div>
                        </div>
                        <?php if ($eligible): ?>
                            <span class="status-badge-eligible"><i class="fa-solid fa-circle-check me-1"></i>Eligible to Vote</span>
                        <?php else: ?>
                            <span class="status-badge-voted"><i class="fa-solid fa-circle-xmark me-1"></i>Already Voted</span>
                        <?php endif; ?>
                    </div>

                    <div class="voter-result-body">
                        <div class="row g-3">
                            <div class="col-sm-6">
                                <div class="info-tile">
                                    <div class="tile-icon"><i class="fa-solid fa-map-location-dot"></i></div>
                                    <div class="tile-label">Constituency</div>
                                    <div class="tile-value"><?= htmlspecialchars($v['constituency_name'] ?? 'N/A') ?></div>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <div class="info-tile">
                                    <div class="tile-icon"><i class="fa-solid fa-building"></i></div>
                                    <div class="tile-label">Polling Center</div>
                                    <div class="tile-value"><?= htmlspecialchars($v['station_name'] ?? 'N/A') ?></div>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <div class="info-tile">
                                    <div class="tile-icon"><i class="fa-solid fa-door-open"></i></div>
                                    <div class="tile-label">Polling Booth</div>
                                    <div class="tile-value">Booth <?= htmlspecialchars($v['booth_number'] ?? 'N/A') ?></div>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <div class="info-tile">
                                    <div class="tile-icon"><i class="fa-solid fa-location-pin"></i></div>
                                    <div class="tile-label">Center Address</div>
                                    <div class="tile-value" style="font-size:13px;"><?= htmlspecialchars($v['station_address'] ?? 'N/A') ?></div>
                                </div>
                            </div>
                        </div>

                        <div class="voting-schedule-box">
                            <i class="fa-solid fa-calendar-days fa-lg"></i>
                            <div>
                                <div>Polling Day: <strong>15 April 2026</strong></div>
                                <div style="font-size:12px;opacity:.8;">Voting Hours: 08:00 AM — 04:00 PM</div>
                            </div>
                        </div>

                        <?php if ($eligible): ?>
                        <div class="mt-3 p-3 rounded-3" style="background:#edfff6;border:1px solid #b7ebd0;font-size:13px;color:var(--green);">
                            <i class="fa-solid fa-circle-info me-1"></i>
                            Please bring your <strong>National ID card</strong> and <strong>Voter Card</strong> to the polling center.
                        </div>
                        <?php endif; ?>
                    </div>

                <?php endif; ?>

                </div>
            </div>

        </div>
    </div>
</section>

<!-- ══════════════════════════════════════
     STATS STRIP
══════════════════════════════════════ -->
<section class="stats-strip">
    <div class="container">
        <div class="row g-4">

            <div class="col-6 col-md-3">
                <div class="stat-item fade-up">
                    <div class="stat-icon"><i class="fa-solid fa-location-dot"></i></div>
                    <div class="stat-number"><?= number_format((int)$totalConstituencies) ?></div>
                    <div class="stat-lbl">Constituencies</div>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="stat-item fade-up delay-1">
                    <div class="stat-icon"><i class="fa-solid fa-school-flag"></i></div>
                    <div class="stat-number"><?= number_format((int)$totalStations) ?></div>
                    <div class="stat-lbl">Polling Stations</div>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="stat-item fade-up delay-2">
                    <div class="stat-icon"><i class="fa-solid fa-users"></i></div>
                    <div class="stat-number"><?= number_format((int)$totalVoters) ?></div>
                    <div class="stat-lbl">Registered Voters</div>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="stat-item fade-up delay-3">
                    <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
                    <div class="stat-number"><?= number_format((int)$publishedResults) ?></div>
                    <div class="stat-lbl">Results Approved</div>
                </div>
            </div>

        </div>
    </div>
</section>

<!-- ══════════════════════════════════════
     FEATURES
══════════════════════════════════════ -->
<section class="features-section" id="features">
    <div class="container">

        <div class="text-center mb-5 fade-up">
            <div class="section-label mx-auto" style="justify-content:center;">System Capabilities</div>
            <h2 class="section-heading">Built for Integrity & Transparency</h2>
            <p class="text-muted">Secure, scalable, and auditable election infrastructure for the national election.</p>
        </div>

        <div class="row g-4">
            <div class="col-lg-3 col-md-6 fade-up">
                <div class="feature-card">
                    <div class="feature-icon-wrap"><i class="fa-solid fa-shield-halved"></i></div>
                    <h5>Role-Based Access</h5>
                    <p>Tiered permissions for APO, PO, ARO, RO, and system administrators — each with scoped capabilities.</p>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 fade-up delay-1">
                <div class="feature-card">
                    <div class="feature-icon-wrap"><i class="fa-solid fa-circle-check"></i></div>
                    <h5>Result Validation</h5>
                    <p>Automated ballot consistency checks — total votes cannot exceed issued ballots at any booth.</p>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 fade-up delay-2">
                <div class="feature-card">
                    <div class="feature-icon-wrap"><i class="fa-solid fa-chart-pie"></i></div>
                    <h5>Result Aggregation</h5>
                    <p>Constituency-level result compilation and winner determination with multi-officer approval chain.</p>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 fade-up delay-3">
                <div class="feature-card">
                    <div class="feature-icon-wrap"><i class="fa-solid fa-wave-square"></i></div>
                    <h5>Live Monitoring</h5>
                    <p>Real-time election dashboards and full audit trail logging every officer action with timestamp and IP.</p>
                </div>
            </div>
        </div>

    </div>
</section>

<!-- ══════════════════════════════════════
     LIVE RESULTS TABLE
══════════════════════════════════════ -->
<section class="results-section" id="results">
    <div class="container">

        <div class="text-center mb-5 fade-up">
            <div class="section-label mx-auto" style="justify-content:center;">Election Results</div>
            <h2 class="section-heading">Constituency Results</h2>
            <p class="text-muted">Official results as approved by Returning Officers. Data is sourced live from the database.</p>
        </div>

        <div class="results-card fade-up delay-1">

            <div class="results-card-header">
                <div>
                    <h4>Live Results Dashboard</h4>
                    <small>Preliminary & approved constituency-level results</small>
                </div>
                <div class="live-dot">
                    <i class="fa-solid fa-circle"></i> LIVE DATA
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-ems mb-0" id="resultsTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Constituency</th>
                            <th>Winning Candidate</th>
                            <th>Party</th>
                            <th>Total Votes</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($liveResults): ?>
    <?php foreach ($liveResults as $i => $r): ?>
    <tr>
        <td class="text-muted" style="font-size:13px;"><?= $i + 1 ?></td>
        <td>
    <div style="font-weight:800;color:#1e3a5f;font-size:14px;"><?= htmlspecialchars($r['constituency']) ?></div>
    <div style="font-size:11px;color:#64748b;margin-top:2px;">📍 Constituency</div>
</td>
<td class="winner-name"><?= htmlspecialchars($r['winner']) ?></td>
        <td><span class="party-tag"><?= htmlspecialchars($r['party'] ?? 'Independent') ?></span></td>
        <td class="votes-count"><?= number_format((int)$r['total_votes_cast']) ?></td>
        <td>
            <span class="turnout-badge">
                <i class="fa-solid fa-chart-simple me-1"></i>
                <?= number_format((float)$r['turnout_rate'], 1) ?>%
            </span>
        </td>
    </tr>
    <?php endforeach; ?>
<?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-5">
                                <i class="fa-solid fa-database me-2"></i>
                                No results available yet — database connection pending.
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>

    </div>
</section>

<!-- ══════════════════════════════════════
     OFFICER LOGIN MODAL
══════════════════════════════════════ -->
<div class="modal fade modal-login" id="loginModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:420px;">
        <div class="modal-content">

            <div class="modal-header">
                <div class="d-flex align-items-center gap-3">
                    <div class="logo-ring" style="width:40px;height:40px;font-size:18px;"><i class="fa-solid fa-landmark"></i></div>
                    <h5 class="modal-title mb-0">Officer Login</h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <p class="text-muted mb-4" style="font-size:13px;">
                    <i class="fa-solid fa-lock me-1"></i>
                    This portal is restricted to authorised election officers only.
                    Unauthorised access is a criminal offence.
                </p>

                <?php
                /* ── Officer Login Handler ──────── */
                $loginError = '';
                $loginSuccess = false;

                if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['officer_login'])) {
                    $uname = trim($_POST['officer_username'] ?? '');
                    $pass  = trim($_POST['officer_password'] ?? '');

                    if ($uname && $pass) {
                        $officer = q1(
                            "SELECT * FROM election_officers WHERE username=? AND password_hash=? AND is_active=1",
                            [$uname, $pass], 'ss'
                        );
                        if ($officer) {
                            // In production: session_start() + $_SESSION['officer'] = $officer
                            $loginSuccess = true;
                        } else {
                            $loginError = 'Invalid credentials. Please try again.';
                        }
                    } else {
                        $loginError = 'Please fill in both fields.';
                    }
                }
                ?>

                <?php if ($loginSuccess): ?>
                <div class="p-4 rounded-3 text-center" style="background:#edfff6;border:1.5px solid #b7ebd0;">
                    <i class="fa-solid fa-circle-check fa-2x text-success mb-2"></i>
                    <h6 class="fw-bold mb-1">Login Successful</h6>
                    <p class="mb-0 text-muted" style="font-size:13px;">Welcome, <?= htmlspecialchars($officer['full_name']) ?> (<?= $officer['role'] ?>).</p>
                </div>
                <?php else: ?>

                <?php
// Also catch errors redirected back from login.php
$urlErrors = [
    'empty'    => 'Please enter both username and password.',
    'invalid'  => '⚠️ Incorrect username or password. Please try again.',
    'inactive' => 'Your account is inactive. Contact your administrator.',
    'db'       => 'System error. Please try again later.',
];
$redirectError = $_GET['error'] ?? '';
if ($redirectError && isset($urlErrors[$redirectError])) {
    $loginError = $urlErrors[$redirectError];
}
?>
<?php if ($loginError): ?>
<div class="alert-ems mb-4" style="background:#fef2f2;border:1.5px solid #fca5a5;border-radius:9px;padding:12px 16px;display:flex;align-items:center;gap:10px;font-size:13px;color:#991b1b;font-weight:600;">
    <i class="fa-solid fa-triangle-exclamation" style="font-size:16px;flex-shrink:0;"></i>
    <?= htmlspecialchars($loginError) ?>
</div>
<?php endif; ?>

                <form method="POST" action="login.php">
                    <div class="modal-input-group">
                        <label><i class="fa-solid fa-user me-1"></i> Username</label>
                        <input type="text" name="officer_username" placeholder="Enter officer username" autocomplete="username">
                    </div>
                    <div class="modal-input-group">
                        <label><i class="fa-solid fa-key me-1"></i> Password</label>
                        <input type="password" name="officer_password" placeholder="Enter password" autocomplete="current-password">
                    </div>
                    <button type="submit" name="officer_login" class="btn-login-submit">
                        <i class="fa-solid fa-right-to-bracket me-2"></i> Login to EMS Dashboard
                    </button>
                    <input type="hidden" name="officer_login" value="1">
                </form>

                <p class="text-center mt-3 text-muted" style="font-size:12px;">
                    Forgot credentials? Contact your Returning Officer or call <strong>105</strong>.
                </p>

                <?php endif; ?>

            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════
     FOOTER
══════════════════════════════════════ -->
<footer id="footer">
    <div class="container">
        <div class="row">

            <div class="col-lg-4 mb-4">
                <div class="logo-ring mb-3" style="width:44px;height:44px;font-size:18px;">
                    <i class="fa-solid fa-landmark"></i>
                </div>
                <div class="footer-brand">Bangladesh Election Commission</div>
                <p class="footer-tagline">
                    Transparent, secure, and accountable election administration
                    for the People's Republic of Bangladesh.
                </p>
            </div>

            <div class="col-lg-2 col-6 mb-4">
                <div class="footer-heading">Portal</div>
                <a class="footer-link" href="#search">Find Polling Station</a>
                <a class="footer-link" href="#results">Election Results</a>
                <a class="footer-link" href="#features">About EMS</a>
            </div>

            <div class="col-lg-3 col-6 mb-4">
                <div class="footer-heading">Legal</div>
                <a class="footer-link" href="#">Privacy Policy</a>
                <a class="footer-link" href="#">Accessibility Statement</a>
                <a class="footer-link" href="#">Terms of Use</a>
                <a class="footer-link" href="#">Data Protection</a>
            </div>

            <div class="col-lg-3 mb-4">
                <div class="footer-heading">Helpdesk</div>
                <p class="footer-tagline">
                    <i class="fa-solid fa-phone me-2" style="color:var(--gold2);"></i>
                    National Helpline: <strong style="color:#fff;">105</strong><br>
                    <i class="fa-solid fa-envelope me-2 mt-2" style="color:var(--gold2);"></i>
                    support@ems.gov.bd<br>
                    <i class="fa-solid fa-clock me-2 mt-2" style="color:var(--gold2);"></i>
                    Mon–Fri, 09:00 AM – 05:00 PM
                </p>
            </div>

        </div>
    </div>
    <div class="footer-divider">
        <div class="container">
            © 2026 Bangladesh Election Commission — Election Management System &nbsp;|&nbsp;
            Developed under CSE327 Term Project &nbsp;|&nbsp;
            All Rights Reserved
        </div>
    </div>
</footer>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
/* Auto-open login modal if login was submitted */
<?php if (isset($_POST['officer_login']) || isset($_GET['error'])): ?>
const loginModal = new bootstrap.Modal(document.getElementById('loginModal'));
loginModal.show();
<?php endif; ?>

/* Smooth scroll for anchor links */
document.querySelectorAll('a[href^="#"]').forEach(a => {
    a.addEventListener('click', e => {
        const target = document.querySelector(a.getAttribute('href'));
        if (target) {
            e.preventDefault();
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
});

/* Intersection Observer — trigger fade-up when in viewport */
const observer = new IntersectionObserver(entries => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            entry.target.style.animationPlayState = 'running';
            observer.unobserve(entry.target);
        }
    });
}, { threshold: 0.1 });

document.querySelectorAll('.fade-up').forEach(el => {
    el.style.animationPlayState = 'paused';
    observer.observe(el);
});
</script>
<!-- jQuery (required for DataTables) -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>

<script>
$(document).ready(function() {
    $('#resultsTable').DataTable({
        pageLength: 10,
        lengthMenu: [[5, 10, 25, 50, -1], [5, 10, 25, 50, "All"]],
        language: {
            search: "Search results:",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ constituencies",
            infoEmpty: "Showing 0 to 0 of 0 constituencies",
            infoFiltered: "(filtered from _MAX_ total entries)",
            emptyTable: "No approved results available yet",
            paginate: {
                first: "First",
                last: "Last",
                next: "",
                previous: ""
            }
        },
        order: [[1, 'asc']],  // Sort by constituency name by default
        columnDefs: [
            { orderable: false, targets: [0] },  // Disable sorting on # column
            { className: 'text-nowrap', targets: '_all' }
        ],
        drawCallback: function() {
            // Add animation to newly loaded rows
            $('#resultsTable tbody tr').addClass('fade-up');
        },
        dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>' +
             '<"row"<"col-sm-12"tr>>' +
             '<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>'
    });
});
</script>
</body>
</html>
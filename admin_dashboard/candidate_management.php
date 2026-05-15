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

    // ── DELETE CANDIDATE ─────────────────────────────────────
    if ($act === 'delete') {
        $id = (int)($_POST['candidate_id'] ?? 0);
        // Block delete if locked vote results exist
        $chk = $pdo->prepare("SELECT COUNT(*) FROM booth_results WHERE candidate_id=? AND is_locked=1");
        $chk->execute([$id]);
        if ((int)$chk->fetchColumn() > 0) {
            echo json_encode(['success'=>false,'message'=>'Cannot delete: candidate has locked vote results. Results must be cleared first.']);
            exit;
        }
        try {
            // Remove unlocked results first
            $pdo->prepare("DELETE FROM booth_results WHERE candidate_id=? AND is_locked=0")->execute([$id]);
            $pdo->prepare("DELETE FROM candidates WHERE candidate_id=?")->execute([$id]);
            $pdo->prepare("INSERT INTO audit_logs (officer_id,action_type,affected_entity,affected_entity_id,details,ip_address) VALUES (?,?,?,?,?,?)")
                ->execute([$logged_in_id,'DELETE_CANDIDATE','candidates',$id,"Deleted candidate ID $id",$_SERVER['REMOTE_ADDR']??'']);
            echo json_encode(['success'=>true,'message'=>'Candidate deleted successfully.']);
        } catch (PDOException $e) {
            echo json_encode(['success'=>false,'message'=>'Error: '.$e->getMessage()]);
        }
        exit;
    }

    // ── UPDATE CANDIDATE ─────────────────────────────────────
    if ($act === 'update') {
        $id       = (int)($_POST['candidate_id']??0);
        $name     = trim($_POST['full_name']??'');
        $nid      = trim($_POST['national_id']??'');
        $party_id = (int)($_POST['party_id']??0) ?: null;
        $con_id   = (int)($_POST['constituency_id']??0);
        $symbol   = trim($_POST['symbol']??'');

        if (!$name || !$nid || !$con_id) {
            echo json_encode(['success'=>false,'message'=>'Full name, national ID and constituency are required.']); exit;
        }
        // Check NID duplicate (exclude self)
         $dup = $pdo->prepare("SELECT COUNT(*) FROM candidates WHERE national_id=? AND candidate_id != ?");
        $dup->execute([$nid, $id]);
        if ((int)$dup->fetchColumn() > 0) {
            echo json_encode(['success'=>false,'message'=>"National ID '$nid' is already registered to another candidate."]); exit;
        }
        // === PARTY+CONSTITUENCY UNIQUENESS CONSTRAINT (exclude self) ===
        if ($party_id && $con_id) {
            $chk = $pdo->prepare("SELECT candidate_id FROM candidates WHERE party_id=? AND constituency_id=? AND candidate_id != ?");
            $chk->execute([$party_id, $con_id, $id]);
            if ($chk->fetch()) {
                echo json_encode(['success'=>false,'message'=>'This party already has a candidate in the selected constituency. Each party can only field one candidate per constituency.']); exit;
            }
        }
        try {
            $pdo->prepare("UPDATE candidates SET full_name=?,national_id=?,party_id=?,constituency_id=?,symbol=? WHERE candidate_id=?")
                ->execute([$name,$nid,$party_id,$con_id,$symbol,$id]);
            $pdo->prepare("INSERT INTO audit_logs (officer_id,action_type,affected_entity,affected_entity_id,details,ip_address) VALUES (?,?,?,?,?,?)")
                ->execute([$logged_in_id,'UPDATE_CANDIDATE','candidates',$id,"Updated candidate '$name' (ID $id)",$_SERVER['REMOTE_ADDR']??'']);
            echo json_encode(['success'=>true,'message'=>"Candidate '$name' updated successfully."]);
        } catch (PDOException $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action.']); exit;
}

// ============================================================
//  CREATE NEW CANDIDATE (form POST)
// ============================================================
$form_success = $form_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_candidate'])) {
    $name     = trim($_POST['full_name']??'');
    $nid      = trim($_POST['national_id']??'');
    $party_id = (int)($_POST['party_id']??0) ?: null;
    $con_id   = (int)($_POST['constituency_id']??0);
    $symbol   = trim($_POST['symbol']??'');

    if (!$name || !$nid || !$con_id) {
        $form_error = 'Full name, national ID and constituency are required.';
    } else {
        // Check NID duplicate
        // Check NID duplicate
        $dup = $pdo->prepare("SELECT COUNT(*) FROM candidates WHERE national_id=?");
        $dup->execute([$nid]);
        if ((int)$dup->fetchColumn() > 0) {
            $form_error = "National ID '$nid' is already registered to another candidate.";
        } else {
            // === PARTY+CONSTITUENCY UNIQUENESS CONSTRAINT ===
            if ($party_id && $con_id) {
                $chk = $pdo->prepare("SELECT candidate_id FROM candidates WHERE party_id=? AND constituency_id=?");
                $chk->execute([$party_id, $con_id]);
                if ($chk->fetch()) {
                    $form_error = 'This party already has a candidate registered in the selected constituency. Each party can only field one candidate per constituency.';
                }
            }

            if (!$form_error) {
                try {
                    $pdo->prepare("INSERT INTO candidates (full_name,national_id,party_id,constituency_id,symbol) VALUES (?,?,?,?,?)")
                        ->execute([$name,$nid,$party_id,$con_id,$symbol]);
                    $new_id = $pdo->lastInsertId();
                    $pdo->prepare("INSERT INTO audit_logs (officer_id,action_type,affected_entity,affected_entity_id,details,ip_address) VALUES (?,?,?,?,?,?)")
                        ->execute([$logged_in_id,'CREATE_CANDIDATE','candidates',$new_id,"Created candidate '$name'",$_SERVER['REMOTE_ADDR']??'']);
                    $form_success = "Candidate <strong>".htmlspecialchars($name)."</strong> registered successfully.";
                } catch (PDOException $e) {
                    $form_error = 'Error: '.$e->getMessage();
                }
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

// Dropdowns
$parties = $pdo->query("SELECT party_id,name,abbreviation FROM political_parties ORDER BY name")->fetchAll();
$constituencies = $pdo->query("SELECT constituency_id,name,code FROM constituencies ORDER BY name")->fetchAll();

// Build a set of already-taken (party_id, constituency_id) pairs
// Format: "party_id:constituency_id" => candidate_id
$taken_combos = [];
$comboStmt = $pdo->query("SELECT party_id, constituency_id, candidate_id FROM candidates WHERE party_id IS NOT NULL");
foreach ($comboStmt->fetchAll() as $row) {
    $taken_combos[$row['party_id'].':'.$row['constituency_id']] = (int)$row['candidate_id'];
}
$js_taken_combos = json_encode($taken_combos);

// Search / filter
$search       = trim($_GET['q']??'');
$filter_party = (int)($_GET['party']??0);
$filter_con   = (int)($_GET['con']??0);

$sqlBase = "
    SELECT c.*,
           pp.name AS party_name, pp.abbreviation AS party_abbr, 
           con.name AS constituency_name, con.code AS constituency_code,
           IFNULL(SUM(br.votes_received),0) AS total_votes,
           IFNULL(MAX(br.is_locked),0) AS has_locked
    FROM candidates c
    LEFT JOIN political_parties pp ON pp.party_id=c.party_id
    LEFT JOIN constituencies con ON con.constituency_id=c.constituency_id
    LEFT JOIN booth_results br ON br.candidate_id=c.candidate_id
";
$where  = [];
$params = [];
if ($search !== '') {
    $where[]  = "(c.full_name LIKE ? OR c.national_id LIKE ? OR c.symbol LIKE ?)";
    $params[] = '%'.$search.'%';
    $params[] = '%'.$search.'%';
    $params[] = '%'.$search.'%';
}
if ($filter_party > 0) { $where[] = "c.party_id=?";        $params[] = $filter_party; }
if ($filter_con   > 0) { $where[] = "c.constituency_id=?"; $params[] = $filter_con;   }

$sqlFull = $sqlBase.($where ? " WHERE ".implode(" AND ",$where) : "")
         ." GROUP BY c.candidate_id ORDER BY con.name, pp.name, c.full_name";
$cStmt   = $pdo->prepare($sqlFull);
$cStmt->execute($params);
$candidates = $cStmt->fetchAll();

// Stats
$total_candidates = (int)$pdo->query("SELECT COUNT(*) FROM candidates")->fetchColumn();
$total_parties    = (int)$pdo->query("SELECT COUNT(*) FROM political_parties")->fetchColumn();
$total_con_rep    = (int)$pdo->query("SELECT COUNT(DISTINCT constituency_id) FROM candidates")->fetchColumn();
$total_votes_locked = (int)$pdo->query("SELECT IFNULL(SUM(votes_received),0) FROM booth_results WHERE is_locked=1")->fetchColumn();
$independents     = (int)$pdo->query("SELECT COUNT(*) FROM candidates WHERE party_id IS NULL")->fetchColumn();

// Party colour helper

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Candidate Management — EMS Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500;600&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<style>
:root{
    --navy:#0a1628;--navy2:#0f2044;--navy3:#1a3060;
    --primary:#1a56db;--primary2:#1e40af;
    --accent:#0ea5e9;--accent2:#38bdf8;
    --gold:#f59e0b;--gold2:#fbbf24;
    --success:#10b981;--success2:#34d399;
    --danger:#ef4444;--warning:#f59e0b;
    --purple:#8b5cf6;--teal:#14b8a6;
    --surface:#ffffff;--bg:#f0f4f8;--bg2:#e8eef5;
    --border:#d1dae6;--border2:#c4d0e0;
    --text:#0f172a;--text2:#334155;--muted:#64748b;--muted2:#94a3b8;
    --radius:14px;--radius-sm:8px;
    --shadow:0 2px 16px rgba(10,22,40,.10);--shadow-lg:0 8px 40px rgba(10,22,40,.18);
    --font-head:'Syne',sans-serif;--font-body:'DM Sans',sans-serif;--font-mono:'IBM Plex Mono',monospace;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{font-size:15px;}
body{font-family:var(--font-body);background:var(--bg);color:var(--text);min-height:100vh;display:flex;flex-direction:column;}
a{text-decoration:none;color:inherit;}
button{cursor:pointer;font-family:var(--font-body);}

/* ── TOPBAR ── */
.topbar{background:linear-gradient(135deg,var(--navy) 0%,var(--navy2) 60%,var(--navy3) 100%);padding:0 32px;height:66px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:200;box-shadow:0 2px 24px rgba(10,22,40,.35);border-bottom:1px solid rgba(255,255,255,.08);}
.topbar-brand{display:flex;align-items:center;gap:14px;}
.brand-emblem{width:40px;height:40px;background:linear-gradient(135deg,var(--primary),var(--accent));border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px;box-shadow:0 2px 12px rgba(14,165,233,.3);flex-shrink:0;}
.brand-text{font-family:var(--font-head);font-size:15px;font-weight:700;color:#fff;letter-spacing:.2px;line-height:1.2;}
.brand-sub{font-size:11px;color:rgba(255,255,255,.5);letter-spacing:.5px;text-transform:uppercase;}
.topbar-center{display:flex;align-items:center;gap:8px;}
.live-chip{display:flex;align-items:center;gap:6px;background:rgba(16,185,129,.15);border:1px solid rgba(16,185,129,.3);border-radius:20px;padding:5px 14px;font-size:11.5px;font-weight:600;color:var(--success2);letter-spacing:.4px;text-transform:uppercase;}
.live-dot{width:7px;height:7px;border-radius:50%;background:var(--success2);animation:pulse-dot 1.6s infinite;}
@keyframes pulse-dot{0%,100%{opacity:1;transform:scale(1);}50%{opacity:.5;transform:scale(1.4);}}
.election-tag{background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.28);border-radius:20px;padding:5px 14px;font-size:11.5px;font-weight:600;color:var(--gold2);letter-spacing:.4px;text-transform:uppercase;}
.topbar-right{display:flex;align-items:center;gap:14px;}
.admin-pill{display:flex;align-items:center;gap:10px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.14);border-radius:30px;padding:5px 14px 5px 6px;}
.admin-avatar{width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--accent));display:flex;align-items:center;justify-content:center;font-family:var(--font-head);font-size:13px;font-weight:700;color:#fff;}
.admin-info .name{font-size:12.5px;font-weight:600;color:#fff;line-height:1.2;}
.admin-info .role-tag{font-size:10px;color:var(--accent2);letter-spacing:.5px;text-transform:uppercase;font-weight:500;}
.btn-logout{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.25);color:#fca5a5;font-size:12px;font-weight:600;padding:7px 16px;border-radius:8px;transition:all .2s;letter-spacing:.3px;}
.btn-logout:hover{background:rgba(239,68,68,.22);color:#fff;}

/* ── SUB NAV ── */
.sub-nav{background:var(--surface);border-bottom:2px solid var(--border);padding:0 32px;display:flex;align-items:center;gap:2px;overflow-x:auto;position:sticky;top:66px;z-index:100;box-shadow:0 1px 8px rgba(10,22,40,.06);}
.sub-nav-link{display:flex;align-items:center;gap:6px;padding:14px 16px;font-size:12.5px;font-weight:600;color:var(--muted);border-bottom:2px solid transparent;margin-bottom:-2px;white-space:nowrap;transition:all .18s;}
.sub-nav-link:hover{color:var(--primary);}
.sub-nav-link.active{color:var(--primary);border-bottom-color:var(--primary);background:rgba(26,86,219,.04);}

/* ── PAGE WRAP ── */
.page-wrap{max-width:1380px;margin:0 auto;padding:28px 28px 56px;width:100%;flex:1;}

/* ── PAGE HEADER ── */
.page-header{display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:14px;}
.page-header-left{display:flex;align-items:center;gap:16px;}
.page-header-icon{width:52px;height:52px;background:linear-gradient(135deg,#f59e0b,#f97316);border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:24px;box-shadow:0 4px 16px rgba(245,158,11,.28);flex-shrink:0;}
.page-title{font-family:var(--font-head);font-size:26px;font-weight:800;color:var(--navy);line-height:1.1;letter-spacing:-.3px;}
.page-subtitle{font-size:13px;color:var(--muted);margin-top:4px;}
.breadcrumb{font-size:11.5px;color:var(--muted2);margin-bottom:4px;}
.breadcrumb a{color:var(--primary);font-weight:600;}
.breadcrumb a:hover{text-decoration:underline;}
.header-actions{display:flex;gap:10px;flex-wrap:wrap;}

/* ── BUTTONS ── */
.btn{display:inline-flex;align-items:center;gap:7px;font-size:13px;font-weight:600;padding:9px 18px;border-radius:var(--radius-sm);border:none;transition:all .2s;cursor:pointer;white-space:nowrap;font-family:var(--font-body);letter-spacing:.2px;}
.btn-primary{background:linear-gradient(135deg,var(--primary),var(--primary2));color:#fff;box-shadow:0 2px 10px rgba(26,86,219,.25);}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 4px 18px rgba(26,86,219,.35);}
.btn-gold{background:linear-gradient(135deg,#f59e0b,#f97316);color:#fff;box-shadow:0 2px 10px rgba(245,158,11,.25);}
.btn-gold:hover{transform:translateY(-1px);box-shadow:0 4px 18px rgba(245,158,11,.35);}
.btn-secondary{background:var(--surface);color:var(--text2);border:1.5px solid var(--border);}
.btn-secondary:hover{border-color:var(--primary);color:var(--primary);background:#eff6ff;}
.btn-danger{background:rgba(239,68,68,.08);color:var(--danger);border:1.5px solid rgba(239,68,68,.25);}
.btn-danger:hover{background:var(--danger);color:#fff;}
.btn-success{background:rgba(16,185,129,.1);color:var(--success);border:1.5px solid rgba(16,185,129,.3);}
.btn-success:hover{background:var(--success);color:#fff;}
.btn-sm{font-size:12px;padding:6px 13px;}
.btn-xs{font-size:11px;padding:4px 10px;border-radius:6px;}

/* ── STAT STRIP ── */
.stat-strip{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:22px;}
@media(max-width:960px){.stat-strip{grid-template-columns:repeat(2,1fr);}}
.stat-tile{background:var(--surface);border-radius:var(--radius);border:1.5px solid var(--border);padding:20px 22px;position:relative;overflow:hidden;transition:all .22s;animation:fadeUp .4s ease both;}
.stat-tile:hover{transform:translateY(-3px);box-shadow:var(--shadow-lg);border-color:var(--border2);}
.stat-tile:nth-child(1){animation-delay:.05s}
.stat-tile:nth-child(2){animation-delay:.10s}
.stat-tile:nth-child(3){animation-delay:.15s}
.stat-tile:nth-child(4){animation-delay:.20s}
.st-icon{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px;margin-bottom:12px;}
.st-icon.blue{background:#dbeafe;}
.st-icon.green{background:#dcfce7;}
.st-icon.gold{background:#fef3c7;}
.st-icon.purple{background:#ede9fe;}
.st-icon.teal{background:#ccfbf1;}
.st-icon.orange{background:#ffedd5;}
.st-value{font-family:var(--font-mono);font-size:26px;font-weight:600;letter-spacing:-.5px;line-height:1;margin-bottom:5px;}
.st-value.blue{color:var(--primary);}
.st-value.green{color:var(--success);}
.st-value.gold{color:var(--gold);}
.st-value.purple{color:var(--purple);}
.st-value.teal{color:var(--teal);}
.st-value.orange{color:#f97316;}
.st-label{font-size:11.5px;font-weight:600;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);}
.st-delta{position:absolute;top:16px;right:16px;font-size:11px;font-weight:600;padding:3px 9px;border-radius:20px;}
.st-delta.up{background:#dcfce7;color:var(--success);}
.st-delta.info{background:#dbeafe;color:var(--primary);}
.st-delta.gold{background:#fef3c7;color:var(--gold);}
.st-delta.purple{background:#ede9fe;color:var(--purple);}
.st-delta.orange{background:#ffedd5;color:#f97316;}

/* ── CARDS ── */
.card{background:var(--surface);border-radius:var(--radius);border:1.5px solid var(--border);overflow:hidden;box-shadow:var(--shadow);margin-bottom:22px;}
.card-header{padding:18px 24px 14px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border);background:#fafbfc;flex-wrap:wrap;gap:10px;}
.card-title{font-family:var(--font-head);font-size:14.5px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px;}
.card-body{padding:22px 24px;}
.count-pill{display:inline-flex;align-items:center;justify-content:center;background:var(--bg2);border:1px solid var(--border);border-radius:20px;font-size:12px;font-weight:700;color:var(--text2);padding:2px 10px;font-family:var(--font-mono);}

/* ── FORM ── */
.form-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.form-grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;}
.form-grid-4{display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:16px;}
@media(max-width:768px){.form-grid-2,.form-grid-3,.form-grid-4{grid-template-columns:1fr;}}
.form-group{display:flex;flex-direction:column;gap:6px;}
.form-label{font-size:11.5px;font-weight:700;color:var(--text2);letter-spacing:.4px;text-transform:uppercase;}
.form-label .req{color:var(--danger);margin-left:2px;}
.form-control{padding:10px 14px;border:1.5px solid var(--border);border-radius:var(--radius-sm);font-size:13.5px;font-family:var(--font-body);color:var(--text);background:var(--surface);transition:border-color .18s,box-shadow .18s;outline:none;width:100%;}
.form-control:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(26,86,219,.12);}
.form-control::placeholder{color:var(--muted2);}
.form-helper{font-size:11.5px;color:var(--muted);margin-top:16px;padding:10px 14px;background:#fffbeb;border-radius:var(--radius-sm);border-left:3px solid var(--gold);}

/* ── FILTER BAR ── */
.filter-bar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.search-input-wrap{position:relative;flex:1;min-width:220px;}
.search-input-wrap .si{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--muted2);font-size:14px;pointer-events:none;}
.search-input-wrap input{width:100%;padding:10px 14px 10px 38px;border:1.5px solid var(--border);border-radius:var(--radius-sm);font-size:13.5px;font-family:var(--font-body);color:var(--text);background:var(--surface);outline:none;transition:border-color .18s,box-shadow .18s;}
.search-input-wrap input:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(26,86,219,.10);}
.filter-select{padding:10px 14px;border:1.5px solid var(--border);border-radius:var(--radius-sm);font-size:13.5px;font-family:var(--font-body);color:var(--text);background:var(--surface);outline:none;min-width:180px;transition:border-color .18s;}
.filter-select:focus{border-color:var(--primary);}

/* ── TABLE ── */
.table-wrap{overflow-x:auto;}
.mgmt-table{width:100%;border-collapse:collapse;font-size:13.5px;}
.mgmt-table thead tr{background:#f1f5f9;}
.mgmt-table th{padding:11px 16px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);border-bottom:2px solid var(--border);white-space:nowrap;}
.mgmt-table td{padding:13px 16px;border-bottom:1px solid var(--border);vertical-align:middle;}
.mgmt-table tbody tr{transition:background .15s;}
.mgmt-table tbody tr:hover{background:#f8fafc;}
.mgmt-table tbody tr:last-child td{border-bottom:none;}

/* ── EDIT ROW ── */
.editing-row{background:#eff6ff !important;outline:2px solid var(--primary);outline-offset:-1px;}
.editing-row td{background:#eff6ff;}
.edit-input{padding:7px 10px;border:1.5px solid var(--primary);border-radius:6px;font-size:13px;font-family:var(--font-body);color:var(--text);width:100%;outline:none;min-width:80px;box-shadow:0 0 0 2px rgba(26,86,219,.12);}
.edit-select{padding:7px 10px;border:1.5px solid var(--primary);border-radius:6px;font-size:13px;font-family:var(--font-body);color:var(--text);outline:none;min-width:130px;box-shadow:0 0 0 2px rgba(26,86,219,.12);background:#fff;}

/* ── CELL STYLES ── */
.cell-main{font-weight:700;color:var(--text);font-size:13.5px;}
.cell-sub{font-size:11px;color:var(--muted);margin-top:2px;}
.id-badge{font-family:var(--font-mono);font-size:12px;font-weight:600;background:var(--bg2);border:1px solid var(--border);border-radius:6px;padding:3px 8px;color:var(--text2);}
.nid-cell{font-family:var(--font-mono);font-size:12.5px;font-weight:600;color:var(--text2);}
.con-tag{font-size:11.5px;font-weight:700;background:#dbeafe;color:var(--primary);border-radius:6px;padding:3px 9px;letter-spacing:.3px;}
.party-chip{display:inline-flex;align-items:center;gap:5px;border-radius:6px;padding:3px 10px;font-size:11.5px;font-weight:700;color:#fff;letter-spacing:.3px;background: linear-gradient(135deg, #1a56db, #0ea5e9);}
.party-chip.independent{background: #64748b;}
.party-chip.bnp{background: linear-gradient(135deg, #0f5c3d, #1a7a4e);}
.party-chip.jp{background: linear-gradient(135deg, #b91c1c, #dc2626);}
.party-chip.iab{background: linear-gradient(135deg, #0d9488, #14b8a6);}
.party-chip.wpb{background: linear-gradient(135deg, #7c2d12, #c2410c);}
/* Party chip variations for different parties (add to CSS) */
.party-chip[data-party="AL"]{background: linear-gradient(135deg, #006633, #008844);}
.party-chip[data-party="BNP"]{background: linear-gradient(135deg, #0f5c3d, #1a7a4e);}
.party-chip[data-party="JP"]{background: linear-gradient(135deg, #b91c1c, #dc2626);}
.party-chip[data-party="IAB"]{background: linear-gradient(135deg, #0d9488, #14b8a6);}
.party-chip[data-party="WPB"]{background: linear-gradient(135deg, #7c2d12, #c2410c);}
.symbol-badge{display:inline-block;background:#f0fdf9;border:1px solid #99f6e4;color:#0f766e;border-radius:6px;padding:3px 10px;font-size:12px;font-weight:600;}
.votes-chip{display:inline-flex;align-items:center;gap:4px;background:#dbeafe;color:var(--primary);border:1px solid #93c5fd;border-radius:6px;padding:3px 9px;font-size:11.5px;font-weight:700;font-family:var(--font-mono);}
.action-row{display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
.num-cell{font-family:var(--font-mono);font-size:14px;font-weight:600;color:var(--text);}

/* Status pills */
.status-pill{display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border-radius:20px;font-size:11.5px;font-weight:700;letter-spacing:.3px;white-space:nowrap;}
.sp-locked{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}
.sp-active{background:#dcfce7;color:#166534;border:1px solid #86efac;}
.sp-empty{background:#f1f5f9;color:#64748b;border:1px solid #cbd5e1;}

/* ── PAGINATION ── */
.pagination-bar{display:flex;align-items:center;justify-content:space-between;padding:14px 24px;border-top:1px solid var(--border);font-size:12.5px;color:var(--muted);flex-wrap:wrap;gap:10px;}
.pagination{display:flex;gap:4px;}
.pag-btn{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:600;cursor:pointer;background:var(--surface);border:1.5px solid var(--border);color:var(--text2);transition:all .18s;}
.pag-btn:hover{border-color:var(--primary);color:var(--primary);background:#eff6ff;}
.pag-btn.active{background:var(--primary);color:#fff;border-color:var(--primary);}
.pag-btn.disabled{opacity:.4;pointer-events:none;}

/* ── DELETE MODAL ── */
.modal-overlay{position:fixed;inset:0;background:rgba(10,22,40,.55);backdrop-filter:blur(3px);z-index:500;display:none;align-items:center;justify-content:center;}
.modal-overlay.open{display:flex;}
.modal-box{background:var(--surface);border-radius:18px;padding:32px 28px;max-width:420px;width:90%;box-shadow:var(--shadow-lg);animation:popIn .22s ease;}
@keyframes popIn{from{opacity:0;transform:scale(.93);}to{opacity:1;transform:scale(1);}}
.modal-icon{font-size:44px;text-align:center;margin-bottom:14px;}
.modal-title{font-family:var(--font-head);font-size:18px;font-weight:800;color:var(--text);text-align:center;margin-bottom:10px;}
.modal-body-txt{font-size:13.5px;color:var(--muted);text-align:center;line-height:1.6;margin-bottom:24px;}
.modal-actions{display:flex;gap:10px;justify-content:center;}

/* ── TOAST ── */
#toast{position:fixed;bottom:28px;right:28px;z-index:999;display:flex;flex-direction:column;gap:8px;}
.toast-msg{background:#1e293b;color:#fff;border-radius:10px;padding:12px 20px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:10px;box-shadow:0 4px 20px rgba(0,0,0,.2);animation:slideIn .3s ease;}
.toast-msg.success{background:#166634;}
.toast-msg.error{background:#991b1b;}
.toast-msg.warning{background:#92400e;}
@keyframes slideIn{from{transform:translateY(20px);opacity:0;}to{transform:translateY(0);opacity:1;}}

/* ── ALERTS ── */
.alert{padding:12px 16px;border-radius:var(--radius-sm);font-size:13.5px;font-weight:500;margin-bottom:18px;display:flex;align-items:center;gap:10px;}
.alert-success{background:#dcfce7;color:#166534;border:1px solid #86efac;}
.alert-error{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}

/* ── FOOTER ── */
.footer{background:var(--navy);padding:18px 32px;display:flex;align-items:center;justify-content:space-between;font-size:12px;color:#64748b;margin-top:auto;flex-wrap:wrap;gap:8px;}
.footer-links{display:flex;gap:18px;}
.footer-links a{color:#64748b;text-decoration:none;transition:color .2s;}
.footer-links a:hover{color:#60a5fa;}

@keyframes fadeUp{from{opacity:0;transform:translateY(18px);}to{opacity:1;transform:translateY(0);}}
</style>
</head>
<body>

<!-- ═══ TOPBAR ═══ -->
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

<!-- ═══ SUB NAV ═══ -->
<nav class="sub-nav">
    <a class="sub-nav-link" href="admin_dashboard.php">🏠 Dashboard</a>
    <a class="sub-nav-link" href="constituency_management.php">🗺️ Constituencies</a>
    <a class="sub-nav-link" href="polling_station_management.php">🏫 Polling Stations</a>
    <a class="sub-nav-link" href="booth_management.php">🚪 Booth Management</a>
    <a class="sub-nav-link active" href="candidate_management.php">🎖️ Candidates</a>
    <a class="sub-nav-link" href="party_management.php">🏳️ Political Parties</a>
    <a class="sub-nav-link" href="officer_management.php">👮 Officers</a>
    <a class="sub-nav-link " href="voter_management.php">🧑‍🤝‍🧑 Voters</a>
</nav>

<div class="page-wrap">

    <!-- PAGE HEADER -->
    <div class="page-header">
        <div class="page-header-left">
            <div class="page-header-icon">🎖️</div>
            <div>
                <div class="breadcrumb"><a href="admin_dashboard.php">Dashboard</a> / Candidate Management</div>
                <div class="page-title">Candidate Management</div>
                <div class="page-subtitle">Register election candidates, assign party affiliation, constituency, and election symbol.</div>
            </div>
        </div>
        <div class="header-actions">
            <button class="btn btn-gold" onclick="toggleForm()">➕ Add Candidate</button>
            <button class="btn btn-secondary" onclick="location.reload()">🔄 Refresh</button>
            <a href="admin_dashboard.php" class="btn btn-secondary">← Dashboard</a>
        </div>
    </div>

    <!-- STAT STRIP -->
    <div class="stat-strip">
        <div class="stat-tile">
            <div class="st-delta info">Registered</div>
            <div class="st-icon gold">🎖️</div>
            <div class="st-value gold"><?= number_format($total_candidates) ?></div>
            <div class="st-label">Total Candidates</div>
        </div>
        <div class="stat-tile">
            <div class="st-delta purple">Active</div>
            <div class="st-icon purple">🏳️</div>
            <div class="st-value purple"><?= number_format($total_parties) ?></div>
            <div class="st-label">Political Parties</div>
        </div>
        <div class="stat-tile">
            <div class="st-delta orange">Contesting</div>
            <div class="st-icon orange">🗺️</div>
            <div class="st-value orange"><?= number_format($total_con_rep) ?></div>
            <div class="st-label">Constituencies</div>
        </div>
        <div class="stat-tile">
            <div class="st-delta up">Independent</div>
            <div class="st-icon teal">🧑</div>
            <div class="st-value teal"><?= number_format($independents) ?></div>
            <div class="st-label">Independent Candidates</div>
        </div>
    </div>

    <!-- ALERTS -->
    <?php if ($form_success): ?>
    <div class="alert alert-success">✅ <?= $form_success ?></div>
    <?php endif; ?>
    <?php if ($form_error): ?>
    <div class="alert alert-error">❌ <?= htmlspecialchars($form_error) ?></div>
    <?php endif; ?>

    <!-- ADD CANDIDATE FORM -->
    <div class="card" id="addFormCard" style="display:none; border-color:var(--gold);">
        <div class="card-header">
            <span class="card-title">🎖️ Register New Candidate</span>
            <button class="btn btn-sm btn-secondary" onclick="toggleForm()">✕ Close</button>
        </div>
        <div class="card-body">
            <form method="POST" action="" onsubmit="return validateAddForm()">
                <div class="form-grid-4" style="margin-bottom:16px;">
                    <div class="form-group" style="grid-column:span 2;">
                        <label class="form-label">Full Name <span class="req">*</span></label>
                        <input type="text" name="full_name" class="form-control" placeholder="e.g. Ahsan Karim" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">National ID <span class="req">*</span></label>
                        <input type="text" name="national_id" class="form-control" placeholder="e.g. CNID001" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Election Symbol</label>
                        <input type="text" name="symbol" class="form-control" placeholder="e.g. Boat, Eagle">
                    </div>
                </div>
                <div class="form-grid-2" style="margin-bottom:18px;">
                    <div class="form-group">
                        <label class="form-label">Constituency <span class="req">*</span></label>
                        <select name="constituency_id" class="form-control" required>
                            <option value="">— Select Constituency —</option>
                            <?php foreach ($constituencies as $con): ?>
                            <option value="<?= $con['constituency_id'] ?>">
                                <?= htmlspecialchars($con['name']) ?> (<?= htmlspecialchars($con['code']) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Political Party</label>
                        <select name="party_id" class="form-control">
                            <option value="">— Independent —</option>
                            <?php foreach ($parties as $p): ?>
                            <option value="<?= $p['party_id'] ?>">
                                <?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['abbreviation']) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <button type="submit" name="create_candidate" class="btn btn-gold">💾 Register Candidate</button>
                    <button type="reset" class="btn btn-secondary">↺ Reset Form</button>
                </div>
                <div class="form-helper">💡 National ID must be unique across all candidates. Leave Party field blank to register as Independent. Each candidate is assigned to exactly one constituency.</div>
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
                               placeholder="Search by candidate name, national ID, or symbol…">
                    </div>
                    <select name="party" class="filter-select">
                        <option value="">All Parties</option>
                        <?php foreach ($parties as $p): ?>
                        <option value="<?= $p['party_id'] ?>" <?= $filter_party==$p['party_id']?'selected':'' ?>>
                            <?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['abbreviation']) ?>)
                        </option>
                        <?php endforeach; ?>
                        <option value="-1" <?= $filter_party==-1?'selected':'' ?>>— Independent —</option>
                    </select>
                    <select name="con" class="filter-select">
                        <option value="">All Constituencies</option>
                        <?php foreach ($constituencies as $con): ?>
                        <option value="<?= $con['constituency_id'] ?>" <?= $filter_con==$con['constituency_id']?'selected':'' ?>>
                            <?= htmlspecialchars($con['name']) ?> (<?= htmlspecialchars($con['code']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-primary">Search</button>
                    <?php if ($search || $filter_party || $filter_con): ?>
                    <a href="candidate_management.php" class="btn btn-secondary">✕ Clear</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- MAIN TABLE CARD -->
    <div class="card">
        <div class="card-header">
            <span class="card-title">🎖️ Candidate Records
                <?php if ($search || $filter_party || $filter_con): ?>
                <span style="font-size:12px;font-weight:400;color:var(--muted);">Filtered results</span>
                <?php endif; ?>
            </span>
            <span class="count-pill"><?= count($candidates) ?> found</span>
        </div>

        <div class="table-wrap">
            <table class="mgmt-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Candidate</th>
                        <th>National ID</th>
                        <th>Party</th>
                        <th>Constituency</th>
                        <th>Symbol</th>
                        <th>Total Votes</th>
                        <th>Result Status</th>
                        <th style="min-width:180px;">Actions</th>
                    </tr>
                </thead>
                <tbody>

                <?php if (empty($candidates)): ?>
                <tr><td colspan="9" style="text-align:center;padding:40px;color:var(--muted);">
                    No candidates found<?= ($search||$filter_party||$filter_con)?' for this filter':'' ?>.
                </td></tr>
                <?php endif; ?>

                <?php foreach ($candidates as $c):
                    $cid      = (int)$c['candidate_id'];
                    $has_lock = (int)$c['has_locked'];
                    $total_v  = (int)$c['total_votes'];
                    $pCol     = isset($c['party_id']) ? ($pColorMap[(int)$c['party_id']] ?? '#64748b') : '#64748b';
                    if     ($has_lock) { $spClass='sp-locked'; $spLabel='🔒 Locked'; }
                    elseif ($total_v)  { $spClass='sp-active'; $spLabel='✏️ Entered'; }
                    else               { $spClass='sp-empty';  $spLabel='⏳ No Entry'; }
                ?>

                <!-- DISPLAY ROW -->
                <tr class="data-row" id="row-<?= $cid ?>">
                    <td><span class="id-badge">#<?= $cid ?></span></td>
                    <td>
                        <div class="cell-main"><?= htmlspecialchars($c['full_name']) ?></div>
                        <div class="cell-sub">Candidate ID: <?= $cid ?></div>
                    </td>
                    <td>
                        <span class="nid-cell"><?= htmlspecialchars($c['national_id']) ?></span>
                    </td>
                    <td>
                        <?php if ($c['party_name']): ?>
                        <span class="party-chip"  data-party="<?= htmlspecialchars($c['party_abbr']) ?>" style="background: linear-gradient(135deg, #1a56db, #0ea5e9);">
                            <?= htmlspecialchars($c['party_abbr']) ?>
                        </span>
                        <div class="cell-sub" style="margin-top:4px;"><?= htmlspecialchars($c['party_name']) ?></div>
                        <?php else: ?>
                        <span class="party-chip independent">IND</span>
                        <div class="cell-sub" style="margin-top:4px;">Independent</div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($c['constituency_name']): ?>
                        <span class="con-tag"><?= htmlspecialchars($c['constituency_code']) ?></span>
                        <div class="cell-sub" style="margin-top:4px;"><?= htmlspecialchars($c['constituency_name']) ?></div>
                        <?php else: ?>
                        <span style="color:var(--muted2);font-size:12px;">— Not Set —</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($c['symbol']): ?>
                        <span class="symbol-badge">🏷️ <?= htmlspecialchars($c['symbol']) ?></span>
                        <?php else: ?>
                        <span style="color:var(--muted2);font-size:12px;">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($total_v > 0): ?>
                        <span class="votes-chip">🗳️ <?= number_format($total_v) ?></span>
                        <?php else: ?>
                        <span style="color:var(--muted2);font-size:12px;font-family:var(--font-mono);">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="status-pill <?= $spClass ?>"><?= $spLabel ?></span>
                    </td>
                    <td>
                        <div class="action-row">
                            <button class="btn btn-sm btn-secondary" onclick="startEdit(<?= $cid ?>)">✏️ Edit</button>
                            <button class="btn btn-sm btn-danger" onclick="confirmDelete(<?= $cid ?>, '<?= htmlspecialchars(addslashes($c['full_name'])) ?>')">🗑️</button>
                        </div>
                    </td>
                </tr>

                <!-- EDIT ROW -->
                <tr class="editing-row" id="editrow-<?= $cid ?>" style="display:none;">
                    <td><span class="id-badge">#<?= $cid ?></span></td>
                    <td>
                        <input class="edit-input" id="en-<?= $cid ?>" value="<?= htmlspecialchars($c['full_name']) ?>" placeholder="Full name" style="min-width:140px;">
                    </td>
                    <td>
                        <input class="edit-input" id="enid-<?= $cid ?>" value="<?= htmlspecialchars($c['national_id']) ?>" placeholder="National ID" style="min-width:110px;">
                    </td>
                    <td>
                        <select class="edit-select" id="ep-<?= $cid ?>">
                            <option value="">— Independent —</option>
                            <?php foreach ($parties as $p): ?>
                            <option value="<?= $p['party_id'] ?>" <?= $p['party_id']==$c['party_id']?'selected':'' ?>>
                                <?= htmlspecialchars($p['abbreviation']) ?> — <?= htmlspecialchars($p['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <select class="edit-select" id="ec-<?= $cid ?>">
                            <option value="">— Select —</option>
                            <?php foreach ($constituencies as $con): ?>
                            <option value="<?= $con['constituency_id'] ?>" <?= $con['constituency_id']==$c['constituency_id']?'selected':'' ?>>
                                <?= htmlspecialchars($con['name']) ?> (<?= htmlspecialchars($con['code']) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <input class="edit-input" id="es-<?= $cid ?>" value="<?= htmlspecialchars($c['symbol']??'') ?>" placeholder="Symbol" style="min-width:90px;">
                    </td>
                    <td colspan="2">
                        <?php if ($has_lock): ?>
                        <span style="font-size:11.5px;color:var(--danger);font-weight:600;">⚠️ Has locked votes</span>
                        <?php else: ?>
                        <span style="font-size:11.5px;color:var(--muted);">No locked results</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="action-row">
                            <button class="btn btn-sm btn-success" onclick="saveEdit(<?= $cid ?>)">💾 Save</button>
                            <button class="btn btn-sm btn-secondary" onclick="cancelEdit(<?= $cid ?>)">✕ Cancel</button>
                        </div>
                    </td>
                </tr>

                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- PAGINATION -->
        <div class="pagination-bar">
            <div>Showing <strong><?= count($candidates) ?></strong> candidate records<?= ($search||$filter_party||$filter_con)?' (filtered)':'' ?></div>
            <div class="pagination">
                <div class="pag-btn disabled">‹</div>
                <div class="pag-btn active">1</div>
                <div class="pag-btn disabled">›</div>
            </div>
        </div>
    </div>

</div><!-- /page-wrap -->

<!-- ═══ DELETE MODAL ═══ -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal-box">
        <div class="modal-icon">⚠️</div>
        <div class="modal-title">Delete Candidate?</div>
        <div class="modal-body-txt">
            Are you sure you want to remove <strong id="deleteCandName">this candidate</strong>?<br>
            Candidates with <strong>locked vote results cannot be deleted</strong>. Unlocked result entries will be removed. This action <strong>cannot be undone.</strong>
        </div>
        <div class="modal-actions">
            <button class="btn btn-danger" id="confirmDeleteBtn">🗑️ Confirm Delete</button>
            <button class="btn btn-secondary" onclick="closeDeleteModal()">✕ Cancel</button>
        </div>
    </div>
</div>

<!-- ═══ FOOTER ═══ -->
<footer class="footer">
    <div>🗳️ EMS Admin &nbsp;·&nbsp; Candidate Management &nbsp;·&nbsp; © 2026 Bangladesh Election Commission. All rights reserved.</div>
    <div class="footer-links">
        <a href="#">Privacy Policy</a>
        <a href="#">Security Audit</a>
        <a href="#">Help &amp; Documentation</a>
        <a href="#">Contact Support</a>
    </div>
</footer>

<div id="toast"></div>

<script>
const TAKEN_COMBOS = <?= $js_taken_combos ?>;
// ── TOAST ────────────────────────────────────────────────────
function showToast(msg, type='info') {
    const c  = document.getElementById('toast');
    const el = document.createElement('div');
    el.className = 'toast-msg ' + type;
    const icons = {success:'✅ ',error:'❌ ',warning:'⚠️ ',info:'ℹ️ '};
    el.innerHTML = (icons[type]||'ℹ️ ') + msg;
    c.appendChild(el);
    setTimeout(()=>{ el.style.opacity='0'; el.style.transition='opacity .4s'; setTimeout(()=>el.remove(),400); },4000);
}

// ── FORM TOGGLE ──────────────────────────────────────────────
function toggleForm() {
    const card = document.getElementById('addFormCard');
    const open = card.style.display !== 'none';
    card.style.display = open ? 'none' : 'block';
    if (!open) card.scrollIntoView({behavior:'smooth',block:'start'});
}
// ── ADD FORM VALIDATION ──────────────────────────────────────
function validateAddForm() {
    const party = document.querySelector('select[name="party_id"]').value;
    const con   = document.querySelector('select[name="constituency_id"]').value;
    if (party && con) {
        const key = party + ':' + con;
        if (TAKEN_COMBOS.hasOwnProperty(key)) {
            showToast('⚠️ This party already has a candidate in the selected constituency.', 'warning');
            return false;
        }
    }
    return true;
}
// ── INLINE EDIT ──────────────────────────────────────────────
function startEdit(cid) {
    document.getElementById('row-'     + cid).style.display = 'none';
    document.getElementById('editrow-' + cid).style.display = 'table-row';
}
function cancelEdit(cid) {
    document.getElementById('editrow-' + cid).style.display = 'none';
    document.getElementById('row-'     + cid).style.display = 'table-row';
}
function saveEdit(cid) {
    const name   = document.getElementById('en-'  + cid).value.trim();
    const nid    = document.getElementById('enid-'+ cid).value.trim();
    const party  = document.getElementById('ep-'  + cid).value;
    const con    = document.getElementById('ec-'  + cid).value;
    const symbol = document.getElementById('es-'  + cid).value.trim();

   if (!name || !nid || !con) {
        showToast('Full name, National ID and constituency are required.','warning');
        return;
    }

    // Party+Constituency uniqueness check (exclude the candidate being edited)
    if (party && con) {
        const key = party + ':' + con;
        if (TAKEN_COMBOS.hasOwnProperty(key) && TAKEN_COMBOS[key] !== cid) {
            showToast('⚠️ This party already has a candidate in the selected constituency.', 'warning');
            return;
        }
    }

    const fd = new FormData();
    fd.append('ajax_action',    'update');
    fd.append('candidate_id',   cid);
    fd.append('full_name',      name);
    fd.append('national_id',    nid);
    fd.append('party_id',       party);
    fd.append('constituency_id',con);
    fd.append('symbol',         symbol);

    fetch(window.location.href, {method:'POST',body:fd})
        .then(r=>r.json())
        .then(res=>{
            if (res.success) { showToast(res.message,'success'); setTimeout(()=>location.reload(),900); }
            else showToast(res.message,'error');
        })
        .catch(()=>showToast('Network error.','error'));
}

// ── DELETE ────────────────────────────────────────────────────
let pendingDeleteId = null;
function confirmDelete(cid, name) {
    pendingDeleteId = cid;
    document.getElementById('deleteCandName').textContent = name;
    document.getElementById('deleteModal').classList.add('open');
}
function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('open');
    pendingDeleteId = null;
}
document.getElementById('confirmDeleteBtn').addEventListener('click', ()=>{
    if (!pendingDeleteId) return;
    const fd = new FormData();
    fd.append('ajax_action','delete');
    fd.append('candidate_id', pendingDeleteId);
    closeDeleteModal();
    fetch(window.location.href, {method:'POST',body:fd})
        .then(r=>r.json())
        .then(res=>{
            if (res.success) {
                showToast(res.message,'success');
                ['row-','editrow-'].forEach(pfx=>{
                    const el = document.getElementById(pfx + pendingDeleteId);
                    if (el) el.remove();
                });
            } else {
                showToast(res.message,'error');
            }
        })
        .catch(()=>showToast('Network error.','error'));
});
document.getElementById('deleteModal').addEventListener('click',function(e){
    if (e.target===this) closeDeleteModal();
});

<?php if ($form_error): ?>
document.getElementById('addFormCard').style.display = 'block';
<?php endif; ?>
</script>
</body>
</html>
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

    // ── DELETE VOTER ─────────────────────────────────────────
   if ($act === 'delete') {
    $id = (int)($_POST['voter_id'] ?? 0);
    // Block delete if voter has already voted
    $chk = $pdo->prepare("SELECT has_voted, constituency_id, polling_station_id, booth_id FROM voters WHERE voter_id=?");
    $chk->execute([$id]);
    $row = $chk->fetch();
    if ($row && (int)$row['has_voted'] === 1) {
        echo json_encode(['success'=>false,'message'=>'Cannot delete: this voter has already cast a vote. Voting records cannot be removed.']);
        exit;
    }
    
    $constituency_id = $row['constituency_id'];
    $station_id = $row['polling_station_id'];
    $booth_id = $row['booth_id'];
    
    try {
        $pdo->beginTransaction();
        
        // Delete the voter
        $pdo->prepare("DELETE FROM voters WHERE voter_id=?")->execute([$id]);
        
        // 1. Update constituency total_registered_voters (-1)
        if ($constituency_id) {
            $pdo->prepare("UPDATE constituencies SET total_registered_voters = total_registered_voters - 1 WHERE constituency_id = ? AND total_registered_voters > 0")
                ->execute([$constituency_id]);
        }
        
        // 2. Update polling_station total_ballots_issued (-1)
        if ($station_id) {
            $pdo->prepare("UPDATE polling_stations SET total_ballots_issued = total_ballots_issued - 1 WHERE station_id = ? AND total_ballots_issued > 0")
                ->execute([$station_id]);
        }
        
        // 3. Update polling_booth ballots_issued (-1)
        if ($booth_id) {
            $pdo->prepare("UPDATE polling_booths SET ballots_issued = ballots_issued - 1 WHERE booth_id = ? AND ballots_issued > 0")
                ->execute([$booth_id]);
        }
        
        $pdo->commit();
        
        $pdo->prepare("INSERT INTO audit_logs (officer_id,action_type,affected_entity,affected_entity_id,details,ip_address) VALUES (?,?,?,?,?,?)")
            ->execute([$logged_in_id,'DELETE_VOTER','voters',$id,
                "Deleted voter ID $id | Constituency: -1 voter | Station: -1 ballot | Booth: -1 ballot",
                $_SERVER['REMOTE_ADDR']??'']);
        echo json_encode(['success'=>true,'message'=>'Voter deleted successfully. Constituency, Station, and Booth counts updated.']);
    } catch (PDOException $e) {
        $pdo->rollBack();
        echo json_encode(['success'=>false,'message'=>'Error: '.$e->getMessage()]);
    }
    exit;
}

    // ── UPDATE VOTER ─────────────────────────────────────────
 if ($act === 'update') {
    $id     = (int)($_POST['voter_id']??0);
    $name   = trim($_POST['full_name']??'');
    $nid    = trim($_POST['national_id']??'');
    $vcode  = trim($_POST['voter_code']??'');
    $con_id = (int)($_POST['constituency_id']??0) ?: null;
    $st_id  = (int)($_POST['polling_station_id']??0) ?: null;
    $bo_id  = (int)($_POST['booth_id']??0) ?: null;

    if (!$name || !$nid || !$vcode) {
        echo json_encode(['success'=>false,'message'=>'Full name, National ID and Voter Code are required.']); exit;
    }
    
    // Get current assignments of this voter
    $curr = $pdo->prepare("SELECT constituency_id, polling_station_id, booth_id FROM voters WHERE voter_id=?");
    $curr->execute([$id]);
    $old = $curr->fetch();
    $old_con_id = $old['constituency_id'];
    $old_st_id  = $old['polling_station_id'];
    $old_bo_id  = $old['booth_id'];
    
    // Uniqueness checks (exclude self)
    $dupNid = $pdo->prepare("SELECT COUNT(*) FROM voters WHERE national_id=? AND voter_id!=?");
    $dupNid->execute([$nid, $id]);
    if ((int)$dupNid->fetchColumn() > 0) {
        echo json_encode(['success'=>false,'message'=>"National ID '$nid' is already registered to another voter."]); exit;
    }
    $dupCode = $pdo->prepare("SELECT COUNT(*) FROM voters WHERE voter_code=? AND voter_id!=?");
    $dupCode->execute([$vcode, $id]);
    if ((int)$dupCode->fetchColumn() > 0) {
        echo json_encode(['success'=>false,'message'=>"Voter Code '$vcode' is already in use."]); exit;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Update the voter
        $pdo->prepare("UPDATE voters SET full_name=?,national_id=?,voter_code=?,constituency_id=?,polling_station_id=?,booth_id=? WHERE voter_id=?")
            ->execute([$name,$nid,$vcode,$con_id,$st_id,$bo_id,$id]);
        
        // 1. Update constituency counts if changed
        if ($old_con_id != $con_id) {
            if ($old_con_id) {
                $pdo->prepare("UPDATE constituencies SET total_registered_voters = total_registered_voters - 1 WHERE constituency_id = ? AND total_registered_voters > 0")
                    ->execute([$old_con_id]);
            }
            if ($con_id) {
                $pdo->prepare("UPDATE constituencies SET total_registered_voters = total_registered_voters + 1 WHERE constituency_id = ?")
                    ->execute([$con_id]);
            }
        }
        
        // 2. Update polling_station counts if changed
        if ($old_st_id != $st_id) {
            if ($old_st_id) {
                $pdo->prepare("UPDATE polling_stations SET total_ballots_issued = total_ballots_issued - 1 WHERE station_id = ? AND total_ballots_issued > 0")
                    ->execute([$old_st_id]);
            }
            if ($st_id) {
                $pdo->prepare("UPDATE polling_stations SET total_ballots_issued = total_ballots_issued + 1 WHERE station_id = ?")
                    ->execute([$st_id]);
            }
        }
        
        // 3. Update polling_booth counts if changed
        if ($old_bo_id != $bo_id) {
            if ($old_bo_id) {
                $pdo->prepare("UPDATE polling_booths SET ballots_issued = ballots_issued - 1 WHERE booth_id = ? AND ballots_issued > 0")
                    ->execute([$old_bo_id]);
            }
            if ($bo_id) {
                $pdo->prepare("UPDATE polling_booths SET ballots_issued = ballots_issued + 1 WHERE booth_id = ?")
                    ->execute([$bo_id]);
            }
        }
        
        $pdo->commit();
        
        $pdo->prepare("INSERT INTO audit_logs (officer_id,action_type,affected_entity,affected_entity_id,details,ip_address) VALUES (?,?,?,?,?,?)")
            ->execute([$logged_in_id,'UPDATE_VOTER','voters',$id,
                "Updated voter '$name' (ID $id) | Adjusted constituency/station/booth counts accordingly",
                $_SERVER['REMOTE_ADDR']??'']);
        echo json_encode(['success'=>true,'message'=>"Voter '$name' updated successfully. All related counts adjusted."]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}
    // ── GET STATIONS BY CONSTITUENCY (for cascade dropdown) ──
    if ($act === 'get_stations') {
        $cid = (int)($_POST['constituency_id']??0);
        $rows = $pdo->prepare("SELECT station_id,name FROM polling_stations WHERE constituency_id=? ORDER BY name");
        $rows->execute([$cid]);
        echo json_encode(['success'=>true,'stations'=>$rows->fetchAll()]);
        exit;
    }

    // ── GET BOOTHS BY STATION (for cascade dropdown) ─────────
    if ($act === 'get_booths') {
        $sid = (int)($_POST['station_id']??0);
        $rows = $pdo->prepare("SELECT booth_id,booth_number FROM polling_booths WHERE station_id=? ORDER BY booth_number");
        $rows->execute([$sid]);
        echo json_encode(['success'=>true,'booths'=>$rows->fetchAll()]);
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action.']); exit;
}

// ============================================================
//  CREATE NEW VOTER (form POST)
// ============================================================
$form_success = $form_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_voter'])) {
    $name   = trim($_POST['full_name']??'');
    $nid    = trim($_POST['national_id']??'');
    $vcode  = trim($_POST['voter_code']??'');
    $con_id = (int)($_POST['constituency_id']??0) ?: null;
    $st_id  = (int)($_POST['polling_station_id']??0) ?: null;
    $bo_id  = (int)($_POST['booth_id']??0) ?: null;

    if (!$name || !$nid || !$vcode) {
        $form_error = 'Full name, National ID and Voter Code are required.';
    } else {
        // Uniqueness checks
        $dupNid = $pdo->prepare("SELECT COUNT(*) FROM voters WHERE national_id=?");
        $dupNid->execute([$nid]);
        if ((int)$dupNid->fetchColumn() > 0) {
            $form_error = "National ID '$nid' is already registered to another voter.";
        } else {
            $dupCode = $pdo->prepare("SELECT COUNT(*) FROM voters WHERE voter_code=?");
            $dupCode->execute([$vcode]);
            if ((int)$dupCode->fetchColumn() > 0) {
                $form_error = "Voter Code '$vcode' is already in use.";
            }
        }
        if (!$form_error) {
    try {
        $pdo->beginTransaction();
        
        // Insert the voter
        $pdo->prepare("INSERT INTO voters (full_name,national_id,voter_code,constituency_id,polling_station_id,booth_id,has_voted,voted_at) VALUES (?,?,?,?,?,?,0,NULL)")
            ->execute([$name,$nid,$vcode,$con_id,$st_id,$bo_id]);
        $new_id = $pdo->lastInsertId();
        
        // 1. Update constituency total_registered_voters (+1)
        if ($con_id) {
            $pdo->prepare("UPDATE constituencies SET total_registered_voters = total_registered_voters + 1 WHERE constituency_id = ?")
                ->execute([$con_id]);
        }
        
        // 2. Update polling_station total_ballots_issued (+1)
        if ($st_id) {
            $pdo->prepare("UPDATE polling_stations SET total_ballots_issued = total_ballots_issued + 1 WHERE station_id = ?")
                ->execute([$st_id]);
        }
        
        // 3. Update polling_booth ballots_issued (+1)
        if ($bo_id) {
            $pdo->prepare("UPDATE polling_booths SET ballots_issued = ballots_issued + 1 WHERE booth_id = ?")
                ->execute([$bo_id]);
        }
        
        $pdo->commit();
        
        $pdo->prepare("INSERT INTO audit_logs (officer_id,action_type,affected_entity,affected_entity_id,details,ip_address) VALUES (?,?,?,?,?,?)")
            ->execute([$logged_in_id,'CREATE_VOTER','voters',$new_id,
                "Created voter '$name' ($vcode) | Constituency: +1 voter | Station: +1 ballot | Booth: +1 ballot",
                $_SERVER['REMOTE_ADDR']??'']);
        $form_success = "Voter <strong>".htmlspecialchars($name)."</strong> registered successfully. Constituency, Station, and Booth counts updated.";
    } catch (PDOException $e) {
        $pdo->rollBack();
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

// Dropdowns
$constituencies = $pdo->query("SELECT constituency_id,name,code FROM constituencies ORDER BY name")->fetchAll();
$all_stations   = $pdo->query("SELECT station_id,constituency_id,name FROM polling_stations ORDER BY name")->fetchAll();
$all_booths     = $pdo->query("SELECT booth_id,station_id,booth_number FROM polling_booths ORDER BY booth_number")->fetchAll();

// Search / filter
$search      = trim($_GET['q']??'');
$filter_con  = (int)($_GET['con']??0);
$filter_voted= $_GET['voted']??'';

$sqlBase = "
    SELECT v.*,
           c.name  AS constituency_name, c.code AS constituency_code,
           ps.name AS station_name,
           pb.booth_number
    FROM voters v
    LEFT JOIN constituencies   c  ON c.constituency_id    = v.constituency_id
    LEFT JOIN polling_stations ps ON ps.station_id        = v.polling_station_id
    LEFT JOIN polling_booths   pb ON pb.booth_id          = v.booth_id
";
$where  = [];
$params = [];

if ($search !== '') {
    $where[]  = "(v.full_name LIKE ? OR v.national_id LIKE ? OR v.voter_code LIKE ?)";
    $params[] = '%'.$search.'%';
    $params[] = '%'.$search.'%';
    $params[] = '%'.$search.'%';
}
if ($filter_con > 0)     { $where[] = "v.constituency_id=?";  $params[] = $filter_con; }
if ($filter_voted === '1') { $where[] = "v.has_voted=1"; }
if ($filter_voted === '0') { $where[] = "v.has_voted=0"; }

$sqlFull = $sqlBase.($where?" WHERE ".implode(" AND ",$where):"")." ORDER BY v.voter_id";
$vStmt   = $pdo->prepare($sqlFull);
$vStmt->execute($params);
$voters  = $vStmt->fetchAll();

// Stats
$total_voters   = (int)$pdo->query("SELECT COUNT(*) FROM voters")->fetchColumn();
$voted_count    = (int)$pdo->query("SELECT COUNT(*) FROM voters WHERE has_voted=1")->fetchColumn();
$not_voted      = $total_voters - $voted_count;
$turnout_pct    = $total_voters > 0 ? round(($voted_count/$total_voters)*100,1) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Voter Management — EMS Admin</title>
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
    --purple:#8b5cf6;--teal:#14b8a6;--indigo:#6366f1;
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

/* ── TOPBAR ─────────────────────────────────────────────── */
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

/* ── SUB NAV ─────────────────────────────────────────────── */
.sub-nav{background:var(--surface);border-bottom:2px solid var(--border);padding:0 32px;display:flex;align-items:center;gap:2px;overflow-x:auto;position:sticky;top:66px;z-index:100;box-shadow:0 1px 8px rgba(10,22,40,.06);}
.sub-nav-link{display:flex;align-items:center;gap:6px;padding:14px 16px;font-size:12.5px;font-weight:600;color:var(--muted);border-bottom:2px solid transparent;margin-bottom:-2px;white-space:nowrap;transition:all .18s;}
.sub-nav-link:hover{color:var(--primary);}
.sub-nav-link.active{color:var(--primary);border-bottom-color:var(--primary);background:rgba(26,86,219,.04);}

/* ── PAGE WRAP ──────────────────────────────────────────── */
.page-wrap{max-width:1380px;margin:0 auto;padding:28px 28px 56px;width:100%;flex:1;}

/* ── PAGE HEADER ────────────────────────────────────────── */
.page-header{display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:14px;}
.page-header-left{display:flex;align-items:center;gap:16px;}
.page-header-icon{width:52px;height:52px;background:linear-gradient(135deg,var(--indigo),var(--purple));border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:24px;box-shadow:0 4px 16px rgba(99,102,241,.28);flex-shrink:0;}
.page-title{font-family:var(--font-head);font-size:26px;font-weight:800;color:var(--navy);line-height:1.1;letter-spacing:-.3px;}
.page-subtitle{font-size:13px;color:var(--muted);margin-top:4px;}
.breadcrumb{font-size:11.5px;color:var(--muted2);margin-bottom:4px;}
.breadcrumb a{color:var(--primary);font-weight:600;}
.breadcrumb a:hover{text-decoration:underline;}
.header-actions{display:flex;gap:10px;flex-wrap:wrap;}

/* ── BUTTONS ────────────────────────────────────────────── */
.btn{display:inline-flex;align-items:center;gap:7px;font-size:13px;font-weight:600;padding:9px 18px;border-radius:var(--radius-sm);border:none;transition:all .2s;cursor:pointer;white-space:nowrap;font-family:var(--font-body);letter-spacing:.2px;}
.btn-primary{background:linear-gradient(135deg,var(--primary),var(--primary2));color:#fff;box-shadow:0 2px 10px rgba(26,86,219,.25);}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 4px 18px rgba(26,86,219,.35);}
.btn-indigo{background:linear-gradient(135deg,var(--indigo),var(--purple));color:#fff;box-shadow:0 2px 10px rgba(99,102,241,.25);}
.btn-indigo:hover{transform:translateY(-1px);box-shadow:0 4px 18px rgba(99,102,241,.35);}
.btn-secondary{background:var(--surface);color:var(--text2);border:1.5px solid var(--border);}
.btn-secondary:hover{border-color:var(--primary);color:var(--primary);background:#eff6ff;}
.btn-danger{background:rgba(239,68,68,.08);color:var(--danger);border:1.5px solid rgba(239,68,68,.25);}
.btn-danger:hover{background:var(--danger);color:#fff;}
.btn-success{background:rgba(16,185,129,.1);color:var(--success);border:1.5px solid rgba(16,185,129,.3);}
.btn-success:hover{background:var(--success);color:#fff;}
.btn-sm{font-size:12px;padding:6px 13px;}
.btn-xs{font-size:11px;padding:4px 10px;border-radius:6px;}

/* ── STAT STRIP ─────────────────────────────────────────── */
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
.st-icon.indigo{background:#e0e7ff;}
.st-icon.red{background:#fee2e2;}
.st-icon.gold{background:#fef3c7;}
.st-icon.teal{background:#ccfbf1;}
.st-icon.purple{background:#ede9fe;}
/* IBM Plex Mono for numbers — compact, not stretchy */
.st-value{font-family:var(--font-mono);font-size:26px;font-weight:600;letter-spacing:-.5px;line-height:1;margin-bottom:5px;color:var(--text);}
.st-value.blue{color:var(--primary);}
.st-value.green{color:var(--success);}
.st-value.indigo{color:var(--indigo);}
.st-value.red{color:var(--danger);}
.st-value.gold{color:var(--gold);}
.st-value.teal{color:var(--teal);}
.st-value.purple{color:var(--purple);}
.st-label{font-size:11.5px;font-weight:600;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);}
.st-delta{position:absolute;top:16px;right:16px;font-size:11px;font-weight:600;padding:3px 9px;border-radius:20px;}
.st-delta.info{background:#dbeafe;color:var(--primary);}
.st-delta.up{background:#dcfce7;color:var(--success);}
.st-delta.red{background:#fee2e2;color:var(--danger);}
.st-delta.gold{background:#fef3c7;color:var(--gold);}
.st-delta.indigo{background:#e0e7ff;color:var(--indigo);}

/* Turnout progress bar inside stat tile */
.turnout-bar-wrap{margin-top:10px;}
.turnout-bar-track{height:5px;background:var(--bg2);border-radius:10px;overflow:hidden;}
.turnout-bar-fill{height:100%;background:linear-gradient(90deg,var(--success),var(--success2));border-radius:10px;transition:width .6s ease;}
.turnout-pct{font-family:var(--font-mono);font-size:12px;color:var(--success);font-weight:600;margin-top:4px;}

/* ── CARDS ──────────────────────────────────────────────── */
.card{background:var(--surface);border-radius:var(--radius);border:1.5px solid var(--border);overflow:hidden;box-shadow:var(--shadow);margin-bottom:22px;}
.card-header{padding:18px 24px 14px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border);background:#fafbfc;flex-wrap:wrap;gap:10px;}
.card-title{font-family:var(--font-head);font-size:14.5px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px;}
.card-body{padding:22px 24px;}
.count-pill{display:inline-flex;align-items:center;justify-content:center;background:var(--bg2);border:1px solid var(--border);border-radius:20px;font-size:12px;font-weight:700;color:var(--text2);padding:2px 10px;font-family:var(--font-mono);}

/* ── FORM ───────────────────────────────────────────────── */
.form-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.form-grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;}
.form-grid-4{display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:16px;}
@media(max-width:900px){.form-grid-4,.form-grid-3{grid-template-columns:1fr 1fr;}}
@media(max-width:600px){.form-grid-4,.form-grid-3,.form-grid-2{grid-template-columns:1fr;}}
.form-group{display:flex;flex-direction:column;gap:6px;}
.form-label{font-size:11.5px;font-weight:700;color:var(--text2);letter-spacing:.4px;text-transform:uppercase;}
.form-label .req{color:var(--danger);margin-left:2px;}
.form-control{padding:10px 14px;border:1.5px solid var(--border);border-radius:var(--radius-sm);font-size:13.5px;font-family:var(--font-body);color:var(--text);background:var(--surface);transition:border-color .18s,box-shadow .18s;outline:none;width:100%;}
.form-control:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(26,86,219,.12);}
.form-control::placeholder{color:var(--muted2);}
.form-control:disabled{background:var(--bg2);color:var(--muted);cursor:not-allowed;}
.form-helper{font-size:11.5px;color:var(--muted);margin-top:16px;padding:10px 14px;background:#f0f0ff;border-radius:var(--radius-sm);border-left:3px solid var(--indigo);}
.form-note{font-size:11px;color:var(--muted2);margin-top:4px;}

/* ── SEARCH / FILTER BAR ────────────────────────────────── */
.filter-bar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.search-input-wrap{position:relative;flex:1;min-width:220px;}
.search-input-wrap .si{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--muted2);font-size:14px;pointer-events:none;}
.search-input-wrap input{width:100%;padding:10px 14px 10px 38px;border:1.5px solid var(--border);border-radius:var(--radius-sm);font-size:13.5px;font-family:var(--font-body);color:var(--text);background:var(--surface);outline:none;transition:border-color .18s,box-shadow .18s;}
.search-input-wrap input:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(26,86,219,.10);}
.filter-select{padding:10px 14px;border:1.5px solid var(--border);border-radius:var(--radius-sm);font-size:13.5px;font-family:var(--font-body);color:var(--text);background:var(--surface);outline:none;min-width:170px;transition:border-color .18s;}
.filter-select:focus{border-color:var(--primary);}

/* ── MANAGEMENT TABLE ───────────────────────────────────── */
.table-wrap{overflow-x:auto;}
.mgmt-table{width:100%;border-collapse:collapse;font-size:13.5px;}
.mgmt-table thead tr{background:#f1f5f9;}
.mgmt-table th{padding:11px 16px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);border-bottom:2px solid var(--border);white-space:nowrap;}
.mgmt-table td{padding:13px 16px;border-bottom:1px solid var(--border);vertical-align:middle;}
.mgmt-table tbody tr{transition:background .15s;}
.mgmt-table tbody tr:hover{background:#f8fafc;}
.mgmt-table tbody tr:last-child td{border-bottom:none;}

/* Editing row */
.editing-row{background:#eff6ff !important;outline:2px solid var(--primary);outline-offset:-1px;}
.editing-row td{background:#eff6ff;}
.edit-input{padding:7px 10px;border:1.5px solid var(--primary);border-radius:6px;font-size:13px;font-family:var(--font-body);color:var(--text);width:100%;outline:none;min-width:90px;box-shadow:0 0 0 2px rgba(26,86,219,.12);}
.edit-select{padding:7px 10px;border:1.5px solid var(--primary);border-radius:6px;font-size:13px;font-family:var(--font-body);color:var(--text);outline:none;min-width:120px;box-shadow:0 0 0 2px rgba(26,86,219,.12);background:#fff;width:100%;}

/* Cell styles */
.cell-main{font-weight:700;color:var(--text);font-size:13.5px;}
.cell-sub{font-size:11px;color:var(--muted);margin-top:2px;}
.id-badge{font-family:var(--font-mono);font-size:12px;font-weight:600;background:var(--bg2);border:1px solid var(--border);border-radius:6px;padding:3px 8px;color:var(--text2);}
.voter-code-tag{font-family:var(--font-mono);font-size:12px;font-weight:600;background:#e0e7ff;color:var(--indigo);border-radius:6px;padding:3px 10px;letter-spacing:.3px;}
.nid-tag{font-family:var(--font-mono);font-size:11.5px;color:var(--muted);background:var(--bg2);border:1px solid var(--border);border-radius:6px;padding:2px 8px;}
.const-tag{font-size:11.5px;font-weight:700;background:#dbeafe;color:var(--primary);border-radius:6px;padding:3px 9px;letter-spacing:.3px;}

/* Voted / Not voted badges */
.voted-yes{display:inline-flex;align-items:center;gap:5px;background:#dcfce7;color:#166534;border:1px solid #86efac;padding:4px 12px;border-radius:20px;font-size:11.5px;font-weight:700;}
.voted-no{display:inline-flex;align-items:center;gap:5px;background:#f1f5f9;color:var(--muted);border:1px solid var(--border);padding:4px 12px;border-radius:20px;font-size:11.5px;font-weight:700;}

.action-row{display:flex;align-items:center;gap:6px;flex-wrap:wrap;}

/* ── PAGINATION ─────────────────────────────────────────── */
.pagination-bar{display:flex;align-items:center;justify-content:space-between;padding:14px 24px;border-top:1px solid var(--border);font-size:12.5px;color:var(--muted);flex-wrap:wrap;gap:10px;}
.pagination{display:flex;gap:4px;}
.pag-btn{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:600;cursor:pointer;background:var(--surface);border:1.5px solid var(--border);color:var(--text2);transition:all .18s;}
.pag-btn:hover{border-color:var(--primary);color:var(--primary);background:#eff6ff;}
.pag-btn.active{background:var(--primary);color:#fff;border-color:var(--primary);}
.pag-btn.disabled{opacity:.4;pointer-events:none;}

/* ── DELETE MODAL ───────────────────────────────────────── */
.modal-overlay{position:fixed;inset:0;background:rgba(10,22,40,.55);backdrop-filter:blur(3px);z-index:500;display:none;align-items:center;justify-content:center;}
.modal-overlay.open{display:flex;}
.modal-box{background:var(--surface);border-radius:18px;padding:32px 28px;max-width:420px;width:90%;box-shadow:var(--shadow-lg);animation:popIn .22s ease;}
@keyframes popIn{from{opacity:0;transform:scale(.93);}to{opacity:1;transform:scale(1);}}
.modal-icon{font-size:44px;text-align:center;margin-bottom:14px;}
.modal-title{font-family:var(--font-head);font-size:18px;font-weight:800;color:var(--text);text-align:center;margin-bottom:10px;}
.modal-body-txt{font-size:13.5px;color:var(--muted);text-align:center;line-height:1.6;margin-bottom:24px;}
.modal-actions{display:flex;gap:10px;justify-content:center;}

/* ── TOAST ──────────────────────────────────────────────── */
#toast{position:fixed;bottom:28px;right:28px;z-index:999;display:flex;flex-direction:column;gap:8px;}
.toast-msg{background:#1e293b;color:#fff;border-radius:10px;padding:12px 20px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:10px;box-shadow:0 4px 20px rgba(0,0,0,.2);animation:slideIn .3s ease;}
.toast-msg.success{background:#166534;}
.toast-msg.error{background:#991b1b;}
.toast-msg.warning{background:#92400e;}
@keyframes slideIn{from{transform:translateY(20px);opacity:0;}to{transform:translateY(0);opacity:1;}}

/* ── ALERTS ─────────────────────────────────────────────── */
.alert{padding:12px 16px;border-radius:var(--radius-sm);font-size:13.5px;font-weight:500;margin-bottom:18px;display:flex;align-items:center;gap:10px;}
.alert-success{background:#dcfce7;color:#166534;border:1px solid #86efac;}
.alert-error{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}

/* ── FOOTER ─────────────────────────────────────────────── */
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
        <button class="btn-logout" onclick="if(confirm('Logout from EMS?')){window.location='login.php'}">⏻ Logout</button>
    </div>
</nav>

<!-- ═══ SUB NAV ═══ -->
<nav class="sub-nav">
    <a class="sub-nav-link" href="admin_dashboard.php">🏠 Dashboard</a>
    <a class="sub-nav-link" href="constituency_management.php">🗺️ Constituencies</a>
    <a class="sub-nav-link" href="polling_station_management.php">🏫 Polling Stations</a>
    <a class="sub-nav-link" href="booth_management.php">🚪 Booth Management</a>
    <a class="sub-nav-link" href="candidate_management.php">🎖️ Candidates</a>
    <a class="sub-nav-link" href="party_management.php">🏳️ Political Parties</a>
    <a class="sub-nav-link" href="officer_management.php">👮 Officers</a>
    <a class="sub-nav-link active" href="voter_management.php">🧑‍🤝‍🧑 Voters</a>

</nav>

<div class="page-wrap">

    <!-- PAGE HEADER -->
    <div class="page-header">
        <div class="page-header-left">
            <div class="page-header-icon">🧑‍🤝‍🧑</div>
            <div>
                <div class="breadcrumb"><a href="admin_dashboard.php">Dashboard</a> / Voter Management</div>
                <div class="page-title">Voter Management</div>
                <div class="page-subtitle">Register, update, and manage voter records including constituency and booth assignment.</div>
            </div>
        </div>
        <div class="header-actions">
            <button class="btn btn-indigo" onclick="toggleForm()">➕ Add Voter</button>
            <button class="btn btn-secondary" onclick="location.reload()">🔄 Refresh</button>
            <a href="admin_dashboard.php" class="btn btn-secondary">← Dashboard</a>
        </div>
    </div>

    <!-- STAT STRIP -->
    <div class="stat-strip">
        <div class="stat-tile">
            <div class="st-delta info">Registered</div>
            <div class="st-icon indigo">🧑‍🤝‍🧑</div>
            <div class="st-value indigo"><?= number_format($total_voters) ?></div>
            <div class="st-label">Total Voters</div>
        </div>
        <div class="stat-tile">
            <div class="st-delta up">Cast</div>
            <div class="st-icon green">✅</div>
            <div class="st-value green"><?= number_format($voted_count) ?></div>
            <div class="st-label">Votes Cast</div>
        </div>
        <div class="stat-tile">
            <div class="st-delta red">Remaining</div>
            <div class="st-icon red">⏳</div>
            <div class="st-value red"><?= number_format($not_voted) ?></div>
            <div class="st-label">Yet to Vote</div>
        </div>
        <div class="stat-tile">
            <div class="st-delta gold">Live</div>
            <div class="st-icon gold">📊</div>
            <div class="st-value gold"><?= $turnout_pct ?>%</div>
            <div class="st-label">Voter Turnout</div>
            <div class="turnout-bar-wrap">
                <div class="turnout-bar-track">
                    <div class="turnout-bar-fill" style="width:<?= min($turnout_pct,100) ?>%"></div>
                </div>
                <div class="turnout-pct"><?= $voted_count ?> / <?= $total_voters ?> voters</div>
            </div>
        </div>
    </div>

    <!-- ALERTS -->
    <?php if ($form_success): ?>
    <div class="alert alert-success">✅ <?= $form_success ?></div>
    <?php endif; ?>
    <?php if ($form_error): ?>
    <div class="alert alert-error">❌ <?= htmlspecialchars($form_error) ?></div>
    <?php endif; ?>

    <!-- ADD VOTER FORM -->
    <div class="card" id="addFormCard" style="display:none; border-color:var(--indigo);">
        <div class="card-header">
            <span class="card-title">🧑‍🤝‍🧑 Register New Voter</span>
            <button class="btn btn-sm btn-secondary" onclick="toggleForm()">✕ Close</button>
        </div>
        <div class="card-body">
            <form method="POST" action="">
                <!-- Row 1: Name, NID, Voter Code -->
                <div class="form-grid-4" style="margin-bottom:16px;">
    <div class="form-group">
        <label class="form-label">Full Name <span class="req">*</span></label>
        <input type="text" name="full_name" class="form-control" placeholder="e.g. Md. Ahsan Kabir" required>
    </div>
    <div class="form-group">
        <label class="form-label">National ID (NID) <span class="req">*</span></label>
        <input type="text" name="national_id" class="form-control" placeholder="e.g. 1234567890123" required>
        <span class="form-note">Must be unique across all voters.</span>
    </div>
    <div class="form-group">
        <label class="form-label">Voter Code <span class="req">*</span></label>
        <input type="text" name="voter_code" class="form-control" placeholder="e.g. VTR-2026-00512" required>
        <span class="form-note">Must be unique. Used for polling station lookup.</span>
    </div>
    <div class="form-group">
        <label class="form-label">Date of Birth</label>
        <input type="date" name="date_of_birth" class="form-control" placeholder="YYYY-MM-DD">
        <span class="form-note">Optional. Format: YYYY-MM-DD</span>
    </div>
</div>
                <!-- Row 2: Constituency, Station, Booth -->
                <div class="form-grid-3" style="margin-bottom:18px;">
                    <div class="form-group">
                        <label class="form-label">Constituency</label>
                        <select name="constituency_id" id="add-con" class="form-control" onchange="cascadeStations(this.value,'add-station','add-booth')">
                            <option value="">— Select Constituency —</option>
                            <?php foreach ($constituencies as $con): ?>
                            <option value="<?= $con['constituency_id'] ?>"><?= htmlspecialchars($con['name']) ?> (<?= htmlspecialchars($con['code']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Polling Station</label>
                        <select name="polling_station_id" id="add-station" class="form-control" onchange="cascadeBooths(this.value,'add-booth')" disabled>
                            <option value="">— Select Constituency First —</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Polling Booth</label>
                        <select name="booth_id" id="add-booth" class="form-control" disabled>
                            <option value="">— Select Station First —</option>
                        </select>
                    </div>
                </div>
                <!-- Note: has_voted defaults to 0, voted_at to NULL — no fields shown -->
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <button type="submit" name="create_voter" class="btn btn-indigo">💾 Register Voter</button>
                    <button type="reset" class="btn btn-secondary" onclick="resetAddForm()">↺ Reset Form</button>
                </div>
               
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
                               placeholder="Search by name, NID, or voter code…">
                    </div>
                    <select name="con" class="filter-select">
                        <option value="">All Constituencies</option>
                        <?php foreach ($constituencies as $con): ?>
                        <option value="<?= $con['constituency_id'] ?>" <?= $filter_con==$con['constituency_id']?'selected':'' ?>>
                            <?= htmlspecialchars($con['name']) ?> (<?= htmlspecialchars($con['code']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="voted" class="filter-select" style="min-width:150px;">
                        <option value="">All Voters</option>
                        <option value="1" <?= $filter_voted==='1'?'selected':'' ?>>✅ Voted</option>
                        <option value="0" <?= $filter_voted==='0'?'selected':'' ?>>⏳ Not Yet Voted</option>
                    </select>
                    <button type="submit" class="btn btn-primary">Search</button>
                    <?php if ($search||$filter_con||$filter_voted!==''): ?>
                    <a href="voter_management.php" class="btn btn-secondary">✕ Clear</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- MAIN TABLE CARD -->
    <div class="card">
        <div class="card-header">
            <span class="card-title">🧑‍🤝‍🧑 Voter Records
                <?php if ($search||$filter_con||$filter_voted!==''): ?>
                <span style="font-size:12px;font-weight:400;color:var(--muted);">Filtered results</span>
                <?php endif; ?>
            </span>
            <span class="count-pill"><?= count($voters) ?> found</span>
        </div>

        <div class="table-wrap">
            <table class="mgmt-table" id="voterTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Full Name</th>
                        <th>Voter Code</th>
                        <th>National ID</th>
                        <th>Constituency</th>
                        <th>Station / Booth</th>
                        <th>Vote Status</th>
                        <th>Voted At</th>
                        <th style="min-width:160px;">Actions</th>
                    </tr>
                </thead>
                <tbody>

                <?php if (empty($voters)): ?>
                <tr>
                    <td colspan="9" style="text-align:center;padding:40px;color:var(--muted);">
                        No voters found<?= ($search||$filter_con||$filter_voted!=='') ? ' for this filter' : '' ?>.
                    </td>
                </tr>
                <?php endif; ?>

                <?php foreach ($voters as $v):
                    $vid    = (int)$v['voter_id'];
                    $voted  = (int)$v['has_voted'] === 1;
                    // Build station/booth options for edit dropdowns
                    $stationsForConst = array_filter($all_stations, fn($s) => $s['constituency_id'] == $v['constituency_id']);
                    $boothsForStation = array_filter($all_booths,   fn($b) => $b['station_id']      == $v['polling_station_id']);
                ?>

                <!-- DISPLAY ROW -->
                <tr class="data-row" id="row-<?= $vid ?>">
                    <td><span class="id-badge">#<?= $vid ?></span></td>
                    <td>
                        <div class="cell-main"><?= htmlspecialchars($v['full_name']) ?></div>
                    </td>
                    <td><span class="voter-code-tag"><?= htmlspecialchars($v['voter_code']) ?></span></td>
                    <td><span class="nid-tag"><?= htmlspecialchars($v['national_id']) ?></span></td>
                    <td>
                        <?php if ($v['constituency_name']): ?>
                        <span class="const-tag"><?= htmlspecialchars($v['constituency_code']) ?></span>
                        <div class="cell-sub" style="margin-top:4px;"><?= htmlspecialchars($v['constituency_name']) ?></div>
                        <?php else: ?>
                        <span style="color:var(--muted2);font-size:12px;">— Not Set —</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($v['station_name']): ?>
                        <div class="cell-main" style="font-size:12.5px;"><?= htmlspecialchars($v['station_name']) ?></div>
                        <div class="cell-sub">
                            Booth <?= $v['booth_number'] ? htmlspecialchars($v['booth_number']) : '—' ?>
                        </div>
                        <?php else: ?>
                        <span style="color:var(--muted2);font-size:12px;">— Not Assigned —</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($voted): ?>
                        <span class="voted-yes">✅ Voted</span>
                        <?php else: ?>
                        <span class="voted-no">⏳ Pending</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($v['voted_at']): ?>
                        <div style="font-family:var(--font-mono);font-size:12px;color:var(--text2);">
                            <?= date('d M Y', strtotime($v['voted_at'])) ?>
                        </div>
                        <div class="cell-sub"><?= date('H:i', strtotime($v['voted_at'])) ?></div>
                        <?php else: ?>
                        <span style="color:var(--muted2);font-size:12px;">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="action-row">
                            <?php if (!$voted): ?>
                            <button class="btn btn-sm btn-secondary" onclick="startEdit(<?= $vid ?>)">✏️ Edit</button>
                            <?php else: ?>
                            <button class="btn btn-sm btn-secondary" disabled title="Cannot edit: voter has already voted" style="opacity:.5;cursor:not-allowed;">✏️ Edit</button>
                            <?php endif; ?>
                            <button class="btn btn-sm btn-danger" onclick="confirmDelete(<?= $vid ?>, '<?= htmlspecialchars(addslashes($v['full_name'])) ?>', <?= $voted ? 'true' : 'false' ?>)">🗑️</button>
                        </div>
                    </td>
                </tr>

                <!-- EDIT ROW -->
                <tr class="editing-row" id="editrow-<?= $vid ?>" style="display:none;">
                    <td><span class="id-badge">#<?= $vid ?></span></td>
                    <td>
                        <input class="edit-input" id="en-<?= $vid ?>" value="<?= htmlspecialchars($v['full_name']) ?>" placeholder="Full name" style="min-width:140px;">
                    </td>
                    <td>
                        <input class="edit-input" id="evc-<?= $vid ?>" value="<?= htmlspecialchars($v['voter_code']) ?>" placeholder="Voter code" style="min-width:130px;">
                    </td>
                    <td>
                        <input class="edit-input" id="enid-<?= $vid ?>" value="<?= htmlspecialchars($v['national_id']) ?>" placeholder="National ID" style="min-width:120px;">
                    </td>
                    <td>
                        <select class="edit-select" id="econ-<?= $vid ?>" onchange="editCascadeStations(<?= $vid ?>)">
                            <option value="">— None —</option>
                            <?php foreach ($constituencies as $con): ?>
                            <option value="<?= $con['constituency_id'] ?>" <?= $con['constituency_id']==$v['constituency_id']?'selected':'' ?>>
                                <?= htmlspecialchars($con['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td colspan="2">
                        <div style="display:flex;gap:6px;flex-direction:column;">
                            <select class="edit-select" id="est-<?= $vid ?>" onchange="editCascadeBooths(<?= $vid ?>)" style="min-width:170px;">
                                <option value="">— Station —</option>
                                <?php foreach ($stationsForConst as $st): ?>
                                <option value="<?= $st['station_id'] ?>" <?= $st['station_id']==$v['polling_station_id']?'selected':'' ?>>
                                    <?= htmlspecialchars($st['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <select class="edit-select" id="ebo-<?= $vid ?>" style="min-width:120px;">
                                <option value="">— Booth —</option>
                                <?php foreach ($boothsForStation as $bo): ?>
                                <option value="<?= $bo['booth_id'] ?>" <?= $bo['booth_id']==$v['booth_id']?'selected':'' ?>>
                                    Booth <?= htmlspecialchars($bo['booth_number']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </td>
                    <td></td>
                    <td>
                        <div class="action-row">
                            <button class="btn btn-sm btn-success" onclick="saveEdit(<?= $vid ?>)">💾 Save</button>
                            <button class="btn btn-sm btn-secondary" onclick="cancelEdit(<?= $vid ?>)">✕ Cancel</button>
                        </div>
                    </td>
                </tr>

                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- PAGINATION -->
        <div class="pagination-bar">
            <div>Showing <strong><?= count($voters) ?></strong> voter records<?= ($search||$filter_con||$filter_voted!=='') ? ' (filtered)' : '' ?></div>
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
        <div class="modal-title">Delete Voter?</div>
        <div class="modal-body-txt">
            Are you sure you want to remove <strong id="deleteVoterName">this voter</strong>?<br>
            <span id="deleteWarningExtra"></span>
            This action <strong>cannot be undone.</strong>
        </div>
        <div class="modal-actions">
            <button class="btn btn-danger" id="confirmDeleteBtn">🗑️ Confirm Delete</button>
            <button class="btn btn-secondary" onclick="closeDeleteModal()">✕ Cancel</button>
        </div>
    </div>
</div>

<!-- ═══ FOOTER ═══ -->
<footer class="footer">
    <div>🗳️ EMS Admin &nbsp;·&nbsp; Voter Management &nbsp;·&nbsp; © 2026 Bangladesh Election Commission. All rights reserved.</div>
    <div class="footer-links">
        <a href="#">Privacy Policy</a>
        <a href="#">Security Audit</a>
        <a href="#">Help &amp; Documentation</a>
        <a href="#">Contact Support</a>
    </div>
</footer>

<div id="toast"></div>

<script>
// PHP data for cascade dropdowns
const ALL_STATIONS = <?= json_encode(array_values($all_stations)) ?>;
const ALL_BOOTHS   = <?= json_encode(array_values($all_booths)) ?>;

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

// ── RESET ADD FORM dropdowns ─────────────────────────────────
function resetAddForm() {
    const st = document.getElementById('add-station');
    const bo = document.getElementById('add-booth');
    st.innerHTML = '<option value="">— Select Constituency First —</option>';
    st.disabled = true;
    bo.innerHTML = '<option value="">— Select Station First —</option>';
    bo.disabled = true;
}

// ── CASCADE: ADD FORM ─────────────────────────────────────────
function cascadeStations(conId, stId, boId) {
    const stSel = document.getElementById(stId);
    const boSel = document.getElementById(boId);
    stSel.innerHTML = '<option value="">— Select Station —</option>';
    boSel.innerHTML = '<option value="">— Select Station First —</option>';
    boSel.disabled = true;
    if (!conId) { stSel.disabled = true; return; }
    const filtered = ALL_STATIONS.filter(s => s.constituency_id == conId);
    filtered.forEach(s => {
        const opt = document.createElement('option');
        opt.value = s.station_id;
        opt.textContent = s.name;
        stSel.appendChild(opt);
    });
    stSel.disabled = false;
}
function cascadeBooths(stId, boId) {
    const boSel = document.getElementById(boId);
    boSel.innerHTML = '<option value="">— Select Booth —</option>';
    if (!stId) { boSel.disabled = true; return; }
    const filtered = ALL_BOOTHS.filter(b => b.station_id == stId);
    filtered.forEach(b => {
        const opt = document.createElement('option');
        opt.value = b.booth_id;
        opt.textContent = 'Booth ' + b.booth_number;
        boSel.appendChild(opt);
    });
    boSel.disabled = filtered.length === 0;
}

// ── CASCADE: EDIT ROW ─────────────────────────────────────────
function editCascadeStations(vid) {
    const conId = document.getElementById('econ-' + vid).value;
    const stSel = document.getElementById('est-'  + vid);
    const boSel = document.getElementById('ebo-'  + vid);
    stSel.innerHTML = '<option value="">— Station —</option>';
    boSel.innerHTML = '<option value="">— Booth —</option>';
    if (!conId) return;
    ALL_STATIONS.filter(s => s.constituency_id == conId).forEach(s => {
        const opt = document.createElement('option');
        opt.value = s.station_id; opt.textContent = s.name;
        stSel.appendChild(opt);
    });
}
function editCascadeBooths(vid) {
    const stId  = document.getElementById('est-' + vid).value;
    const boSel = document.getElementById('ebo-' + vid);
    boSel.innerHTML = '<option value="">— Booth —</option>';
    if (!stId) return;
    ALL_BOOTHS.filter(b => b.station_id == stId).forEach(b => {
        const opt = document.createElement('option');
        opt.value = b.booth_id; opt.textContent = 'Booth ' + b.booth_number;
        boSel.appendChild(opt);
    });
}

// ── INLINE EDIT ───────────────────────────────────────────────
function startEdit(vid) {
    document.getElementById('row-'     + vid).style.display = 'none';
    document.getElementById('editrow-' + vid).style.display = 'table-row';
}
function cancelEdit(vid) {
    document.getElementById('editrow-' + vid).style.display = 'none';
    document.getElementById('row-'     + vid).style.display = 'table-row';
}
function saveEdit(vid) {
    const name  = document.getElementById('en-'  + vid).value.trim();
    const vcode = document.getElementById('evc-' + vid).value.trim();
    const nid   = document.getElementById('enid-'+ vid).value.trim();
    const con   = document.getElementById('econ-'+ vid).value;
    const st    = document.getElementById('est-' + vid).value;
    const bo    = document.getElementById('ebo-' + vid).value;

    if (!name || !nid || !vcode) {
        showToast('Full name, National ID and Voter Code are required.','warning');
        return;
    }

    const fd = new FormData();
    fd.append('ajax_action',        'update');
    fd.append('voter_id',            vid);
    fd.append('full_name',           name);
    fd.append('national_id',         nid);
    fd.append('voter_code',          vcode);
    fd.append('constituency_id',     con);
    fd.append('polling_station_id',  st);
    fd.append('booth_id',            bo);

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

function confirmDelete(vid, name, hasVoted) {
    pendingDeleteId = vid;
    document.getElementById('deleteVoterName').textContent = name;
    const extra = document.getElementById('deleteWarningExtra');
    if (hasVoted) {
        extra.innerHTML = '<strong style="color:var(--danger);">⚠️ This voter has already cast a ballot — deletion is blocked.</strong><br>';
    } else {
        extra.innerHTML = '';
    }
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
    fd.append('voter_id', pendingDeleteId);
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
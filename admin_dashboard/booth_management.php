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
//  BALLOT CAPACITY HELPER
//  Returns how many ballots are already allocated to a station
//  excluding a specific booth_id (for update scenario)
// ============================================================
function getStationAllocated(PDO $pdo, int $station_id, int $exclude_booth_id = 0): int {
    $stmt = $pdo->prepare(
        "SELECT IFNULL(SUM(ballots_issued),0) FROM polling_booths
         WHERE station_id=? AND booth_id != ?"
    );
    $stmt->execute([$station_id, $exclude_booth_id]);
    return (int)$stmt->fetchColumn();
}

function getStationCapacity(PDO $pdo, int $station_id): int {
    $stmt = $pdo->prepare("SELECT IFNULL(total_ballots_issued,0) FROM polling_stations WHERE station_id=?");
    $stmt->execute([$station_id]);
    return (int)$stmt->fetchColumn();
}

// ============================================================
//  AJAX HANDLERS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $act = $_POST['ajax_action'];

    // ── GET STATION CAPACITY INFO (for form JS validation) ──
    if ($act === 'get_station_capacity') {
        $sid         = (int)($_POST['station_id'] ?? 0);
        $exclude_bid = (int)($_POST['exclude_booth_id'] ?? 0);
        $capacity    = getStationCapacity($pdo, $sid);
        $allocated   = getStationAllocated($pdo, $sid, $exclude_bid);
        $remaining   = $capacity - $allocated;
        echo json_encode([
            'success'   => true,
            'capacity'  => $capacity,
            'allocated' => $allocated,
            'remaining' => max(0, $remaining),
        ]);
        exit;
    }

    // ── DELETE BOOTH ─────────────────────────────────────────
    if ($act === 'delete') {
        $id = (int)($_POST['booth_id'] ?? 0);
        // Check if booth has locked results
        $chk = $pdo->prepare("SELECT COUNT(*) FROM booth_results WHERE booth_id=? AND is_locked=1");
        $chk->execute([$id]);
        if ((int)$chk->fetchColumn() > 0) {
            echo json_encode(['success'=>false,'message'=>'Cannot delete: booth has locked vote results. Results must be cleared first.']);
            exit;
        }
        try {
            // Delete booth_results first (unlocked ones)
            $pdo->prepare("DELETE FROM booth_results WHERE booth_id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM polling_booths WHERE booth_id=?")->execute([$id]);
            $pdo->prepare("INSERT INTO audit_logs (officer_id,action_type,affected_entity,affected_entity_id,details,ip_address) VALUES (?,?,?,?,?,?)")
                ->execute([$logged_in_id,'DELETE_BOOTH','polling_booths',$id,"Deleted polling booth ID $id",$_SERVER['REMOTE_ADDR']??'']);
            echo json_encode(['success'=>true,'message'=>'Booth deleted successfully.']);
        } catch (PDOException $e) {
            echo json_encode(['success'=>false,'message'=>'Error: '.$e->getMessage()]);
        }
        exit;
    }

    // ── UPDATE BOOTH ─────────────────────────────────────────
    if ($act === 'update') {
        $id           = (int)($_POST['booth_id']??0);
        $station_id   = (int)($_POST['station_id']??0);
        $booth_number = trim($_POST['booth_number']??'');
        $apo_id       = (int)($_POST['apo_id']??0) ?: null;
        $ballots      = (int)($_POST['ballots_issued']??0);

        if (!$booth_number || !$station_id) {
            echo json_encode(['success'=>false,'message'=>'Booth number and station are required.']); exit;
        }
        if ($ballots < 0) {
            echo json_encode(['success'=>false,'message'=>'Ballots issued cannot be negative.']); exit;
        }

        // Ballot capacity check
        $capacity  = getStationCapacity($pdo, $station_id);
        $allocated = getStationAllocated($pdo, $station_id, $id); // exclude self
        if ($capacity > 0 && ($allocated + $ballots) > $capacity) {
            $remaining = $capacity - $allocated;
            echo json_encode([
                'success' => false,
                'message' => "⚠️ Ballot limit exceeded! Station capacity: {$capacity}. Already allocated to other booths: {$allocated}. Max you can set for this booth: {$remaining}."
            ]);
            exit;
        }

         // === APO UNIQUENESS CONSTRAINT (exclude the booth being edited) ===
        if ($apo_id) {
            $chk = $pdo->prepare("SELECT booth_id FROM polling_booths WHERE assistant_presiding_officer_id=? AND booth_id != ?");
            $chk->execute([$apo_id, $id]);
            if ($chk->fetch()) {
                echo json_encode(['success'=>false,'message'=>'This APO is already assigned to another booth. Each APO can only be assigned to one polling booth.']); exit;
            }
        }

        try {
            $pdo->prepare("UPDATE polling_booths SET station_id=?,booth_number=?,assistant_presiding_officer_id=?,ballots_issued=? WHERE booth_id=?")
                ->execute([$station_id,$booth_number,$apo_id,$ballots,$id]);
            $pdo->prepare("INSERT INTO audit_logs (officer_id,action_type,affected_entity,affected_entity_id,details,ip_address) VALUES (?,?,?,?,?,?)")
                ->execute([$logged_in_id,'UPDATE_BOOTH','polling_booths',$id,"Updated booth '$booth_number' (ID $id)",$_SERVER['REMOTE_ADDR']??'']);
            echo json_encode(['success'=>true,'message'=>"Booth '$booth_number' updated successfully."]);
        } catch (PDOException $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action.']); exit;
}

// ============================================================
//  CREATE NEW BOOTH (form POST)
// ============================================================
$form_success = $form_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_booth'])) {
    $station_id   = (int)($_POST['station_id']??0);
    $booth_number = trim($_POST['booth_number']??'');
    $apo_id       = (int)($_POST['apo_id']??0) ?: null;
    $ballots      = (int)($_POST['ballots_issued']??0);

    if (!$booth_number || !$station_id) {
        $form_error = 'Booth number and polling station are required.';
    } elseif ($ballots < 0) {
        $form_error = 'Ballots issued cannot be negative.';
    } else {
        // Ballot capacity check
        $capacity  = getStationCapacity($pdo, $station_id);
        $allocated = getStationAllocated($pdo, $station_id, 0);
        if ($capacity > 0 && ($allocated + $ballots) > $capacity) {
            $remaining = $capacity - $allocated;
            $form_error = "⚠️ Ballot limit exceeded! Station capacity: {$capacity}. Already allocated: {$allocated}. Maximum you can issue for a new booth: {$remaining}.";
        } else {
            // Check duplicate booth_number in same station
            $dup = $pdo->prepare("SELECT COUNT(*) FROM polling_booths WHERE station_id=? AND booth_number=?");
            $dup->execute([$station_id, $booth_number]);
            if ((int)$dup->fetchColumn() > 0) {
                $form_error = "Booth number '$booth_number' already exists in this polling station.";
            } else {
                // === APO UNIQUENESS CONSTRAINT ===
                if ($apo_id) {
                    $chk = $pdo->prepare("SELECT booth_id FROM polling_booths WHERE assistant_presiding_officer_id=?");
                    $chk->execute([$apo_id]);
                    if ($chk->fetch()) {
                        $form_error = 'This APO is already assigned to another booth. Each APO can only be assigned to one polling booth.';
                    }
                }

                if (!$form_error) {
                    try {
                        $pdo->prepare("INSERT INTO polling_booths (station_id,booth_number,assistant_presiding_officer_id,ballots_issued) VALUES (?,?,?,?)")
                            ->execute([$station_id,$booth_number,$apo_id,$ballots]);
                        $new_id = $pdo->lastInsertId();
                        $pdo->prepare("INSERT INTO audit_logs (officer_id,action_type,affected_entity,affected_entity_id,details,ip_address) VALUES (?,?,?,?,?,?)")
                            ->execute([$logged_in_id,'CREATE_BOOTH','polling_booths',$new_id,"Created booth '$booth_number' at station ID $station_id",$_SERVER['REMOTE_ADDR']??'']);
                        $form_success = "Polling Booth <strong>$booth_number</strong> created successfully.";
                    } catch (PDOException $e) {
                        $form_error = 'Error: '.$e->getMessage();
                    }
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
$stations = $pdo->query("
    SELECT ps.station_id, ps.name, ps.total_ballots_issued, c.name AS con_name, c.code AS con_code
    FROM polling_stations ps
    LEFT JOIN constituencies c ON c.constituency_id=ps.constituency_id
    ORDER BY ps.name
")->fetchAll();

$apos = $pdo->query("
    SELECT officer_id, full_name FROM election_officers
    WHERE role='APO' AND is_active=1 ORDER BY full_name
")->fetchAll();

// APOs already assigned to a booth: { officer_id => booth_id }
$taken_apo_map = $pdo->query("SELECT assistant_presiding_officer_id, booth_id FROM polling_booths WHERE assistant_presiding_officer_id IS NOT NULL")->fetchAll(PDO::FETCH_KEY_PAIR);
$taken_apo_ids = array_map('intval', array_keys($taken_apo_map));
$js_taken_apo_map = json_encode(array_map('intval', $taken_apo_map));

// Search / filter
$search        = trim($_GET['q']??'');
$filter_station= (int)($_GET['station']??0);

$sqlBase = "
    SELECT pb.*,
           ps.name AS station_name, ps.total_ballots_issued AS station_capacity,
           ps.constituency_id,
           c.name AS constituency_name, c.code AS constituency_code,
           eo.full_name AS apo_name,
           IFNULL((SELECT SUM(br.votes_received) FROM booth_results br WHERE br.booth_id=pb.booth_id),0) AS total_votes,
           IFNULL((SELECT MAX(br.is_locked) FROM booth_results br WHERE br.booth_id=pb.booth_id),0) AS is_locked,
           IFNULL((SELECT COUNT(*) FROM booth_results br WHERE br.booth_id=pb.booth_id),0) AS result_count
    FROM polling_booths pb
    LEFT JOIN polling_stations ps ON ps.station_id=pb.station_id
    LEFT JOIN constituencies c ON c.constituency_id=ps.constituency_id
    LEFT JOIN election_officers eo ON eo.officer_id=pb.assistant_presiding_officer_id
";
$where  = [];
$params = [];
if ($search !== '') {
    $where[]  = "(pb.booth_number LIKE ? OR ps.name LIKE ? OR eo.full_name LIKE ?)";
    $params[] = '%'.$search.'%';
    $params[] = '%'.$search.'%';
    $params[] = '%'.$search.'%';
}
if ($filter_station > 0) {
    $where[]  = "pb.station_id=?";
    $params[] = $filter_station;
}
$sqlFull = $sqlBase.($where ? " WHERE ".implode(" AND ",$where) : "")." ORDER BY pb.station_id, pb.booth_id";
$bStmt   = $pdo->prepare($sqlFull);
$bStmt->execute($params);
$booths  = $bStmt->fetchAll();

// Stats
$total_booths   = (int)$pdo->query("SELECT COUNT(*) FROM polling_booths")->fetchColumn();
$total_ballots  = (int)$pdo->query("SELECT IFNULL(SUM(ballots_issued),0) FROM polling_booths")->fetchColumn();
$locked_booths  = (int)$pdo->query("SELECT COUNT(DISTINCT booth_id) FROM booth_results WHERE is_locked=1")->fetchColumn();
$total_votes    = (int)$pdo->query("SELECT IFNULL(SUM(votes_received),0) FROM booth_results WHERE is_locked=1")->fetchColumn();
$apo_assigned   = (int)$pdo->query("SELECT COUNT(*) FROM polling_booths WHERE assistant_presiding_officer_id IS NOT NULL")->fetchColumn();

// Station capacity map for JS (station_id => [capacity, allocated])
$capMap = [];
foreach ($stations as $s) {
    $sid = (int)$s['station_id'];
    $allocStmt = $pdo->prepare("SELECT IFNULL(SUM(ballots_issued),0) FROM polling_booths WHERE station_id=?");
    $allocStmt->execute([$sid]);
    $capMap[$sid] = [
        'capacity'  => (int)$s['total_ballots_issued'],
        'allocated' => (int)$allocStmt->fetchColumn(),
        'name'      => $s['name'],
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Booth Management — EMS Admin</title>
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

/* TOPBAR */
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

/* SUB NAV */
.sub-nav{background:var(--surface);border-bottom:2px solid var(--border);padding:0 32px;display:flex;align-items:center;gap:2px;overflow-x:auto;position:sticky;top:66px;z-index:100;box-shadow:0 1px 8px rgba(10,22,40,.06);}
.sub-nav-link{display:flex;align-items:center;gap:6px;padding:14px 16px;font-size:12.5px;font-weight:600;color:var(--muted);border-bottom:2px solid transparent;margin-bottom:-2px;white-space:nowrap;transition:all .18s;}
.sub-nav-link:hover{color:var(--primary);}
.sub-nav-link.active{color:var(--primary);border-bottom-color:var(--primary);background:rgba(26,86,219,.04);}

/* PAGE WRAP */
.page-wrap{max-width:1380px;margin:0 auto;padding:28px 28px 56px;width:100%;flex:1;}

/* PAGE HEADER */
.page-header{display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:14px;}
.page-header-left{display:flex;align-items:center;gap:16px;}
.page-header-icon{width:52px;height:52px;background:linear-gradient(135deg,var(--accent),var(--primary));border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:24px;box-shadow:0 4px 16px rgba(14,165,233,.3);flex-shrink:0;}
.page-title{font-family:var(--font-head);font-size:26px;font-weight:800;color:var(--navy);line-height:1.1;letter-spacing:-.3px;}
.page-subtitle{font-size:13px;color:var(--muted);margin-top:4px;}
.breadcrumb{font-size:11.5px;color:var(--muted2);margin-bottom:4px;}
.breadcrumb a{color:var(--primary);font-weight:600;}
.breadcrumb a:hover{text-decoration:underline;}
.header-actions{display:flex;gap:10px;flex-wrap:wrap;}

/* BUTTONS */
.btn{display:inline-flex;align-items:center;gap:7px;font-size:13px;font-weight:600;padding:9px 18px;border-radius:var(--radius-sm);border:none;transition:all .2s;cursor:pointer;white-space:nowrap;font-family:var(--font-body);letter-spacing:.2px;}
.btn-primary{background:linear-gradient(135deg,var(--primary),var(--primary2));color:#fff;box-shadow:0 2px 10px rgba(26,86,219,.25);}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 4px 18px rgba(26,86,219,.35);}
.btn-teal{background:linear-gradient(135deg,var(--teal),var(--accent));color:#fff;box-shadow:0 2px 10px rgba(14,165,233,.2);}
.btn-teal:hover{transform:translateY(-1px);box-shadow:0 4px 18px rgba(14,165,233,.3);}
.btn-secondary{background:var(--surface);color:var(--text2);border:1.5px solid var(--border);}
.btn-secondary:hover{border-color:var(--primary);color:var(--primary);background:#eff6ff;}
.btn-danger{background:rgba(239,68,68,.08);color:var(--danger);border:1.5px solid rgba(239,68,68,.25);}
.btn-danger:hover{background:var(--danger);color:#fff;}
.btn-success{background:rgba(16,185,129,.1);color:var(--success);border:1.5px solid rgba(16,185,129,.3);}
.btn-success:hover{background:var(--success);color:#fff;}
.btn-sm{font-size:12px;padding:6px 13px;}
.btn-xs{font-size:11px;padding:4px 10px;border-radius:6px;}

/* STAT STRIP */
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
.st-icon.teal{background:#ccfbf1;}
.st-icon.red{background:#fee2e2;}
.st-value{font-family:var(--font-mono);font-size:26px;font-weight:600;letter-spacing:-.5px;line-height:1;margin-bottom:5px;}
.st-value.blue{color:var(--primary);}
.st-value.green{color:var(--success);}
.st-value.gold{color:var(--gold);}
.st-value.teal{color:var(--teal);}
.st-value.red{color:var(--danger);}
.st-label{font-size:11.5px;font-weight:600;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);}
.st-delta{position:absolute;top:16px;right:16px;font-size:11px;font-weight:600;padding:3px 9px;border-radius:20px;}
.st-delta.up{background:#dcfce7;color:var(--success);}
.st-delta.info{background:#dbeafe;color:var(--primary);}
.st-delta.teal{background:#ccfbf1;color:var(--teal);}
.st-delta.gold{background:#fef3c7;color:var(--gold);}
.st-delta.red{background:#fee2e2;color:var(--danger);}

/* CARDS */
.card{background:var(--surface);border-radius:var(--radius);border:1.5px solid var(--border);overflow:hidden;box-shadow:var(--shadow);margin-bottom:22px;}
.card-header{padding:18px 24px 14px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border);background:#fafbfc;flex-wrap:wrap;gap:10px;}
.card-title{font-family:var(--font-head);font-size:14.5px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px;}
.card-body{padding:22px 24px;}
.count-pill{display:inline-flex;align-items:center;justify-content:center;background:var(--bg2);border:1px solid var(--border);border-radius:20px;font-size:12px;font-weight:700;color:var(--text2);padding:2px 10px;font-family:var(--font-mono);}

/* FORM */
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
.form-helper{font-size:11.5px;color:var(--muted);margin-top:16px;padding:10px 14px;background:#f0fdf9;border-radius:var(--radius-sm);border-left:3px solid var(--teal);}

/* Capacity indicator bar in form */
.capacity-bar-wrap{margin-top:8px;}
.capacity-bar-label{display:flex;justify-content:space-between;font-size:11px;color:var(--muted);margin-bottom:4px;}
.capacity-bar{height:6px;background:var(--bg2);border-radius:99px;overflow:hidden;}
.capacity-bar-fill{height:100%;border-radius:99px;transition:width .3s,background .3s;}
.cap-ok{background:var(--teal);}
.cap-warn{background:var(--warning);}
.cap-over{background:var(--danger);}
.capacity-info-box{padding:8px 12px;border-radius:7px;font-size:12px;font-weight:600;margin-top:6px;display:flex;align-items:center;gap:7px;}
.cap-info-ok{background:#f0fdf9;border:1px solid #99f6e4;color:#0f766e;}
.cap-info-warn{background:#fffbeb;border:1px solid #fde68a;color:#92400e;}
.cap-info-over{background:#fef2f2;border:1px solid #fca5a5;color:#991b1b;}

/* FILTER BAR */
.filter-bar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.search-input-wrap{position:relative;flex:1;min-width:220px;}
.search-input-wrap .si{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--muted2);font-size:14px;pointer-events:none;}
.search-input-wrap input{width:100%;padding:10px 14px 10px 38px;border:1.5px solid var(--border);border-radius:var(--radius-sm);font-size:13.5px;font-family:var(--font-body);color:var(--text);background:var(--surface);outline:none;transition:border-color .18s,box-shadow .18s;}
.search-input-wrap input:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(26,86,219,.10);}
.filter-select{padding:10px 14px;border:1.5px solid var(--border);border-radius:var(--radius-sm);font-size:13.5px;font-family:var(--font-body);color:var(--text);background:var(--surface);outline:none;min-width:200px;transition:border-color .18s;}
.filter-select:focus{border-color:var(--primary);}

/* TABLE */
.table-wrap{overflow-x:auto;}
.mgmt-table{width:100%;border-collapse:collapse;font-size:13.5px;}
.mgmt-table thead tr{background:#f1f5f9;}
.mgmt-table th{padding:11px 16px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);border-bottom:2px solid var(--border);white-space:nowrap;}
.mgmt-table td{padding:13px 16px;border-bottom:1px solid var(--border);vertical-align:middle;}
.mgmt-table tbody tr{transition:background .15s;}
.mgmt-table tbody tr:hover{background:#f8fafc;}
.mgmt-table tbody tr:last-child td{border-bottom:none;}

/* Edit row */
.editing-row{background:#eff6ff !important;outline:2px solid var(--primary);outline-offset:-1px;}
.editing-row td{background:#eff6ff;}
.edit-input{padding:7px 10px;border:1.5px solid var(--primary);border-radius:6px;font-size:13px;font-family:var(--font-body);color:var(--text);width:100%;outline:none;min-width:80px;box-shadow:0 0 0 2px rgba(26,86,219,.12);}
.edit-select{padding:7px 10px;border:1.5px solid var(--primary);border-radius:6px;font-size:13px;font-family:var(--font-body);color:var(--text);outline:none;min-width:140px;box-shadow:0 0 0 2px rgba(26,86,219,.12);background:#fff;}

/* Cell styles */
.cell-main{font-weight:700;color:var(--text);font-size:13.5px;}
.cell-sub{font-size:11px;color:var(--muted);margin-top:2px;}
.id-badge{font-family:var(--font-mono);font-size:12px;font-weight:600;background:var(--bg2);border:1px solid var(--border);border-radius:6px;padding:3px 8px;color:var(--text2);}
.station-tag{font-size:11.5px;font-weight:700;background:#dbeafe;color:var(--primary);border-radius:6px;padding:3px 9px;letter-spacing:.3px;}
.num-cell{font-family:var(--font-mono);font-size:14px;font-weight:600;color:var(--text);letter-spacing:-.3px;}
.num-sub{font-size:10.5px;color:var(--muted);margin-top:1px;font-family:var(--font-body);}
.action-row{display:flex;align-items:center;gap:6px;flex-wrap:wrap;}

/* Capacity bar in table */
.tbl-cap-wrap{min-width:120px;}
.tbl-cap-bar{height:5px;background:var(--bg2);border-radius:99px;overflow:hidden;margin-top:4px;}
.tbl-cap-fill{height:100%;border-radius:99px;}

/* Status pills */
.status-pill{display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border-radius:20px;font-size:11.5px;font-weight:700;letter-spacing:.3px;white-space:nowrap;}
.sp-locked{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}
.sp-active{background:#dcfce7;color:#166534;border:1px solid #86efac;}
.sp-empty{background:#f1f5f9;color:#64748b;border:1px solid #cbd5e1;}

/* Inline capacity warning in edit row */
.edit-cap-info{font-size:11px;margin-top:4px;padding:5px 9px;border-radius:6px;font-weight:600;}
.edit-cap-ok{background:#f0fdf9;color:#0f766e;}
.edit-cap-warn{background:#fef2f2;color:#991b1b;}

/* PAGINATION */
.pagination-bar{display:flex;align-items:center;justify-content:space-between;padding:14px 24px;border-top:1px solid var(--border);font-size:12.5px;color:var(--muted);flex-wrap:wrap;gap:10px;}
.pagination{display:flex;gap:4px;}
.pag-btn{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:600;cursor:pointer;background:var(--surface);border:1.5px solid var(--border);color:var(--text2);transition:all .18s;}
.pag-btn:hover{border-color:var(--primary);color:var(--primary);background:#eff6ff;}
.pag-btn.active{background:var(--primary);color:#fff;border-color:var(--primary);}
.pag-btn.disabled{opacity:.4;pointer-events:none;}

/* DELETE MODAL */
.modal-overlay{position:fixed;inset:0;background:rgba(10,22,40,.55);backdrop-filter:blur(3px);z-index:500;display:none;align-items:center;justify-content:center;}
.modal-overlay.open{display:flex;}
.modal-box{background:var(--surface);border-radius:18px;padding:32px 28px;max-width:420px;width:90%;box-shadow:var(--shadow-lg);animation:popIn .22s ease;}
@keyframes popIn{from{opacity:0;transform:scale(.93);}to{opacity:1;transform:scale(1);}}
.modal-icon{font-size:44px;text-align:center;margin-bottom:14px;}
.modal-title{font-family:var(--font-head);font-size:18px;font-weight:800;color:var(--text);text-align:center;margin-bottom:10px;}
.modal-body-txt{font-size:13.5px;color:var(--muted);text-align:center;line-height:1.6;margin-bottom:24px;}
.modal-actions{display:flex;gap:10px;justify-content:center;}

/* TOAST */
#toast{position:fixed;bottom:28px;right:28px;z-index:999;display:flex;flex-direction:column;gap:8px;}
.toast-msg{background:#1e293b;color:#fff;border-radius:10px;padding:12px 20px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:10px;box-shadow:0 4px 20px rgba(0,0,0,.2);animation:slideIn .3s ease;}
.toast-msg.success{background:#166534;}
.toast-msg.error{background:#991b1b;}
.toast-msg.warning{background:#92400e;}
@keyframes slideIn{from{transform:translateY(20px);opacity:0;}to{transform:translateY(0);opacity:1;}}

/* ALERTS */
.alert{padding:12px 16px;border-radius:var(--radius-sm);font-size:13.5px;font-weight:500;margin-bottom:18px;display:flex;align-items:center;gap:10px;}
.alert-success{background:#dcfce7;color:#166534;border:1px solid #86efac;}
.alert-error{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}

/* FOOTER */
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
    <a class="sub-nav-link active" href="booth_management.php">🚪 Booth Management</a>
    <a class="sub-nav-link" href="candidate_management.php">🎖️ Candidates</a>
    <a class="sub-nav-link" href="party_management.php">🏳️ Political Parties</a>
    <a class="sub-nav-link" href="officer_management.php">👮 Officers</a>
    <a class="sub-nav-link " href="voter_management.php">🧑‍🤝‍🧑 Voters</a>
</nav>

<div class="page-wrap">

    <!-- PAGE HEADER -->
    <div class="page-header">
        <div class="page-header-left">
            <div class="page-header-icon">🚪</div>
            <div>
                <div class="breadcrumb"><a href="admin_dashboard.php">Dashboard</a> / Booth Management</div>
                <div class="page-title">Polling Booth Management</div>
                <div class="page-subtitle">Manage polling booths, APO assignments, ballot issuance, and station capacity limits.</div>
            </div>
        </div>
        <div class="header-actions">
            <button class="btn btn-teal" onclick="toggleForm()">➕ Add Booth</button>
            <button class="btn btn-secondary" onclick="location.reload()">🔄 Refresh</button>
            <a href="admin_dashboard.php" class="btn btn-secondary">← Dashboard</a>
        </div>
    </div>

    <!-- STAT STRIP -->
    <div class="stat-strip">
        <div class="stat-tile">
            <div class="st-delta info">Total</div>
            <div class="st-icon blue">🚪</div>
            <div class="st-value blue"><?= number_format($total_booths) ?></div>
            <div class="st-label">Polling Booths</div>
        </div>
        <div class="stat-tile">
            <div class="st-delta gold">Issued</div>
            <div class="st-icon gold">🗳️</div>
            <div class="st-value gold"><?= number_format($total_ballots) ?></div>
            <div class="st-label">Total Ballots</div>
        </div>
        <div class="stat-tile">
            <div class="st-delta up">Verified</div>
            <div class="st-icon green">🔒</div>
            <div class="st-value green"><?= number_format($locked_booths) ?></div>
            <div class="st-label">Booths Locked</div>
        </div>
        <div class="stat-tile">
            <div class="st-delta teal">Assigned</div>
            <div class="st-icon teal">👮</div>
            <div class="st-value teal"><?= number_format($apo_assigned) ?></div>
            <div class="st-label">APOs Assigned</div>
        </div>
    </div>

    <!-- ALERTS -->
    <?php if ($form_success): ?>
    <div class="alert alert-success">✅ <?= $form_success ?></div>
    <?php endif; ?>
    <?php if ($form_error): ?>
    <div class="alert alert-error">❌ <?= htmlspecialchars($form_error) ?></div>
    <?php endif; ?>

    <!-- ADD BOOTH FORM -->
    <div class="card" id="addFormCard" style="display:none; border-color:var(--teal);">
        <div class="card-header">
            <span class="card-title">🚪 Add New Polling Booth</span>
            <button class="btn btn-sm btn-secondary" onclick="toggleForm()">✕ Close</button>
        </div>
        <div class="card-body">
            <form method="POST" action="" onsubmit="return validateAddForm()">
                <div class="form-grid-4" style="margin-bottom:16px;">
                    <div class="form-group">
                        <label class="form-label">Polling Station <span class="req">*</span></label>
                        <select name="station_id" id="addStation" class="form-control" required onchange="updateAddCapacity()">
                            <option value="">— Select Station —</option>
                            <?php foreach ($stations as $st): ?>
                            <option value="<?= $st['station_id'] ?>"
                                data-capacity="<?= (int)$st['total_ballots_issued'] ?>"
                                data-allocated="<?= $capMap[$st['station_id']]['allocated'] ?? 0 ?>">
                                <?= htmlspecialchars($st['name']) ?>
                                <?= $st['con_code'] ? '('.$st['con_code'].')' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Booth Number <span class="req">*</span></label>
                        <input type="text" name="booth_number" id="addBoothNum" class="form-control" placeholder="e.g. B1, Booth-3" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Assigned APO</label>
                        <select name="apo_id" class="form-control">
                            <option value="">— Not Assigned —</option>
                            <?php foreach ($apos as $a):
                                $is_taken = in_array((int)$a['officer_id'], $taken_apo_ids);
                            ?>
                            <option value="<?= $a['officer_id'] ?>" <?= $is_taken ? 'data-taken="1"' : '' ?>>
                                <?= htmlspecialchars($a['full_name']) ?><?= $is_taken ? ' ⚠ Already Assigned' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Ballots Issued</label>
                        <input type="number" name="ballots_issued" id="addBallots" class="form-control" placeholder="e.g. 300" min="0" oninput="updateAddCapacity()">
                        <!-- Live capacity bar -->
                        <div class="capacity-bar-wrap" id="addCapWrap" style="display:none;">
                            <div class="capacity-bar-label">
                                <span id="addCapLabel">Station capacity</span>
                                <span id="addCapPct">0%</span>
                            </div>
                            <div class="capacity-bar"><div class="capacity-bar-fill" id="addCapFill" style="width:0%"></div></div>
                            <div class="capacity-info-box cap-info-ok" id="addCapInfo">—</div>
                        </div>
                    </div>
                </div>
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <button type="submit" name="create_booth" class="btn btn-teal">💾 Save Booth</button>
                    <button type="reset" class="btn btn-secondary" onclick="resetAddForm()">↺ Reset Form</button>
                </div>
                <div class="form-helper">💡 The total ballots issued across all booths in a station <strong>cannot exceed</strong> the station's total ballot capacity. Exceeding this limit will be blocked automatically.</div>
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
                        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                               placeholder="Search by booth number, station name, or APO name…">
                    </div>
                    <select name="station" class="filter-select">
                        <option value="">All Polling Stations</option>
                        <?php foreach ($stations as $st): ?>
                        <option value="<?= $st['station_id'] ?>" <?= $filter_station==$st['station_id']?'selected':'' ?>>
                            <?= htmlspecialchars($st['name']) ?><?= $st['con_code'] ? ' ('.$st['con_code'].')' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-primary">Search</button>
                    <?php if ($search || $filter_station): ?>
                    <a href="booth_management.php" class="btn btn-secondary">✕ Clear</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- MAIN TABLE -->
    <div class="card">
        <div class="card-header">
            <span class="card-title">🚪 Polling Booth Records
                <?php if ($search || $filter_station): ?>
                <span style="font-size:12px;font-weight:400;color:var(--muted);">Filtered results</span>
                <?php endif; ?>
            </span>
            <span class="count-pill"><?= count($booths) ?> found</span>
        </div>

        <div class="table-wrap">
            <table class="mgmt-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Booth No.</th>
                        <th>Polling Station</th>
                        <th>Constituency</th>
                        <th>Assigned APO</th>
                        <th>Ballots Issued</th>
                        <th>Station Usage</th>
                        <th>Votes / Status</th>
                        <th style="min-width:190px;">Actions</th>
                    </tr>
                </thead>
                <tbody>

                <?php if (empty($booths)): ?>
                <tr><td colspan="9" style="text-align:center;padding:40px;color:var(--muted);">
                    No booths found<?= ($search||$filter_station) ? ' for this filter' : '' ?>.
                </td></tr>
                <?php endif; ?>

                <?php foreach ($booths as $b):
                    $bid      = (int)$b['booth_id'];
                    $sid      = (int)$b['station_id'];
                    $capacity = (int)$b['station_capacity'];
                    $allocated= $capMap[$sid]['allocated'] ?? 0;
                    $bal      = (int)$b['ballots_issued'];
                    $pct      = $capacity > 0 ? min(100, round($bal/$capacity*100)) : 0;
                    $barColor = $pct >= 100 ? '#ef4444' : ($pct >= 85 ? '#f59e0b' : '#14b8a6');
                    $is_locked= (int)$b['is_locked'];
                    $total_v  = (int)$b['total_votes'];
                    $res_count= (int)$b['result_count'];
                    if ($is_locked)     { $spClass='sp-locked'; $spLabel='🔒 Locked'; }
                    elseif ($res_count) { $spClass='sp-active'; $spLabel='✏️ In Progress'; }
                    else                { $spClass='sp-empty';  $spLabel='⏳ No Entry'; }
                ?>

                <!-- DISPLAY ROW -->
                <tr class="data-row" id="row-<?= $bid ?>">
                    <td><span class="id-badge">#<?= $bid ?></span></td>
                    <td>
                        <div class="cell-main">Booth <?= htmlspecialchars($b['booth_number']) ?></div>
                        <div class="cell-sub">ID: <?= $bid ?></div>
                    </td>
                    <td>
                        <div class="cell-main" style="font-size:13px;"><?= htmlspecialchars($b['station_name']??'N/A') ?></div>
                        <div class="cell-sub">Station ID: <?= $sid ?></div>
                    </td>
                    <td>
                        <?php if ($b['constituency_name']): ?>
                        <span class="station-tag"><?= htmlspecialchars($b['constituency_code']) ?></span>
                        <div class="cell-sub" style="margin-top:4px;"><?= htmlspecialchars($b['constituency_name']) ?></div>
                        <?php else: ?>
                        <span style="color:var(--muted2);font-size:12px;">— N/A —</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($b['apo_name']): ?>
                        <div class="cell-main" style="font-size:13px;"><?= htmlspecialchars($b['apo_name']) ?></div>
                        <div class="cell-sub">APO</div>
                        <?php else: ?>
                        <span style="color:var(--muted2);font-size:12px;">— Unassigned —</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="num-cell"><?= number_format($bal) ?></div>
                        <div class="num-sub">of <?= number_format($capacity) ?> capacity</div>
                    </td>
                    <td>
                        <div class="tbl-cap-wrap">
                            <div style="font-size:11px;color:var(--muted);margin-bottom:3px;"><?= $pct ?>% of station</div>
                            <div class="tbl-cap-bar">
                                <div class="tbl-cap-fill" style="width:<?= $pct ?>%;background:<?= $barColor ?>;height:100%;border-radius:99px;"></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="status-pill <?= $spClass ?>"><?= $spLabel ?></span>
                        <?php if ($total_v > 0): ?>
                        <div style="font-size:11px;color:var(--muted);margin-top:3px;font-family:var(--font-mono);"><?= number_format($total_v) ?> votes</div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="action-row">
                            <button class="btn btn-sm btn-secondary" onclick="startEdit(<?= $bid ?>)" <?= $is_locked?'title="Locked — unlock via booth results"':'' ?>>✏️ Edit</button>
                            <button class="btn btn-sm btn-danger" onclick="confirmDelete(<?= $bid ?>, '<?= htmlspecialchars(addslashes('Booth '.$b['booth_number'])) ?>')">🗑️</button>
                        </div>
                    </td>
                </tr>

                <!-- EDIT ROW -->
                <tr class="editing-row" id="editrow-<?= $bid ?>" style="display:none;">
                    <td><span class="id-badge">#<?= $bid ?></span></td>
                    <td>
                        <input class="edit-input" id="en-<?= $bid ?>" value="<?= htmlspecialchars($b['booth_number']) ?>" placeholder="Booth No." style="min-width:80px;">
                    </td>
                    <td>
                        <select class="edit-select" id="es-<?= $bid ?>" onchange="updateEditCapacity(<?= $bid ?>)">
                            <?php foreach ($stations as $st): ?>
                            <option value="<?= $st['station_id'] ?>"
                                data-capacity="<?= (int)$st['total_ballots_issued'] ?>"
                                data-allocated="<?= $capMap[$st['station_id']]['allocated'] ?? 0 ?>"
                                <?= $st['station_id']==$sid ? 'selected' : '' ?>>
                                <?= htmlspecialchars($st['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td style="color:var(--muted2);font-size:12px;" id="edit-con-<?= $bid ?>"><?= htmlspecialchars($b['constituency_name']??'—') ?></td>
                    <td>
                        <select class="edit-select" id="ea-<?= $bid ?>">
                            <option value="">— None —</option>
                            <?php foreach ($apos as $a):
                                $aid = (int)$a['officer_id'];
                                // Taken by a DIFFERENT booth (own current assignment is allowed)
                                $taken_by_other = isset($taken_apo_map[$aid]) && (int)$taken_apo_map[$aid] !== $bid;
                            ?>
                            <option value="<?= $aid ?>"
                                <?= $aid == $b['assistant_presiding_officer_id'] ? 'selected' : '' ?>
                                <?= $taken_by_other ? 'disabled' : '' ?>>
                                <?= htmlspecialchars($a['full_name']) ?><?= $taken_by_other ? ' ⚠ Already Assigned' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td colspan="2">
                        <input class="edit-input" type="number" id="eb-<?= $bid ?>"
                               value="<?= $bal ?>" min="0"
                               style="min-width:90px;"
                               data-station-id="<?= $sid ?>"
                               data-current-ballots="<?= $bal ?>"
                               oninput="updateEditCapacity(<?= $bid ?>)">
                        <div class="edit-cap-info edit-cap-ok" id="ecap-<?= $bid ?>" style="margin-top:4px;">
                            <?php
                            $rem = $capacity - ($allocated - $bal);
                            echo "Remaining capacity: ".number_format(max(0,$rem))." / ".number_format($capacity);
                            ?>
                        </div>
                    </td>
                    <td>—</td>
                    <td>
                        <div class="action-row">
                            <button class="btn btn-sm btn-success" onclick="saveEdit(<?= $bid ?>)">💾 Save</button>
                            <button class="btn btn-sm btn-secondary" onclick="cancelEdit(<?= $bid ?>)">✕ Cancel</button>
                        </div>
                    </td>
                </tr>

                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- PAGINATION -->
        <div class="pagination-bar">
            <div>Showing <strong><?= count($booths) ?></strong> booth records<?= ($search||$filter_station) ? ' (filtered)' : '' ?></div>
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
        <div class="modal-title">Delete Polling Booth?</div>
        <div class="modal-body-txt">
            Are you sure you want to remove <strong id="deleteBoothName">this booth</strong>?<br>
            Booths with <strong>locked vote results cannot be deleted</strong>. Unlocked booth results will be removed automatically. This action <strong>cannot be undone.</strong>
        </div>
        <div class="modal-actions">
            <button class="btn btn-danger" id="confirmDeleteBtn">🗑️ Confirm Delete</button>
            <button class="btn btn-secondary" onclick="closeDeleteModal()">✕ Cancel</button>
        </div>
    </div>
</div>

<!-- ═══ FOOTER ═══ -->
<footer class="footer">
    <div>🗳️ EMS Admin &nbsp;·&nbsp; Polling Booth Management &nbsp;·&nbsp; © 2026 Bangladesh Election Commission. All rights reserved.</div>
    <div class="footer-links">
        <a href="#">Privacy Policy</a>
        <a href="#">Security Audit</a>
        <a href="#">Help &amp; Documentation</a>
        <a href="#">Contact Support</a>
    </div>
</footer>

<div id="toast"></div>

<!-- Station capacity map from PHP -->
<script>

const STATION_CAP = <?= json_encode($capMap) ?>;
const TAKEN_APO_MAP = <?= $js_taken_apo_map ?>;
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
function resetAddForm() {
    document.getElementById('addCapWrap').style.display = 'none';
}

// ── ADD FORM CAPACITY LIVE CHECK ─────────────────────────────
function updateAddCapacity() {
    const stEl   = document.getElementById('addStation');
    const balEl  = document.getElementById('addBallots');
    const wrap   = document.getElementById('addCapWrap');
    const fill   = document.getElementById('addCapFill');
    const label  = document.getElementById('addCapLabel');
    const pctEl  = document.getElementById('addCapPct');
    const infoEl = document.getElementById('addCapInfo');

    const sid    = parseInt(stEl.value) || 0;
    const ballots= parseInt(balEl.value) || 0;
    if (!sid) { wrap.style.display='none'; return; }

    const cap    = STATION_CAP[sid]?.capacity  || 0;
    const alloc  = STATION_CAP[sid]?.allocated || 0;
    const newTotal = alloc + ballots;

    wrap.style.display = 'block';
    if (cap === 0) {
        fill.style.width = '0%';
        fill.className   = 'capacity-bar-fill cap-ok';
        pctEl.textContent = '—';
        label.textContent = 'No capacity limit set';
        infoEl.className  = 'capacity-info-box cap-info-ok';
        infoEl.textContent = 'ℹ️ Station has no ballot capacity limit set.';
        return;
    }

    const pct  = Math.min(100, Math.round(newTotal / cap * 100));
    const rem  = cap - alloc;
    fill.style.width = pct + '%';

    if (newTotal > cap) {
        fill.className   = 'capacity-bar-fill cap-over';
        pctEl.textContent = pct + '% ❌';
        infoEl.className  = 'capacity-info-box cap-info-over';
        infoEl.textContent = `❌ Exceeds limit! Max you can add: ${(rem).toLocaleString()} ballots (Capacity: ${cap.toLocaleString()}, Allocated: ${alloc.toLocaleString()})`;
    } else if (pct >= 85) {
        fill.className   = 'capacity-bar-fill cap-warn';
        pctEl.textContent = pct + '% ⚠️';
        infoEl.className  = 'capacity-info-box cap-info-warn';
        infoEl.textContent = `⚠️ Near limit. Remaining: ${(rem - ballots + ballots).toLocaleString()} available. Capacity: ${cap.toLocaleString()}`;
    } else {
        fill.className   = 'capacity-bar-fill cap-ok';
        pctEl.textContent = pct + '%';
        infoEl.className  = 'capacity-info-box cap-info-ok';
        infoEl.textContent = `✅ OK — Remaining after this: ${(rem - ballots).toLocaleString()} / ${cap.toLocaleString()} total capacity`;
    }
    label.textContent = `Allocated: ${alloc.toLocaleString()} + ${ballots.toLocaleString()} this booth`;
}

// ── ADD FORM SUBMIT VALIDATION ───────────────────────────────
function validateAddForm() {
     const apo = parseInt(document.querySelector('select[name="apo_id"]').value) || 0;
    if (apo && TAKEN_APO_MAP.hasOwnProperty(apo)) {
        showToast('⚠️ This APO is already assigned to another booth.', 'warning');
        return false;
    }
    const sid    = parseInt(document.getElementById('addStation').value) || 0;
    const ballots= parseInt(document.getElementById('addBallots').value) || 0;
    if (!sid) return true;
    const cap   = STATION_CAP[sid]?.capacity || 0;
    const alloc = STATION_CAP[sid]?.allocated || 0;
    if (cap > 0 && (alloc + ballots) > cap) {
        showToast(`❌ Cannot save: Total ballots (${(alloc+ballots).toLocaleString()}) would exceed station capacity (${cap.toLocaleString()}).`, 'error');
        return false;
    }
    return true;
}

// ── EDIT INLINE ──────────────────────────────────────────────
function startEdit(bid) {
    document.getElementById('row-'    + bid).style.display = 'none';
    document.getElementById('editrow-'+ bid).style.display = 'table-row';
    updateEditCapacity(bid);
}
function cancelEdit(bid) {
    document.getElementById('editrow-'+ bid).style.display = 'none';
    document.getElementById('row-'    + bid).style.display = 'table-row';
}

function updateEditCapacity(bid) {
    const stEl  = document.getElementById('es-' + bid);
    const balEl = document.getElementById('eb-' + bid);
    const infoEl= document.getElementById('ecap-' + bid);
    if (!stEl || !balEl || !infoEl) return;

    const sid     = parseInt(stEl.value) || 0;
    const ballots = parseInt(balEl.value) || 0;
    const origBal = parseInt(balEl.dataset.currentBallots) || 0;
    const cap     = STATION_CAP[sid]?.capacity || 0;
    const alloc   = STATION_CAP[sid]?.allocated || 0;
    // When editing, orig ballots of this booth are already in allocated, so subtract them
    const otherAlloc = alloc - origBal;
    const newTotal   = otherAlloc + ballots;

    if (cap === 0) {
        infoEl.className = 'edit-cap-info edit-cap-ok';
        infoEl.textContent = 'ℹ️ No capacity limit.';
        return;
    }
    if (newTotal > cap) {
        infoEl.className = 'edit-cap-info edit-cap-warn';
        infoEl.textContent = `❌ Exceeds capacity! Max: ${(cap - otherAlloc).toLocaleString()} (Cap: ${cap.toLocaleString()})`;
    } else {
        const rem = cap - newTotal;
        infoEl.className = 'edit-cap-info edit-cap-ok';
        infoEl.textContent = `✅ OK — Remaining after: ${rem.toLocaleString()} / ${cap.toLocaleString()}`;
    }
}

function saveEdit(bid) {
    const booth_number = document.getElementById('en-' + bid).value.trim();
    const station_id   = document.getElementById('es-' + bid).value;
    const apo_id       = document.getElementById('ea-' + bid).value;
    const ballots      = parseInt(document.getElementById('eb-' + bid).value) || 0;
    const origBal      = parseInt(document.getElementById('eb-' + bid).dataset.currentBallots) || 0;

    if (!booth_number || !station_id) {
        showToast('Booth number and station are required.','warning'); return;
    }

    // APO uniqueness check: taken by a different booth?
    const apoVal = parseInt(apo_id) || 0;
    if (apoVal && TAKEN_APO_MAP.hasOwnProperty(apoVal) && TAKEN_APO_MAP[apoVal] !== bid) {
        showToast('⚠️ This APO is already assigned to another booth.', 'warning');
        return;
    }

    // Client-side capacity check
    const sid       = parseInt(station_id);
    const cap       = STATION_CAP[sid]?.capacity || 0;
    const alloc     = STATION_CAP[sid]?.allocated || 0;
    const otherAlloc= alloc - origBal;
    if (cap > 0 && (otherAlloc + ballots) > cap) {
        showToast(`❌ Ballots exceed station capacity! Max allowed: ${(cap - otherAlloc).toLocaleString()}`, 'error');
        return;
    }

    const fd = new FormData();
    fd.append('ajax_action',  'update');
    fd.append('booth_id',     bid);
    fd.append('station_id',   station_id);
    fd.append('booth_number', booth_number);
    fd.append('apo_id',       apo_id);
    fd.append('ballots_issued', ballots);

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
function confirmDelete(bid, name) {
    pendingDeleteId = bid;
    document.getElementById('deleteBoothName').textContent = name;
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
    fd.append('booth_id', pendingDeleteId);
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
    if(e.target===this) closeDeleteModal();
});

<?php if ($form_error): ?>
document.getElementById('addFormCard').style.display = 'block';
<?php endif; ?>
</script>
</body>
</html>
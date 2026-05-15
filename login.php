<?php
// ============================================================
//  EMS LOGIN PROCESSOR — no HTML, pure PHP
//  Your homepage form POSTs here, this redirects to dashboard
// ============================================================

$DB_HOST = 'localhost';
$DB_NAME = 'ems';
$DB_USER = 'root';
$DB_PASS = '';

session_start();

// Already logged in — skip straight to dashboard
if (isset($_SESSION['officer_id'], $_SESSION['role'])) {
    redirectByRole($_SESSION['role']);
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: home.php');
    exit;
}

$username = trim($_POST['officer_username'] ?? '');
$password = $_POST['officer_password'] ?? '';

if ($username === '' || $password === '') {
    header('Location: home.php?error=empty');
    exit;
}

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    $stmt = $pdo->prepare("
        SELECT officer_id, full_name, role, password_hash, is_active
        FROM election_officers
        WHERE username = ?
        LIMIT 1
    ");
    $stmt->execute([$username]);
    $officer = $stmt->fetch();

    if (!$officer) {
        header('Location: home.php?error=invalid');
        exit;
    }

    if (!$officer['is_active']) {
        header('Location: home.php?error=inactive');
        exit;
    }

    // Check password — supports both plain text (seed data) and hashed
    $passwordValid = password_verify($password, $officer['password_hash'])
                     || $password === $officer['password_hash'];

    if (!$passwordValid) {
        header('Location: home.php?error=invalid');
        exit;
    }

    // Set session
    $_SESSION['officer_id'] = $officer['officer_id'];
    $_SESSION['full_name']  = $officer['full_name'];
    $_SESSION['role']       = $officer['role'];
    $_SESSION['username']   = $username;
    $_SESSION['login_time'] = time();

    // Audit log
    try {
        $log = $pdo->prepare("
            INSERT INTO audit_logs
            (officer_id, action_type, affected_entity, affected_entity_id, details, ip_address)
            VALUES (?, 'LOGIN', 'Session', ?, ?, ?)
        ");
        $log->execute([
            $officer['officer_id'],
            $officer['officer_id'],
            'Login: ' . $officer['full_name'] . ' (' . $officer['role'] . ')',
            $_SERVER['REMOTE_ADDR'] ?? ''
        ]);
    } catch (Exception $e) { /* don't block login if audit fails */ }

    redirectByRole($officer['role']);

} catch (PDOException $e) {
    header('Location: home.php?error=db');
    exit;
}

function redirectByRole($role) {
    $map = [
        'APO'   => 'apo_dashboard.php',
        'PO'    => 'po_dashboard.php',
        'ARO'   => 'aro_dashboard.php',
        'RO'    => 'ro_dashboard.php',
        'ADMIN' => 'admin_dashboard/admin_dashboard.php',
    ];
    header('Location: ' . ($map[$role] ?? 'home.php'));
    exit;
}
?>
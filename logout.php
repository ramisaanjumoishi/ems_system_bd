<?php
// ============================================================
//  LOGOUT SCRIPT — Works from any subfolder
//  Destroys session and redirects to homepage (index.php)
// ============================================================

session_start();

// Database connection (same as your other files)
$DB_HOST = 'localhost';
$DB_NAME = 'ems';
$DB_USER = 'root';
$DB_PASS = '';

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER, $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    // Log the logout action if officer is logged in
    if (isset($_SESSION['officer_id'])) {
        $officer_id = (int)$_SESSION['officer_id'];
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        
        $log = $pdo->prepare("
            INSERT INTO audit_logs (officer_id, action_type, affected_entity, affected_entity_id, details, ip_address) 
            VALUES (?, 'LOGOUT', 'Session', ?, ?, ?)
        ");
        $log->execute([$officer_id, $officer_id, "User logged out of the system", $ip]);
    }
} catch (PDOException $e) {
    // Silently fail — we still want to logout even if DB fails
}

// Clear all session variables
$_SESSION = array();

// Destroy the session cookie if it exists
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Redirect to homepage (index.php in the root directory)
// Using absolute path from root
header('Location: /ems_327/home.php');
exit;
?>
<?php
// config.php
$host     = 'localhost';
$dbname   = 'JJ_Stores';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false
    ]);
} catch (PDOException $e) {
    die("Database Connection Failed: " . $e->getMessage());
}

// Start session safely if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
function checkLogin() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: ../login.php");
        exit();
    }
}

// Restrict access based on user role (Supports single string or array of allowed roles)
function restrictTo($allowedRoles) {
    checkLogin();
    
    $userRole = $_SESSION['role'] ?? '';

    // Convert string to array for standard checking
    if (!is_array($allowedRoles)) {
        $allowedRoles = [$allowedRoles];
    }

    // Case-insensitive check for roles
    $allowedRolesLower = array_map('strtolower', $allowedRoles);
    
    if (!in_array(strtolower($userRole), $allowedRolesLower)) {
        header("Location: ../login.php?error=unauthorized");
        exit();
    }
}

// Log actions to the audit_logs table (Supports store_id)
function logActivity($pdo, $action, $details, $store_id = null) {
    $user_id = $_SESSION['user_id'] ?? null;
    $ip      = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    
    try {
        $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, details, ip_address, store_id) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $action, $details, $ip, $store_id]);
    } catch (PDOException $e) {
        // Fallback if store_id column does not exist in audit_logs
        try {
            $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
            $stmt->execute([$user_id, $action, $details, $ip]);
        } catch (PDOException $ex) {
            error_log("Audit Log Failure: " . $ex->getMessage());
        }
    }
}
?>
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Mark user status as Offline before clearing session
if (isset($_SESSION['user_id'])) {
    $uid = (int)$_SESSION['user_id'];
    
    // Database connection
    if (file_exists(__DIR__ . '/config.php')) {
        require_once __DIR__ . '/config.php';
    } elseif (file_exists(__DIR__ . '/../config.php')) {
        require_once __DIR__ . '/../config.php';
    }
    
    global $pdo;
    if (!$pdo instanceof PDO) {
        try {
            $pdo = new PDO("mysql:host=localhost;dbname=JJ_Stores;charset=utf8mb4", "root", "", [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
        } catch (Throwable $e) {}
    }
    
    if ($pdo instanceof PDO) {
        try {
            $pdo->prepare("UPDATE users SET status = 'Offline', last_activity = NOW() WHERE id = ?")->execute([$uid]);
        } catch (Throwable $t) {}
    }
}

// Destroy session
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();

// Redirect back to login
$redirect = file_exists(__DIR__ . '/login.php') ? 'login.php' : '../login.php';
header("Location: {$redirect}");
exit();
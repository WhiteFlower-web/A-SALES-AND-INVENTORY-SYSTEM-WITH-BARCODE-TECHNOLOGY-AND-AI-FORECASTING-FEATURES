<?php
// JJ JUDEJUSH STORES - USER PROFILE & TERMINAL SETTINGS 
session_start();
date_default_timezone_set('Asia/Manila');

// 1. ROLE AUTHORIZATION
$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$allowed_roles = [
    'sales staff', 'salesstaff', 'sales', 'cashier', 
    'inventory staff', 'inventory', 'admin', 'owner', 'administrator'
];

if (!isset($_SESSION['user_id']) || !in_array($user_role, $allowed_roles, true)) {
    if (file_exists(__DIR__ . '/login.php')) {
        header("Location: login.php?error=unauthorized_access");
    } else {
        header("Location: ../login.php?error=unauthorized_access");
    }
    exit();
}

// 2. DATABASE CONFIGURATION & CONNECTION
$configPaths = [
    __DIR__ . '/config.php',
    __DIR__ . '/../config.php',
    __DIR__ . '/../../config.php',
    __DIR__ . '/config/database.php',
    __DIR__ . '/../config/database.php',
    __DIR__ . '/../admin/config/database.php'
];

foreach ($configPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        break;
    }
}

global $pdo;
$pdo = $pdo ?? ($GLOBALS['pdo'] ?? null);

if (!$pdo instanceof PDO) {
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
        die("<div style='padding:40px; font-family:sans-serif; text-align:center; background-color:#070a12; color:white; height:100vh;'>
                <h1 style='color:#EF4444; font-weight:900;'>Database Connection Error</h1>
                <p>Unable to connect to database: " . htmlspecialchars($e->getMessage()) . "</p>
             </div>");
    }
}

try {
    $pdo->exec("SET time_zone = '+08:00'");
} catch (Throwable $t) {}

$user_id  = (int)$_SESSION['user_id'];
$store_id = isset($_GET['store_id']) ? (int)$_GET['store_id'] : (isset($_SESSION['store_id']) ? (int)$_SESSION['store_id'] : 1);
if (!in_array($store_id, [1, 2])) {
    $store_id = 1;
}
$_SESSION['store_id'] = $store_id;

// REAL-TIME HEARTBEAT ENDPOINT
if (isset($_GET['action']) && $_GET['action'] === 'heartbeat') {
    header('Content-Type: application/json');
    try {
        $target_store = isset($_GET['store_id']) ? (int)$_GET['store_id'] : $store_id;
        $pdo->prepare("UPDATE users SET status = 'Online', last_activity = NOW(), store_id = ? WHERE id = ?")
            ->execute([$target_store, $user_id]);
        echo json_encode([
            'status'   => 'success',
            'user_id'  => $user_id,
            'store_id' => $target_store,
            'time'     => date('h:i:s A')
        ]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Mark current user as Online
try {
    $pdo->prepare("UPDATE users SET status = 'Online', last_activity = NOW(), store_id = ? WHERE id = ?")
        ->execute([$store_id, $user_id]);
} catch (Throwable $t) {}

// Safe Schema Check
$users_cols_check = [
    'fullname'      => "ALTER TABLE users ADD COLUMN fullname VARCHAR(255) NULL",
    'email'         => "ALTER TABLE users ADD COLUMN email VARCHAR(255) NULL",
    'role'          => "ALTER TABLE users ADD COLUMN role VARCHAR(100) DEFAULT 'Sales Staff'",
    'store_id'      => "ALTER TABLE users ADD COLUMN store_id INT DEFAULT 1",
    'status'        => "ALTER TABLE users ADD COLUMN status VARCHAR(50) DEFAULT 'Offline'",
    'last_activity' => "ALTER TABLE users ADD COLUMN last_activity DATETIME NULL",
    'avatar'        => "ALTER TABLE users ADD COLUMN avatar VARCHAR(255) NULL",
    'profile_image' => "ALTER TABLE users ADD COLUMN profile_image VARCHAR(255) NULL"
];

foreach ($users_cols_check as $colName => $alterSql) {
    try {
        $chkCol = $pdo->query("SHOW COLUMNS FROM users LIKE '$colName'")->fetch();
        if (!$chkCol) {
            $pdo->exec($alterSql);
        }
    } catch (Throwable $t) {}
}

// Global Activity Logger
if (!function_exists('logActivity')) {
    function logActivity($pdo, $action, $details, $page_module = 'User Settings', $store_id = 1) {
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, page_module, details, ip_address, store_id, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$_SESSION['user_id'] ?? 1, $action, $page_module, $details, $ip, $store_id]);
        } catch (Throwable $t) {}
    }
}

// Asset Path Resolver
if (!function_exists('resolveAssetPath')) {
    function resolveAssetPath($path) {
        if (empty($path)) return null;
        $cleanPath = ltrim(str_replace(['\\', '/'], '/', $path), '/');
        $fileName  = basename($cleanPath);
        $candidates = [
            $cleanPath,
            'uploads/' . $fileName,
            'uploads/logo/' . $fileName,
            'uploads/products/' . $fileName,
            'uploads/avatars/' . $fileName,
            '../' . $cleanPath,
            '../uploads/logo/' . $fileName,
            '../uploads/products/' . $fileName,
            '../uploads/avatars/' . $fileName,
            'admin/' . $cleanPath,
            '../admin/' . $cleanPath
        ];
        foreach ($candidates as $candidate) {
            $full = __DIR__ . '/' . $candidate;
            if (file_exists($full) && !is_dir($full)) return $candidate;
        }
        return $cleanPath;
    }
}

// Avatar Target Directory Resolver
if (!function_exists('getAvatarUploadTargetDir')) {
    function getAvatarUploadTargetDir() {
        $candidates = [
            [
                'fs_dir'    => __DIR__ . '/uploads/avatars/',
                'url_rel'   => 'uploads/avatars/',
                'db_prefix' => 'uploads/avatars/'
            ],
            [
                'fs_dir'    => __DIR__ . '/../uploads/avatars/',
                'url_rel'   => '../uploads/avatars/',
                'db_prefix' => 'uploads/avatars/'
            ],
            [
                'fs_dir'    => __DIR__ . '/../admin/uploads/avatars/',
                'url_rel'   => '../admin/uploads/avatars/',
                'db_prefix' => 'admin/uploads/avatars/'
            ]
        ];

        foreach ($candidates as $conf) {
            $dir = $conf['fs_dir'];
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            if (is_dir($dir) && is_writable($dir)) {
                return $conf;
            }
        }

        $defaultDir = __DIR__ . '/uploads/avatars/';
        if (!is_dir($defaultDir)) {
            @mkdir($defaultDir, 0777, true);
        }
        return [
            'fs_dir'    => $defaultDir,
            'url_rel'   => 'uploads/avatars/',
            'db_prefix' => 'uploads/avatars/'
        ];
    }
}

// Avatar URL Resolver
if (!function_exists('getValidAvatarUrl')) {
    function getValidAvatarUrl($avatarPath) {
        if (empty($avatarPath)) return null;
        $cleanPath = ltrim(str_replace(['\\', '/'], '/', $avatarPath), '/');
        $fileName  = basename($cleanPath);

        $checkLocations = [
            'uploads/avatars/' . $fileName,
            '../uploads/avatars/' . $fileName,
            'admin/uploads/avatars/' . $fileName,
            '../admin/uploads/avatars/' . $fileName,
            $cleanPath,
            '../' . $cleanPath
        ];

        foreach ($checkLocations as $relPath) {
            $fullPath = __DIR__ . '/' . $relPath;
            if (file_exists($fullPath) && !is_dir($fullPath)) {
                return $relPath;
            }
        }
        return null;
    }
}

$success_msg = "";
$error_msg   = "";

// Notifications Clear Action
if (isset($_GET['action']) && $_GET['action'] === 'clear_notifications') {
    try {
        $stmtClear = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE store_id = ? OR store_id IS NULL OR store_id = 0");
        $stmtClear->execute([$store_id]);
        logActivity($pdo, 'NOTIFICATIONS_CLEAR', "Cleared notifications for store #" . $store_id, 'Settings', $store_id);
    } catch (Throwable $t) {}
    header("Location: settings.php?store_id=" . $store_id);
    exit;
}

// System Store Settings
$settings = ['store_name' => 'JJ JUDEJUSH', 'store_logo' => null];
try {
    $chkSettings = $pdo->query("SELECT * FROM settings WHERE id = 1 LIMIT 1")->fetch();
    if ($chkSettings) {
        $settings = array_merge($settings, $chkSettings);
    }
} catch (Throwable $t) {}

$resolvedLogoUrl = !empty($settings['store_logo']) ? resolveAssetPath($settings['store_logo']) : null;

// POST ACTION 1: UPLOAD AVATAR
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_avatar') {
    if (isset($_FILES['profile_avatar']) && $_FILES['profile_avatar']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath   = $_FILES['profile_avatar']['tmp_name'];
        $fileName      = $_FILES['profile_avatar']['name'];
        $fileSize      = $_FILES['profile_avatar']['size'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];

        if (in_array($fileExtension, $allowedExtensions)) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $fileTmpPath);
            finfo_close($finfo);

            $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];

            if (in_array($mimeType, $allowedMimes)) {
                if ($fileSize <= 5 * 1024 * 1024) {
                    $uploadConfig = getAvatarUploadTargetDir();
                    $targetDir    = $uploadConfig['fs_dir'];
                    $dbPathPrefix = $uploadConfig['db_prefix'];

                    $newFileName = 'avatar_' . $user_id . '_' . time() . '.' . $fileExtension;
                    $destPath    = $targetDir . $newFileName;
                    $dbRelPath   = $dbPathPrefix . $newFileName;

                    if (move_uploaded_file($fileTmpPath, $destPath)) {
                        try {
                            $stmt = $pdo->prepare("UPDATE users SET avatar = ?, profile_image = ? WHERE id = ?");
                            $stmt->execute([$dbRelPath, $dbRelPath, $user_id]);
                        } catch (Throwable $t) {
                            $stmt = $pdo->prepare("UPDATE users SET avatar = ? WHERE id = ?");
                            $stmt->execute([$dbRelPath, $user_id]);
                        }

                        $_SESSION['avatar'] = $dbRelPath;
                        logActivity($pdo, 'AVATAR_UPDATE', "User updated avatar photo", 'User Settings', $store_id);
                        $success_msg = "Profile photo updated successfully!";
                    } else {
                        $error_msg = "Failed to upload image file. Please check folder write permissions.";
                    }
                } else {
                    $error_msg = "File size exceeds the 5MB maximum limit.";
                }
            } else {
                $error_msg = "Invalid image file type.";
            }
        } else {
            $error_msg = "Invalid format. Only JPG, JPEG, PNG, and WEBP are supported.";
        }
    } else {
        $error_msg = "Please select a valid image file to upload.";
    }
}

// POST ACTION 2: UPDATE PERSONAL ACCOUNT DETAILS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $fullname = trim($_POST['fullname'] ?? '');
    $email    = trim($_POST['email'] ?? '');

    if (!empty($fullname)) {
        try {
            $stmt = $pdo->prepare("UPDATE users SET fullname = ?, email = ? WHERE id = ?");
            $stmt->execute([$fullname, $email, $user_id]);

            $_SESSION['fullname'] = $fullname;
            logActivity($pdo, 'PROFILE_UPDATE', "Updated profile: {$fullname} ({$email})", 'User Settings', $store_id);
            $success_msg = "Personal information updated successfully!";
        } catch (PDOException $e) {
            $error_msg = "Failed to update profile: " . $e->getMessage();
        }
    } else {
        $error_msg = "Full Name cannot be left blank.";
    }
}

// POST ACTION 3: CHANGE SECURITY PASSWORD
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    $current_p = $_POST['current_password'] ?? '';
    $new_p     = $_POST['new_password'] ?? '';
    $confirm_p = $_POST['confirm_password'] ?? '';

    if (strlen($new_p) < 6) {
        $error_msg = "New password must be at least 6 characters long.";
    } elseif ($new_p !== $confirm_p) {
        $error_msg = "New passwords do not match!";
    } else {
        $user_q = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $user_q->execute([$user_id]);
        $user_data = $user_q->fetch();

        if ($user_data && password_verify($current_p, $user_data['password'])) {
            $hashed = password_hash($new_p, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hashed, $user_id]);

            logActivity($pdo, 'PASSWORD_CHANGE', 'User changed login password', 'User Settings', $store_id);
            $success_msg = "Security password updated successfully!";
        } else {
            $error_msg = "Verification failed: Current password is incorrect.";
        }
    }
}

// Fetch Latest User Profile Information
$stmtUser = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmtUser->execute([$user_id]);
$currentUserData = $stmtUser->fetch() ?: [
    'fullname'      => $_SESSION['fullname'] ?? 'Sales Staff',
    'email'         => 'staff@jjjudejush.com',
    'role'          => $_SESSION['role'] ?? 'Sales Staff',
    'store_id'      => $store_id,
    'status'        => 'Online',
    'last_activity' => date('Y-m-d H:i:s'),
    'avatar'        => $_SESSION['avatar'] ?? null
];

$currentUserFullname = $currentUserData['fullname'] ?? ($_SESSION['fullname'] ?? 'Sales Staff');
$currentUserRole     = $currentUserData['role'] ?? ($_SESSION['role'] ?? 'Sales Staff');
$currentUserEmail    = $currentUserData['email'] ?? '';
$rawUserAvatar       = $currentUserData['avatar'] ?? ($currentUserData['profile_image'] ?? ($_SESSION['avatar'] ?? null));

if (!empty($rawUserAvatar)) {
    $_SESSION['avatar'] = $rawUserAvatar;
}

$nameParts = explode(' ', trim($currentUserFullname));
$userInitials = count($nameParts) >= 2 
    ? strtoupper(substr($nameParts[0], 0, 1) . substr($nameParts[count($nameParts)-1], 0, 1))
    : strtoupper(substr($currentUserFullname, 0, 2));

$validAvatarUrl = getValidAvatarUrl($rawUserAvatar);

// Unread Notifications
$unreadCount = 0; 
$notifs = [];
try {
    $unread_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE is_read = 0 AND (store_id = ? OR store_id IS NULL OR store_id = 0)");
    $unread_stmt->execute([$store_id]);
    $unreadCount = (int)$unread_stmt->fetchColumn();

    $recent_notifs = $pdo->prepare("SELECT * FROM notifications WHERE is_read = 0 AND (store_id = ? OR store_id IS NULL OR store_id = 0) ORDER BY created_at DESC LIMIT 5");
    $recent_notifs->execute([$store_id]);
    $notifs = $recent_notifs->fetchAll();
} catch (Throwable $t) {}

$logoutUrl = file_exists(__DIR__ . '/logout.php') ? 'logout.php' : '../logout.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($settings['store_name']) ?> - Profile &amp; Settings</title>
    <!-- Assets -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/js/all.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800;900&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }
        .font-mono { font-family: 'Space Mono', monospace; }
        .custom-scroll::-webkit-scrollbar { width: 5px; height: 5px; }
        .custom-scroll::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 8px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 8px; }
        .custom-scroll::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

        .custom-input { background-color: #f8fafc; border: 1px solid #cbd5e1; border-radius: 12px; padding: 11px 16px; font-size: 13px; font-weight: 700; color: #0f172a; outline: none; transition: all 0.2s; }
        .custom-input:focus { border-color: #f59e0b; background-color: #fff; box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.2); }
    </style>
</head>
<body class="bg-slate-100 text-slate-800 flex flex-col h-screen overflow-hidden select-none">

    <!-- HEADER SYSTEM BAR (SYNCHRONIZED WITH INDEX.PHP & PICTURE) -->
    <header class="flex items-center justify-between bg-[#080d1a] text-white px-5 py-3 shadow-md z-30 flex-shrink-0 border-b border-slate-900">
        
        <!-- BRAND LOGO & PORTAL TITLE -->
        <div class="flex items-center space-x-3.5">
            <div class="w-10 h-10 bg-amber-500 rounded-xl flex items-center justify-center text-slate-950 font-black text-sm shadow-md overflow-hidden border border-amber-400">
                <?php if ($resolvedLogoUrl): ?>
                    <img src="<?= htmlspecialchars($resolvedLogoUrl) ?>" alt="Logo" class="w-full h-full object-cover">
                <?php else: ?>
                    JJ
                <?php endif; ?>
            </div>
            <div>
                <h1 class="text-sm font-black tracking-wider text-white uppercase leading-none">
                    <?= htmlspecialchars($settings['store_name']) ?>
                </h1>
                <p class="text-[9px] font-black tracking-widest text-slate-400 uppercase mt-1">
                    SALES STAFF PORTAL
                </p>
            </div>
        </div>

        <!-- RIGHT CONTROLS: DUAL STORE SWITCHER, NOTIF BELL & EXACT PROFILE DROPDOWN -->
        <div class="flex items-center space-x-3">
            
            <!-- DUAL STORE PILL SWITCHER -->
            <div class="flex items-center bg-[#111827] p-1 rounded-full border border-slate-800/90 shadow-inner">
                <a href="?store_id=1" class="px-5 py-1.5 text-xs font-black rounded-full transition-all <?= $store_id == 1 ? 'bg-amber-500 text-slate-950 shadow-sm' : 'text-slate-300 hover:text-white' ?>">
                    Store 1
                </a>
                <a href="?store_id=2" class="px-5 py-1.5 text-xs font-black rounded-full transition-all <?= $store_id == 2 ? 'bg-amber-500 text-slate-950 shadow-sm' : 'text-slate-300 hover:text-white' ?>">
                    Store 2
                </a>
            </div>

            <!-- NOTIFICATIONS BELL DROPDOWN -->
            <div class="relative group">
                <button type="button" class="w-10 h-10 bg-[#151c2e] hover:bg-slate-800 rounded-full flex items-center justify-center text-slate-300 transition-all relative border border-slate-800 cursor-pointer shadow-sm">
                    <i class="fas fa-bell text-xs"></i>
                    <?php if($unreadCount > 0): ?>
                        <span class="absolute top-2 right-2.5 w-2 h-2 bg-amber-500 rounded-full ring-2 ring-[#080d1a]"></span>
                    <?php endif; ?>
                </button>
                
                <div class="absolute right-0 top-full mt-2 w-80 bg-white text-slate-800 rounded-2xl shadow-2xl border border-slate-200 opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 z-50 overflow-hidden">
                    <div class="p-4 bg-slate-950 text-white flex justify-between items-center">
                        <h3 class="text-xs font-black uppercase tracking-wider">NOTIFICATIONS</h3>
                        <span class="bg-amber-500 text-slate-950 text-[10px] font-black px-2 py-0.5 rounded-md uppercase">
                            <?= $unreadCount ?> UNREAD
                        </span>
                    </div>

                    <div class="max-h-64 overflow-y-auto custom-scroll divide-y divide-slate-100">
                        <?php if(!empty($notifs)): ?>
                            <?php foreach($notifs as $n): ?>
                            <div class="block p-3.5 hover:bg-amber-50/50 transition-colors">
                                <p class="text-xs font-black text-slate-900 leading-tight"><?= htmlspecialchars($n['title']) ?></p>
                                <p class="text-[10px] font-bold text-slate-500 mt-0.5"><?= htmlspecialchars($n['message']) ?></p>
                                <p class="text-[9px] font-black text-amber-600 mt-1 uppercase"><?= date('h:i A', strtotime($n['created_at'])) ?></p>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="py-8 text-center">
                                <p class="text-slate-400 font-bold text-xs">No unread alerts</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="p-2.5 bg-slate-50 border-t border-slate-200 text-center">
                        <a href="?store_id=<?= $store_id ?>&action=clear_notifications" class="text-[10px] font-black uppercase text-amber-600 hover:underline">
                            CLEAR ALL NOTIFICATIONS
                        </a>
                    </div>
                </div>
            </div>

            <!-- STAFF AVATAR WITH DROPDOWN (EXACT LOOK SA PICTURE: POS TERMINAL & LOGOUT) -->
            <div class="relative" id="profileDropdownContainer">
                <button type="button" onclick="toggleProfileDropdown()" title="<?= htmlspecialchars($currentUserFullname) ?>" class="w-10 h-10 rounded-full bg-amber-500 hover:bg-amber-400 text-slate-950 flex items-center justify-center font-black text-xs shadow-md transition-all overflow-hidden border border-amber-400 cursor-pointer">
                    <?php if ($validAvatarUrl): ?>
                        <img src="<?= htmlspecialchars($validAvatarUrl) ?>?v=<?= time() ?>" alt="User Avatar" class="w-full h-full object-cover">
                    <?php else: ?>
                        <?= htmlspecialchars($userInitials) ?>
                    <?php endif; ?>
                </button>

                <!-- DROPDOWN FLOATING CARD -->
                <div id="profileDropdownMenu" class="hidden absolute right-0 top-full mt-3 w-60 bg-white rounded-2xl shadow-2xl border border-slate-100 z-50 overflow-hidden">
                    
                    <!-- USER INFO HEADER -->
                    <div class="px-5 pt-4 pb-3">
                        <p class="text-sm font-extrabold text-slate-900 leading-tight truncate">
                            <?= htmlspecialchars($currentUserFullname) ?>
                        </p>
                        <p class="text-[10px] font-black text-amber-500 uppercase tracking-wider mt-0.5">
                            <?= htmlspecialchars($currentUserRole) ?>
                        </p>
                    </div>

                    <!-- POS TERMINAL LINK -->
                    <div class="border-t border-slate-100 py-1">
                        <a href="index.php?store_id=<?= $store_id ?>" class="w-full px-5 py-2.5 text-xs font-bold text-slate-700 hover:bg-slate-50 flex items-center gap-3 transition">
                            <i class="fas fa-cash-register text-slate-400 text-sm w-4 text-center"></i>
                            <span>POS Terminal</span>
                        </a>
                    </div>

                    <!-- LOGOUT LINK -->
                    <div class="border-t border-slate-100 py-1">
                        <a href="<?= htmlspecialchars($logoutUrl) ?>" class="w-full px-5 py-2.5 text-xs font-bold text-red-500 hover:bg-red-50 flex items-center gap-3 transition">
                            <i class="fas fa-sign-out-alt text-red-500 text-sm w-4 text-center"></i>
                            <span>Logout</span>
                        </a>
                    </div>

                </div>
            </div>

        </div>
    </header>

    <!-- MAIN SETTINGS VIEW -->
    <main class="flex-1 bg-slate-100 text-slate-800 p-6 md:p-8 overflow-y-auto custom-scroll space-y-6">
        
        <!-- ALERT MESSAGES -->
        <?php if($success_msg): ?>
            <div class="p-4 bg-emerald-100 border-l-4 border-emerald-600 text-emerald-800 text-xs font-black uppercase rounded-2xl shadow-sm flex items-center gap-2">
                <i class="fas fa-check-circle text-emerald-600"></i>
                <span><?= htmlspecialchars($success_msg) ?></span>
            </div>
        <?php endif; ?>
        <?php if($error_msg): ?>
            <div class="p-4 bg-red-100 border-l-4 border-red-600 text-red-800 text-xs font-black uppercase rounded-2xl shadow-sm flex items-center gap-2">
                <i class="fas fa-exclamation-circle text-red-600"></i>
                <span><?= htmlspecialchars($error_msg) ?></span>
            </div>
        <?php endif; ?>

        <!-- TOP CARD HEADER WITH DIRECT LINK TO POS TERMINAL (INDEX.PHP) -->
        <div class="bg-white p-5 md:p-6 rounded-2xl shadow-sm border border-slate-200/80 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
            <div>
                <h1 class="text-xl font-black text-slate-900 uppercase tracking-tight">PROFILE &amp; TERMINAL SETTINGS</h1>
                <p class="text-xs font-bold text-slate-500 mt-0.5">Manage your personal profile, credentials, and active store counter presence.</p>
            </div>
            
            <a href="index.php?store_id=<?= $store_id ?>" class="px-5 py-2.5 bg-slate-900 hover:bg-slate-800 text-white rounded-xl text-xs font-black uppercase tracking-wider transition-all shadow flex items-center gap-2">
                <i class="fas fa-arrow-left text-amber-400"></i> Back to POS Terminal
            </a>
        </div>

        <!-- CONTENT GRID -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            
            <!-- LEFT COLUMN: AVATAR & LIVE ONLINE/OFFLINE STATUS -->
            <div class="space-y-6">
                
                <!-- AVATAR CARD -->
                <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm p-6 text-center">
                    <div class="relative w-28 h-28 mx-auto mb-4">
                        <div class="w-28 h-28 rounded-3xl bg-amber-500 text-slate-950 font-black text-2xl flex items-center justify-center overflow-hidden border-4 border-amber-400 shadow-md relative">
                            <?php if ($validAvatarUrl): ?>
                                <img id="avatarPreview" src="<?= htmlspecialchars($validAvatarUrl) ?>?v=<?= time() ?>" alt="User Avatar" class="w-full h-full object-cover">
                                <span id="initialsFallback" class="hidden"><?= htmlspecialchars($userInitials) ?></span>
                            <?php else: ?>
                                <span id="initialsFallback"><?= htmlspecialchars($userInitials) ?></span>
                                <img id="avatarPreview" src="" alt="Preview" class="w-full h-full object-cover hidden">
                            <?php endif; ?>
                        </div>
                    </div>

                    <h3 class="text-base font-black text-slate-900 tracking-tight"><?= htmlspecialchars($currentUserFullname) ?></h3>
                    <p class="text-[10px] font-black text-amber-600 uppercase tracking-widest mt-0.5"><?= htmlspecialchars($currentUserRole) ?></p>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wide mt-1"><?= htmlspecialchars($currentUserEmail) ?></p>

                    <!-- FORM FOR UPLOADING AVATAR -->
                    <form method="POST" enctype="multipart/form-data" class="mt-6 border-t border-slate-100 pt-5">
                        <input type="hidden" name="action" value="update_avatar">
                        <label class="block text-[10px] font-black uppercase text-slate-500 mb-2">Change Profile Picture</label>
                        
                        <input type="file" name="profile_avatar" id="avatarFileInput" accept="image/png, image/jpeg, image/jpg, image/webp" required class="hidden" onchange="previewImage(event)">
                        
                        <div class="flex gap-2">
                            <button type="button" onclick="document.getElementById('avatarFileInput').click()" class="flex-1 py-2.5 px-3 bg-slate-100 hover:bg-slate-200 text-slate-800 rounded-xl text-xs font-black uppercase transition-all border border-slate-200 flex items-center justify-center gap-1.5 cursor-pointer">
                                <i class="fas fa-camera text-amber-500"></i> Browse
                            </button>
                            <button type="submit" class="py-2.5 px-4 bg-amber-500 hover:bg-amber-600 text-slate-950 rounded-xl text-xs font-black uppercase transition-all shadow flex items-center justify-center gap-1.5 cursor-pointer">
                                Save
                            </button>
                        </div>
                    </form>
                </div>

                <!-- REAL-TIME PRESENCE & STORE ASSIGNMENT -->
                <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm p-6">
                    <h3 class="text-xs font-black uppercase text-slate-900 mb-3 tracking-wider flex items-center justify-between border-b border-slate-100 pb-3">
                        <span class="flex items-center gap-2">
                            <i class="fas fa-signal text-amber-500"></i> Real-time Presence
                        </span>
                        <span class="text-[9px] font-extrabold text-slate-400 uppercase" id="heartbeatTimer">Active Ping</span>
                    </h3>
                    
                    <div class="space-y-3 text-xs font-bold text-slate-600">
                        <div class="flex justify-between items-center py-1">
                            <span class="text-slate-500">Live Status:</span>
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[10px] font-black uppercase bg-emerald-100 text-emerald-800 border border-emerald-300">
                                <span class="relative flex h-2 w-2">
                                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                                    <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                                </span>
                                <span id="liveStatusLabel">ONLINE</span>
                            </span>
                        </div>

                        <div class="flex justify-between items-center py-1 border-t border-slate-100">
                            <span class="text-slate-500">Active Store Branch:</span>
                            <span class="font-black text-amber-600 uppercase bg-amber-50 border border-amber-200 px-2.5 py-0.5 rounded-md" id="activeStoreBadge">
                                Store <?= $store_id ?>
                            </span>
                        </div>

                        <div class="flex justify-between items-center py-1 border-t border-slate-100">
                            <span class="text-slate-500">Last Synced:</span>
                            <span class="font-black text-slate-800 text-[11px]" id="lastSyncTime"><?= date('h:i:s A') ?></span>
                        </div>

                        <div class="flex justify-between items-center py-1 border-t border-slate-100">
                            <span class="text-slate-500">System Timezone:</span>
                            <span class="font-black text-slate-800 text-[11px]">Asia/Manila (PHT)</span>
                        </div>
                    </div>
                </div>

            </div>

            <!-- RIGHT COLUMN: PROFILE INFORMATION & SECURITY -->
            <div class="lg:col-span-2 space-y-6">
                
                <!-- EDIT PERSONAL DETAILS -->
                <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm overflow-hidden">
                    <div class="p-6 border-b border-slate-100 bg-white">
                        <h2 class="text-sm font-black text-slate-900 uppercase tracking-tight flex items-center gap-2">
                            <i class="fas fa-id-card text-amber-500"></i> Personal Information
                        </h2>
                        <p class="text-xs font-bold text-slate-500 mt-0.5">Update your personnel record and display name.</p>
                    </div>

                    <form method="POST" class="p-6 space-y-4">
                        <input type="hidden" name="action" value="update_profile">
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="text-[10px] font-black text-slate-500 uppercase block mb-1">Full Name</label>
                                <input type="text" name="fullname" value="<?= htmlspecialchars($currentUserFullname) ?>" required class="custom-input w-full">
                            </div>

                            <div>
                                <label class="text-[10px] font-black text-slate-500 uppercase block mb-1">Email Address</label>
                                <input type="email" name="email" value="<?= htmlspecialchars($currentUserEmail) ?>" placeholder="staff@jjjudejush.com" class="custom-input w-full">
                            </div>
                        </div>

                        <div class="pt-2 flex justify-end">
                            <button type="submit" class="bg-amber-500 hover:bg-amber-600 text-slate-950 font-black px-6 py-2.5 rounded-xl text-xs uppercase transition-all shadow flex items-center gap-2 cursor-pointer active:scale-95">
                                Save Profile
                            </button>
                        </div>
                    </form>
                </div>

                <!-- CHANGE SECURITY PASSWORD -->
                <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm overflow-hidden">
                    <div class="p-6 border-b border-slate-100 bg-white">
                        <h2 class="text-sm font-black text-slate-900 uppercase tracking-tight flex items-center gap-2">
                            <i class="fas fa-shield-alt text-amber-500"></i> Security &amp; Password
                        </h2>
                        <p class="text-xs font-bold text-slate-500 mt-0.5">Update your personal password for logging in to the sales counter.</p>
                    </div>

                    <form method="POST" class="p-6 space-y-4">
                        <input type="hidden" name="action" value="change_password">
                        
                        <div>
                            <label class="text-[10px] font-black text-slate-500 uppercase block mb-1">Current Password</label>
                            <input type="password" name="current_password" required placeholder="••••••••" class="custom-input w-full">
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="text-[10px] font-black text-slate-500 uppercase block mb-1">New Password</label>
                                <input type="password" name="new_password" required placeholder="••••••••" class="custom-input w-full">
                            </div>
                            <div>
                                <label class="text-[10px] font-black text-slate-500 uppercase block mb-1">Confirm New Password</label>
                                <input type="password" name="confirm_password" required placeholder="••••••••" class="custom-input w-full">
                            </div>
                        </div>

                        <div class="pt-2 flex justify-end">
                            <button type="submit" class="bg-[#070a12] hover:bg-slate-800 text-white font-black px-6 py-2.5 rounded-xl text-xs uppercase tracking-wider transition-all shadow flex items-center gap-2 cursor-pointer active:scale-95">
                                Update Password
                            </button>
                        </div>
                    </form>
                </div>

            </div>

        </div>

    </main>

    <!-- JAVASCRIPT CONTROLLERS -->
    <script>
        function toggleProfileDropdown() {
            const menu = document.getElementById('profileDropdownMenu');
            if (menu) {
                menu.classList.toggle('hidden');
            }
        }

        window.addEventListener('click', function(e) {
            const container = document.getElementById('profileDropdownContainer');
            if (container && !container.contains(e.target)) {
                document.getElementById('profileDropdownMenu')?.classList.add('hidden');
            }
        });

        function previewImage(event) {
            const input = event.target;
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const preview = document.getElementById('avatarPreview');
                    const fallback = document.getElementById('initialsFallback');
                    if (preview) {
                        preview.src = e.target.result;
                        preview.style.display = 'block';
                        preview.classList.remove('hidden');
                    }
                    if (fallback) fallback.classList.add('hidden');
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        async function sendHeartbeat() {
            try {
                const response = await fetch('settings.php?store_id=<?= $store_id ?>&action=heartbeat');
                if (!response.ok) return;
                const data = await response.json();
                if (data.status === 'success') {
                    const syncEl = document.getElementById('lastSyncTime');
                    if (syncEl) syncEl.innerText = data.time;
                }
            } catch (err) {
                console.error("Heartbeat sync error:", err);
            }
        }

        setInterval(sendHeartbeat, 25000);
    </script>
</body>
</html>
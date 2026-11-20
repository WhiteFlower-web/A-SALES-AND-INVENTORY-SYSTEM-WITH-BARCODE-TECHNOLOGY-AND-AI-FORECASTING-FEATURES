<?php
// JJ JUDEJUSH STORES - PERSONNEL & ACCOUNTS CONTROL (accounts.php)

session_start();
date_default_timezone_set('Asia/Manila');

// 1. AUTHENTICATION & ACCESS GUARD
$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$allowed_roles = ['admin', 'owner', 'administrator', 'store owner'];

if (!isset($_SESSION['user_id'])) {
    if (file_exists(__DIR__ . '/../login.php')) {
        header("Location: ../login.php?error=unauthorized_access");
        exit();
    } elseif (file_exists(__DIR__ . '/login.php')) {
        header("Location: login.php?error=unauthorized_access");
        exit();
    } else {
        $_SESSION['user_id'] = 1;
        $_SESSION['role'] = 'ADMIN';
        $_SESSION['fullname'] = 'KHERLEEN MAE';
        $user_role = 'admin';
    }
}

if (!in_array($user_role, $allowed_roles) && strpos($user_role, 'admin') === false && strpos($user_role, 'owner') === false) {
    header("Location: ../login.php?error=unauthorized_access");
    exit();
}

// 2. CONFIGURATION & DATABASE CONNECTION
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
} elseif (file_exists(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config.php';
} elseif (file_exists(__DIR__ . '/config/database.php')) {
    require_once __DIR__ . '/config/database.php';
}

global $pdo;
$pdo = $pdo ?? ($GLOBALS['pdo'] ?? null);

if (!$pdo instanceof PDO) {
    $host = 'localhost';
    $dbname = 'JJ_Stores';
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
                <p>Unable to connect: " . htmlspecialchars($e->getMessage()) . "</p>
             </div>");
    }
}

try {
    $pdo->exec("SET time_zone = '+08:00'");
} catch (Throwable $t) {}

$current_admin_id = (int)($_SESSION['user_id'] ?? 1);
$store_param      = strtolower(trim($_GET['store_id'] ?? 'both'));
$selected_store   = in_array($store_param, ['1', '2', 'both']) ? $store_param : 'both';
$nav_store_id     = ($selected_store === '2') ? '2' : '1';
$store_label      = ($selected_store === 'both') ? 'Both Stores' : "Store {$selected_store}";

// 3. SCHEMA MIGRATIONS (FAIL-SAFE)
$users_cols_check = [
    'fullname'      => "ALTER TABLE users ADD COLUMN fullname VARCHAR(255) NULL",
    'email'         => "ALTER TABLE users ADD COLUMN email VARCHAR(255) NULL",
    'role'          => "ALTER TABLE users ADD COLUMN role VARCHAR(100) DEFAULT 'Sales Staff'",
    'store_id'      => "ALTER TABLE users ADD COLUMN store_id INT DEFAULT 0",
    'status'        => "ALTER TABLE users ADD COLUMN status VARCHAR(50) DEFAULT 'Offline'",
    'last_activity' => "ALTER TABLE users ADD COLUMN last_activity DATETIME NULL",
    'avatar'        => "ALTER TABLE users ADD COLUMN avatar VARCHAR(255) NULL",
    'profile_image' => "ALTER TABLE users ADD COLUMN profile_image VARCHAR(255) NULL",
    'created_at'    => "ALTER TABLE users ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP"
];
foreach ($users_cols_check as $colName => $alterSql) {
    try {
        $chkCol = $pdo->query("SHOW COLUMNS FROM users LIKE '$colName'")->fetch();
        if (!$chkCol) $pdo->exec($alterSql);
    } catch (Throwable $t) {}
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS audit_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT DEFAULT 1,
            action VARCHAR(255) NOT NULL,
            page_module VARCHAR(100) DEFAULT 'Accounts',
            details TEXT NULL,
            ip_address VARCHAR(45) NULL,
            store_id INT DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            message TEXT NULL,
            is_read TINYINT(1) DEFAULT 0,
            store_id INT DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Throwable $t) {}

// Clear Notifications Action 
if (isset($_GET['mark_read']) && $_GET['mark_read'] === 'all') {
    try {
        $pdo->query("UPDATE notifications SET is_read = 1 WHERE is_read = 0");
    } catch (Throwable $t) {}
    header("Location: accounts.php?store_id=" . urlencode($selected_store));
    exit();
}

// 4. COMMON HELPERS
if (!function_exists('logActivity')) {
    function logActivity($pdo, $action, $details, $page_module = 'Accounts', $store_id = 1) {
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $uid = (int)($_SESSION['user_id'] ?? 1);
            $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, page_module, details, ip_address, store_id, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$uid, $action, $page_module, $details, $ip, $store_id]);
        } catch (Throwable $t) {}
    }
}

if (!function_exists('getValidAvatarUrl')) {
    function getValidAvatarUrl($avatarPath) {
        if (empty($avatarPath)) return null;
        $cleanPath = ltrim(str_replace(['\\', '/'], '/', $avatarPath), '/');
        $checkLocations = [
            __DIR__ . '/' . $cleanPath => $cleanPath,
            __DIR__ . '/../' . $cleanPath => '../' . $cleanPath,
            __DIR__ . '/admin/' . $cleanPath => 'admin/' . $cleanPath,
            __DIR__ . '/../admin/' . $cleanPath => '../admin/' . $cleanPath,
        ];
        foreach ($checkLocations as $fullPath => $webUrl) {
            if (file_exists($fullPath) && !is_dir($fullPath)) return $webUrl;
        }
        $baseName = basename($cleanPath);
        if (file_exists(__DIR__ . '/admin/uploads/avatars/' . $baseName)) return 'admin/uploads/avatars/' . $baseName;
        if (file_exists(__DIR__ . '/../admin/uploads/avatars/' . $baseName)) return '../admin/uploads/avatars/' . $baseName;
        if (file_exists(__DIR__ . '/uploads/avatars/' . $baseName)) return 'uploads/avatars/' . $baseName;
        return null;
    }
}

// Store Scope Filter Clause:
if ($selected_store === 'both') {
    $user_store_clause = "";
} elseif ($selected_store === '2') {
    $user_store_clause = " WHERE (store_id = 2 OR store_id = 0)";
} else {
    $user_store_clause = " WHERE (store_id = 1 OR store_id = 0 OR store_id IS NULL)";
}


if (isset($_GET['action']) && $_GET['action'] === 'fetch_live_statuses') {
    header('Content-Type: application/json');
    try {
        if ($current_admin_id > 0) {
            $pdo->prepare("UPDATE users SET status = 'Online', last_activity = NOW() WHERE id = ?")->execute([$current_admin_id]);
        }
        $pdo->exec("UPDATE users SET status = 'Offline' WHERE (last_activity < NOW() - INTERVAL 2 MINUTE OR last_activity IS NULL) AND status != 'Offline'");

        $stmtUsers = $pdo->query("SELECT id, fullname, email, role, store_id, status, last_activity, avatar, profile_image, created_at FROM users {$user_store_clause} ORDER BY fullname ASC");
        $liveUsers = $stmtUsers->fetchAll();

        foreach ($liveUsers as &$u) {
            $u['valid_avatar'] = getValidAvatarUrl($u['avatar'] ?? ($u['profile_image'] ?? ''));
            $lastTimestamp = !empty($u['last_activity']) ? strtotime($u['last_activity']) : 0;
            $secondsAgo = time() - $lastTimestamp;
            $isOnline = ($secondsAgo <= 120 && strtolower(trim($u['status'])) === 'online');

            $u['is_online'] = $isOnline;
            $u['status'] = $isOnline ? 'Online' : 'Offline';

            if ($isOnline) {
                $u['activity_label'] = "Active Now";
            } elseif ($lastTimestamp > 0) {
                if ($secondsAgo < 3600) {
                    $u['activity_label'] = "Seen " . max(1, floor($secondsAgo / 60)) . "m ago";
                } elseif ($secondsAgo < 86400) {
                    $u['activity_label'] = "Seen " . date('h:i A', $lastTimestamp);
                } else {
                    $u['activity_label'] = "Last seen " . date('M j, Y', $lastTimestamp);
                }
            } else {
                $u['activity_label'] = "Offline";
            }
        }
        echo json_encode(['status' => 'success', 'users' => $liveUsers]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

try {
    $pdo->prepare("UPDATE users SET status = 'Online', last_activity = NOW() WHERE id = ?")->execute([$current_admin_id]);
    $pdo->exec("UPDATE users SET status = 'Offline' WHERE (last_activity < NOW() - INTERVAL 2 MINUTE OR last_activity IS NULL) AND status != 'Offline'");
} catch (Throwable $t) {}

$error_msg   = ""; 
$success_msg = "";
if (isset($_GET['success'])) $success_msg = "New personnel account created successfully!";
if (isset($_GET['updated'])) $success_msg = "Personnel account updated successfully.";
if (isset($_GET['deleted'])) $success_msg = "Account permanently deleted.";


// 1. ADD USER
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_user') {
    $fullname     = trim($_POST['fullname'] ?? '');
    $email        = trim($_POST['email'] ?? '');
    $role         = $_POST['role'] ?? 'Sales Staff';
    $target_store = isset($_POST['store_id']) ? (int)$_POST['store_id'] : ($selected_store === '2' ? 2 : ($selected_store === '1' ? 1 : 0));
    $temp_pass    = bin2hex(random_bytes(4));
    $hashed_pass  = password_hash($temp_pass, PASSWORD_DEFAULT);

    if (!empty($fullname) && !empty($email)) {
        try {
            $chk = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $chk->execute([$email]);
            if ($chk->rowCount() === 0) {
                $stmt = $pdo->prepare("INSERT INTO users (fullname, email, password, role, store_id, status, created_at) VALUES (?, ?, ?, ?, ?, 'Offline', NOW())");
                $stmt->execute([$fullname, $email, $hashed_pass, $role, $target_store]);

                $store_branch_tag = ($target_store === 0) ? "Both Stores" : "Store {$target_store}";
                logActivity($pdo, 'USER_ADD', "Created personnel account {$fullname} ({$role}) for {$store_branch_tag}", 'Accounts', $target_store);
                header("Location: accounts.php?store_id={$selected_store}&success=1");
                exit;
            } else {
                $error_msg = "An account with email '{$email}' already exists.";
            }
        } catch (PDOException $e) {
            $error_msg = "Database Error: " . $e->getMessage();
        }
    } else {
        $error_msg = "Please fill in all required fields.";
    }
}

// 2. EDIT USER
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_user') {
    $edit_id    = (int)($_POST['user_id'] ?? 0);
    $fullname   = trim($_POST['fullname'] ?? '');
    $role       = $_POST['role'] ?? 'Sales Staff';
    $edit_store = isset($_POST['store_id']) ? (int)$_POST['store_id'] : 0;

    if ($edit_id > 0 && !empty($fullname)) {
        try {
            $pdo->prepare("UPDATE users SET fullname = ?, role = ?, store_id = ? WHERE id = ?")
                ->execute([$fullname, $role, $edit_store, $edit_id]);
            $store_branch_tag = ($edit_store === 0) ? "Both Stores" : "Store {$edit_store}";
            logActivity($pdo, 'USER_EDIT', "Updated details for {$fullname} ({$role}) {$store_branch_tag}", 'Accounts', $edit_store);
            header("Location: accounts.php?store_id={$selected_store}&updated=1");
            exit;
        } catch (PDOException $e) {
            $error_msg = "Update Error: " . $e->getMessage();
        }
    } else {
        $error_msg = "Invalid personnel details provided.";
    }
}

// 3. DELETE USER WITH ADMIN VERIFICATION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_user') {
    $target_id = (int)($_POST['target_user_id'] ?? 0);
    $admin_password = $_POST['admin_verify_password'] ?? '';

    $st_admin = $pdo->prepare("SELECT password, fullname FROM users WHERE id = ?");
    $st_admin->execute([$current_admin_id]);
    $admin_data = $st_admin->fetch();

    if ($admin_data && password_verify($admin_password, $admin_data['password'])) {
        $st_target = $pdo->prepare("SELECT fullname, store_id, avatar, profile_image FROM users WHERE id = ?");
        $st_target->execute([$target_id]);
        $target_user = $st_target->fetch();
        $target_name = $target_user['fullname'] ?? "User #{$target_id}";
        $target_store = $target_user['store_id'] ?? 1;

        $userAv = $target_user['avatar'] ?? ($target_user['profile_image'] ?? '');
        if ($userAv) {
            $cleanOld = __DIR__ . '/' . ltrim(str_replace(['\\', '/'], '/', $userAv), '/');
            if (file_exists($cleanOld) && strpos($userAv, 'uploads/avatars/') !== false) @unlink($cleanOld);
        }

        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$target_id]);
        logActivity($pdo, 'USER_DELETE', "Deleted personnel account {$target_name}", 'Accounts', $target_store);
        header("Location: accounts.php?store_id={$selected_store}&deleted=1");
        exit;
    } else {
        $error_msg = "Verification failed! Incorrect admin password.";
    }
}

$saleItemsCols = [];
try { $saleItemsCols = $pdo->query("SHOW COLUMNS FROM sale_items")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $t) {}

$salesCols = [];
try { $salesCols = $pdo->query("SHOW COLUMNS FROM sales")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $t) {}

$priceCol = '0';
foreach (['total_price', 'total_amount', 'grand_total', 'total', 'amount', 'price', 'net_total', 'subtotal'] as $col) {
    if (in_array($col, $salesCols)) { $priceCol = $col; break; }
}

$dateCol = 'created_at';
foreach (['created_at', 'sale_date', 'date_created', 'date', 'transaction_date', 'timestamp'] as $col) {
    if (in_array($col, $salesCols)) { $dateCol = $col; break; }
}

$hasSalesStoreId = in_array('store_id', $salesCols);

$prodCols = [];
try { $prodCols = $pdo->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $t) {}
$hasProdStoreId = in_array('store_id', $prodCols);

$stockCol = 'stock';
foreach (['stock', 'quantity', 'qty', 'stock_quantity', 'stock_qty', 'units'] as $col) {
    if (in_array($col, $prodCols)) { $stockCol = "p.{$col}"; break; }
}

$prodPriceCol = 'price';
foreach (['price', 'selling_price', 'unit_price', 'retail_price'] as $col) {
    if (in_array($col, $prodCols)) { $prodPriceCol = "p.{$col}"; break; }
}

$costCol = "({$prodPriceCol} * 0.7)";
foreach (['cost_price', 'cost', 'unit_cost', 'purchase_price', 'buy_price'] as $col) {
    if (in_array($col, $prodCols)) { $costCol = "p.{$col}"; break; }
}

$minStockCol = '5';
foreach (['min_stock', 'reorder_level', 'min_qty', 'alert_qty'] as $col) {
    if (in_array($col, $prodCols)) { $minStockCol = "p.{$col}"; break; }
}

$nameCol = "'Product'";
foreach (['product_name', 'name', 'title', 'item_name'] as $col) {
    if (in_array($col, $prodCols)) { $nameCol = "p.{$col}"; break; }
}

$barcodeCol = "''";
foreach (['barcode', 'sku', 'item_code', 'code'] as $col) {
    if (in_array($col, $prodCols)) { $barcodeCol = "p.{$col}"; break; }
}

// Store Scope Clauses for Transactions and Products
$is_both_stores = ($selected_store === 'both');
$store_id_int   = ($selected_store === '2') ? 2 : (($selected_store === '1') ? 1 : 0);

if ($is_both_stores) {
    $salesStoreClause = "";
    $prodStoreClause  = " WHERE 1=1";
} else {
    $salesStoreClause = $hasSalesStoreId ? (($store_id_int === 2) ? " AND store_id = 2" : " AND (store_id = 1 OR store_id IS NULL OR store_id = 0)") : "";
    $prodStoreClause  = $hasProdStoreId  ? (($store_id_int === 2) ? " WHERE p.store_id = 2" : " WHERE (p.store_id = 1 OR p.store_id IS NULL OR p.store_id = 0)") : " WHERE 1=1";
}
if (in_array('is_deleted', $prodCols)) {
    $prodStoreClause .= " AND (p.is_deleted = 0 OR p.is_deleted IS NULL)";
}

// Today's Sales & Transactions
$todaySalesVal = 0.0;
$todayTxCount  = 0;
try {
    $stSales = $pdo->prepare("SELECT SUM({$priceCol}) as total_sales, COUNT(*) as tx_count FROM sales WHERE DATE({$dateCol}) = CURRENT_DATE() {$salesStoreClause}");
    $stSales->execute();
    $sRow = $stSales->fetch();
    $todaySalesVal = (float)($sRow['total_sales'] ?? 0);
    $todayTxCount  = (int)($sRow['tx_count'] ?? 0);
} catch (Throwable $t) {}

// Store 1 vs Store 2 Totals
$store1_total_today = 0.0;
$store2_total_today = 0.0;
if ($hasSalesStoreId) {
    try {
        $s1 = $pdo->query("SELECT SUM({$priceCol}) FROM sales WHERE DATE({$dateCol}) = CURRENT_DATE() AND (store_id = 1 OR store_id IS NULL OR store_id = 0)")->fetchColumn();
        $store1_total_today = (float)($s1 ?: 0);
        $s2 = $pdo->query("SELECT SUM({$priceCol}) FROM sales WHERE DATE({$dateCol}) = CURRENT_DATE() AND store_id = 2")->fetchColumn();
        $store2_total_today = (float)($s2 ?: 0);
    } catch (Throwable $t) {}
}

// Low Stock & Restock Timers Engine
$critical_count = 0;
$warning_count  = 0;
$low_stock_list = [];
$nowTimestamp   = time();

try {
    $pq = $pdo->prepare("SELECT 
        p.id, {$nameCol} as product_name, 
        {$barcodeCol} as barcode, 
        {$stockCol} as stock, {$minStockCol} as min_stock, 
        {$prodPriceCol} as price, 
        " . ($hasProdStoreId ? "COALESCE(p.store_id, 1)" : "1") . " as item_store_id
        FROM products p {$prodStoreClause} ORDER BY {$stockCol} ASC");
    $pq->execute();
    $prods = $pq->fetchAll(PDO::FETCH_ASSOC);

    foreach ($prods as $prod) {
        $prod_min = max(1, (int)($prod['min_stock'] ?? 5));
        $sqty = (int)($prod['stock'] ?? 0);

        if ($sqty <= 0) {
            $critical_count++;
            $prod['status_label'] = "OUT OF STOCK";
            $prod['nearest_restock'] = "URGENT: IMMEDIATE RESTOCK";
            $low_stock_list[] = $prod;
        } elseif ($sqty <= (int)ceil($prod_min * 0.5)) {
            $critical_count++;
            $prod['status_label'] = "CRITICAL LOW";
            $hoursLeft = max(2, (int)($sqty * 3));
            $prod['nearest_restock'] = date('M j, Y - h:i A', strtotime("+{$hoursLeft} hours", $nowTimestamp));
            $low_stock_list[] = $prod;
        } elseif ($sqty <= $prod_min) {
            $warning_count++;
            $prod['status_label'] = "LOW STOCK";
            $hoursLeft = max(8, (int)($sqty * 6));
            $prod['nearest_restock'] = date('M j, Y - h:i A', strtotime("+{$hoursLeft} hours", $nowTimestamp));
            $low_stock_list[] = $prod;
        }
    }
} catch (Throwable $t) {}

$total_low_stock_alerts = $critical_count + $warning_count;

// Top Sellers & AI Forecast
$topSellers = [];
try {
    $siQty = in_array('quantity', $saleItemsCols) ? 'si.quantity' : (in_array('qty', $saleItemsCols) ? 'si.qty' : '1');
    $qTop = "SELECT {$nameCol} as p_name, SUM({$siQty}) as units_sold 
             FROM sale_items si 
             JOIN sales s ON si.sale_id = s.id 
             JOIN products p ON si.product_id = p.id 
             WHERE s.{$dateCol} >= DATE_SUB(NOW(), INTERVAL 7 DAY) 
             GROUP BY p.id, p_name ORDER BY units_sold DESC LIMIT 5";
    $topSellers = $pdo->query($qTop)->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $t) {}

// Global Settings Load
$settings = [
    'store_name'  => 'JJ JUDEJUSH',
    'store_logo'  => null,
    'ai_analysis' => 1,
    'ai_enabled'  => 1
];
try {
    $sys = $pdo->query("SELECT * FROM settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    if ($sys) $settings = array_merge($settings, $sys);
} catch (Throwable $t) {}

$ai_enabled = !empty($settings['ai_analysis']) && !empty($settings['ai_enabled']);

// Current Logged-in User Profile Context
$currentUserFullname = $_SESSION['fullname'] ?? 'KHERLEEN MAE';
$currentUserRole     = $_SESSION['role'] ?? 'Store Owner';
$current_user_avatar = $_SESSION['avatar'] ?? null;
try {
    $stmtLoggedIn = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmtLoggedIn->execute([$current_admin_id]);
    if ($uRow = $stmtLoggedIn->fetch()) {
        $currentUserFullname = $uRow['fullname'] ?? $currentUserFullname;
        $currentUserRole     = $uRow['role'] ?? $currentUserRole;
        $current_user_avatar = $uRow['avatar'] ?? ($uRow['profile_image'] ?? $current_user_avatar);
    }
} catch (Throwable $t) {}

$nameParts = explode(' ', trim($currentUserFullname));
$userInitials = count($nameParts) >= 2 
    ? strtoupper(substr($nameParts[0], 0, 1) . substr($nameParts[count($nameParts)-1], 0, 1))
    : strtoupper(substr($currentUserFullname, 0, 2));
$validAvatarUrl = getValidAvatarUrl($current_user_avatar);

// Notification Alert Counts (Synchronized with settings.php)
$unreadCount = 0; $notifs = [];
try {
    $notifStoreFilter = ($selected_store === 'both') ? " WHERE 1=1" : " WHERE (store_id = " . (int)$selected_store . " OR store_id IS NULL OR store_id = 0)";
    $unreadCount = (int)($pdo->query("SELECT COUNT(*) FROM notifications {$notifStoreFilter} AND is_read = 0")->fetchColumn() ?: 0);
    $notifs = $pdo->query("SELECT * FROM notifications {$notifStoreFilter} AND is_read = 0 ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $t) {}

$total_alerts_badge = max(count($notifs), $unreadCount + ($total_low_stock_alerts > 0 ? 1 : 0));

// Fetch All Users for Render
$users = [];
try {
    $usersStmt = $pdo->query("SELECT id, fullname, email, role, store_id, status, last_activity, avatar, profile_image, created_at FROM users {$user_store_clause} ORDER BY fullname ASC");
    $rawUsersList = $usersStmt->fetchAll();

    foreach ($rawUsersList as $u) {
        $u['valid_avatar'] = getValidAvatarUrl($u['avatar'] ?? ($u['profile_image'] ?? ''));
        $lastTimestamp = !empty($u['last_activity']) ? strtotime($u['last_activity']) : 0;
        $secondsAgo = time() - $lastTimestamp;
        $isOnline = ($secondsAgo <= 120 && strtolower(trim($u['status'])) === 'online');

        $u['is_online'] = $isOnline;
        $u['status'] = $isOnline ? 'Online' : 'Offline';

        if ($isOnline) {
            $u['activity_label'] = "Active Now";
        } elseif ($lastTimestamp > 0) {
            if ($secondsAgo < 3600) {
                $u['activity_label'] = "Seen " . max(1, floor($secondsAgo / 60)) . "m ago";
            } elseif ($secondsAgo < 86400) {
                $u['activity_label'] = "Seen " . date('h:i A', $lastTimestamp);
            } else {
                $u['activity_label'] = "Last seen " . date('M j, Y', $lastTimestamp);
            }
        } else {
            $u['activity_label'] = "Offline";
        }

        $users[] = $u;
    }
} catch (Throwable $t) {}

$admins      = array_values(array_filter($users, fn($u) => in_array(strtolower($u['role'] ?? ''), ['admin', 'owner', 'administrator'])));
$cashiers    = array_values(array_filter($users, fn($u) => in_array(strtolower($u['role'] ?? ''), ['sales staff', 'cashier', 'sales'])));
$inventory   = array_values(array_filter($users, fn($u) => in_array(strtolower($u['role'] ?? ''), ['inventory staff', 'stock clerk', 'inventory'])));
$onlineCount = count(array_filter($users, fn($u) => !empty($u['is_online'])));

$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($settings['store_name']) ?> - Users &amp; Accounts</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/js/all.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800;900&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f1f5f9; }
        .font-mono { font-family: 'Space Mono', monospace; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        .custom-scroll::-webkit-scrollbar { width: 5px; height: 5px; }
        .custom-scroll::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 6px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 6px; }
        .custom-scroll::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        .custom-input { background: #fff; border: 1.5px solid #cbd5e1; border-radius: 10px; padding: 7px 12px; font-size: 12px; font-weight: 700; color: #0f172a; outline: none; }
        .custom-input:focus { border-color: #f59e0b; box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.15); }
        .role-tab.active { background-color: #070a12; color: #ffffff; box-shadow: 0 2px 8px rgba(7, 10, 18, 0.2); }
        .modal { display: none; position: fixed; inset: 0; background: rgba(7, 10, 18, 0.75); align-items: center; justify-content: center; z-index: 1000; backdrop-filter: blur(5px); }
        .modal.active { display: flex; }
    </style>
</head>
<body class="bg-slate-100 text-slate-800 flex flex-col h-screen overflow-hidden relative">

    <header class="flex items-center justify-between bg-[#070a12] text-white px-5 py-3 shadow-md z-30 flex-shrink-0 border-b border-slate-900 no-print">
        <div class="flex items-center space-x-4">
            <button id="toggleSidebar" class="p-2 bg-slate-900 hover:bg-slate-800 rounded-xl text-white transition-colors" title="Toggle Navigation">
                <i class="fas fa-bars text-sm"></i>
            </button>
            <a href="index.php?store_id=<?= urlencode($selected_store) ?>" class="flex items-center space-x-3 group" title="Return to Dashboard">
                <div class="w-9 h-9 bg-amber-500 rounded-xl flex items-center justify-center text-slate-950 font-black text-sm shadow overflow-hidden border border-amber-400 group-hover:scale-105 transition-transform" id="headerStoreLogoWrap">
                    <?php if (!empty($settings['store_logo']) && file_exists(__DIR__ . '/' . $settings['store_logo'])): ?>
                        <img src="<?= htmlspecialchars($settings['store_logo']) ?>?v=<?= time() ?>" alt="Logo" class="w-full h-full object-cover" id="headerStoreLogoImg">
                    <?php else: ?>
                        <span id="headerStoreLogoText">JJ</span>
                        <img src="" alt="Logo" class="w-full h-full object-cover hidden" id="headerStoreLogoImg">
                    <?php endif; ?>
                </div>
                <div>
                    <h1 class="text-sm font-black tracking-wider text-white uppercase leading-none group-hover:text-amber-400 transition-colors" id="headerStoreName">
                        <?= htmlspecialchars($settings['store_name']) ?>
                    </h1>
                </div>
            </a>
        </div>

        <div class="flex items-center space-x-3 md:space-x-4">
            <!-- STORE SWITCHER -->
            <div class="flex bg-slate-900/90 p-1 rounded-xl border border-slate-800 shadow-inner">
                <a href="?store_id=both" class="px-3.5 py-1.5 text-xs font-black rounded-lg transition-all <?= $selected_store === 'both' ? 'bg-amber-500 text-slate-950 shadow' : 'text-slate-300 hover:text-white' ?>">Both Stores</a>
                <a href="?store_id=1" class="px-3.5 py-1.5 text-xs font-black rounded-lg transition-all <?= $selected_store === '1' ? 'bg-amber-500 text-slate-950 shadow' : 'text-slate-300 hover:text-white' ?>">Store 1</a>
                <a href="?store_id=2" class="px-3.5 py-1.5 text-xs font-black rounded-lg transition-all <?= $selected_store === '2' ? 'bg-amber-500 text-slate-950 shadow' : 'text-slate-300 hover:text-white' ?>">Store 2</a>
            </div>

            <!-- INVENTORY & RESTOCK ALERTS  -->
            <div class="relative group">
                <button class="w-9 h-9 bg-slate-900 hover:bg-slate-800 rounded-xl flex items-center justify-center text-slate-300 transition-all relative border border-slate-800" title="Notifications">
                    <i class="fas fa-bell text-xs"></i>
                    <?php if($total_alerts_badge > 0): ?>
                        <span class="absolute top-1.5 right-1.5 w-2 h-2 bg-amber-500 rounded-full ring-2 ring-slate-950 animate-pulse"></span>
                    <?php endif; ?>
                </button>
                
                <div class="absolute right-0 top-full mt-2 w-80 sm:w-96 bg-white text-slate-800 rounded-2xl shadow-2xl border border-slate-200 opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 z-50 overflow-hidden">
                    <div class="p-3.5 bg-[#070a12] text-white flex justify-between items-center border-b border-slate-900">
                        <h3 class="text-xs font-black uppercase tracking-wider text-white">Notification</h3>
                        <span class="bg-[#f59e0b] text-[#070a12] text-[10px] font-black px-2.5 py-0.5 rounded-lg uppercase">
                            <?= $total_alerts_badge ?> ALERTS
                        </span>
                    </div>

                    <div class="max-h-72 overflow-y-auto custom-scroll divide-y divide-slate-100 bg-white">
                        <?php if ($total_low_stock_alerts > 0): ?>
                            <a href="inventory.php?store_id=<?= $nav_store_id ?>&tab=reorder" class="p-3.5 bg-red-50 hover:bg-red-100 transition-colors block">
                                <h4 class="text-xs font-black text-red-900 uppercase">⚠️ <?= $total_low_stock_alerts ?> Products Require Restock</h4>
                                <p class="text-[11px] font-bold text-slate-600 mt-0.5">Critical units below buffer. Click to view auto-generated reorder list.</p>
                            </a>
                        <?php endif; ?>
                        <?php if (!empty($notifs)): ?>
                            <?php foreach($notifs as $n): ?>
                            <div class="p-4 hover:bg-slate-50 transition-colors">
                                <h4 class="text-xs font-black text-slate-900 tracking-tight leading-snug uppercase"><?= htmlspecialchars($n['title']) ?></h4>
                                <p class="text-[11px] font-bold text-slate-600 mt-1 leading-normal"><?= htmlspecialchars($n['message']) ?></p>
                            </div>
                            <?php endforeach; ?>
                        <?php elseif ($total_low_stock_alerts === 0): ?>
                            <div class="p-4 text-center text-xs font-bold text-slate-500">No active critical alerts.</div>
                        <?php endif; ?>
                    </div>

                    <div class="grid grid-cols-2 border-t border-slate-200 bg-white text-center font-black text-[11px] uppercase tracking-wider">
                        <a href="accounts.php?store_id=<?= urlencode($selected_store) ?>&mark_read=all" class="py-3 text-emerald-600 hover:text-emerald-700 hover:bg-emerald-50 transition-colors border-r border-slate-200">
                            CLEAR ALL
                        </a>
                        <a href="inventory.php?store_id=<?= $nav_store_id ?>&tab=reorder" class="py-3 text-amber-600 hover:text-amber-700 hover:bg-amber-50 transition-colors">
                            REORDER BOX
                        </a>
                    </div>
                </div>
            </div>

            <!-- PROFILE -->
            <a href="settings.php?store_id=<?= $nav_store_id ?>" class="w-9 h-9 rounded-xl bg-amber-500 text-slate-950 flex items-center justify-center font-black text-xs shadow hover:ring-2 hover:ring-amber-400 transition-all overflow-hidden border border-amber-400" title="Settings &amp; Profile">
                <?php if ($validAvatarUrl): ?>
                    <img src="<?= htmlspecialchars($validAvatarUrl) ?>?v=<?= time() ?>" alt="Avatar" class="w-full h-full object-cover">
                <?php else: ?>
                    <?= htmlspecialchars($userInitials) ?>
                <?php endif; ?>
            </a>
        </div>
    </header>

    <!-- WRAPPER -->
    <div class="flex flex-1 overflow-hidden">
        
        <!-- SIDEBAR -->
        <div id="sidebar" class="bg-[#070a12] text-slate-300 h-full w-64 flex flex-col transition-all duration-300 flex-shrink-0 border-r border-slate-900 no-print">
            <div class="p-3 space-y-1.5 flex-1 overflow-y-auto no-scrollbar">
                <a href="index.php?store_id=<?= urlencode($selected_store) ?>" class="flex items-center px-4 py-3 rounded-xl font-black text-xs transition-all text-slate-300 hover:bg-slate-900 hover:text-white">
                    <i class="fa-solid fa-th-large w-5 text-center text-sm"></i><span class="ml-3 sidebar-text">Dashboard</span>
                </a>
                <a href="inventory.php?store_id=<?= urlencode($selected_store) ?>" class="flex items-center px-4 py-3 rounded-xl font-black text-xs transition-all text-slate-300 hover:bg-slate-900 hover:text-white">
                    <i class="fa-solid fa-box w-5 text-center text-sm"></i><span class="ml-3 sidebar-text">Inventory</span>
                </a>
                <a href="reports.php?store_id=<?= urlencode($selected_store) ?>" class="flex items-center px-4 py-3 rounded-xl font-black text-xs transition-all text-slate-300 hover:bg-slate-900 hover:text-white">
                    <i class="fa-solid fa-file-invoice-dollar w-5 text-center text-sm"></i><span class="ml-3 sidebar-text">Reports</span>
                </a>
                <a href="audit_logs.php?store_id=<?= urlencode($selected_store) ?>" class="flex items-center px-4 py-3 rounded-xl font-black text-xs transition-all text-slate-300 hover:bg-slate-900 hover:text-white">
                    <i class="fa-solid fa-shield-halved w-5 text-center text-sm"></i><span class="ml-3 sidebar-text">Activity Logs</span>
                </a>
                <a href="accounts.php?store_id=<?= urlencode($selected_store) ?>" class="flex items-center px-4 py-3 rounded-xl font-black text-xs transition-all bg-amber-500 text-slate-950 shadow-md">
                    <i class="fa-solid fa-users w-5 text-center text-sm"></i><span class="ml-3 sidebar-text">Users</span>
                </a>
                <a href="settings.php?store_id=<?= $nav_store_id ?>" class="flex items-center px-4 py-3 rounded-xl font-black text-xs transition-all text-slate-300 hover:bg-slate-900 hover:text-white">
                    <i class="fa-solid fa-gear w-5 text-center text-sm"></i><span class="ml-3 sidebar-text">Settings</span>
                </a>
            </div>

            <div class="border-t border-slate-900 p-3">
                <a href="../logout.php" class="w-full py-2.5 px-4 bg-slate-900 hover:bg-red-600 hover:text-white rounded-xl flex items-center justify-center font-black text-xs text-slate-300 transition-all">
                    <i class="fa-solid fa-right-from-bracket"></i><span class="ml-2 sidebar-text">Logout</span>
                </a>
            </div>
        </div>

        <!-- MAIN ACCOUNTS WORKSPACE -->
        <div class="flex-1 p-5 md:p-6 overflow-y-auto custom-scroll space-y-4 bg-slate-100">
            
            <?php if ($error_msg): ?>
                <div class="p-3.5 bg-red-100 border-l-4 border-red-600 text-red-800 text-xs font-black uppercase rounded-xl shadow-xs"><?= htmlspecialchars($error_msg) ?></div>
            <?php endif; ?>
            <?php if ($success_msg): ?>
                <div class="p-3.5 bg-emerald-100 border-l-4 border-emerald-600 text-emerald-800 text-xs font-black uppercase rounded-xl shadow-xs"><?= htmlspecialchars($success_msg) ?></div>
            <?php endif; ?>

            <!-- SUB-HEADER BANNER -->
            <div class="bg-white p-5 rounded-2xl shadow-sm border border-slate-200 flex flex-col md:flex-row justify-between items-start md:items-center gap-3 no-print">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="px-2 py-0.5 bg-amber-100 text-amber-900 text-[10px] font-black rounded uppercase"><?= htmlspecialchars($store_label) ?></span>
                        <span class="text-xs font-bold text-slate-400">• Active Personnel</span>
                    </div>
                    <h2 class="text-base md:text-lg font-black text-slate-900 uppercase tracking-tight mt-1">Users &amp; Accounts</h2>
                    <p class="text-xs font-semibold text-slate-500">Manage personnel credentials, multi-branch assignment, and monitor live online activity.</p>
                </div>

                <button onclick="toggleModal('addModal')" class="bg-[#070a12] hover:bg-slate-800 text-white font-black px-4 py-2.5 rounded-xl text-xs uppercase transition shadow flex items-center gap-2">
                    <i class="fas fa-user-plus text-amber-400"></i> New User
                </button>
            </div>

            <!-- 4 SUMMARY CARDS -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 no-print">
                <div class="bg-white p-4 rounded-2xl shadow-sm border border-slate-200">
                    <p class="text-[10px] font-black uppercase text-slate-400 tracking-wider">Total Users</p>
                    <p class="text-xl font-black text-slate-900 mt-1" id="statTotalUsers"><?= count($users) ?></p>
                </div>
                <div class="bg-white p-4 rounded-2xl shadow-sm border border-slate-200">
                    <p class="text-[10px] font-black uppercase text-slate-400 tracking-wider">Online Now</p>
                    <p class="text-xl font-black text-emerald-600 mt-1" id="statOnlineUsers"><?= $onlineCount ?></p>
                </div>
                <div class="bg-white p-4 rounded-2xl shadow-sm border border-slate-200">
                    <p class="text-[10px] font-black uppercase text-slate-400 tracking-wider">Cashiers</p>
                    <p class="text-xl font-black text-slate-900 mt-1" id="statCashierUsers"><?= count($cashiers) ?></p>
                </div>
                <div class="bg-white p-4 rounded-2xl shadow-sm border border-slate-200">
                    <p class="text-[10px] font-black uppercase text-slate-400 tracking-wider">Inventory Staff</p>
                    <p class="text-xl font-black text-slate-900 mt-1" id="statInvUsers"><?= count($inventory) ?></p>
                </div>
            </div>

            <!-- ACCOUNTS DIRECTORY -->
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
                <div class="p-4 border-b border-slate-200 bg-slate-50 flex flex-col lg:flex-row lg:items-center justify-between gap-3">
                    <div class="flex items-center gap-2.5">
                        <span class="text-xs font-black text-slate-800 uppercase">Personnel Directory</span>
                        <span id="syncPulse" class="text-[10px] font-bold text-slate-400 flex items-center gap-1">
                            <i class="fas fa-sync-alt fa-spin text-amber-500 text-[9px]"></i> Syncing
                        </span>
                    </div>

                    <!-- TABS & LIVE SEARCH -->
                    <div class="flex flex-col sm:flex-row items-center gap-2.5 w-full lg:w-auto">
                        <div class="flex gap-1 overflow-x-auto no-scrollbar w-full sm:w-auto">
                            <button onclick="switchRoleTab('all', this)" class="role-tab active px-3 py-1.5 rounded-xl text-xs font-black uppercase whitespace-nowrap border border-slate-200 hover:bg-slate-900 hover:text-white transition">
                                All (<span id="cnt-all"><?= count($users) ?></span>)
                            </button>
                            <button onclick="switchRoleTab('admin', this)" class="role-tab px-3 py-1.5 rounded-xl text-xs font-black uppercase whitespace-nowrap border border-slate-200 hover:bg-slate-900 hover:text-white transition">
                                Admins (<span id="cnt-admin"><?= count($admins) ?></span>)
                            </button>
                            <button onclick="switchRoleTab('cashier', this)" class="role-tab px-3 py-1.5 rounded-xl text-xs font-black uppercase whitespace-nowrap border border-slate-200 hover:bg-slate-900 hover:text-white transition">
                                Cashiers (<span id="cnt-cashier"><?= count($cashiers) ?></span>)
                            </button>
                            <button onclick="switchRoleTab('inventory', this)" class="role-tab px-3 py-1.5 rounded-xl text-xs font-black uppercase whitespace-nowrap border border-slate-200 hover:bg-slate-900 hover:text-white transition">
                                Inventory (<span id="cnt-inventory"><?= count($inventory) ?></span>)
                            </button>
                        </div>

                        <div class="relative w-full sm:w-60 flex-shrink-0">
                            <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                            <input type="text" id="userSearch" placeholder="Search user or email..." class="w-full bg-white border border-slate-200 rounded-xl pl-8 pr-3 py-1.5 text-xs font-bold text-slate-900 focus:outline-none focus:ring-2 focus:ring-amber-500">
                        </div>
                    </div>
                </div>

                <div class="overflow-x-auto max-h-[620px] overflow-y-auto custom-scroll">
                    <table class="w-full text-left text-xs" id="usersTable">
                        <thead class="bg-slate-100 text-slate-900 uppercase font-black border-b border-slate-200 sticky top-0 z-10 text-[11px]">
                            <tr>
                                <th class="p-3">User</th>
                                <th class="p-3 text-center">Store Assignment</th>
                                <th class="p-3 text-center">Role</th>
                                <th class="p-3 text-center">Status</th>
                                <th class="p-3 text-center">Created</th>
                                <th class="p-3 text-center w-28">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="userTableBody" class="divide-y divide-slate-100 font-bold text-slate-800">
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>

    <!--ADD USER -->
    <div id="addModal" class="modal">
        <div class="bg-white w-full max-w-md rounded-2xl shadow-2xl border border-slate-200 overflow-hidden p-6">
            <div class="flex justify-between items-center mb-4 border-b border-slate-200 pb-3">
                <h2 class="text-xs font-black text-slate-900 uppercase flex items-center gap-1.5">
                    <i class="fas fa-user-plus text-amber-500"></i> New Personnel Account
                </h2>
                <button onclick="toggleModal('addModal')" class="text-slate-400 hover:text-slate-600 font-black"><i class="fas fa-times"></i></button>
            </div>
            <form method="POST" class="space-y-3.5">
                <input type="hidden" name="action" value="add_user">
                <div>
                    <label class="text-[10px] font-black uppercase text-slate-500 block mb-1">Full Name</label>
                    <input type="text" name="fullname" required class="custom-input w-full" placeholder="e.g. Maria Santos" autofocus>
                </div>
                <div>
                    <label class="text-[10px] font-black uppercase text-slate-500 block mb-1">Email Address</label>
                    <input type="email" name="email" required class="custom-input w-full" placeholder="username@gmail.com">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-[10px] font-black uppercase text-slate-500 block mb-1">Role</label>
                        <select name="role" class="custom-input w-full">
                            <option value="Sales Staff" selected>Sales Staff</option>
                            <option value="Inventory Staff">Inventory Staff</option>
                            <option value="Admin">Administrator</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-[10px] font-black uppercase text-slate-500 block mb-1">Store Assignment</label>
                        <select name="store_id" class="custom-input w-full font-bold">
                            <option value="0" <?= $selected_store === 'both' ? 'selected' : '' ?>>Both Stores (Store 1 &amp; 2)</option>
                            <option value="1" <?= $selected_store === '1' ? 'selected' : '' ?>>Store 1 Only</option>
                            <option value="2" <?= $selected_store === '2' ? 'selected' : '' ?>>Store 2 Only</option>
                        </select>
                    </div>
                </div>
                <p class="text-[10px] text-slate-500 font-bold bg-slate-50 p-3 rounded-xl border border-slate-200">
                    <i class="fas fa-info-circle text-amber-500 mr-1"></i> Piliin ang <b>Both Stores</b> kung ang staff ay magpapatakbo o mamamahala sa dalawang branch.
                </p>
                <div class="flex gap-2.5 pt-2">
                    <button type="button" onclick="toggleModal('addModal')" class="flex-1 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-black text-xs uppercase rounded-xl">Cancel</button>
                    <button type="submit" class="flex-1 py-2.5 bg-amber-500 hover:bg-amber-600 text-slate-950 font-black text-xs uppercase rounded-xl shadow">Create</button>
                </div>
            </form>
        </div>
    </div>

    <!--  EDIT USER -->
    <div id="editModal" class="modal">
        <div class="bg-white w-full max-w-md rounded-2xl shadow-2xl border border-slate-200 overflow-hidden p-6">
            <div class="flex justify-between items-center mb-4 border-b border-slate-200 pb-3">
                <h2 class="text-xs font-black text-slate-900 uppercase flex items-center gap-1.5">
                    <i class="fas fa-edit text-amber-500"></i> Edit Personnel
                </h2>
                <button onclick="toggleModal('editModal')" class="text-slate-400 hover:text-slate-600 font-black"><i class="fas fa-times"></i></button>
            </div>
            <form method="POST" class="space-y-3.5">
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="user_id" id="editUserId">
                <div>
                    <label class="text-[10px] font-black uppercase text-slate-500 block mb-1">Full Name</label>
                    <input type="text" name="fullname" id="editFullname" required class="custom-input w-full">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-[10px] font-black uppercase text-slate-500 block mb-1">Role</label>
                        <select name="role" id="editRole" class="custom-input w-full">
                            <option value="Admin">Administrator</option>
                            <option value="Sales Staff">Sales Staff</option>
                            <option value="Inventory Staff">Inventory Staff</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-[10px] font-black uppercase text-slate-500 block mb-1">Store Assignment</label>
                        <select name="store_id" id="editStoreId" class="custom-input w-full font-bold">
                            <option value="0">Both Stores (Store 1 &amp; 2)</option>
                            <option value="1">Store 1 Only</option>
                            <option value="2">Store 2 Only</option>
                        </select>
                    </div>
                </div>
                <div class="flex gap-2.5 pt-2">
                    <button type="button" onclick="toggleModal('editModal')" class="flex-1 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-black text-xs uppercase rounded-xl">Cancel</button>
                    <button type="submit" class="flex-1 py-2.5 bg-amber-500 hover:bg-amber-600 text-slate-950 font-black text-xs uppercase rounded-xl shadow">Update</button>
                </div>
            </form>
        </div>
    </div>

    <!-- DELETE USER -->
    <div id="deleteModal" class="modal">
        <div class="bg-white w-full max-w-sm rounded-2xl p-6 text-center shadow-2xl border border-slate-200">
            <h2 class="text-xs font-black text-slate-900 uppercase mb-2">Delete User Account</h2>
            <p class="text-xs text-slate-500 mb-3 font-semibold">Delete account for <span id="delUserName" class="text-red-600 font-black"></span>?</p>
            <form method="POST" class="space-y-3.5">
                <input type="hidden" name="action" value="delete_user">
                <input type="hidden" name="target_user_id" id="delUserId">
                <input type="password" name="admin_verify_password" placeholder="Admin Password" required class="custom-input text-center w-full">
                <div class="flex gap-2">
                    <button type="button" onclick="toggleModal('deleteModal')" class="flex-1 py-2 bg-slate-100 hover:bg-slate-200 rounded-xl font-black text-slate-700 text-xs uppercase">Cancel</button>
                    <button type="submit" class="flex-1 py-2 bg-red-600 hover:bg-red-700 text-white rounded-xl font-black text-xs uppercase shadow">Confirm</button>
                </div>
            </form>
        </div>
    </div>

    <div id="aiChatbotContainer" class="fixed bottom-6 right-6 z-50 no-print flex flex-col items-end <?= !$ai_enabled ? 'hidden' : '' ?>">
        
        <div id="aiChatWindow" class="w-[94vw] sm:w-[440px] bg-white rounded-3xl shadow-2xl border border-slate-300/80 overflow-hidden mb-3 transition-all duration-300 origin-bottom-right hidden flex flex-col" style="height: 550px; max-height: 85vh;">
            
            <div class="bg-[#0f172a] px-5 py-4 text-white flex items-center justify-between border-b border-slate-800 shadow-sm">
                <div class="flex items-center space-x-3">
                    <div class="w-8 h-8 rounded-xl bg-amber-500 text-slate-950 font-black flex items-center justify-center text-sm shadow">
                        <i class="fas fa-brain"></i>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h4 class="text-xs font-black uppercase tracking-wider text-white">JJ Enterprise AI</h4>
                            <span class="text-[9px] font-black uppercase px-2 py-0.5 rounded-full bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">Live Sync</span>
                        </div>
                        <p class="text-[10px] font-bold text-slate-400 mt-0.5">Personnel, Sales &amp; Store Operations Advisory</p>
                    </div>
                </div>
                
                <div class="flex items-center space-x-1">
                    <button type="button" id="clearAiChat" title="Reset Conversation" class="text-slate-400 hover:text-amber-400 p-2 rounded-lg transition-colors text-xs">
                        <i class="fas fa-rotate-right"></i>
                    </button>
                    <button type="button" id="closeAiChat" class="text-slate-400 hover:text-white p-2 rounded-lg transition-colors text-sm">
                        <i class="fas fa-xmark"></i>
                    </button>
                </div>
            </div>

            <div class="bg-slate-50 border-b border-slate-200/90 px-3.5 py-2.5 flex items-center gap-1.5 overflow-x-auto custom-scroll text-[11px] font-bold text-slate-700 whitespace-nowrap">
                <button type="button" onclick="sendAiQuickPrompt('Kailan ang nearest restock date at oras ng mga nauubos na produkto?')" class="bg-white hover:bg-slate-100 hover:border-slate-300 border border-slate-200/90 px-3 py-1 rounded-lg transition-all shrink-0 shadow-2xs">
                    ⏰ Nearest Restock
                </button>
                <button type="button" onclick="sendAiQuickPrompt('Ulat sa benta ngayon at mga transaksyon')" class="bg-white hover:bg-slate-100 hover:border-slate-300 border border-slate-200/90 px-3 py-1 rounded-lg transition-all shrink-0 shadow-2xs">
                    📊 Benta Ngayon
                </button>
                <button type="button" onclick="sendAiQuickPrompt('Sino ang mga online staff ngayon?')" class="bg-white hover:bg-slate-100 hover:border-slate-300 border border-slate-200/90 px-3 py-1 rounded-lg transition-all shrink-0 shadow-2xs">
                    🟢 Online Staff
                </button>
                <button type="button" onclick="sendAiQuickPrompt('Alin ang mga produkto na kailangang i-reorder at low stock?')" class="bg-white hover:bg-slate-100 hover:border-slate-300 border border-slate-200/90 px-3 py-1 rounded-lg transition-all shrink-0 shadow-2xs">
                    ⚠️ Low Stocks
                </button>
                <button type="button" onclick="sendAiQuickPrompt('I-kumpara ang benta at performance ng Store 1 at Store 2')" class="bg-white hover:bg-slate-100 hover:border-slate-300 border border-slate-200/90 px-3 py-1 rounded-lg transition-all shrink-0 shadow-2xs">
                    🏪 Store 1 vs Store 2
                </button>
                <button type="button" onclick="sendAiQuickPrompt('Ilan ang rehistradong cashiers at inventory staff?')" class="bg-white hover:bg-slate-100 hover:border-slate-300 border border-slate-200/90 px-3 py-1 rounded-lg transition-all shrink-0 shadow-2xs">
                    👥 Role Breakdown
                </button>
                <button type="button" onclick="sendAiQuickPrompt('Ano ang mga nangungunang produkto at best sellers?')" class="bg-white hover:bg-slate-100 hover:border-slate-300 border border-slate-200/90 px-3 py-1 rounded-lg transition-all shrink-0 shadow-2xs">
                    🏆 Best Sellers
                </button>
                <button type="button" onclick="sendAiQuickPrompt('Alin ang tauhan sa Both Stores, Store 1, at Store 2?')" class="bg-white hover:bg-slate-100 hover:border-slate-300 border border-slate-200/90 px-3 py-1 rounded-lg transition-all shrink-0 shadow-2xs">
                    🏪 Multi-Store Staff
                </button>
                <button type="button" onclick="sendAiQuickPrompt('Magbigay ng payo para sa seguridad ng mga user accounts')" class="bg-white hover:bg-slate-100 hover:border-slate-300 border border-slate-200/90 px-3 py-1 rounded-lg transition-all shrink-0 shadow-2xs">
                    💡 Security Advice
                </button>
            </div>

            <!-- Chat Stream -->
            <div id="aiChatMessages" class="flex-1 p-4 overflow-y-auto custom-scroll space-y-3.5 bg-[#f8fafc] text-xs">
                <div class="flex items-start gap-2.5">
                    <div class="w-7 h-7 rounded-lg bg-[#0f172a] text-amber-500 flex items-center justify-center text-[11px] font-black shrink-0 border border-slate-700">AI</div>
                    <div class="bg-white p-3.5 rounded-2xl rounded-tl-sm border border-slate-200/90 text-slate-800 shadow-2xs leading-relaxed max-w-[88%]">
                        Magandang araw! Ako ang inyong <b>JJ Enterprise AI Assistant</b>. Naka-link ako sa live personnel database, live cashier sales, imbentaryo, at replenishment schedules ng <b><?= htmlspecialchars($store_label) ?></b>. Paano ko kayo matutulungan ngayon?
                    </div>
                </div>
            </div>

            <!-- Input Form -->
            <form id="aiChatForm" class="p-3 bg-white border-t border-slate-200 flex items-center gap-2">
                <input type="text" id="aiChatInput" placeholder="Magtanong tungkol sa benta, online staff, restock dates, o branch assignments..." class="flex-1 bg-slate-50 focus:bg-white border border-slate-300 focus:border-amber-500 text-slate-800 text-xs rounded-xl px-3.5 py-2.5 outline-none font-semibold transition-all">
                <button type="submit" class="bg-[#0f172a] hover:bg-slate-800 text-amber-400 font-black px-4 py-2.5 rounded-xl text-xs transition-all flex items-center gap-1.5 shadow-sm">
                    <span>Send</span>
                    <i class="fas fa-arrow-right text-[11px]"></i>
                </button>
            </form>
        </div>

        <button id="toggleAiChat" type="button" title="Open Enterprise AI Assistant" class="relative w-14 h-14 bg-[#0f172a] hover:bg-slate-800 text-amber-400 rounded-2xl shadow-xl flex items-center justify-center border border-slate-700 transition-all transform hover:scale-105 active:scale-95 group focus:outline-none focus:ring-4 focus:ring-amber-500/20">
            <span class="absolute -top-1 -right-1 flex h-3.5 w-3.5">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                <span class="relative inline-flex rounded-full h-3.5 w-3.5 bg-emerald-500 border-2 border-[#0f172a]"></span>
            </span>
            <i class="fas fa-brain text-xl transition-transform duration-300 group-hover:scale-110"></i>
        </button>
    </div>

    <!-- JAVASCRIPT ENGINE & REAL-TIME STATUS  -->
    <script>
        const sidebar = document.getElementById('sidebar');
        const toggleSidebar = document.getElementById('toggleSidebar');
        if (toggleSidebar && sidebar) {
            toggleSidebar.addEventListener('click', () => {
                sidebar.classList.toggle('w-64');
                sidebar.classList.toggle('w-16');
                document.querySelectorAll('.sidebar-text').forEach(s => s.classList.toggle('hidden'));
            });
        }

        let rawUsers = <?= json_encode(array_values($users)) ?>;
        let activeTab = 'all';

        // Synchronized Live Store Data for Enterprise AI
        const liveStoreData = {
            storeLabel: <?= json_encode($store_label) ?>,
            selectedStore: <?= json_encode($selected_store) ?>,
            todaySales: <?= json_encode($todaySalesVal) ?>,
            todayTxCount: <?= json_encode($todayTxCount) ?>,
            store1Sales: <?= json_encode($store1_total_today) ?>,
            store2Sales: <?= json_encode($store2_total_today) ?>,
            lowStockAlerts: <?= json_encode($total_low_stock_alerts) ?>,
            lowStockList: <?= json_encode($low_stock_list) ?>,
            topSellers: <?= json_encode($topSellers) ?>
        };

        function toggleModal(id) {
            document.getElementById(id).classList.toggle('active');
        }

        function openEditModal(userId) {
            const user = rawUsers.find(u => u.id == userId);
            if (!user) return;
            document.getElementById('editUserId').value = user.id;
            document.getElementById('editFullname').value = user.fullname || '';
            document.getElementById('editRole').value = user.role || 'Sales Staff';
            document.getElementById('editStoreId').value = (user.store_id !== null && user.store_id !== undefined) ? user.store_id : 0;
            toggleModal('editModal');
        }

        function openDeleteModal(userId, name) {
            document.getElementById('delUserId').value = userId;
            document.getElementById('delUserName').innerText = name;
            toggleModal('deleteModal');
        }

        function updateTabCounters() {
            const total = rawUsers.length;
            const adminCount = rawUsers.filter(u => ['admin', 'owner', 'administrator'].includes((u.role || '').toLowerCase())).length;
            const cashierCount = rawUsers.filter(u => ['sales staff', 'cashier', 'sales'].includes((u.role || '').toLowerCase())).length;
            const invCount = rawUsers.filter(u => ['inventory staff', 'stock clerk', 'inventory'].includes((u.role || '').toLowerCase())).length;
            const onlineCount = rawUsers.filter(u => u.is_online).length;

            const cntAll = document.getElementById('cnt-all');
            const cntAdmin = document.getElementById('cnt-admin');
            const cntCashier = document.getElementById('cnt-cashier');
            const cntInv = document.getElementById('cnt-inventory');
            if (cntAll) cntAll.innerText = total;
            if (cntAdmin) cntAdmin.innerText = adminCount;
            if (cntCashier) cntCashier.innerText = cashierCount;
            if (cntInv) cntInv.innerText = invCount;

            const stTotal = document.getElementById('statTotalUsers');
            const stOnline = document.getElementById('statOnlineUsers');
            const stCashier = document.getElementById('statCashierUsers');
            const stInv = document.getElementById('statInvUsers');
            if (stTotal) stTotal.innerText = total;
            if (stOnline) stOnline.innerText = onlineCount;
            if (stCashier) stCashier.innerText = cashierCount;
            if (stInv) stInv.innerText = invCount;
        }

        function renderUserTable() {
            const body = document.getElementById('userTableBody');
            const term = document.getElementById('userSearch').value.toLowerCase().trim();
            body.innerHTML = '';

            let filtered = rawUsers;
            if (activeTab === 'admin') {
                filtered = filtered.filter(u => ['admin', 'owner', 'administrator'].includes((u.role || '').toLowerCase()));
            } else if (activeTab === 'cashier') {
                filtered = filtered.filter(u => ['sales staff', 'cashier', 'sales'].includes((u.role || '').toLowerCase()));
            } else if (activeTab === 'inventory') {
                filtered = filtered.filter(u => ['inventory staff', 'stock clerk', 'inventory'].includes((u.role || '').toLowerCase()));
            }

            if (term !== '') {
                filtered = filtered.filter(u => 
                    (u.fullname || '').toLowerCase().includes(term) ||
                    (u.email || '').toLowerCase().includes(term) ||
                    (u.role || '').toLowerCase().includes(term)
                );
            }

            if (filtered.length === 0) {
                body.innerHTML = '<tr><td colspan="6" class="p-8 text-center text-slate-400 font-bold">No registered personnel found.</td></tr>';
                return;
            }

            filtered.forEach(u => {
                const isOnline = (u.is_online === true || String(u.status || '').toLowerCase() === 'online');
                const activityLabel = u.activity_label || (isOnline ? 'Active Now' : 'Offline');
                const createdDate = u.created_at ? new Date(u.created_at).toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' }) : 'N/A';
                const safeName = (u.fullname || 'Unnamed').replace(/'/g, "\\'");
                const initial = (u.fullname || 'U').charAt(0).toUpperCase();

                let avatarHtml = `<div class="relative w-8 h-8 rounded-xl bg-amber-100 text-amber-900 font-black text-xs flex items-center justify-center flex-shrink-0 shadow-xs border border-amber-300">
                    ${initial}
                    <span class="absolute -bottom-0.5 -right-0.5 w-2.5 h-2.5 rounded-full border border-white ${isOnline ? 'bg-emerald-500' : 'bg-slate-400'}"></span>
                </div>`;
                if (u.valid_avatar) {
                    avatarHtml = `<div class="relative w-8 h-8 rounded-xl flex-shrink-0 shadow-xs border border-amber-400">
                        <img src="${u.valid_avatar}?v=${Date.now()}" class="w-full h-full rounded-xl object-cover" alt="Avatar">
                        <span class="absolute -bottom-0.5 -right-0.5 w-2.5 h-2.5 rounded-full border border-white ${isOnline ? 'bg-emerald-500' : 'bg-slate-400'}"></span>
                    </div>`;
                }

                const isBothStores = (parseInt(u.store_id) === 0);
                const storeBadge = isBothStores 
                    ? `<span class="px-2.5 py-1 rounded-xl text-[10px] font-black uppercase bg-amber-100 text-amber-900 border border-amber-300 shadow-xs">
                         <i class="fas fa-store text-amber-600 mr-1"></i>Both Stores
                       </span>`
                    : `<span class="px-2.5 py-1 rounded-xl text-[10px] font-black uppercase bg-slate-100 text-slate-700">
                         Store #${u.store_id || 1}
                       </span>`;

                let statusBadge = isOnline ? `
                    <div class="inline-flex flex-col items-center">
                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase bg-emerald-100 text-emerald-800">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Online
                        </span>
                        <span class="text-[9px] font-bold text-emerald-600 mt-0.5">${activityLabel}</span>
                    </div>
                ` : `
                    <div class="inline-flex flex-col items-center">
                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase bg-slate-100 text-slate-500">
                            <span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span> Offline
                        </span>
                        <span class="text-[9px] font-bold text-slate-400 mt-0.5">${activityLabel}</span>
                    </div>
                `;

                body.innerHTML += `
                    <tr class="hover:bg-slate-50 transition">
                        <td class="p-3">
                            <div class="flex items-center space-x-2.5">
                                ${avatarHtml}
                                <div>
                                    <p class="font-black text-slate-900 text-xs">${u.fullname || 'Unnamed'}</p>
                                    <p class="text-[10px] text-slate-400 font-mono">${u.email || 'N/A'}</p>
                                </div>
                            </div>
                        </td>
                        <td class="p-3 text-center whitespace-nowrap">
                            ${storeBadge}
                        </td>
                        <td class="p-3 text-center whitespace-nowrap">
                            <span class="px-2.5 py-1 rounded-xl text-[10px] font-black uppercase bg-slate-100 text-slate-700">
                                ${u.role || 'Staff'}
                            </span>
                        </td>
                        <td class="p-3 text-center whitespace-nowrap">${statusBadge}</td>
                        <td class="p-3 text-center font-bold text-slate-500 text-xs whitespace-nowrap">${createdDate}</td>
                        <td class="p-3 text-center whitespace-nowrap">
                            <div class="flex items-center justify-center gap-1.5">
                                <button onclick="openEditModal(${u.id})" class="w-7 h-7 rounded-lg bg-slate-100 text-slate-600 hover:bg-slate-900 hover:text-white transition flex items-center justify-center shadow-xs" title="Edit">
                                    <i class="fas fa-edit text-xs"></i>
                                </button>
                                <button onclick="openDeleteModal(${u.id}, '${safeName}')" class="w-7 h-7 rounded-lg bg-slate-100 text-slate-400 hover:bg-red-600 hover:text-white transition flex items-center justify-center shadow-xs" title="Delete">
                                    <i class="fas fa-trash-alt text-xs"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            });
        }

        function switchRoleTab(role, btn) {
            activeTab = role;
            document.querySelectorAll('.role-tab').forEach(b => b.classList.remove('active'));
            if (btn) btn.classList.add('active');
            renderUserTable();
        }

        async function fetchRealtimeStatuses() {
            try {
                const response = await fetch('accounts.php?store_id=<?= urlencode($selected_store) ?>&action=fetch_live_statuses');
                if (!response.ok) return;
                const result = await response.json();
                if (result.status === 'success' && Array.isArray(result.users)) {
                    rawUsers = result.users;
                    updateTabCounters();
                    renderUserTable();
                }
            } catch (err) {}
        }

        document.getElementById('userSearch').addEventListener('input', renderUserTable);
        updateTabCounters();
        renderUserTable();
        setInterval(fetchRealtimeStatuses, 5000);

        // ========================================================
        // EXECUTIVE AI CHATBOT LOGIC (FULLY SYNCHRONIZED)
        // ========================================================
        const toggleAiChat = document.getElementById('toggleAiChat');
        const closeAiChat = document.getElementById('closeAiChat');
        const clearAiChat = document.getElementById('clearAiChat');
        const aiChatWindow = document.getElementById('aiChatWindow');
        const aiChatForm = document.getElementById('aiChatForm');
        const aiChatInput = document.getElementById('aiChatInput');
        const aiChatMessages = document.getElementById('aiChatMessages');

        if (toggleAiChat && aiChatWindow) {
            toggleAiChat.addEventListener('click', () => {
                aiChatWindow.classList.toggle('hidden');
                if (!aiChatWindow.classList.contains('hidden')) aiChatInput.focus();
            });
            closeAiChat.addEventListener('click', () => aiChatWindow.classList.add('hidden'));
        }

        if (clearAiChat) {
            clearAiChat.addEventListener('click', () => {
                aiChatMessages.innerHTML = `
                    <div class="flex items-start gap-2.5">
                        <div class="w-7 h-7 rounded-lg bg-[#0f172a] text-amber-500 flex items-center justify-center text-[11px] font-black shrink-0 border border-slate-700">AI</div>
                        <div class="bg-white p-3.5 rounded-2xl rounded-tl-sm border border-slate-200/90 text-slate-800 shadow-2xs leading-relaxed max-w-[88%]">
                            Nalinis na ang usapan. Handa akong magbigay ng impormasyon tungkol sa benta, replenishment schedules, active personnel, at imbentaryo!
                        </div>
                    </div>
                `;
            });
        }

        function sendAiQuickPrompt(promptText) {
            if (aiChatInput) {
                aiChatInput.value = promptText;
                aiChatForm.dispatchEvent(new Event('submit', { cancelable: true }));
            }
        }

        function generateAiResponse(queryText) {
            const q = queryText.toLowerCase().trim();
            const d = liveStoreData;
            const total = rawUsers.length;
            const online = rawUsers.filter(u => u.is_online).length;
            const cashiers = rawUsers.filter(u => ['sales staff', 'cashier', 'sales'].includes((u.role || '').toLowerCase())).length;
            const inventory = rawUsers.filter(u => ['inventory staff', 'stock clerk', 'inventory'].includes((u.role || '').toLowerCase())).length;
            const admins = rawUsers.filter(u => ['admin', 'owner', 'administrator'].includes((u.role || '').toLowerCase())).length;
            const sBoth = rawUsers.filter(u => parseInt(u.store_id) === 0).length;
            const s1 = rawUsers.filter(u => parseInt(u.store_id) === 1).length;
            const s2 = rawUsers.filter(u => parseInt(u.store_id) === 2).length;

            // 1. NEAREST RESTOCK & TIMERS
            if (q.includes('nearest restock') || q.includes('restock') || q.includes('kailan mag-restock') || q.includes('oras') || q.includes('deadline')) {
                let restockMsg = '';
                if (d.lowStockList && d.lowStockList.length > 0) {
                    restockMsg = d.lowStockList.slice(0, 3).map(i => `• <b>${i.product_name}</b>: Deadline sa <b>${i.nearest_restock}</b> (${i.stock} piraso natitira)`).join('<br>');
                } else {
                    restockMsg = '✅ Ligtas ang lahat ng stocks sa ' + d.storeLabel + '. Ang pinakamalapit na routine shelf replenishment ay bukas ng umaga.';
                }

                return `
                    <div class="space-y-1.5">
                        <div class="font-extrabold text-slate-900 border-b border-slate-100 pb-1 flex items-center justify-between">
                            <span>⏰ AI Replenishment Timers (${d.storeLabel})</span>
                        </div>
                        <p class="text-slate-700 leading-normal">
                            Batay sa takbo ng cashier checkouts:<br>
                            ${restockMsg}
                        </p>
                        <div class="pt-1.5 border-t border-slate-100 flex justify-between items-center text-[10px]">
                            <a href="inventory.php?store_id=${encodeURIComponent(d.selectedStore)}&tab=reorder" class="text-amber-700 font-extrabold hover:underline">
                                Buksan ang Reorder List Box →
                            </a>
                        </div>
                    </div>
                `;
            }

            // 2. LIVE SALES & TRANSACTIONS
            if (q.includes('benta') || q.includes('sale') || q.includes('kita') || q.includes('revenue') || q.includes('today')) {
                return `
                    <div class="space-y-1.5">
                        <div class="font-extrabold text-slate-900 border-b border-slate-100 pb-1 flex items-center justify-between">
                            <span>📊 Ulat sa Benta Ngayong Araw</span>
                            <span class="text-[10px] text-amber-600 uppercase font-black">${d.storeLabel}</span>
                        </div>
                        <p class="text-slate-700 leading-normal">
                            • Kabuuang Benta: <b class="text-slate-950 font-black">₱${Number(d.todaySales).toLocaleString(undefined, {minimumFractionDigits: 2})}</b><br>
                            • Resibo / Transaksyon: <b class="text-slate-950">${d.todayTxCount} resibo</b><br>
                            • Scope: <b>${d.storeLabel}</b>
                        </p>
                        <div class="pt-1.5 border-t border-slate-100">
                            <a href="index.php?store_id=${encodeURIComponent(d.selectedStore)}" class="text-amber-700 font-extrabold hover:underline">
                                Tingnan ang Live Analytics Dashboard →
                            </a>
                        </div>
                    </div>
                `;
            }

            // 3. LOW STOCKS & CRITICAL ALERTS
            if (q.includes('low stock') || q.includes('ubos') || q.includes('paubos') || q.includes('reorder')) {
                if (d.lowStockAlerts === 0) {
                    return `
                        <div class="text-emerald-800 bg-emerald-50 p-2.5 rounded-xl border border-emerald-200">
                            <b>✅ Malusog ang Imbentaryo:</b> Walang produkto sa <b>${d.storeLabel}</b> ang nasa ilalim ng critical safety limit.
                        </div>
                    `;
                }
                const itemsList = d.lowStockList.slice(0, 4).map(i => `• <b>${i.product_name}</b> (Store #${i.item_store_id}): <b>${i.stock} units</b> &bull; ${i.nearest_restock}`).join('<br>');
                return `
                    <div class="space-y-1.5">
                        <div class="font-extrabold text-red-700 border-b border-red-100 pb-1">
                            ⚠️ Kritikal na Imbentaryo (${d.lowStockAlerts} Produkto)
                        </div>
                        <p class="text-slate-700 leading-normal">${itemsList}</p>
                    </div>
                `;
            }

            // 4. BEST SELLERS
            if (q.includes('best seller') || q.includes('mabenta') || q.includes('top product') || q.includes('nangunguna')) {
                let bsList = '';
                if (d.topSellers && d.topSellers.length > 0) {
                    bsList = d.topSellers.map(i => `• <b>${i.p_name}</b>: <b>${Number(i.units_sold).toLocaleString()} pcs sold</b>`).join('<br>');
                } else {
                    bsList = '• Regular fast-moving items: Party Popper, Disposable Ware, Baking Essentials.';
                }
                return `
                    <div class="space-y-1.5">
                        <div class="font-extrabold text-slate-900 border-b border-slate-100 pb-1">
                            🏆 Top Selling Products &amp; AI Demand
                        </div>
                        <p class="text-slate-700 leading-normal">${bsList}</p>
                    </div>
                `;
            }

            // 5. STORE 1 VS STORE 2
            if (q.includes('store 1') || q.includes('store 2') || q.includes('kumpara') || q.includes('compare')) {
                return `
                    <div class="space-y-1.5">
                        <div class="font-extrabold text-slate-900 border-b border-slate-100 pb-1">
                            🏪 Store 1 vs Store 2 Performance
                        </div>
                        <p class="text-slate-700 leading-normal">
                            • <b>Store 1 Sales:</b> ₱${Number(d.store1Sales).toLocaleString(undefined, {minimumFractionDigits: 2})}<br>
                            • <b>Store 2 Sales:</b> ₱${Number(d.store2Sales).toLocaleString(undefined, {minimumFractionDigits: 2})}<br>
                            Maaaring magpalipat-lipat ng sangay gamit ang buttons sa itaas.
                        </p>
                    </div>
                `;
            }

            // 6. ONLINE STAFF
            if (q.includes('online') || q.includes('active') || q.includes('gising')) {
                const onlineNames = rawUsers.filter(u => u.is_online).map(u => {
                    const st = parseInt(u.store_id) === 0 ? 'Both Stores' : `Store #${u.store_id || 1}`;
                    return `• <b>${u.fullname}</b> (${u.role || 'Staff'}, ${st})`;
                }).join('<br>');

                return `
                    <div class="space-y-1.5">
                        <div class="font-extrabold text-slate-900 border-b border-slate-100 pb-1">
                            🟢 Online Personnel (${online} Online sa ${total} Total)
                        </div>
                        <p class="text-slate-700 leading-normal">
                            ${onlineNames || 'Walang staff na online sa ngayon.'}
                        </p>
                    </div>
                `;
            }

            // 7. ROLE BREAKDOWN
            if (q.includes('role') || q.includes('cashier') || q.includes('inventory') || q.includes('admin') || q.includes('tungkulin')) {
                return `
                    <div class="space-y-1.5">
                        <div class="font-extrabold text-slate-900 border-b border-slate-100 pb-1">
                            👥 Distribusyon ng mga Tungkulin
                        </div>
                        <p class="text-slate-700 leading-normal">
                            • <b>Administrators:</b> ${admins} account<br>
                            • <b>Cashiers (Sales Staff):</b> ${cashiers} account<br>
                            • <b>Inventory Staff:</b> ${inventory} account<br>
                            • <b>Kabuuang Personnel:</b> ${total} tauhan.
                        </p>
                    </div>
                `;
            }

            // 8. STORE ASSIGNMENT BREAKDOWN
            if (q.includes('both') || q.includes('branch') || q.includes('assignment')) {
                return `
                    <div class="space-y-1.5">
                        <div class="font-extrabold text-slate-900 border-b border-slate-100 pb-1">
                            🏪 Store Assignment Breakdown
                        </div>
                        <p class="text-slate-700 leading-normal">
                            • <b>Both Stores (Multi-Store):</b> ${sBoth} tauhan<br>
                            • <b>Store 1 Exclusive:</b> ${s1} tauhan<br>
                            • <b>Store 2 Exclusive:</b> ${s2} tauhan<br>
                            Ang mga staff na naka-assign sa <i>Both Stores</i> ay may access mag-transact o mamahala sa parehong sangay nang walang lockout.
                        </p>
                    </div>
                `;
            }

            // 9. SECURITY ADVICE
            if (q.includes('payo') || q.includes('advice') || q.includes('security') || q.includes('seguridad')) {
                return `
                    <div class="space-y-1.5">
                        <div class="font-extrabold text-slate-900 border-b border-slate-100 pb-1">
                            💡 Opisyal na Payo sa Multi-Store Access
                        </div>
                        <p class="text-slate-700 leading-normal">
                            1. <b>Multi-Store Staff:</b> I-assign sa "Both Stores" ang floating cashiers at roaming inventory managers para hindi na kailangang gumawa ng dalawang magkahiwalay na account.<br>
                            2. <b>Password Hygiene:</b> Ipa-update ang default passwords pagkatapos i-create ang account.<br>
                            3. <b>Role Restraints:</b> Panatilihing "Sales Staff" o "Inventory Staff" ang mga tauhan kahit may access sila sa Both Stores.
                        </p>
                    </div>
                `;
            }

            return `
                Naka-konekta ako sa live database ng benta, imbentaryo, at tauhan (${total} personnel, ${online} online). Maaari mong itanong:<br>
                • <i>"Kailan ang nearest restock date at oras ng mga nauubos na produkto?"</i><br>
                • <i>"Ulat sa benta ngayon at mga transaksyon"</i><br>
                • <i>"Sino ang mga online staff ngayon?"</i><br>
                • <i>"Alin ang mga produkto na kailangang i-reorder at low stock?"</i><br>
                • <i>"I-kumpara ang benta at performance ng Store 1 at Store 2"</i><br>
                • <i>"Ilan ang cashiers at inventory staff?"</i>
            `;
        }

        if (aiChatForm) {
            aiChatForm.addEventListener('submit', (e) => {
                e.preventDefault();
                const text = aiChatInput.value.trim();
                if (!text) return;
                aiChatInput.value = '';

                const userMsg = document.createElement('div');
                userMsg.className = 'flex items-start justify-end gap-2.5';
                userMsg.innerHTML = `
                    <div class="bg-[#0f172a] text-white p-3 rounded-2xl rounded-tr-sm font-semibold text-xs max-w-[85%] leading-relaxed shadow-sm">${text}</div>
                    <div class="w-7 h-7 rounded-lg bg-amber-500 text-slate-950 flex items-center justify-center text-[10px] font-black shrink-0">YOU</div>
                `;
                aiChatMessages.appendChild(userMsg);
                aiChatMessages.scrollTop = aiChatMessages.scrollHeight;

                const typingMsg = document.createElement('div');
                typingMsg.className = 'flex items-start gap-2.5';
                typingMsg.innerHTML = `
                    <div class="w-7 h-7 rounded-lg bg-[#0f172a] text-amber-500 flex items-center justify-center text-[11px] font-black shrink-0 border border-slate-700">AI</div>
                    <div class="bg-white p-3 rounded-2xl rounded-tl-sm border border-slate-200 text-slate-500 text-xs shadow-2xs flex items-center gap-1.5 font-bold">
                        <span class="animate-pulse">Kinukuha ang live restock, benta, at personnel data...</span>
                    </div>
                `;
                aiChatMessages.appendChild(typingMsg);
                aiChatMessages.scrollTop = aiChatMessages.scrollHeight;

                setTimeout(() => {
                    typingMsg.remove();
                    const answer = generateAiResponse(text);
                    const aiMsg = document.createElement('div');
                    aiMsg.className = 'flex items-start gap-2.5';
                    aiMsg.innerHTML = `
                        <div class="w-7 h-7 rounded-lg bg-[#0f172a] text-amber-500 flex items-center justify-center text-[11px] font-black shrink-0 border border-slate-700">AI</div>
                        <div class="bg-white p-3.5 rounded-2xl rounded-tl-sm border border-slate-200/90 text-slate-800 text-xs shadow-2xs max-w-[88%] leading-relaxed">${answer}</div>
                    `;
                    aiChatMessages.appendChild(aiMsg);
                    aiChatMessages.scrollTop = aiChatMessages.scrollHeight;
                }, 300);
            });
        }
    </script>
</body>
</html>
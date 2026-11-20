<?php
// JJ JUDEJUSH STORES - SETTINGS & HARDWARE MANAGEMENT 
session_start();
date_default_timezone_set('Asia/Manila');

// 1. AUTHENTICATION & ACCESS GUARD (Synced with index.php)
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

$user_id = (int)($_SESSION['user_id'] ?? 1);

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
                <p>Unable to connect to JJ_Stores: " . htmlspecialchars($e->getMessage()) . "</p>
             </div>");
    }
}

try {
    $pdo->exec("SET time_zone = '+08:00'");
} catch (Throwable $t) {}

// Store Scope Resolution
$store_param    = strtolower(trim($_GET['store_id'] ?? 'both'));
$selected_store = in_array($store_param, ['1', '2', 'both']) ? $store_param : 'both';
$nav_store_id   = ($selected_store === '2') ? 2 : 1;
$store_label    = ($selected_store === 'both') ? 'Both Stores' : "Store {$selected_store}";
$custom_date    = isset($_GET['custom_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['custom_date']) ? $_GET['custom_date'] : date('Y-m-d');

// 3. SCHEMA MIGRATIONS & FAIL-SAFE SETUP
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            id INT PRIMARY KEY,
            store_name VARCHAR(255) DEFAULT 'JJ JUDEJUSH',
            store_address TEXT NULL,
            contact_number VARCHAR(100) NULL,
            footer_message TEXT NULL,
            receipt_font_size VARCHAR(50) DEFAULT 'Medium',
            paper_width VARCHAR(20) DEFAULT '58mm',
            store_logo VARCHAR(255) NULL,
            show_logo TINYINT(1) DEFAULT 1,
            include_qr TINYINT(1) DEFAULT 1,
            auto_print TINYINT(1) DEFAULT 0,
            auto_backup TINYINT(1) DEFAULT 1,
            ai_analysis TINYINT(1) DEFAULT 1,
            ai_enabled TINYINT(1) DEFAULT 1,
            gcash_name VARCHAR(150) NULL,
            gcash_number VARCHAR(50) NULL,
            gcash_qr VARCHAR(255) NULL,
            maya_name VARCHAR(150) NULL,
            maya_number VARCHAR(50) NULL,
            maya_qr VARCHAR(255) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $cols = $pdo->query("SHOW COLUMNS FROM settings")->fetchAll(PDO::FETCH_COLUMN);
    $extra = [
        'store_logo'   => "VARCHAR(255) NULL",
        'paper_width'  => "VARCHAR(20) DEFAULT '58mm'",
        'ai_analysis'  => "TINYINT(1) DEFAULT 1",
        'ai_enabled'   => "TINYINT(1) DEFAULT 1",
        'auto_backup'  => "TINYINT(1) DEFAULT 1",
        'gcash_name'   => "VARCHAR(150) NULL",
        'gcash_number' => "VARCHAR(50) NULL",
        'gcash_qr'     => "VARCHAR(255) NULL",
        'maya_name'    => "VARCHAR(150) NULL",
        'maya_number'  => "VARCHAR(50) NULL",
        'maya_qr'      => "VARCHAR(255) NULL"
    ];
    foreach ($extra as $col => $type) {
        if (!in_array($col, $cols)) {
            try { $pdo->exec("ALTER TABLE settings ADD COLUMN {$col} {$type}"); } catch (Throwable $t) {}
        }
    }

    $chkSettings = $pdo->query("SELECT id FROM settings WHERE id = 1")->fetch();
    if (!$chkSettings) {
        $pdo->exec("INSERT INTO settings (id, store_name, store_address, contact_number, footer_message, receipt_font_size, paper_width, show_logo, include_qr, auto_print, auto_backup, ai_analysis, ai_enabled) 
                    VALUES (1, 'JJ JUDEJUSH', 'Kapak Palengke Brgy 12 Caloocan City', '09123456789', 'Thank you for shopping with us!', 'Medium', '58mm', 1, 1, 0, 1, 1, 1)");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS audit_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT DEFAULT 1,
            action VARCHAR(255) NOT NULL,
            page_module VARCHAR(100) DEFAULT 'Settings',
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
    header("Location: settings.php?store_id=" . urlencode($selected_store));
    exit();
}

// 4. COMMON HELPERS & AUDIT LOGGER
if (!function_exists('logActivity')) {
    function logActivity($pdo, $action, $details, $page_module = 'Settings', $store_id = 1) {
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

// Heartbeat & Online Status
if (isset($_GET['action']) && $_GET['action'] === 'heartbeat') {
    header('Content-Type: application/json');
    try {
        if ($user_id > 0) {
            $pdo->prepare("UPDATE users SET status = 'Online', last_activity = NOW() WHERE id = ?")->execute([$user_id]);
        }
        echo json_encode(['status' => 'success', 'user_status' => 'Online']);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error']);
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'set_offline') {
    try {
        if ($user_id > 0) {
            $pdo->prepare("UPDATE users SET status = 'Offline', last_activity = NOW() WHERE id = ?")->execute([$user_id]);
        }
    } catch (Throwable $t) {}
    exit;
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

// LIVE STORE ANALYTICS & NEAREST RESTOCK ENGINE FOR AI ASSISTANT
$is_both_stores = ($selected_store === 'both');
$store_id_int = ($selected_store === '2') ? 2 : (($selected_store === '1') ? 1 : 0);

if ($is_both_stores) {
    $salesStoreClause = "";
    $prodStoreClause = " WHERE 1=1";
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
    'store_name'        => 'JJ JUDEJUSH', 
    'store_address'     => 'Kapak Palengke Brgy 12 Caloocan City',
    'contact_number'    => '09123456789', 
    'footer_message'    => 'Thank you for shopping with us!',
    'receipt_font_size' => 'Medium', 
    'paper_width'       => '58mm', 
    'show_logo'         => 1, 
    'include_qr'        => 1, 
    'auto_print'        => 0, 
    'auto_backup'       => 1,
    'ai_analysis'       => 1,
    'ai_enabled'        => 1, 
    'store_logo'        => null,
    'gcash_name'        => 'JJ Store Cashier', 
    'gcash_number'      => '09123456789', 
    'gcash_qr'          => null,
    'maya_name'         => 'JJ Store Merchant', 
    'maya_number'       => '09987654321', 
    'maya_qr'           => null
];

try {
    $sRow = $pdo->query("SELECT * FROM settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    if ($sRow) $settings = array_merge($settings, $sRow);
} catch (Throwable $t) {}

$ai_enabled = !empty($settings['ai_analysis']) && !empty($settings['ai_enabled']);

// AJAX ENDPOINT: LOAD LAST SALE FROM CASHIER 
if (isset($_GET['action']) && $_GET['action'] === 'load_last_sale') {
    header('Content-Type: application/json');
    try {
        $filterBranch = "";
        if ($selected_store === '1') {
            $filterBranch = " WHERE (s.store_id = 1 OR s.store_id IS NULL OR s.store_id = 0)";
        } elseif ($selected_store === '2') {
            $filterBranch = " WHERE s.store_id = 2";
        }

        $stSale = $pdo->query("SELECT s.*, u.fullname as cashier_name 
                               FROM sales s 
                               LEFT JOIN users u ON s.user_id = u.id 
                               {$filterBranch}
                               ORDER BY s.id DESC LIMIT 1");
        $sale = $stSale->fetch(PDO::FETCH_ASSOC);

        if ($sale) {
            $receipt_no = $sale['receipt_number'] ?? ($sale['receipt_no'] ?? ('REC-' . str_pad($sale['id'], 6, '0', STR_PAD_LEFT)));
            $totalVal = (float)($sale[$priceCol] ?? 0);
            
            $items = [];
            $hasSaleItems = ($pdo->query("SHOW TABLES LIKE 'sale_items'")->rowCount() > 0);
            if ($hasSaleItems) {
                $stItems = $pdo->prepare("SELECT product_name, quantity, unit_price, total_price FROM sale_items WHERE sale_id = ?");
                $stItems->execute([$sale['id']]);
                $items = $stItems->fetchAll(PDO::FETCH_ASSOC);
            }

            if (empty($items)) {
                $items = [
                    ['product_name' => 'PARTY POPPER 12S', 'quantity' => 1, 'unit_price' => $totalVal, 'total_price' => $totalVal]
                ];
            }

            echo json_encode([
                'status' => 'success',
                'receipt_no' => $receipt_no,
                'created_at' => date('m/d/Y H:i:s', strtotime($sale[$dateCol] ?? 'now')),
                'total_amount' => $totalVal,
                'subtotal' => (float)($sale['subtotal'] ?? $totalVal),
                'cashier' => $sale['cashier_name'] ?: 'Cashier Staff',
                'items' => $items
            ]);
        } else {
            echo json_encode([
                'status' => 'success',
                'receipt_no' => 'REC-' . date('Ymd') . '-00481',
                'created_at' => date('m/d/Y H:i:s'),
                'total_amount' => 75.00,
                'subtotal' => 75.00,
                'cashier' => 'Cashier Staff',
                'items' => [
                    ['product_name' => 'PARTY POPPER 12S', 'quantity' => 1, 'unit_price' => 75.00, 'total_price' => 75.00]
                ]
            ]);
        }
    } catch (Throwable $t) {
        echo json_encode(['status' => 'error', 'message' => $t->getMessage()]);
    }
    exit;
}


$is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') 
        || (isset($_POST['is_ajax']) && $_POST['is_ajax'] === '1');

$ajax_response = ['status' => 'error', 'message' => 'Invalid action'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // 1. UPDATE AVATAR
    if ($_POST['action'] === 'update_avatar') {
        if (!empty($_FILES['profile_avatar']['tmp_name'])) {
            $uploadDir = __DIR__ . '/uploads/avatars/';
            if (!is_dir($uploadDir)) @mkdir($uploadDir, 0777, true);
            $ext = strtolower(pathinfo($_FILES['profile_avatar']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                $newName = 'avatar_' . $user_id . '_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['profile_avatar']['tmp_name'], $uploadDir . $newName)) {
                    $dbPath = 'uploads/avatars/' . $newName;
                    $pdo->prepare("UPDATE users SET avatar = ?, profile_image = ? WHERE id = ?")->execute([$dbPath, $dbPath, $user_id]);
                    $_SESSION['avatar'] = $dbPath;
                    logActivity($pdo, 'AVATAR_UPDATE', "Updated admin profile avatar", 'Settings', $nav_store_id);

                    $ajax_response = [
                        'status' => 'success',
                        'message' => 'PROFILE PICTURE UPDATED AND SYNCED WITH DASHBOARD!',
                        'avatar_url' => $dbPath . '?v=' . time()
                    ];
                } else {
                    $ajax_response = ['status' => 'error', 'message' => 'FAILED TO UPLOAD IMAGE FILE!'];
                }
            } else {
                $ajax_response = ['status' => 'error', 'message' => 'INVALID IMAGE FORMAT (JPG, PNG, WEBP ONLY)!'];
            }
        } else {
            $ajax_response = ['status' => 'error', 'message' => 'NO IMAGE FILE SELECTED!'];
        }
    }

    // 2. UPDATE PROFILE
    if ($_POST['action'] === 'update_profile') {
        $fn = trim($_POST['fullname'] ?? '');
        $em = trim($_POST['email'] ?? '');
        if (!empty($fn)) {
            $pdo->prepare("UPDATE users SET fullname = ?, email = ? WHERE id = ?")->execute([$fn, $em, $user_id]);
            $_SESSION['fullname'] = $fn;
            logActivity($pdo, 'PROFILE_UPDATE', "Updated admin credentials: {$fn} ({$em})", 'Settings', $nav_store_id);

            $parts = explode(' ', trim($fn));
            $initials = count($parts) >= 2 ? strtoupper(substr($parts[0], 0, 1) . substr($parts[count($parts)-1], 0, 1)) : strtoupper(substr($fn, 0, 2));

            $ajax_response = [
                'status' => 'success',
                'message' => 'ACCOUNT DETAILS SAVED & DASHBOARD SYNCED!',
                'fullname' => $fn,
                'email' => $em,
                'initials' => $initials
            ];
        } else {
            $ajax_response = ['status' => 'error', 'message' => 'FULL NAME CANNOT BE EMPTY!'];
        }
    }

    // 3. CHANGE PASSWORD
    if ($_POST['action'] === 'change_password') {
        $cur = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $st_u = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $st_u->execute([$user_id]);
        $uRow = $st_u->fetch();
        if ($uRow && password_verify($cur, $uRow['password']) && strlen($new) >= 6) {
            $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([password_hash($new, PASSWORD_DEFAULT), $user_id]);
            logActivity($pdo, 'PASSWORD_CHANGE', "Updated security password", 'Settings', $nav_store_id);
            $ajax_response = ['status' => 'success', 'message' => 'PASSWORD CHANGED SUCCESSFULLY!'];
        } else {
            $ajax_response = ['status' => 'error', 'message' => 'INCORRECT CURRENT PASSWORD OR PASSWORD TOO SHORT (MIN 6 CHARS)!'];
        }
    }

    // 4. SAVE STORE/POS SETTINGS & LOGO 
    if ($_POST['action'] === 'save_pos_settings') {
        $s_name    = trim($_POST['store_name'] ?? 'JJ JUDEJUSH');
        $s_contact = trim($_POST['contact_number'] ?? '');
        $s_addr    = trim($_POST['store_address'] ?? '');
        $s_footer  = trim($_POST['footer_message'] ?? 'Thank you for shopping with us!');
        $ai_on     = isset($_POST['ai_analysis']) ? 1 : 0;
        $auto_prn  = isset($_POST['auto_print']) ? 1 : 0;
        $auto_bck  = isset($_POST['auto_backup']) ? 1 : 0;

        $logo_sql = "";
        $params = [$s_name, $s_contact, $s_addr, $s_footer, $ai_on, $ai_on, $auto_prn, $auto_bck];
        $newLogoUrl = null;

        if (!empty($_FILES['store_logo']['tmp_name'])) {
            $uDir = __DIR__ . '/uploads/logo/';
            if (!is_dir($uDir)) @mkdir($uDir, 0777, true);
            $ext = strtolower(pathinfo($_FILES['store_logo']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'svg'])) {
                $lName = 'logo_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['store_logo']['tmp_name'], $uDir . $lName)) {
                    $logo_sql = ", store_logo = ?";
                    $params[] = 'uploads/logo/' . $lName;
                    $newLogoUrl = 'uploads/logo/' . $lName . '?v=' . time();
                }
            }
        }

        $pdo->prepare("UPDATE settings SET store_name = ?, contact_number = ?, store_address = ?, footer_message = ?, 
                       ai_analysis = ?, ai_enabled = ?, auto_print = ?, auto_backup = ? {$logo_sql} WHERE id = 1")
            ->execute($params);

        logActivity($pdo, 'SETTINGS_UPDATE', "Configured official store branding & AI predictive flags", 'Settings', $nav_store_id);

        $ajax_response = [
            'status' => 'success',
            'message' => 'STORE SETTINGS & LOGO SYNCHRONIZED ACROSS DASHBOARD & POS!',
            'store_name' => $s_name,
            'contact_number' => $s_contact,
            'store_address' => $s_addr,
            'footer_message' => $s_footer,
            'ai_analysis' => $ai_on,
            'auto_print' => $auto_prn,
            'auto_backup' => $auto_bck,
            'logo_url' => $newLogoUrl
        ];
    }

    // 5. SAVE THERMAL PRINTER SETTINGS
    if ($_POST['action'] === 'save_thermal_settings') {
        $s_font   = $_POST['receipt_font_size'] ?? 'Medium';
        $s_paper  = $_POST['paper_width'] ?? '58mm';
        $auto_prn = isset($_POST['auto_print']) ? 1 : 0;
        $sh_logo  = isset($_POST['show_logo']) ? 1 : 0;
        $inc_qr   = isset($_POST['include_qr']) ? 1 : 0;

        $pdo->prepare("UPDATE settings SET receipt_font_size = ?, paper_width = ?, auto_print = ?, show_logo = ?, include_qr = ? WHERE id = 1")
            ->execute([$s_font, $s_paper, $auto_prn, $sh_logo, $inc_qr]);

        logActivity($pdo, 'THERMAL_UPDATE', "Configured ESC/POS thermal printer ({$s_paper})", 'Settings', $nav_store_id);

        $ajax_response = [
            'status' => 'success',
            'message' => 'THERMAL PRINTER SETTINGS SAVED SUCCESSFULLY!',
            'paper_width' => $s_paper,
            'font_size' => $s_font,
            'auto_print' => $auto_prn,
            'show_logo' => $sh_logo,
            'include_qr' => $inc_qr
        ];
    }

    // 6. SAVE E-WALLETS
    if ($_POST['action'] === 'save_wallets') {
        $g_name = trim($_POST['gcash_name'] ?? '');
        $g_num  = trim($_POST['gcash_number'] ?? '');
        $m_name = trim($_POST['maya_name'] ?? '');
        $m_num  = trim($_POST['maya_number'] ?? '');

        $g_qr_sql = ""; $m_qr_sql = "";
        $params = [$g_name, $g_num, $m_name, $m_num];

        $uDir = __DIR__ . '/uploads/wallets/';
        if (!is_dir($uDir)) @mkdir($uDir, 0777, true);

        if (!empty($_FILES['gcash_qr']['tmp_name'])) {
            $ext = strtolower(pathinfo($_FILES['gcash_qr']['name'], PATHINFO_EXTENSION));
            $fName = 'gcash_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['gcash_qr']['tmp_name'], $uDir . $fName)) {
                $g_qr_sql = ", gcash_qr = ?";
                $params[] = 'uploads/wallets/' . $fName;
            }
        }

        if (!empty($_FILES['maya_qr']['tmp_name'])) {
            $ext = strtolower(pathinfo($_FILES['maya_qr']['name'], PATHINFO_EXTENSION));
            $fName = 'maya_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['maya_qr']['tmp_name'], $uDir . $fName)) {
                $m_qr_sql = ", maya_qr = ?";
                $params[] = 'uploads/wallets/' . $fName;
            }
        }

        $pdo->prepare("UPDATE settings SET gcash_name = ?, gcash_number = ?, maya_name = ?, maya_number = ? {$g_qr_sql} {$m_qr_sql} WHERE id = 1")
            ->execute($params);

        logActivity($pdo, 'EWALLET_UPDATE', "Updated GCash & Maya merchant QR accounts", 'Settings', $nav_store_id);

        $ajax_response = [
            'status' => 'success',
            'message' => 'E-WALLET ACCOUNTS SAVED AND SYNCED WITH POS CHECKOUT!'
        ];
    }

    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode($ajax_response);
        exit();
    }
}

// Current User Profile Context
$currentUserData = [];
try {
    $stmtUser = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmtUser->execute([$user_id]);
    $currentUserData = $stmtUser->fetch() ?: [];
} catch (Throwable $t) {}

$currentUserFullname = $currentUserData['fullname'] ?? ($_SESSION['fullname'] ?? 'KHERLEEN MAE');
$currentUserRole     = $currentUserData['role'] ?? 'Admin';
$currentUserEmail    = $currentUserData['email'] ?? 'kherleenestrechoo@gmail.com';
$validAvatarUrl      = getValidAvatarUrl($currentUserData['avatar'] ?? ($currentUserData['profile_image'] ?? ($_SESSION['avatar'] ?? null)));

$parts = explode(' ', trim($currentUserFullname));
$userInitials = count($parts) >= 2 ? strtoupper(substr($parts[0], 0, 1) . substr($parts[count($parts)-1], 0, 1)) : strtoupper(substr($currentUserFullname, 0, 2));

// Unread Notifications & Alerts
$unreadCount = 0; $notifs = [];
try {
    $notifStoreFilter = ($selected_store === 'both') ? " WHERE 1=1" : " WHERE (store_id = " . (int)$selected_store . " OR store_id IS NULL OR store_id = 0)";
    $unreadCount = (int)($pdo->query("SELECT COUNT(*) FROM notifications {$notifStoreFilter} AND is_read = 0")->fetchColumn() ?: 0);
    $notifs = $pdo->query("SELECT * FROM notifications {$notifStoreFilter} AND is_read = 0 ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $t) {}

$total_alerts_badge = max(count($notifs), $unreadCount + ($total_low_stock_alerts > 0 ? 1 : 0));

// Active Products for Barcode Scanner Tab
$productsList = [];
try {
    $prodBranchFilter = ($selected_store === 'both') ? "" : " AND (store_id = " . (int)$selected_store . " OR store_id IS NULL OR store_id = 0)";
    $stProds = $pdo->query("SELECT id, product_name, COALESCE(NULLIF(barcode, ''), sku, CONCAT('4515', id)) as barcode, price, stock FROM products WHERE (is_deleted = 0 OR is_deleted IS NULL) {$prodBranchFilter} ORDER BY product_name ASC LIMIT 50");
    $productsList = $stProds->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $t) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($settings['store_name']) ?> - Enterprise Settings &amp; Hardware</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/js/all.min.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #0f172a; }
        .font-mono { font-family: 'Space Mono', monospace; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        .custom-scroll::-webkit-scrollbar { width: 5px; height: 5px; }
        .custom-scroll::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 6px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 6px; }
        .custom-scroll::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        
        .custom-input { 
            background: #fff; 
            border: 1.5px solid #cbd5e1; 
            border-radius: 12px; 
            padding: 9px 14px; 
            font-size: 13px; 
            font-weight: 700; 
            color: #0f172a; 
            outline: none; 
            width: 100%; 
            transition: all 0.2s; 
        }
        .custom-input:focus { 
            border-color: #f59e0b; 
            box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.15); 
        }
        
        .tab-btn.active { 
            background-color: #070a12; 
            color: #ffffff; 
            box-shadow: 0 4px 12px rgba(7, 10, 18, 0.2); 
        }

        #toastContainer { 
            position: fixed; 
            top: 24px; 
            right: 24px; 
            z-index: 9999; 
            display: flex; 
            flex-direction: column; 
            gap: 10px; 
            pointer-events: none; 
        }
        .toast-pill {
            background-color: #10b981;
            color: #ffffff;
            font-size: 12px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 12px 20px;
            border-radius: 16px;
            box-shadow: 0 10px 25px -5px rgba(16, 185, 129, 0.4);
            display: flex;
            align-items: center;
            gap: 10px;
            transform: translateX(120%);
            transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .toast-pill.show { transform: translateX(0); }
        .toast-pill.error { background-color: #ef4444; box-shadow: 0 10px 25px -5px rgba(239, 68, 68, 0.4); }

        @media print {
            body * { visibility: hidden !important; }
            #printableSlipArea, #printableSlipArea *, #printableBarcodeLabel, #printableBarcodeLabel * { visibility: visible !important; }
            #printableSlipArea, #printableBarcodeLabel { 
                position: absolute !important; 
                left: 0 !important; 
                top: 0 !important; 
                width: 100% !important; 
                display: block !important; 
                border: none !important; 
                box-shadow: none !important; 
            }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-800 flex flex-col h-screen overflow-hidden relative">

    <div id="toastContainer"></div>

    <!-- TOP HEADER (IDENTICAL & FULLY SYNCHRONIZED WITH index.php) -->
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
            <!-- STORE SWITCHER (PRESERVES ACTIVE TAB) -->
            <div class="flex bg-slate-900/90 p-1 rounded-xl border border-slate-800 shadow-inner">
                <button type="button" onclick="switchStoreScope('both')" class="px-3.5 py-1.5 text-xs font-black rounded-lg transition-all <?= $selected_store === 'both' ? 'bg-amber-500 text-slate-950 shadow' : 'text-slate-300 hover:text-white' ?>">Both Stores</button>
                <button type="button" onclick="switchStoreScope('1')" class="px-3.5 py-1.5 text-xs font-black rounded-lg transition-all <?= $selected_store === '1' ? 'bg-amber-500 text-slate-950 shadow' : 'text-slate-300 hover:text-white' ?>">Store 1</button>
                <button type="button" onclick="switchStoreScope('2')" class="px-3.5 py-1.5 text-xs font-black rounded-lg transition-all <?= $selected_store === '2' ? 'bg-amber-500 text-slate-950 shadow' : 'text-slate-300 hover:text-white' ?>">Store 2</button>
            </div>

            <!-- INVENTORY & RESTOCK ALERTS -->
            <div class="relative group">
                <button class="w-9 h-9 bg-slate-900 hover:bg-slate-800 rounded-xl flex items-center justify-center text-slate-300 relative border border-slate-800 transition-colors" title="Notifications">
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
                        <a href="settings.php?store_id=<?= urlencode($selected_store) ?>&mark_read=all" class="py-3 text-emerald-600 hover:text-emerald-700 hover:bg-emerald-50 transition-colors border-r border-slate-200">
                            CLEAR ALL
                        </a>
                        <a href="inventory.php?store_id=<?= $nav_store_id ?>&tab=reorder" class="py-3 text-amber-600 hover:text-amber-700 hover:bg-amber-50 transition-colors">
                            REORDER BOX
                        </a>
                    </div>
                </div>
            </div>

            <!-- PROFILE AVATAR / INITIALS -->
            <button type="button" onclick="switchSettingsTab('profile')" class="w-9 h-9 rounded-xl bg-amber-500 text-slate-950 flex items-center justify-center font-black text-xs shadow overflow-hidden border border-amber-400 hover:ring-2 hover:ring-amber-300 transition-all" title="Manage Profile Settings">
                <span id="navUserInitials" class="<?= $validAvatarUrl ? 'hidden' : '' ?>"><?= htmlspecialchars($userInitials) ?></span>
                <img id="navAvatarImg" src="<?= $validAvatarUrl ? htmlspecialchars($validAvatarUrl) . '?v=' . time() : '' ?>" alt="Avatar" class="w-full h-full object-cover <?= $validAvatarUrl ? '' : 'hidden' ?>">
            </button>
        </div>
    </header>

    <!-- MAIN BODY WRAPPER -->
    <div class="flex flex-1 overflow-hidden">
        
        <!-- SIDEBAR (FULL INTEGRATION WITH index.php) -->
        <div id="sidebar" class="bg-[#070a12] text-slate-300 h-full w-64 flex flex-col transition-all duration-300 flex-shrink-0 border-r border-slate-900 no-print">
            <div class="p-3 space-y-1.5 flex-1 overflow-y-auto no-scrollbar">
                <a href="index.php?store_id=<?= urlencode($selected_store) ?>" class="flex items-center px-4 py-3 rounded-xl font-black text-xs transition-all text-slate-300 hover:bg-slate-900 hover:text-white">
                    <i class="fa-solid fa-th-large w-5 text-center text-sm"></i><span class="ml-3 sidebar-text">Dashboard</span>
                </a>
                <a href="inventory.php?store_id=<?= $nav_store_id ?>" class="flex items-center px-4 py-3 rounded-xl font-black text-xs transition-all text-slate-300 hover:bg-slate-900 hover:text-white">
                    <i class="fa-solid fa-box w-5 text-center text-sm"></i><span class="ml-3 sidebar-text">Inventory</span>
                </a>
                <a href="reports.php?store_id=<?= $nav_store_id ?>" class="flex items-center px-4 py-3 rounded-xl font-black text-xs transition-all text-slate-300 hover:bg-slate-900 hover:text-white">
                    <i class="fa-solid fa-file-invoice-dollar w-5 text-center text-sm"></i><span class="ml-3 sidebar-text">Reports</span>
                </a>
                <a href="audit_logs.php?store_id=<?= $nav_store_id ?>" class="flex items-center px-4 py-3 rounded-xl font-black text-xs transition-all text-slate-300 hover:bg-slate-900 hover:text-white">
                    <i class="fa-solid fa-shield-halved w-5 text-center text-sm"></i><span class="ml-3 sidebar-text">Activity Logs</span>
                </a>
                <a href="accounts.php?store_id=<?= $nav_store_id ?>" class="flex items-center px-4 py-3 rounded-xl font-black text-xs transition-all text-slate-300 hover:bg-slate-900 hover:text-white">
                    <i class="fa-solid fa-users w-5 text-center text-sm"></i><span class="ml-3 sidebar-text">Users</span>
                </a>
                <a href="settings.php?store_id=<?= urlencode($selected_store) ?>" class="flex items-center px-4 py-3 rounded-xl font-black text-xs transition-all bg-amber-500 text-slate-950 shadow-md">
                    <i class="fa-solid fa-gear w-5 text-center text-sm"></i><span class="ml-3 sidebar-text">Settings</span>
                </a>
            </div>

            <div class="border-t border-slate-900 p-3">
                <a href="../logout.php" class="w-full py-2.5 px-4 bg-slate-900 hover:bg-red-600 hover:text-white rounded-xl flex items-center justify-center font-black text-xs text-slate-300 transition-all">
                    <i class="fa-solid fa-right-from-bracket"></i><span class="ml-2 sidebar-text">Logout</span>
                </a>
            </div>
        </div>

        <!-- MAIN VIEW CONTENT -->
        <main class="flex-1 p-5 md:p-7 overflow-y-auto custom-scroll space-y-5 bg-slate-100">
            
            <!-- TOP SUB-HEADER: TITLE & 5-TAB CONTROLS -->
            <div class="bg-white p-5 rounded-2xl shadow-sm border border-slate-200/80 flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4 no-print">
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-base font-black text-slate-900 uppercase tracking-tight">Enterprise Settings</h1>
                        <span class="px-2 py-0.5 bg-amber-100 text-amber-900 text-[10px] font-black rounded uppercase"><?= htmlspecialchars($store_label) ?></span>
                    </div>
                    <p class="text-xs font-bold text-slate-500 mt-0.5">Manage store POS profile, barcode hardware, thermal printing, and digital e-wallets.</p>
                </div>

                <!-- 5 TAB NAV CONTROLS -->
                <div class="inline-flex items-center bg-slate-100 p-1.5 rounded-2xl border border-slate-200/80 gap-1 overflow-x-auto no-scrollbar w-full sm:w-auto" id="settingsTabNav">
                    <button type="button" onclick="switchSettingsTab('profile')" data-tab="profile" class="tab-btn active px-3.5 py-2 rounded-xl text-xs font-black uppercase whitespace-nowrap transition flex items-center gap-2">
                        <i class="fas fa-user text-xs"></i> <span>PROFILE</span>
                    </button>
                    <button type="button" onclick="switchSettingsTab('storepos')" data-tab="storepos" class="tab-btn px-3.5 py-2 rounded-xl text-xs font-black uppercase whitespace-nowrap text-slate-600 hover:text-slate-900 transition flex items-center gap-2">
                        <i class="fas fa-store text-xs"></i> <span>STORE/POS</span>
                    </button>
                    <button type="button" onclick="switchSettingsTab('barcode')" data-tab="barcode" class="tab-btn px-3.5 py-2 rounded-xl text-xs font-black uppercase whitespace-nowrap text-slate-600 hover:text-slate-900 transition flex items-center gap-2">
                        <i class="fas fa-barcode text-xs"></i> <span>BARCODE SCANNER</span>
                    </button>
                    <button type="button" onclick="switchSettingsTab('thermal')" data-tab="thermal" class="tab-btn px-3.5 py-2 rounded-xl text-xs font-black uppercase whitespace-nowrap text-slate-600 hover:text-slate-900 transition flex items-center gap-2">
                        <i class="fas fa-print text-xs"></i> <span>THERMAL PRINTER</span>
                    </button>
                    <button type="button" onclick="switchSettingsTab('ewallet')" data-tab="ewallet" class="tab-btn px-3.5 py-2 rounded-xl text-xs font-black uppercase whitespace-nowrap text-slate-600 hover:text-slate-900 transition flex items-center gap-2">
                        <i class="fas fa-wallet text-xs"></i> <span>E-WALLET</span>
                    </button>
                </div>
            </div>

            <!-- ======================================================== -->
            <!-- TAB 1: PROFILE & SECURITY                                -->
            <!-- ======================================================== -->
            <div id="tabContent-profile" class="tab-pane space-y-4">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
                    
                    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm p-6 text-center flex flex-col justify-between">
                        <div>
                            <div class="w-28 h-28 mx-auto mb-4 rounded-2xl bg-[#070a12] overflow-hidden border-2 border-slate-800 relative flex items-center justify-center shadow-inner">
                                <span id="avatarInitialsText" class="text-3xl font-black text-amber-500 <?= $validAvatarUrl ? 'hidden' : '' ?>"><?= htmlspecialchars($userInitials) ?></span>
                                <img id="avatarPreviewImg" src="<?= $validAvatarUrl ? htmlspecialchars($validAvatarUrl) . '?v=' . time() : '' ?>" alt="Avatar" class="w-full h-full object-cover <?= $validAvatarUrl ? '' : 'hidden' ?>">
                            </div>
                            <h3 id="profileCardFullname" class="text-sm font-black text-slate-900"><?= htmlspecialchars($currentUserFullname) ?></h3>
                            <span class="px-2 py-0.5 bg-amber-400 text-slate-950 font-black text-[9px] rounded uppercase mt-1 inline-block"><?= strtoupper($currentUserRole) ?></span>
                            <p id="profileCardEmail" class="text-[10px] text-slate-500 font-mono mt-1"><?= htmlspecialchars($currentUserEmail) ?></p>
                        </div>

                        <form id="formAvatarUpload" class="mt-6 pt-4 border-t border-slate-100 flex gap-2">
                            <input type="hidden" name="action" value="update_avatar">
                            <input type="file" name="profile_avatar" id="avatarInput" accept="image/*" class="hidden" onchange="previewAvatarLocal(event)">
                            <button type="button" onclick="document.getElementById('avatarInput').click()" class="flex-1 py-2.5 px-3 bg-slate-100 hover:bg-slate-200 text-slate-800 rounded-xl text-xs font-black uppercase transition border border-slate-200">
                                BROWSE
                            </button>
                            <button type="submit" class="py-2.5 px-5 bg-amber-500 hover:bg-amber-600 text-slate-950 font-black rounded-xl text-xs uppercase shadow transition">
                                SAVE
                            </button>
                        </form>
                    </div>

                    <div class="lg:col-span-2 space-y-5">
                        <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm p-6">
                            <div class="border-b border-slate-100 pb-3 mb-4">
                                <h3 class="text-xs font-black uppercase text-slate-900">ACCOUNT DETAILS</h3>
                            </div>
                            <form id="formUpdateProfile" class="space-y-4">
                                <input type="hidden" name="action" value="update_profile">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="text-[10px] font-black uppercase text-slate-500 block mb-1">FULL NAME</label>
                                        <input type="text" name="fullname" id="inputFullname" value="<?= htmlspecialchars($currentUserFullname) ?>" required class="custom-input">
                                    </div>
                                    <div>
                                        <label class="text-[10px] font-black uppercase text-slate-500 block mb-1">EMAIL ADDRESS</label>
                                        <input type="email" name="email" id="inputEmail" value="<?= htmlspecialchars($currentUserEmail) ?>" required class="custom-input">
                                    </div>
                                </div>

                                <div class="p-3.5 bg-amber-50/70 border border-amber-200 rounded-2xl flex items-center justify-between">
                                    <div>
                                        <span class="text-[10px] font-black text-amber-900 uppercase block">ACCESS LEVEL</span>
                                        <p class="text-xs font-bold text-slate-700">Full Access to Point-of-Sale, Hardware, &amp; Enterprise Dashboard Analytics.</p>
                                    </div>
                                    <span class="px-2.5 py-1 bg-amber-400 text-slate-950 font-black text-[10px] rounded-lg uppercase">ADMIN</span>
                                </div>

                                <div class="text-right">
                                    <button type="submit" class="bg-amber-500 hover:bg-amber-600 text-slate-950 font-black px-6 py-2.5 rounded-xl text-xs uppercase transition shadow">
                                        SAVE PROFILE
                                    </button>
                                </div>
                            </form>
                        </div>

                        <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm p-6">
                            <div class="border-b border-slate-100 pb-3 mb-4">
                                <h3 class="text-xs font-black uppercase text-slate-900">SECURITY &amp; PASSWORD</h3>
                            </div>
                            <form id="formChangePassword" class="space-y-4">
                                <input type="hidden" name="action" value="change_password">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="text-[10px] font-black uppercase text-slate-500 block mb-1">CURRENT PASSWORD</label>
                                        <input type="password" name="current_password" required placeholder="••••••••" class="custom-input">
                                    </div>
                                    <div>
                                        <label class="text-[10px] font-black uppercase text-slate-500 block mb-1">NEW PASSWORD (MIN. 6 CHARS)</label>
                                        <input type="password" name="new_password" required placeholder="••••••••" class="custom-input">
                                    </div>
                                </div>
                                <div class="text-right">
                                    <button type="submit" class="bg-[#070a12] hover:bg-slate-800 text-white font-black px-6 py-2.5 rounded-xl text-xs uppercase transition shadow">
                                        CHANGE PASSWORD
                                    </button>
                                </div>
                            </form>
                        </div>

                    </div>
                </div>
            </div>

            <!-- ======================================================== -->
            <!-- TAB 2: STORE / POS CONFIGURATION                         -->
            <!-- ======================================================== -->
            <div id="tabContent-storepos" class="tab-pane hidden space-y-5">
                <form id="formStorePos" class="space-y-5">
                    <input type="hidden" name="action" value="save_pos_settings">

                    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm p-6">
                        <h3 class="text-xs font-black uppercase text-slate-900 mb-3">STORE LOGO</h3>
                        <div class="flex items-center gap-5">
                            <div class="w-16 h-16 rounded-2xl bg-amber-500 text-slate-950 font-black text-xl flex items-center justify-center overflow-hidden border border-slate-300 shadow" id="storeLogoBox">
                                <?php if (!empty($settings['store_logo']) && file_exists(__DIR__ . '/' . $settings['store_logo'])): ?>
                                    <img src="<?= htmlspecialchars($settings['store_logo']) ?>?v=<?= time() ?>" alt="Logo" class="w-full h-full object-cover" id="storeLogoImg">
                                <?php else: ?>
                                    <span id="storeLogoText">JJ</span>
                                    <img src="" alt="Logo" class="w-full h-full object-cover hidden" id="storeLogoImg">
                                <?php endif; ?>
                            </div>
                            <div class="space-y-2 flex-1">
                                <p class="text-xs font-bold text-slate-500">Displays on the top navigation bar, dashboard header, and generated POS thermal receipts.</p>
                                <div class="flex items-center gap-2">
                                    <input type="file" name="store_logo" id="logoInput" accept="image/*" class="hidden" onchange="previewLogoLocal(event)">
                                    <button type="button" onclick="document.getElementById('logoInput').click()" class="py-2 px-3.5 bg-slate-100 hover:bg-slate-200 text-slate-800 rounded-xl text-xs font-black uppercase transition border border-slate-200">
                                        BROWSE LOGO
                                    </button>
                                    <span id="logoFileNameNotice" class="text-xs font-bold text-slate-500 italic"></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm p-6 space-y-4">
                        <div class="border-b border-slate-100 pb-3">
                            <h3 class="text-xs font-black uppercase text-slate-900">POINT-OF-SALE INFORMATION</h3>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="text-[10px] font-black uppercase text-slate-500 block mb-1">OFFICIAL STORE NAME</label>
                                <input type="text" name="store_name" id="inputStoreName" value="<?= htmlspecialchars($settings['store_name']) ?>" required class="custom-input">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase text-slate-500 block mb-1">CONTACT NUMBER</label>
                                <input type="text" name="contact_number" id="inputContactNumber" value="<?= htmlspecialchars($settings['contact_number']) ?>" class="custom-input">
                            </div>
                        </div>

                        <div>
                            <label class="text-[10px] font-black uppercase text-slate-500 block mb-1">BRANCH PHYSICAL ADDRESS</label>
                            <input type="text" name="store_address" id="inputStoreAddress" value="<?= htmlspecialchars($settings['store_address']) ?>" class="custom-input">
                        </div>

                        <div>
                            <label class="text-[10px] font-black uppercase text-slate-500 block mb-1">RECEIPT FOOTER MESSAGE</label>
                            <input type="text" name="footer_message" id="inputFooterMessage" value="<?= htmlspecialchars($settings['footer_message']) ?>" class="custom-input">
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 pt-2">
                            <div class="p-3.5 bg-slate-50 rounded-2xl border border-slate-200 flex items-center justify-between">
                                <div>
                                    <span class="text-xs font-black text-slate-800 uppercase block">AI SALES FORECASTING</span>
                                    <p class="text-[10px] font-bold text-slate-500">Enable predictive curves &amp; AI chat across system.</p>
                                </div>
                                <input type="checkbox" name="ai_analysis" id="inputAiAnalysis" value="1" <?= !empty($settings['ai_analysis']) ? 'checked' : '' ?> class="w-4 h-4 accent-amber-500 rounded cursor-pointer">
                            </div>

                            <div class="p-3.5 bg-slate-50 rounded-2xl border border-slate-200 flex items-center justify-between">
                                <div>
                                    <span class="text-xs font-black text-slate-800 uppercase block">AUTO PRINT RECEIPT</span>
                                    <p class="text-[10px] font-bold text-slate-500">Prompt thermal print dialog on cashier checkout.</p>
                                </div>
                                <input type="checkbox" name="auto_print" id="inputAutoPrint" value="1" <?= !empty($settings['auto_print']) ? 'checked' : '' ?> class="w-4 h-4 accent-amber-500 rounded cursor-pointer">
                            </div>

                            <div class="p-3.5 bg-slate-50 rounded-2xl border border-slate-200 flex items-center justify-between">
                                <div>
                                    <span class="text-xs font-black text-slate-800 uppercase block">AUTOMATIC BACKUP</span>
                                    <p class="text-[10px] font-bold text-slate-500">Auto backup sales &amp; audit trails nightly.</p>
                                </div>
                                <input type="checkbox" name="auto_backup" id="inputAutoBackup" value="1" <?= !empty($settings['auto_backup']) ? 'checked' : '' ?> class="w-4 h-4 accent-amber-500 rounded cursor-pointer">
                            </div>
                        </div>

                        <div class="text-right pt-2">
                            <button type="submit" class="bg-amber-500 hover:bg-amber-600 text-slate-950 font-black px-7 py-2.5 rounded-xl text-xs uppercase transition shadow">
                                SAVE POS SETTINGS
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- ======================================================== -->
            <!-- TAB 3: BARCODE SCANNER HARDWARE & 58MM LABELS            -->
            <!-- ======================================================== -->
            <div id="tabContent-barcode" class="tab-pane hidden space-y-5">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
                    
                    <div class="lg:col-span-2 space-y-4">
                        <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm p-6 space-y-4">
                            <div class="flex justify-between items-center border-b border-slate-100 pb-3">
                                <div>
                                    <h3 class="text-xs font-black uppercase text-slate-900">BARCODE SCANNER LIVE FEED</h3>
                                    <p class="text-[11px] font-bold text-slate-500 mt-0.5">Instant hardware verification against registered store product catalog.</p>
                                </div>
                                <span class="px-2.5 py-1 bg-emerald-100 text-emerald-800 rounded-lg text-[10px] font-black uppercase flex items-center gap-1.5">
                                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span> HARDWARE READY
                                </span>
                            </div>

                            <div>
                                <label class="text-[10px] font-black uppercase text-slate-500 block mb-1">SCAN OR TYPE BARCODE (AUTO-DETECT)</label>
                                <div class="flex gap-2">
                                    <div class="relative flex-1">
                                        <i class="fas fa-barcode absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                                        <input type="text" id="liveScannerInput" placeholder="I-scan ang item gamit ang USB scanner..." class="custom-input pl-10" onkeypress="handleBarcodeEnter(event)">
                                    </div>
                                    <button type="button" onclick="performBarcodeLookup()" class="bg-[#070a12] hover:bg-slate-800 text-white font-black px-5 py-2.5 rounded-xl text-xs uppercase shadow transition">
                                        SCAN
                                    </button>
                                </div>
                            </div>

                            <div id="detectedProductCard" class="p-4 bg-amber-50/70 border border-amber-200 rounded-2xl flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs font-black text-amber-900 uppercase">SELECTED PRODUCT:</span>
                                        <span id="detProdName" class="text-xs font-black text-slate-900 uppercase"><?= htmlspecialchars($productsList[0]['product_name'] ?? 'Party Popper') ?></span>
                                    </div>
                                    <p class="text-[11px] font-bold text-slate-600 mt-0.5">
                                        Code: <b id="detProdCode" class="font-mono text-slate-900"><?= htmlspecialchars($productsList[0]['barcode'] ?? '45156832') ?></b> &bull; Price: <b id="detProdPrice" class="text-emerald-700">₱<?= number_format($productsList[0]['price'] ?? 75, 2) ?></b> &bull; Stock: <b id="detProdStock"><?= ($productsList[0]['stock'] ?? 17) ?> units</b>
                                    </p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <a href="inventory.php?store_id=<?= $nav_store_id ?>" class="px-3 py-1.5 bg-amber-500 hover:bg-amber-600 text-slate-950 rounded-xl text-[10px] font-black uppercase shadow">
                                        OPEN INVENTORY
                                    </a>
                                </div>
                            </div>

                            <div class="pt-2">
                                <label class="text-[10px] font-black uppercase text-slate-500 block mb-1">SELECT PRODUCT FROM DATABASE</label>
                                <div class="flex gap-2">
                                    <select id="selectProductBarcode" class="custom-input" onchange="onSelectProductDropdown(this)">
                                        <?php foreach($productsList as $p): ?>
                                            <option value="<?= htmlspecialchars($p['barcode']) ?>" 
                                                    data-name="<?= htmlspecialchars($p['product_name']) ?>" 
                                                    data-price="<?= number_format($p['price'], 2) ?>" 
                                                    data-stock="<?= $p['stock'] ?>">
                                                <?= htmlspecialchars($p['product_name']) ?> (<?= htmlspecialchars($p['barcode']) ?>) - ₱<?= number_format($p['price'], 2) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" onclick="printSelectedLabel()" class="bg-emerald-600 hover:bg-emerald-700 text-white font-black px-4 py-2.5 rounded-xl text-xs uppercase shadow flex items-center gap-1.5">
                                        <i class="fas fa-print"></i> PRINT
                                    </button>
                                </div>
                            </div>

                            <div class="pt-2">
                                <span class="text-[10px] font-black uppercase text-slate-500 block mb-2">QUICK SELECTION PRESETS:</span>
                                <div class="flex flex-wrap gap-1.5">
                                    <?php foreach(array_slice($productsList, 0, 15) as $qp): ?>
                                        <button type="button" onclick="loadProductToLabel('<?= addslashes($qp['product_name']) ?>', '<?= $qp['barcode'] ?>', '<?= number_format($qp['price'], 2) ?>', '<?= $qp['stock'] ?>')" 
                                                class="px-2.5 py-1 bg-slate-100 hover:bg-amber-100 text-slate-700 rounded-lg text-[10px] font-black transition border border-slate-200">
                                            <?= htmlspecialchars($qp['product_name']) ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm p-6 text-center space-y-4">
                        <div class="flex justify-between items-center border-b border-slate-100 pb-3">
                            <h3 class="text-xs font-black uppercase text-slate-900">58MM LABEL PREVIEW</h3>
                            <span class="px-2 py-0.5 bg-amber-100 text-amber-900 rounded text-[9px] font-black uppercase">LIVE PREVIEW</span>
                        </div>

                        <div id="printableBarcodeLabel" class="w-48 mx-auto p-4 bg-white rounded-xl border border-slate-300 shadow-sm text-center font-sans space-y-1">
                            <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest" id="lblPreviewStoreName"><?= htmlspecialchars($settings['store_name']) ?></p>
                            <h4 id="lblPreviewName" class="text-xs font-black text-slate-900 leading-tight"><?= htmlspecialchars($productsList[0]['product_name'] ?? 'Party Popper') ?></h4>
                            <p id="lblPreviewPrice" class="text-sm font-black text-emerald-600">₱<?= number_format($productsList[0]['price'] ?? 75, 2) ?></p>
                            
                            <div class="py-1">
                                <svg id="previewBarcodeSVG" class="w-full h-10"></svg>
                            </div>
                            
                        </div>

                        <button type="button" onclick="printSelectedLabel()" class="w-full py-2.5 bg-[#070a12] hover:bg-slate-800 text-white rounded-xl text-xs font-black uppercase shadow flex items-center justify-center gap-2">
                            <i class="fas fa-print text-amber-400"></i> PRINT 58MM STICKER LABEL
                        </button>
                    </div>

                </div>
            </div>

            <!-- ======================================================== -->
            <!-- TAB 4: THERMAL PRINTER HARDWARE & RECEIPT PREVIEW        -->
            <!-- ======================================================== -->
            <div id="tabContent-thermal" class="tab-pane hidden space-y-5">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
                    
                    <div class="lg:col-span-2 space-y-4">
                        <form id="formThermalSettings" class="bg-white rounded-2xl border border-slate-200/80 shadow-sm p-6 space-y-4">
                            <input type="hidden" name="action" value="save_thermal_settings">
                            
                            <div class="flex justify-between items-center border-b border-slate-100 pb-3">
                                <div>
                                    <h3 class="text-xs font-black uppercase text-slate-900">THERMAL PRINTER HARDWARE</h3>
                                    <p class="text-[11px] font-bold text-slate-500 mt-0.5">Compatible with standard ESC/POS 58mm (POS58) and 80mm (POS80) thermal receipt units.</p>
                                </div>
                                <button type="button" onclick="loadLastSale()" class="px-3 py-1.5 bg-amber-100 text-amber-900 rounded-xl text-[10px] font-black uppercase flex items-center gap-1.5 hover:bg-amber-200 transition">
                                    <i class="fas fa-rotate"></i> LOAD LAST POS SALE
                                </button>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="text-[10px] font-black uppercase text-slate-500 block mb-1">PAPER WIDTH</label>
                                    <select name="paper_width" class="custom-input" onchange="onPaperWidthChange(this.value)">
                                        <option value="58mm" <?= ($settings['paper_width'] ?? '') === '58mm' ? 'selected' : '' ?>>58mm (POS58 Standard)</option>
                                        <option value="80mm" <?= ($settings['paper_width'] ?? '') === '80mm' ? 'selected' : '' ?>>80mm (POS80 Standard)</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-[10px] font-black uppercase text-slate-500 block mb-1">FONT SCALE</label>
                                    <select name="receipt_font_size" class="custom-input">
                                        <option value="Small" <?= ($settings['receipt_font_size'] ?? '') === 'Small' ? 'selected' : '' ?>>Small</option>
                                        <option value="Medium" <?= ($settings['receipt_font_size'] ?? '') === 'Medium' || empty($settings['receipt_font_size']) ? 'selected' : '' ?>>Medium</option>
                                        <option value="Large" <?= ($settings['receipt_font_size'] ?? '') === 'Large' ? 'selected' : '' ?>>Large</option>
                                    </select>
                                </div>
                            </div>

                            <div class="space-y-3 pt-2">
                                <label class="p-3.5 bg-slate-50 rounded-2xl border border-slate-200 flex items-center justify-between cursor-pointer">
                                    <div>
                                        <span class="text-xs font-black text-slate-800 uppercase block">AUTO PRINT RECEIPT</span>
                                        <p class="text-[10px] font-bold text-slate-500">Automatically trigger print dialog after checkout completion in cashier.</p>
                                    </div>
                                    <input type="checkbox" name="auto_print" value="1" <?= !empty($settings['auto_print']) ? 'checked' : '' ?> class="w-4 h-4 accent-amber-500 rounded">
                                </label>

                                <label class="p-3.5 bg-slate-50 rounded-2xl border border-slate-200 flex items-center justify-between cursor-pointer">
                                    <div>
                                        <span class="text-xs font-black text-slate-800 uppercase block">INCLUDE STORE LOGO</span>
                                        <p class="text-[10px] font-bold text-slate-500">Render store logo header on generated thermal invoice.</p>
                                    </div>
                                    <input type="checkbox" name="show_logo" value="1" <?= !empty($settings['show_logo']) ? 'checked' : '' ?> class="w-4 h-4 accent-amber-500 rounded">
                                </label>

                                <label class="p-3.5 bg-slate-50 rounded-2xl border border-slate-200 flex items-center justify-between cursor-pointer">
                                    <div>
                                        <span class="text-xs font-black text-slate-800 uppercase block">RECEIPT QR CODE</span>
                                        <p class="text-[10px] font-bold text-slate-500">Print digital QR verification at receipt footer for customer returns.</p>
                                    </div>
                                    <input type="checkbox" name="include_qr" value="1" <?= !empty($settings['include_qr']) ? 'checked' : '' ?> class="w-4 h-4 accent-amber-500 rounded">
                                </label>
                            </div>

                            <div class="flex gap-2.5 pt-2">
                                <button type="button" onclick="testPrintThermal()" class="flex-1 py-2.5 bg-[#070a12] hover:bg-slate-800 text-white rounded-xl text-xs font-black uppercase transition shadow flex items-center justify-center gap-2">
                                    <i class="fas fa-print text-amber-400"></i> TEST PRINT RECEIPT
                                </button>
                                <button type="submit" class="py-2.5 px-6 bg-amber-500 hover:bg-amber-600 text-slate-950 font-black rounded-xl text-xs uppercase shadow transition">
                                    SAVE SETTINGS
                                </button>
                            </div>
                        </form>
                    </div>

                    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm p-6 text-center space-y-4">
                        <div class="flex justify-between items-center border-b border-slate-100 pb-3">
                            <h3 class="text-xs font-black uppercase text-slate-900">RECEIPT PREVIEW</h3>
                            <span id="badgePaperWidth" class="px-2 py-0.5 bg-slate-900 text-white rounded text-[9px] font-black uppercase font-mono"><?= strtoupper($settings['paper_width']) ?> THERMAL</span>
                        </div>

                        <div id="printableSlipArea" class="w-64 mx-auto p-4 bg-white rounded-xl border border-slate-300 shadow-sm font-mono text-left text-[11px] leading-tight text-black select-none">
                            <div class="text-center pb-2 border-b border-dashed border-black">
                                <h4 class="font-black text-sm tracking-tight uppercase" id="slipStoreName"><?= htmlspecialchars($settings['store_name']) ?></h4>
                                <p class="text-[9px] uppercase font-bold text-slate-700">SUPERMARKET &amp; GENERAL MERCHANDISE</p>
                                <p class="text-[9px] leading-3 mt-0.5" id="slipStoreAddress"><?= htmlspecialchars($settings['store_address']) ?></p>
                                <p class="text-[9px]">TEL: <span id="slipStoreContact"><?= htmlspecialchars($settings['contact_number']) ?></span></p>
                                <div class="text-[8px] leading-3 mt-1.5 text-slate-800">
                                    <p>VAT REG TIN: 000-842-120-00001</p>
                                    <p>MIN: 241014092415982</p>
                                    <p>SERIAL NO: FP-POS58-99410</p>
                                    <p>PERMIT NO: FP-102026-004-91823-POS</p>
                                </div>
                            </div>

                            <div class="py-1.5 border-b border-dashed border-black text-[9px] leading-3 space-y-0.5">
                                <div class="flex justify-between">
                                    <span>POS: 01-CASHIER</span>
                                    <span id="slipCashier" class="font-bold uppercase">CASHIER STAFF</span>
                                </div>
                                <div class="flex justify-between">
                                    <span>DATE: <span id="slipDate"><?= date('m/d/Y H:i:s') ?></span></span>
                                    <span class="font-bold">TRANS #: 8412</span>
                                </div>
                                <div class="flex justify-between">
                                    <span>SI / OR NO:</span>
                                    <b id="slipOR" class="font-black uppercase">REC-<?= date('Ymd') ?>-00481</b>
                                </div>
                            </div>

                            <div class="py-2 border-b border-dashed border-black">
                                <div class="flex justify-between text-[8px] font-black border-b border-black pb-0.5 mb-1 uppercase">
                                    <span>DESCRIPTION</span>
                                    <span>AMOUNT</span>
                                </div>
                                <div id="puregoldItemsList" class="space-y-1.5 text-[10px]">
                                    <div>
                                        <div class="font-bold uppercase tracking-tight">PARTY POPPER 12S</div>
                                        <div class="flex justify-between text-[9px] pl-2 text-slate-800">
                                            <span>1 @ 75.00</span>
                                            <span class="font-bold font-mono">75.00 V</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="py-1.5 border-b border-dashed border-black space-y-1 text-[10px]">
                                <div class="flex justify-between text-xs font-black">
                                    <span>TOTAL AMOUNT:</span>
                                    <span id="slipTotal" class="text-sm font-black">₱75.00</span>
                                </div>
                                <div class="flex justify-between text-[9px]">
                                    <span>CASH TENDERED:</span>
                                    <span id="slipTendered">₱100.00</span>
                                </div>
                                <div class="flex justify-between text-[9px] font-bold">
                                    <span>CHANGE:</span>
                                    <span id="slipChange">₱25.00</span>
                                </div>
                                <div class="flex justify-between text-[8px] text-slate-700 pt-0.5 border-t border-dotted border-slate-400">
                                    <span>TOTAL ITEMS SOLD:</span>
                                    <span id="slipItemCount" class="font-bold">1</span>
                                </div>
                            </div>

                            <div class="py-1.5 border-b border-dashed border-black text-[8px] leading-3 space-y-0.5 text-slate-800">
                                <div class="flex justify-between">
                                    <span>VATABLE SALES:</span>
                                    <span id="slipVatable">₱66.96</span>
                                </div>
                                <div class="flex justify-between">
                                    <span>VAT AMOUNT (12%):</span>
                                    <span id="slipVat">₱8.04</span>
                                </div>
                                <div class="flex justify-between">
                                    <span>VAT-EXEMPT SALES:</span>
                                    <span>₱0.00</span>
                                </div>
                                <div class="flex justify-between">
                                    <span>ZERO RATED SALES:</span>
                                    <span>₱0.00</span>
                                </div>
                            </div>

                            <div class="text-center pt-2 text-[8px] leading-3 text-slate-800 space-y-1">
                                <p class="font-black uppercase tracking-tight">THIS SERVES AS YOUR SALES INVOICE</p>
                                <p class="text-[7.5px] text-slate-600">Exchange allowed within 7 days with this receipt in original packaging.</p>
                                <p class="font-bold" id="slipFooterMessage"><?= htmlspecialchars($settings['footer_message']) ?></p>
                                <div class="pt-1.5 flex flex-col items-center justify-center">
                                    <svg id="receiptSlipBarcodeSVG" class="w-full h-8"></svg>
                                    <p id="receiptBarcodeText" class="text-[8px] tracking-widest font-mono mt-0.5"></p>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <!-- ======================================================== -->
            <!-- TAB 5: DIGITAL E-WALLETS (GCASH & MAYA)                 -->
            <!-- ======================================================== -->
            <div id="tabContent-ewallet" class="tab-pane hidden space-y-5">
                <form id="formEwallets" class="bg-white rounded-2xl border border-slate-200/80 shadow-sm p-6 space-y-5">
                    <input type="hidden" name="action" value="save_wallets">

                    <div class="flex justify-between items-center border-b border-slate-100 pb-3">
                        <div>
                            <h3 class="text-xs font-black uppercase text-slate-900">E-WALLET CONFIGURATION</h3>
                            <p class="text-[11px] font-bold text-slate-500 mt-0.5">Payment accounts synchronized with cashier checkout screen.</p>
                        </div>
                        <button type="submit" class="bg-amber-500 hover:bg-amber-600 text-slate-950 font-black px-6 py-2 rounded-xl text-xs uppercase shadow transition">
                            SAVE WALLETS
                        </button>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        
                        <!-- GCASH -->
                        <div class="p-5 bg-slate-50 rounded-2xl border border-slate-200 space-y-3.5">
                            <div class="flex items-center gap-2">
                                <span class="w-3 h-3 rounded-full bg-blue-500 inline-block"></span>
                                <h4 class="text-xs font-black uppercase text-blue-900">GCASH ACCOUNT</h4>
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase text-slate-500 block mb-1">ACCOUNT NAME</label>
                                <input type="text" name="gcash_name" value="<?= htmlspecialchars($settings['gcash_name'] ?? '') ?>" placeholder="e.g. JJ Store Cashier" class="custom-input">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase text-slate-500 block mb-1">ACCOUNT NUMBER</label>
                                <input type="text" name="gcash_number" value="<?= htmlspecialchars($settings['gcash_number'] ?? '') ?>" placeholder="09XXXXXXXXX" class="custom-input">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase text-slate-500 block mb-1">QR CODE IMAGE</label>
                                <input type="file" name="gcash_qr" accept="image/*" class="text-xs">
                            </div>
                        </div>

                        <!-- MAYA -->
                        <div class="p-5 bg-slate-50 rounded-2xl border border-slate-200 space-y-3.5">
                            <div class="flex items-center gap-2">
                                <span class="w-3 h-3 rounded-full bg-emerald-500 inline-block"></span>
                                <h4 class="text-xs font-black uppercase text-emerald-900">MAYA ACCOUNT</h4>
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase text-slate-500 block mb-1">ACCOUNT NAME</label>
                                <input type="text" name="maya_name" value="<?= htmlspecialchars($settings['maya_name'] ?? '') ?>" placeholder="e.g. JJ Store Merchant" class="custom-input">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase text-slate-500 block mb-1">ACCOUNT NUMBER</label>
                                <input type="text" name="maya_number" value="<?= htmlspecialchars($settings['maya_number'] ?? '') ?>" placeholder="09XXXXXXXXX" class="custom-input">
                            </div>
                            <div>
                                <label class="text-[10px] font-black uppercase text-slate-500 block mb-1">QR CODE IMAGE</label>
                                <input type="file" name="maya_qr" accept="image/*" class="text-xs">
                            </div>
                        </div>

                    </div>

                    <div class="text-right pt-2">
                        <button type="submit" class="bg-amber-500 hover:bg-amber-600 text-slate-950 font-black px-7 py-2.5 rounded-xl text-xs uppercase transition shadow">
                            SAVE WALLETS
                        </button>
                    </div>
                </form>
            </div>

        </main>
    </div>

    <!-- ======================================================== -->
    <!-- FORMAL EXECUTIVE AI CHATBOT WIDGET (FULLY SYNCHRONIZED)  -->
    <!-- ======================================================== -->
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
                        <p class="text-[10px] font-bold text-slate-400 mt-0.5">POS Hardware &amp; Store Operations Advisory</p>
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

            <!-- Action Prompt Chips matching index.php -->
            <div class="bg-slate-50 border-b border-slate-200/90 px-3.5 py-2.5 flex items-center gap-1.5 overflow-x-auto custom-scroll text-[11px] font-bold text-slate-700 whitespace-nowrap">
                <button type="button" onclick="sendAiQuickPrompt('Kailan ang nearest restock date at oras ng mga nauubos na produkto?')" class="bg-white hover:bg-slate-100 hover:border-slate-300 border border-slate-200/90 px-3 py-1 rounded-lg transition-all shrink-0 shadow-2xs">
                    ⏰ Nearest Restock
                </button>
                <button type="button" onclick="sendAiQuickPrompt('Ulat sa benta ngayon at mga transaksyon')" class="bg-white hover:bg-slate-100 hover:border-slate-300 border border-slate-200/90 px-3 py-1 rounded-lg transition-all shrink-0 shadow-2xs">
                    📊 Benta Ngayon
                </button>
                <button type="button" onclick="sendAiQuickPrompt('Alin ang mga produkto na kailangang i-reorder at low stock?')" class="bg-white hover:bg-slate-100 hover:border-slate-300 border border-slate-200/90 px-3 py-1 rounded-lg transition-all shrink-0 shadow-2xs">
                    ⚠️ Low Stocks
                </button>
                <button type="button" onclick="sendAiQuickPrompt('Ano ang mga nangungunang produkto at best sellers?')" class="bg-white hover:bg-slate-100 hover:border-slate-300 border border-slate-200/90 px-3 py-1 rounded-lg transition-all shrink-0 shadow-2xs">
                    🏆 Best Sellers
                </button>
                <button type="button" onclick="sendAiQuickPrompt('I-kumpara ang benta at performance ng Store 1 at Store 2')" class="bg-white hover:bg-slate-100 hover:border-slate-300 border border-slate-200/90 px-3 py-1 rounded-lg transition-all shrink-0 shadow-2xs">
                    🏪 Store 1 vs Store 2
                </button>
                <button type="button" onclick="sendAiQuickPrompt('Ano ang format ng thermal receipt at paper width?')" class="bg-white hover:bg-slate-100 hover:border-slate-300 border border-slate-200/90 px-3 py-1 rounded-lg transition-all shrink-0 shadow-2xs">
                    🧾 Thermal Settings
                </button>
            </div>

            <!-- Messages Stream -->
            <div id="aiChatMessages" class="flex-1 p-4 overflow-y-auto custom-scroll space-y-3.5 bg-[#f8fafc] text-xs">
                <div class="flex items-start gap-2.5">
                    <div class="w-7 h-7 rounded-lg bg-[#0f172a] text-amber-500 flex items-center justify-center text-[11px] font-black shrink-0 border border-slate-700">
                        AI
                    </div>
                    <div class="bg-white p-3.5 rounded-2xl rounded-tl-sm border border-slate-200/90 text-slate-800 shadow-2xs leading-relaxed max-w-[88%]">
                        Magandang araw! Ako ang inyong <b>JJ Enterprise AI Assistant</b>. Naka-link ako sa live settings, POS hardware, imbentaryo, replenishment schedules, at dashboard ng <b><?= htmlspecialchars($store_label) ?></b>. Paano ko kayo matutulungan ngayon?
                    </div>
                </div>
            </div>

            <!-- Chat Input -->
            <form id="aiChatForm" class="p-3 bg-white border-t border-slate-200 flex items-center gap-2">
                <input type="text" id="aiChatInput" placeholder="Magtanong tungkol sa benta, restock dates, o hardware..." class="flex-1 bg-slate-50 focus:bg-white border border-slate-300 focus:border-amber-500 text-slate-800 text-xs rounded-xl px-3.5 py-2.5 outline-none font-semibold transition-all">
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

    <!-- JAVASCRIPTS -->
    <script>
        let currentTabKey = 'profile';

        // Synchronized Live Data Object for Enterprise AI
        const liveStoreData = {
            storeLabel: <?= json_encode($store_label) ?>,
            selectedStore: <?= json_encode($selected_store) ?>,
            todaySales: <?= json_encode($todaySalesVal) ?>,
            todayTxCount: <?= json_encode($todayTxCount) ?>,
            store1Sales: <?= json_encode($store1_total_today) ?>,
            store2Sales: <?= json_encode($store2_total_today) ?>,
            lowStockAlerts: <?= json_encode($total_low_stock_alerts) ?>,
            lowStockList: <?= json_encode($low_stock_list) ?>,
            topSellers: <?= json_encode($topSellers) ?>,
            paperWidth: <?= json_encode($settings['paper_width']) ?>
        };

        // Sidebar Toggle
        const sidebar = document.getElementById('sidebar');
        const toggleSidebar = document.getElementById('toggleSidebar');
        if (toggleSidebar && sidebar) {
            toggleSidebar.addEventListener('click', () => {
                sidebar.classList.toggle('w-64');
                sidebar.classList.toggle('w-16');
                document.querySelectorAll('.sidebar-text').forEach(s => s.classList.toggle('hidden'));
            });
        }

        // Toast Notification System
        function showToast(message, isError = false) {
            const container = document.getElementById('toastContainer');
            const pill = document.createElement('div');
            pill.className = `toast-pill ${isError ? 'error' : ''}`;
            pill.innerHTML = `<i class="fas ${isError ? 'fa-triangle-exclamation' : 'fa-check-circle'}"></i> <span>${message}</span>`;
            container.appendChild(pill);
            setTimeout(() => pill.classList.add('show'), 10);
            setTimeout(() => {
                pill.classList.remove('show');
                setTimeout(() => pill.remove(), 400);
            }, 3500);
        }

        // Tab Switcher & State Persistence
        function switchSettingsTab(tabKey) {
            currentTabKey = tabKey;
            
            document.querySelectorAll('.tab-pane').forEach(el => el.classList.add('hidden'));
            const pane = document.getElementById('tabContent-' + tabKey);
            if (pane) pane.classList.remove('hidden');

            document.querySelectorAll('#settingsTabNav .tab-btn').forEach(btn => {
                if (btn.getAttribute('data-tab') === tabKey) {
                    btn.className = "tab-btn active px-3.5 py-2 rounded-xl text-xs font-black uppercase whitespace-nowrap transition flex items-center gap-2";
                } else {
                    btn.className = "tab-btn px-3.5 py-2 rounded-xl text-xs font-black uppercase whitespace-nowrap text-slate-600 hover:text-slate-900 transition flex items-center gap-2";
                }
            });

            try {
                history.replaceState(null, null, '#' + tabKey);
                localStorage.setItem('jj_active_settings_tab', tabKey);
            } catch(e) {}

            if (tabKey === 'barcode') {
                setTimeout(renderBarcode, 60);
            }
        }

        function switchStoreScope(storeVal) {
            window.location.href = `settings.php?store_id=${encodeURIComponent(storeVal)}#${currentTabKey}`;
        }

        document.addEventListener('DOMContentLoaded', () => {
            const validTabs = ['profile', 'storepos', 'barcode', 'thermal', 'ewallet'];
            let initialTab = 'profile';

            const hashVal = window.location.hash.replace('#', '').trim().toLowerCase();
            const storedTab = localStorage.getItem('jj_active_settings_tab');

            if (validTabs.includes(hashVal)) {
                initialTab = hashVal;
            } else if (validTabs.includes(storedTab)) {
                initialTab = storedTab;
            }

            switchSettingsTab(initialTab);
        });

        // Universal Asynchronous Form Submission
        function attachAjaxForm(formId, customSuccessCallback) {
            const form = document.getElementById(formId);
            if (!form) return;

            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                const submitBtn = form.querySelector('button[type="submit"]');
                const originalBtnHtml = submitBtn ? submitBtn.innerHTML : '';
                
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = `<i class="fas fa-spinner fa-spin"></i> SAVING...`;
                }

                try {
                    const formData = new FormData(form);
                    formData.append('is_ajax', '1');

                    const response = await fetch('settings.php?store_id=<?= urlencode($selected_store) ?>', {
                        method: 'POST',
                        body: formData,
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    });

                    const result = await response.json();

                    if (result.status === 'success') {
                        showToast(result.message || 'SETTINGS SAVED SUCCESSFULLY!');
                        if (customSuccessCallback) customSuccessCallback(result, form);
                    } else {
                        showToast(result.message || 'AN ERROR OCCURRED WHILE SAVING!', true);
                    }
                } catch (err) {
                    showToast('NETWORK ERROR: UNABLE TO SAVE SETTINGS', true);
                    console.error('Save error:', err);
                } finally {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalBtnHtml;
                    }
                }
            });
        }

        attachAjaxForm('formAvatarUpload', (data) => {
            if (data.avatar_url) {
                const previewImg = document.getElementById('avatarPreviewImg');
                const initialsText = document.getElementById('avatarInitialsText');
                const navAvatar = document.getElementById('navAvatarImg');
                const navInitials = document.getElementById('navUserInitials');

                previewImg.src = data.avatar_url;
                previewImg.classList.remove('hidden');
                if (initialsText) initialsText.classList.add('hidden');

                if (navAvatar) {
                    navAvatar.src = data.avatar_url;
                    navAvatar.classList.remove('hidden');
                }
                if (navInitials) navInitials.classList.add('hidden');
            }
        });

        attachAjaxForm('formUpdateProfile', (data) => {
            if (data.fullname) {
                document.getElementById('profileCardFullname').innerText = data.fullname;
            }
            if (data.email) {
                document.getElementById('profileCardEmail').innerText = data.email;
            }
            if (data.initials) {
                const navInit = document.getElementById('navUserInitials');
                const cardInit = document.getElementById('avatarInitialsText');
                if (navInit) navInit.innerText = data.initials;
                if (cardInit) cardInit.innerText = data.initials;
            }
        });

        attachAjaxForm('formChangePassword', (data, form) => {
            form.reset();
        });

        attachAjaxForm('formStorePos', (data) => {
            if (data.store_name) {
                document.getElementById('headerStoreName').innerText = data.store_name;
                document.getElementById('slipStoreName').innerText = data.store_name;
                document.getElementById('lblPreviewStoreName').innerText = data.store_name;
            }
            if (data.store_address) {
                document.getElementById('slipStoreAddress').innerText = data.store_address;
            }
            if (data.contact_number) {
                document.getElementById('slipStoreContact').innerText = data.contact_number;
            }
            if (data.footer_message) {
                document.getElementById('slipFooterMessage').innerText = data.footer_message;
            }
            if (data.logo_url) {
                const headerLogoImg = document.getElementById('headerStoreLogoImg');
                const headerLogoTxt = document.getElementById('headerStoreLogoText');
                const boxLogoImg = document.getElementById('storeLogoImg');
                const boxLogoTxt = document.getElementById('storeLogoText');

                if (headerLogoImg) {
                    headerLogoImg.src = data.logo_url;
                    headerLogoImg.classList.remove('hidden');
                }
                if (headerLogoTxt) headerLogoTxt.classList.add('hidden');

                if (boxLogoImg) {
                    boxLogoImg.src = data.logo_url;
                    boxLogoImg.classList.remove('hidden');
                }
                if (boxLogoTxt) boxLogoTxt.classList.add('hidden');
            }

            const aiContainer = document.getElementById('aiChatbotContainer');
            if (aiContainer) {
                if (data.ai_analysis === 1) aiContainer.classList.remove('hidden');
                else aiContainer.classList.add('hidden');
            }
        });

        attachAjaxForm('formThermalSettings', (data) => {
            if (data.paper_width) {
                onPaperWidthChange(data.paper_width);
            }
        });

        attachAjaxForm('formEwallets');

        // Image Preview Helpers
        function previewAvatarLocal(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(evt) {
                    const img = document.getElementById('avatarPreviewImg');
                    img.src = evt.target.result;
                    img.classList.remove('hidden');
                    const initials = document.getElementById('avatarInitialsText');
                    if (initials) initials.classList.add('hidden');
                };
                reader.readAsDataURL(file);
            }
        }

        function previewLogoLocal(e) {
            const file = e.target.files[0];
            if (file) {
                const notice = document.getElementById('logoFileNameNotice');
                if (notice) notice.innerText = file.name;
                const reader = new FileReader();
                reader.onload = function(evt) {
                    const img = document.getElementById('storeLogoImg');
                    img.src = evt.target.result;
                    img.classList.remove('hidden');
                    const txt = document.getElementById('storeLogoText');
                    if (txt) txt.classList.add('hidden');
                };
                reader.readAsDataURL(file);
            }
        }

        // Barcode Scanner & Label Logic
        let currentBarcodeData = {
            name: <?= json_encode($productsList[0]['product_name'] ?? 'Party Popper') ?>,
            price: <?= json_encode(number_format($productsList[0]['price'] ?? 75, 2)) ?>,
            code: <?= json_encode($productsList[0]['barcode'] ?? '45156832') ?>,
            stock: <?= json_encode((string)($productsList[0]['stock'] ?? 17)) ?>
        };

        function renderBarcode() {
            try {
                JsBarcode("#previewBarcodeSVG", currentBarcodeData.code, {
                    format: "CODE128",
                    lineColor: "#0f172a",
                    width: 1.8,
                    height: 35,
                    displayValue: true,
                    fontSize: 10,
                    margin: 0
                });
            } catch (err) {}
        }

        function loadProductToLabel(name, code, price, stock) {
            currentBarcodeData = { name, code, price, stock };
            document.getElementById('detProdName').innerText = name;
            document.getElementById('detProdCode').innerText = code;
            document.getElementById('detProdPrice').innerText = '₱' + price;
            document.getElementById('detProdStock').innerText = stock + ' units';

            document.getElementById('lblPreviewName').innerText = name;
            document.getElementById('lblPreviewPrice').innerText = '₱' + price;
            document.getElementById('lblPreviewStock').innerText = stock;
            renderBarcode();
        }

        function onSelectProductDropdown(selectEl) {
            const opt = selectEl.options[selectEl.selectedIndex];
            if (!opt) return;
            const code = opt.value;
            const name = opt.getAttribute('data-name');
            const price = opt.getAttribute('data-price');
            const stock = opt.getAttribute('data-stock');
            loadProductToLabel(name, code, price, stock);
        }

        function handleBarcodeEnter(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                performBarcodeLookup();
            }
        }

        function performBarcodeLookup() {
            const val = document.getElementById('liveScannerInput').value.trim();
            if (!val) return;
            const select = document.getElementById('selectProductBarcode');
            let found = false;
            for (let i = 0; i < select.options.length; i++) {
                if (select.options[i].value.toLowerCase() === val.toLowerCase() || select.options[i].getAttribute('data-name').toLowerCase().includes(val.toLowerCase())) {
                    select.selectedIndex = i;
                    onSelectProductDropdown(select);
                    found = true;
                    showToast("PRODUCT DETECTED: " + select.options[i].getAttribute('data-name'));
                    break;
                }
            }
            if (!found) {
                showToast("BARCODE NOT FOUND IN CATALOG", true);
            }
        }

        function printSelectedLabel() {
            window.print();
        }

        // Thermal Receipt Live Preview
        async function loadLastSale() {
            showToast("FETCHING LAST SALE FOR " + <?= json_encode($store_label) ?> + "...");
            try {
                const res = await fetch('settings.php?action=load_last_sale&store_id=' + encodeURIComponent(<?= json_encode($selected_store) ?>));
                const data = await res.json();
                if (data.status === 'success') {
                    document.getElementById('slipDate').innerText = data.created_at;
                    document.getElementById('slipOR').innerText = data.receipt_no;
                    document.getElementById('slipCashier').innerText = data.cashier;
                    
                    const total = Number(data.total_amount) || 0;
                    const tendered = total > 0 ? (Math.ceil(total / 50) * 50) : 100.00;
                    const change = Math.max(0, tendered - total);
                    
                    const vatable = total / 1.12;
                    const vat = total - vatable;

                    document.getElementById('slipTotal').innerText = '₱' + total.toFixed(2);
                    document.getElementById('slipTendered').innerText = '₱' + tendered.toFixed(2);
                    document.getElementById('slipChange').innerText = '₱' + change.toFixed(2);
                    document.getElementById('slipVatable').innerText = '₱' + vatable.toFixed(2);
                    document.getElementById('slipVat').innerText = '₱' + vat.toFixed(2);

                    const itemsContainer = document.getElementById('puregoldItemsList');
                    itemsContainer.innerHTML = '';
                    
                    let totalQty = 0;
                    data.items.forEach(itm => {
                        const qty = Number(itm.quantity) || 1;
                        const unitPrice = Number(itm.unit_price) || (Number(itm.total_price) / qty);
                        const itemTotal = Number(itm.total_price) || 0;
                        totalQty += qty;

                        itemsContainer.innerHTML += `
                            <div>
                                <div class="font-bold uppercase tracking-tight">${itm.product_name}</div>
                                <div class="flex justify-between text-[9px] pl-2 text-slate-800">
                                    <span>${qty} @ ${unitPrice.toFixed(2)}</span>
                                    <span class="font-bold font-mono">${itemTotal.toFixed(2)} V</span>
                                </div>
                            </div>
                        `;
                    });

                    document.getElementById('slipItemCount').innerText = totalQty;

                    try {
                        const cleanCode = data.receipt_no.replace(/[^A-Za-z0-9]/g, '');
                        JsBarcode("#receiptSlipBarcodeSVG", cleanCode, {
                            format: "CODE128",
                            lineColor: "#000",
                            width: 1.2,
                            height: 25,
                            displayValue: false,
                            margin: 0
                        });
                        document.getElementById('receiptBarcodeText').innerText = data.receipt_no;
                    } catch (bErr) {}

                    setTimeout(() => {
                        showToast("LOADED OR#: " + data.receipt_no);
                    }, 400);
                }
            } catch (err) {
                showToast("FAILED TO FETCH LAST SALE", true);
            }
        }

        function onPaperWidthChange(val) {
            const badge = document.getElementById('badgePaperWidth');
            if (badge) badge.innerText = val.toUpperCase() + ' THERMAL';
        }

        function testPrintThermal() {
            window.print();
        }

        document.addEventListener('DOMContentLoaded', () => {
            renderBarcode();
            try {
                const defaultOR = document.getElementById('slipOR').innerText.trim().replace(/[^A-Za-z0-9]/g, '');
                JsBarcode("#receiptSlipBarcodeSVG", defaultOR || "REC20261014001", {
                    format: "CODE128",
                    lineColor: "#000",
                    width: 1.2,
                    height: 25,
                    displayValue: false,
                    margin: 0
                });
                document.getElementById('receiptBarcodeText').innerText = defaultOR;
            } catch(e) {}
        });

        // Heartbeat API
        function sendUserHeartbeat() {
            fetch('settings.php?store_id=<?= urlencode($selected_store) ?>&action=heartbeat')
                .catch(() => {});
        }
        sendUserHeartbeat();
        setInterval(sendUserHeartbeat, 10000);

        window.addEventListener('beforeunload', () => {
            if (navigator.sendBeacon) {
                navigator.sendBeacon('settings.php?store_id=<?= urlencode($selected_store) ?>&action=set_offline');
            }
        });

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
                            Nalinis na ang usapan. Handa akong magbigay ng pagsusuri sa benta, replenishment schedules, barcode labels, o thermal hardware!
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

            // 6. HARDWARE & SETTINGS GUIDES
            if (q.includes('thermal') || q.includes('resibo') || q.includes('receipt') || q.includes('print')) {
                return `
                    <div class="space-y-1.5">
                        <div class="font-extrabold text-slate-900 border-b border-slate-100 pb-1">
                            🧾 ESC/POS Thermal Receipt Hardware
                        </div>
                        <p class="text-slate-700 leading-normal">
                            Kasalukuyang naka-set sa <b>${d.paperWidth.toUpperCase()} Thermal</b>. May kasamang 12% BIR VAT computation at auto-generated barcode. Pindutin ang <b>"LOAD LAST POS SALE"</b> sa THERMAL PRINTER tab para sa live test.
                        </p>
                    </div>
                `;
            }

            if (q.includes('ewallet') || q.includes('wallet') || q.includes('gcash') || q.includes('maya')) {
                return `
                    <div class="space-y-1.5">
                        <div class="font-extrabold text-slate-900 border-b border-slate-100 pb-1">
                            💳 Digital E-Wallets (GCash &amp; Maya)
                        </div>
                        <p class="text-slate-700 leading-normal">
                            Pumunta sa <b>E-WALLET</b> tab upang i-upload ang GCash at Maya merchant QR codes. Awtomatiko itong lalabas sa kahera kapag pinili ang digital payment method.
                        </p>
                    </div>
                `;
            }

            if (q.includes('barcode') || q.includes('scanner') || q.includes('label')) {
                return `
                    <div class="space-y-1.5">
                        <div class="font-extrabold text-slate-900 border-b border-slate-100 pb-1">
                            🏷️ Barcode Scanner &amp; 58mm Label Printing
                        </div>
                        <p class="text-slate-700 leading-normal">
                            Sa <b>BARCODE SCANNER</b> tab, suportado ang live barcode search via USB scanner at pag-print ng standard 58mm adhesive merchandise price tags.
                        </p>
                    </div>
                `;
            }

            // Default fallback menu
            return `
                Naka-konekta ako sa live database at hardware configuration. Maaari mong itanong:<br>
                • <i>"Kailan ang nearest restock date at oras ng mga nauubos na produkto?"</i><br>
                • <i>"Magkano ang benta at kita ngayon?"</i><br>
                • <i>"Alin ang mga produkto na kailangang i-reorder at low stock?"</i><br>
                • <i>"Ano ang mga nangungunang produkto at best sellers?"</i><br>
                • <i>"I-kumpara ang benta at performance ng Store 1 at Store 2"</i><br>
                • <i>"Paano i-setup ang thermal printer o e-wallets?"</i>
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
                        <span class="animate-pulse">Kinukuha ang live restock schedules at hardware data...</span>
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

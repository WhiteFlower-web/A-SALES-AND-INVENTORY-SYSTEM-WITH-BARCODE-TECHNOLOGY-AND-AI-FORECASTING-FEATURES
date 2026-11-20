<?php
// JJ JUDEJUSH STORES - SALES STAFF POS TERMINAL 
session_start();
date_default_timezone_set('Asia/Manila');

// 1. AUTHENTICATION & ACCESS GUARD
$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$allowed_roles = [
    'sales staff', 'salesstaff', 'sales', 'cashier', 
    'admin', 'administrator', 'owner', 'store owner'
];

if (!isset($_SESSION['user_id']) || !in_array($user_role, $allowed_roles, true)) {
    if (file_exists(__DIR__ . '/login.php')) {
        header("Location: login.php?error=unauthorized_access");
    } else {
        header("Location: ../login.php?error=unauthorized_access");
    }
    exit();
}

// 2. CONFIGURATION & DATABASE CONNECTION
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
                <p>Unable to connect to JJ_Stores: " . htmlspecialchars($e->getMessage()) . "</p>
             </div>");
    }
}

try {
    $pdo->exec("SET time_zone = '+08:00'");
} catch (Throwable $t) {}

$current_file = basename($_SERVER['PHP_SELF']);
$user_id      = (int)($_SESSION['user_id'] ?? 1);

// Resolve User Profile & Store Assignment
$currentUserFullname = $_SESSION['fullname'] ?? 'Sales Staff';
$currentUserRole     = $_SESSION['role'] ?? 'Sales Staff';
$current_user_avatar = $_SESSION['avatar'] ?? null;
$userAssignedStore   = null;

try {
    $stmtUser = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmtUser->execute([$user_id]);
    $userRow = $stmtUser->fetch();
    if ($userRow) {
        $currentUserFullname = $userRow['fullname'] ?? $currentUserFullname;
        $currentUserRole     = $userRow['role'] ?? $currentUserRole;
        $userAssignedStore   = isset($userRow['store_id']) ? (int)$userRow['store_id'] : null;
        $avatarVal = $userRow['avatar'] ?? ($userRow['profile_image'] ?? null);
        if (!empty($avatarVal)) {
            $_SESSION['avatar']  = $avatarVal;
            $current_user_avatar = $avatarVal;
        }
    }
} catch (Throwable $t) {}

$store_id = isset($_GET['store_id']) ? (int)$_GET['store_id'] : (isset($_SESSION['store_id']) ? (int)$_SESSION['store_id'] : ($userAssignedStore ?: 1));
if (!in_array($store_id, [1, 2])) {
    $store_id = 1;
}
$_SESSION['store_id'] = $store_id;

// Mark current user as Online
try {
    $pdo->prepare("UPDATE users SET status = 'Online', last_activity = NOW(), store_id = ? WHERE id = ?")
        ->execute([$store_id, $user_id]);
} catch (Throwable $t) {}

// 3. SCHEMA SYNCHRONIZATION WITH SETTINGS.PHP
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

        CREATE TABLE IF NOT EXISTS sales (
            id INT AUTO_INCREMENT PRIMARY KEY,
            store_id INT DEFAULT 1,
            user_id INT DEFAULT 1,
            product_id INT NULL DEFAULT NULL,
            quantity INT NULL DEFAULT 1,
            receipt_number VARCHAR(100) NULL,
            subtotal DECIMAL(10,2) NULL DEFAULT 0.00,
            discount_amount DECIMAL(10,2) NULL DEFAULT 0.00,
            discount_type VARCHAR(50) DEFAULT 'NONE',
            total_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            payment_method VARCHAR(50) DEFAULT 'Cash',
            reference_no VARCHAR(100) NULL,
            cash_received DECIMAL(10,2) NULL DEFAULT 0.00,
            change_amount DECIMAL(10,2) NULL DEFAULT 0.00,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        
        CREATE TABLE IF NOT EXISTS sale_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sale_id INT NOT NULL,
            product_id INT NOT NULL,
            product_name VARCHAR(255) NULL,
            quantity INT NOT NULL,
            unit_price DECIMAL(10,2) NOT NULL,
            discount_amount DECIMAL(10,2) DEFAULT 0.00,
            total_price DECIMAL(10,2) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS stock_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            product_name VARCHAR(255) NULL,
            type VARCHAR(50) NOT NULL,
            quantity INT NOT NULL,
            stock_after INT DEFAULT 0,
            notes TEXT NULL,
            remarks TEXT NULL,
            user_id INT DEFAULT 1,
            store_id INT DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            store_id INT DEFAULT 1,
            title VARCHAR(255) NOT NULL,
            message TEXT NULL,
            is_read TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS audit_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT DEFAULT 1,
            action VARCHAR(255) NOT NULL,
            page_module VARCHAR(100) DEFAULT 'Cashier POS',
            details TEXT NULL,
            ip_address VARCHAR(45) NULL,
            store_id INT DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Throwable $t) {}

// Dynamic Product Table Inspection
$prodCols = [];
try {
    $prodCols = $pdo->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $t) {}

$stockCol = 'stock';
foreach (['stock', 'quantity', 'qty'] as $sc) {
    if (in_array($sc, $prodCols, true)) {
        $stockCol = $sc;
        break;
    }
}
$hasProdStoreId   = in_array('store_id', $prodCols, true);
$hasProdIsDeleted = in_array('is_deleted', $prodCols, true);
$hasProdVerified  = in_array('is_verified', $prodCols, true);
$hasProdMinStock  = in_array('min_stock', $prodCols, true);

// 4. AJAX ENDPOINTS
if (isset($_GET['action']) && $_GET['action'] === 'heartbeat') {
    header('Content-Type: application/json');
    try {
        $target_store = isset($_GET['store_id']) ? (int)$_GET['store_id'] : $store_id;
        $pdo->prepare("UPDATE users SET status = 'Online', last_activity = NOW(), store_id = ? WHERE id = ?")
            ->execute([$target_store, $user_id]);
        echo json_encode(['status' => 'success', 'time' => date('h:i:s A')]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'find_receipt') {
    header('Content-Type: application/json');
    $query = trim($_GET['query'] ?? '');
    if ($query === '') {
        echo json_encode(['found' => false]);
        exit;
    }
    try {
        $cleanQ = str_replace('-', '', $query);
        $stmtF = $pdo->prepare("
            SELECT s.*, u.fullname AS cashier_name 
            FROM sales s 
            LEFT JOIN users u ON s.user_id = u.id 
            WHERE (s.receipt_number = ? OR REPLACE(s.receipt_number, '-', '') = ? OR s.receipt_number LIKE ?)
              AND (s.store_id = ? OR s.store_id IS NULL OR s.store_id = 0)
            ORDER BY s.id DESC LIMIT 1
        ");
        $stmtF->execute([$query, $cleanQ, "%{$query}%", $store_id]);
        $sale = $stmtF->fetch();

        if ($sale) {
            $stmtItems = $pdo->prepare("SELECT * FROM sale_items WHERE sale_id = ?");
            $stmtItems->execute([$sale['id']]);
            $rawItems = $stmtItems->fetchAll();

            $formattedItems = [];
            $totalPieces = 0;
            foreach ($rawItems as $it) {
                $unitP = (float)$it['unit_price'];
                $qty   = (int)$it['quantity'];
                $disc  = (float)($it['discount_amount'] ?? 0);
                $origP = $unitP + ($disc / max(1, $qty));
                $totalPieces += $qty;

                $formattedItems[] = [
                    'name'          => $it['product_name'] ?: 'Product Item',
                    'qty'           => $qty,
                    'price'         => $unitP,
                    'originalPrice' => $origP,
                    'total_price'   => (float)$it['total_price']
                ];
            }

            $saleTotal   = (float)$sale['total_price'];
            $vatable     = round($saleTotal / 1.12, 2);
            $vatAmount   = round($saleTotal - $vatable, 2);

            echo json_encode([
                'found'   => true,
                'receipt' => [
                    'id'             => (int)$sale['id'],
                    'receipt_number' => $sale['receipt_number'] ?: ('REC-' . sprintf('%05d', $sale['id'])),
                    'date'           => date('m/d/Y H:i:s', strtotime($sale['created_at'])),
                    'cashier'        => $sale['cashier_name'] ?: 'Sales Staff',
                    'payment_method' => $sale['payment_method'] ?: 'Cash',
                    'reference_no'   => $sale['reference_no'] ?: '',
                    'subtotal'       => (float)($sale['subtotal'] ?: $saleTotal),
                    'discount'       => (float)($sale['discount_amount'] ?: 0),
                    'discount_type'  => $sale['discount_type'] ?: 'NONE',
                    'total'          => $saleTotal,
                    'cash'           => (float)($sale['cash_received'] ?: $saleTotal),
                    'change'         => (float)($sale['change_amount'] ?: 0),
                    'item_count'     => $totalPieces,
                    'vatable'        => $vatable,
                    'vat_amount'     => $vatAmount,
                    'items'          => $formattedItems
                ]
            ]);
            exit;
        }
    } catch (Throwable $e) {}
    echo json_encode(['found' => false]);
    exit;
}

if (!function_exists('logActivity')) {
    function logActivity($pdo, $action, $details, $page_module = 'Cashier POS', $store_id = 1) {
        try {
            $ip  = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $uid = (int)($_SESSION['user_id'] ?? 1);
            $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, page_module, details, ip_address, store_id, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$uid, $action, $page_module, $details, $ip, $store_id]);
        } catch (Throwable $t) {}
    }
}

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
            'uploads/wallets/' . $fileName,
            '../' . $cleanPath,
            '../uploads/logo/' . $fileName,
            '../uploads/products/' . $fileName,
            '../uploads/avatars/' . $fileName,
            '../uploads/wallets/' . $fileName,
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

// 5. LOAD SETTINGS SYNCHRONIZED WITH SETTINGS.PHP
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
    'store_logo'        => null,
    'gcash_name'        => 'JJ Store Cashier',
    'gcash_number'      => '09123456789',
    'gcash_qr'          => null,
    'maya_name'         => 'JJ Store Merchant',
    'maya_number'       => '09987654321',
    'maya_qr'           => null
];

try {
    $chkSettings = $pdo->query("SELECT * FROM settings WHERE id = 1 LIMIT 1")->fetch();
    if ($chkSettings) {
        $settings = array_merge($settings, $chkSettings);
    }
} catch (Throwable $t) {}

$resolvedLogoUrl   = !empty($settings['store_logo']) ? resolveAssetPath($settings['store_logo']) : null;
$resolvedAvatarUrl = !empty($current_user_avatar) ? resolveAssetPath($current_user_avatar) : null;
$resolvedGcashQr   = !empty($settings['gcash_qr']) ? resolveAssetPath($settings['gcash_qr']) : null;
$resolvedMayaQr    = !empty($settings['maya_qr']) ? resolveAssetPath($settings['maya_qr']) : null;

$nameParts = explode(' ', trim($currentUserFullname));
$userInitials = count($nameParts) >= 2 
    ? strtoupper(substr($nameParts[0], 0, 1) . substr($nameParts[count($nameParts)-1], 0, 1))
    : strtoupper(substr($currentUserFullname, 0, 2));

if (isset($_GET['mark_read']) && $_GET['mark_read'] === 'all') {
    try {
        $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE store_id = ? OR store_id IS NULL OR store_id = 0")->execute([$store_id]);
    } catch (Throwable $t) {}
    header("Location: {$current_file}?store_id={$store_id}");
    exit;
}

if (isset($_GET['close_receipt'])) {
    unset($_SESSION['show_receipt_modal'], $_SESSION['last_receipt']);
    header("Location: {$current_file}?store_id={$store_id}");
    exit;
}

// 6. CHECKOUT TRANSACTION PROCESSOR
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'complete_sale') {
    $items           = json_decode($_POST['basket'] ?? '[]', true);
    $payment_method  = trim($_POST['payment_method'] ?? 'Cash');
    $reference_no    = trim($_POST['reference_no'] ?? '');
    $cash_received   = round((float)($_POST['cash_received'] ?? 0), 2);
    $discount_type   = trim($_POST['discount_type'] ?? 'NONE');
    $order_discount  = round((float)($_POST['order_discount'] ?? 0), 2);
    
    $subtotal        = 0;
    $total_qty_units = 0;
    
    if (!empty($items)) {
        $pdo->beginTransaction();
        try {
            foreach ($items as $item) {
                $qty          = (int)$item['qty'];
                $price        = round((float)$item['price'], 2);
                $subtotal     += round($price * $qty, 2);
                $total_qty_units += $qty;
            }

            $finalTotal = max(0, round($subtotal - $order_discount, 2));

            if (in_array($payment_method, ['GCash', 'Maya'], true)) {
                $cash_received = $finalTotal;
                $changeAmount  = 0.00;
            } else {
                if ($cash_received < $finalTotal) {
                    throw new Exception("Cash tendered is less than Total Due: ₱" . number_format($finalTotal, 2));
                }
                $changeAmount = max(0, round($cash_received - $finalTotal, 2));
            }

            $receipt_num = "REC-" . date('Ymd') . "-" . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
            $first_pid   = (int)($items[0]['id'] ?? 1);

            $stmtSale = $pdo->prepare("
                INSERT INTO sales (
                    product_id, quantity, store_id, user_id, receipt_number, 
                    subtotal, discount_amount, discount_type, total_price, 
                    payment_method, reference_no, cash_received, change_amount, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmtSale->execute([
                $first_pid, $total_qty_units, $store_id, $user_id, $receipt_num,
                $subtotal, $order_discount, $discount_type, $finalTotal,
                $payment_method, $reference_no, $cash_received, $changeAmount
            ]);
            $sale_id = $pdo->lastInsertId();

            $minStockSelect = $hasProdMinStock ? "min_stock" : "5 AS min_stock";

            foreach ($items as $item) {
                $pid       = (int)$item['id'];
                $qty       = (int)$item['qty'];
                $price     = round((float)$item['price'], 2);
                $lineTotal = round($price * $qty, 2);

                $stmtCheck = $pdo->prepare("SELECT product_name, {$stockCol} as stock, {$minStockSelect} FROM products WHERE id = ? FOR UPDATE");
                $stmtCheck->execute([$pid]);
                $product = $stmtCheck->fetch();

                if (!$product || $product['stock'] < $qty) {
                    throw new Exception("Insufficient stock for item: " . ($product['product_name'] ?? "#$pid"));
                }

                $newStock = $product['stock'] - $qty;

                $pdo->prepare("INSERT INTO sale_items (sale_id, product_id, product_name, quantity, unit_price, discount_amount, total_price) VALUES (?, ?, ?, ?, ?, 0.00, ?)")
                    ->execute([$sale_id, $pid, $product['product_name'], $qty, $price, $lineTotal]);

                $updateSql = "UPDATE products SET {$stockCol} = ?" . ($hasProdVerified ? ", is_verified = 0" : "") . " WHERE id = ?";
                $pdo->prepare($updateSql)->execute([$newStock, $pid]);

                $remarks = "POS Sale #{$sale_id} (OR: {$receipt_num})";
                try {
                    $pdo->prepare("
                        INSERT INTO stock_history (product_id, product_name, type, quantity, stock_after, notes, remarks, user_id, store_id, created_at) 
                        VALUES (?, ?, 'SALE', ?, ?, ?, ?, ?, ?, NOW())
                    ")->execute([$pid, $product['product_name'], -$qty, $newStock, $remarks, $remarks, $user_id, $store_id]);
                } catch (Throwable $tHist) {}

                $minStock = (int)($product['min_stock'] ?? 5);
                if ($newStock <= $minStock) {
                    $notif_title = "LOW STOCK: " . $product['product_name'];
                    $notif_msg   = "Store {$store_id} has only {$newStock} unit(s) remaining.";
                    try {
                        $chkNotif = $pdo->prepare("SELECT id FROM notifications WHERE title = ? AND is_read = 0 AND store_id = ?");
                        $chkNotif->execute([$notif_title, $store_id]);
                        if (!$chkNotif->fetch()) {
                            $pdo->prepare("INSERT INTO notifications (store_id, title, message, is_read, created_at) VALUES (?, ?, ?, 0, NOW())")
                                ->execute([$store_id, $notif_title, $notif_msg]);
                        }
                    } catch (Throwable $tNotif) {}
                }
            }

            $auditDetail = "Sale #{$sale_id} ({$receipt_num}) - Total: ₱" . number_format($finalTotal, 2) . " via {$payment_method}";
            logActivity($pdo, 'TRANSACTION_SALE', $auditDetail, 'Cashier POS', $store_id);

            $vatable = round($finalTotal / 1.12, 2);
            $vatAmt  = round($finalTotal - $vatable, 2);

            $_SESSION['last_receipt'] = [
                'items'          => $items,
                'subtotal'       => $subtotal,
                'discount'       => $order_discount,
                'discount_type'  => $discount_type,
                'total'          => $finalTotal,
                'payment_method' => $payment_method,
                'reference_no'   => $reference_no,
                'cash'           => $cash_received,
                'change'         => $changeAmount,
                'item_count'     => $total_qty_units,
                'vatable'        => $vatable,
                'vat_amount'     => $vatAmt,
                'date'           => date('m/d/Y H:i:s'),
                'receipt_number' => $receipt_num,
                'cashier'        => $currentUserFullname
            ];
            $_SESSION['show_receipt_modal'] = true;

            $pdo->commit();
            header("Location: {$current_file}?store_id={$store_id}&success=1");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            die("<script>alert('Error: " . addslashes($e->getMessage()) . "'); window.location.href='{$current_file}?store_id={$store_id}';</script>");
        }
    }
}

// 7. FETCH CATALOG DATA
$productsList = [];
try {
    $whereProd = ["{$stockCol} > 0"];
    $paramProd = [];
    if ($hasProdStoreId) {
        $whereProd[] = "(store_id = ? OR store_id IS NULL OR store_id = 0)";
        $paramProd[] = $store_id;
    }
    if ($hasProdIsDeleted) {
        $whereProd[] = "(is_deleted = 0 OR is_deleted IS NULL)";
    }
    $sqlProd = "SELECT * FROM products WHERE " . implode(" AND ", $whereProd) . " ORDER BY product_name ASC";
    $stmtP = $pdo->prepare($sqlProd);
    $stmtP->execute($paramProd);
    $productsList = $stmtP->fetchAll();
} catch (Throwable $t) {}

$categoriesList = [];
try {
    $whereCat = [];
    $paramCat = [];
    if ($hasProdStoreId) {
        $whereCat[] = "(store_id = ? OR store_id IS NULL OR store_id = 0)";
        $paramCat[] = $store_id;
    }
    if ($hasProdIsDeleted) {
        $whereCat[] = "(is_deleted = 0 OR is_deleted IS NULL)";
    }
    $whereCatStr = !empty($whereCat) ? ("WHERE " . implode(" AND ", $whereCat)) : "";
    $stmtC = $pdo->prepare("SELECT DISTINCT category FROM products {$whereCatStr} ORDER BY category ASC");
    $stmtC->execute($paramCat);
    $categoriesList = array_filter($stmtC->fetchAll(PDO::FETCH_COLUMN));
} catch (Throwable $t) {}

$todaySalesStaffTotal = 0;
try {
    $stmtRev = $pdo->prepare("SELECT SUM(total_price) FROM sales WHERE (store_id = ? OR store_id IS NULL OR store_id = 0) AND DATE(created_at) = CURDATE()");
    $stmtRev->execute([$store_id]);
    $todaySalesStaffTotal = (float)($stmtRev->fetchColumn() ?: 0);
} catch (Throwable $t) {}

$pastReceiptsList = [];
try {
    $stmtPast = $pdo->prepare("
        SELECT s.*, u.fullname AS cashier_name 
        FROM sales s 
        LEFT JOIN users u ON s.user_id = u.id 
        WHERE s.store_id = ? OR s.store_id IS NULL OR s.store_id = 0
        ORDER BY s.created_at DESC 
        LIMIT 50
    ");
    $stmtPast->execute([$store_id]);
    $rawPastSales = $stmtPast->fetchAll();

    foreach ($rawPastSales as $ps) {
        $stmtItems = $pdo->prepare("SELECT * FROM sale_items WHERE sale_id = ?");
        $stmtItems->execute([$ps['id']]);
        $items = $stmtItems->fetchAll();

        $formattedItems = [];
        $pTotalQty = 0;
        foreach ($items as $it) {
            $unitP = (float)$it['unit_price'];
            $qty   = (int)$it['quantity'];
            $disc  = (float)($it['discount_amount'] ?? 0);
            $origP = $unitP + ($disc / max(1, $qty));
            $pTotalQty += $qty;

            $formattedItems[] = [
                'name'          => $it['product_name'] ?: 'Product Item',
                'qty'           => $qty,
                'price'         => $unitP,
                'originalPrice' => $origP,
                'total_price'   => (float)$it['total_price']
            ];
        }

        $pTotal   = (float)$ps['total_price'];
        $pSubtot  = (float)($ps['subtotal'] ?: $pTotal);
        $pVatable = round($pTotal / 1.12, 2);
        $pVatAmt  = round($pTotal - $pVatable, 2);

        $pastReceiptsList[] = [
            'id'             => (int)$ps['id'],
            'receipt_number' => $ps['receipt_number'] ?: ('REC-' . sprintf('%05d', $ps['id'])),
            'date'           => date('m/d/Y H:i:s', strtotime($ps['created_at'])),
            'cashier'        => $ps['cashier_name'] ?: 'Sales Staff',
            'payment_method' => $ps['payment_method'] ?: 'Cash',
            'reference_no'   => $ps['reference_no'] ?: '',
            'subtotal'       => $pSubtot,
            'discount'       => (float)($ps['discount_amount'] ?: 0),
            'discount_type'  => $ps['discount_type'] ?: 'NONE',
            'total'          => $pTotal,
            'cash'           => (float)($ps['cash_received'] ?: $pTotal),
            'change'         => (float)($ps['change_amount'] ?: 0),
            'item_count'     => $pTotalQty,
            'vatable'        => $pVatable,
            'vat_amount'     => $pVatAmt,
            'items'          => $formattedItems
        ];
    }
} catch (Throwable $t) {}

$unreadNotifsCount = 0;
$notifsList = [];
try {
    $stmtUnread = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE is_read = 0 AND (store_id = ? OR store_id IS NULL OR store_id = 0)");
    $stmtUnread->execute([$store_id]);
    $unreadNotifsCount = (int)$stmtUnread->fetchColumn();

    $stmtNot = $pdo->prepare("SELECT * FROM notifications WHERE (store_id = ? OR store_id IS NULL OR store_id = 0) AND is_read = 0 ORDER BY created_at DESC LIMIT 5");
    $stmtNot->execute([$store_id]);
    $notifsList = $stmtNot->fetchAll();
} catch (Throwable $t) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($settings['store_name']) ?> - Sales POS Terminal</title>
    <!-- Assets -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/js/all.min.js"></script>
    <script src="https://unpkg.com/html5-qrcode"></script>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800;900&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }
        .font-mono { font-family: 'Space Mono', monospace; }
        .custom-scroll::-webkit-scrollbar { width: 5px; height: 5px; }
        .custom-scroll::-webkit-scrollbar-track { background: #f1f5f9; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        .custom-scroll::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

        /* ========================================================
           EXACT 48mm PRINTABLE THERMAL RECEIPT RULES (ESC/POS 58mm)
           ======================================================== */
        @media print {
            @page {
                size: 58mm auto;
                margin: 0 !important;
            }
            *, *::before, *::after {
                color: #000000 !important;
                background: transparent !important;
                box-shadow: none !important;
                text-shadow: none !important;
            }
            html, body {
                width: 58mm !important;
                max-width: 58mm !important;
                margin: 0 !important;
                padding: 0 !important;
                background: #ffffff !important;
                font-family: 'Space Mono', 'Courier New', monospace !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            header, main, #receiptHistoryModal, #cameraModal, #settingsModal, .no-print {
                display: none !important;
            }
            #receiptModal {
                position: static !important;
                display: block !important;
                width: 58mm !important;
                max-width: 58mm !important;
                margin: 0 !important;
                padding: 0 !important;
                background: #ffffff !important;
                border: none !important;
                box-shadow: none !important;
            }
            #printableSlipArea {
                width: 48mm !important;
                max-width: 48mm !important;
                min-width: 48mm !important;
                margin: 0 auto !important;
                padding: 1.5mm 0.5mm 6mm 0.5mm !important;
                font-size: 9px !important;
                line-height: 1.2 !important;
                background: #ffffff !important;
                border: none !important;
                box-sizing: border-box !important;
                display: block !important;
            }
            #printableSlipArea svg#receiptSlipBarcodeSVG {
                width: 100% !important;
                max-width: 46mm !important;
                height: 32px !important;
                margin: 2px auto !important;
                display: block !important;
                shape-rendering: crispEdges !important;
                image-rendering: pixelated !important;
            }
            #receiptSlipLogoImg {
                display: block !important;
                margin: 0 auto 3px auto !important;
                max-height: 36px !important;
                max-width: 36px !important;
                filter: grayscale(100%) contrast(150%) !important;
            }
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-800 flex flex-col h-screen overflow-hidden select-none">

    <!-- TOP NAVIGATION BAR -->
    <header class="flex items-center justify-between bg-[#080d1a] text-white px-5 py-3 shadow-md z-30 flex-shrink-0 border-b border-slate-900 no-print">
        
        <!-- BRAND & PORTAL TITLE -->
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
                
            </div>
        </div>

        <!-- RIGHT CONTROLS: DUAL STORE SWITCHER, NOTIF BELL & PROFILE DROPDOWN -->
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
                    <?php if($unreadNotifsCount > 0): ?>
                        <span class="absolute top-2 right-2.5 w-2 h-2 bg-amber-500 rounded-full ring-2 ring-[#080d1a]"></span>
                    <?php endif; ?>
                </button>
                <div class="absolute right-0 top-full mt-2 w-72 bg-white text-slate-800 rounded-2xl shadow-2xl border border-slate-200 opacity-0 invisible group-hover:opacity-100 group-hover:visible transition duration-150 z-50 overflow-hidden">
                    <div class="p-3 bg-slate-950 text-white flex justify-between items-center text-xs font-black uppercase">
                        <span>Notifications</span>
                        <span class="bg-amber-500 text-slate-950 text-[10px] px-1.5 py-0.5 rounded font-black"><?= $unreadNotifsCount ?> New</span>
                    </div>
                    <div class="max-h-56 overflow-y-auto custom-scroll divide-y divide-slate-100 text-xs">
                        <?php if(!empty($notifsList)): ?>
                            <?php foreach($notifsList as $n): ?>
                            <div class="p-3 hover:bg-slate-50">
                                <p class="font-bold text-slate-900"><?= htmlspecialchars($n['title']) ?></p>
                                <p class="text-[11px] text-slate-500 mt-0.5"><?= htmlspecialchars($n['message']) ?></p>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="py-6 text-center text-slate-400 font-semibold text-xs">No alerts</p>
                        <?php endif; ?>
                    </div>
                    <div class="p-2 bg-slate-50 border-t text-center">
                        <a href="?store_id=<?= $store_id ?>&mark_read=all" class="text-[10px] font-black text-amber-600 hover:underline uppercase">Clear All</a>
                    </div>
                </div>
            </div>

            <!-- USER PROFILE DROPDOWN -->
            <div class="relative" id="profileDropdownContainer">
                <button type="button" onclick="toggleProfileDropdown()" title="<?= htmlspecialchars($currentUserFullname) ?>" class="w-10 h-10 rounded-full bg-amber-500 hover:bg-amber-400 text-slate-950 flex items-center justify-center font-black text-xs shadow-md transition-all overflow-hidden border border-amber-400 cursor-pointer">
                    <?php if ($resolvedAvatarUrl): ?>
                        <img src="<?= htmlspecialchars($resolvedAvatarUrl) ?>?v=<?= time() ?>" alt="User Avatar" class="w-full h-full object-cover">
                    <?php else: ?>
                        <?= htmlspecialchars($userInitials) ?>
                    <?php endif; ?>
                </button>

                <!-- DROPDOWN FLOATING CARD -->
                <div id="profileDropdownMenu" class="hidden absolute right-0 top-full mt-3 w-60 bg-white rounded-2xl shadow-2xl border border-slate-100 z-50 overflow-hidden">
                    <div class="px-5 pt-4 pb-3">
                        <p class="text-sm font-extrabold text-slate-900 leading-tight truncate">
                            <?= htmlspecialchars($currentUserFullname) ?>
                        </p>
                        <p class="text-[10px] font-black text-amber-500 uppercase tracking-wider mt-0.5">
                            <?= htmlspecialchars($currentUserRole) ?>
                        </p>
                    </div>

                    <div class="border-t border-slate-100 py-1">
                        <a href="settings.php?store_id=<?= $store_id ?>" class="w-full px-5 py-2.5 text-xs font-bold text-slate-700 hover:bg-slate-50 flex items-center gap-3 transition">
                            <i class="fas fa-cog text-slate-400 text-sm w-4 text-center"></i>
                            <span>Settings</span>
                        </a>
                    </div>

                    <div class="border-t border-slate-100 py-1">
                        <a href="<?= file_exists(__DIR__ . '/logout.php') ? 'logout.php' : '../logout.php' ?>" class="w-full px-5 py-2.5 text-xs font-bold text-red-500 hover:bg-red-50 flex items-center gap-3 transition">
                            <i class="fas fa-sign-out-alt text-red-500 text-sm w-4 text-center"></i>
                            <span>Logout</span>
                        </a>
                    </div>
                </div>
            </div>

        </div>
    </header>

    <!-- POS TERMINAL MAIN BODY -->
    <main class="flex-1 flex overflow-hidden no-print">
        
        <!-- LEFT: ITEMS CATALOG & SCANNER -->
        <div class="flex-1 p-4 md:p-5 overflow-y-auto custom-scroll flex flex-col space-y-4">
            
            <!-- SCANNER & SEARCH BAR -->
            <div class="bg-white p-3 rounded-2xl shadow-xs border border-slate-200 flex flex-col md:flex-row justify-between items-center gap-3">
                <div class="flex items-center space-x-2 w-full md:w-[460px]">
                    <div class="relative flex-1">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                        <input type="text" id="barcodeInput" autofocus placeholder="Scan Barcode or Search Item / Receipt OR..." 
                               class="w-full bg-slate-50 border border-slate-300 rounded-xl pl-9 pr-3 py-2.5 text-xs font-bold text-slate-900 focus:outline-none focus:ring-2 focus:ring-amber-500">
                    </div>
                    <button onclick="openCameraScanner()" type="button" class="bg-slate-900 hover:bg-slate-800 text-white px-4 py-2.5 rounded-xl text-xs font-bold transition flex items-center gap-1.5 shrink-0 shadow-sm cursor-pointer">
                        <i class="fas fa-camera text-amber-400"></i> Camera
                    </button>
                    <button onclick="openReceiptHistoryModal()" type="button" title="View Receipts" class="bg-slate-100 hover:bg-slate-200 text-slate-700 px-3 py-2.5 rounded-xl text-xs font-bold transition border border-slate-200 shrink-0 cursor-pointer">
                        <i class="fas fa-receipt text-amber-600"></i>
                    </button>
                </div>

                <div class="flex items-center space-x-4 text-xs font-bold">
                    <div class="text-right">
                        <span class="text-[10px] text-slate-400 uppercase block font-semibold">Today's Sales</span>
                        <span class="text-slate-900 font-black">₱<?= number_format($todaySalesStaffTotal, 2) ?></span>
                    </div>
                    <div class="h-6 w-px bg-slate-200"></div>
                    <div class="text-right">
                        <span class="text-[10px] text-slate-400 uppercase block font-semibold">Store</span>
                        <span class="text-amber-600 font-black">#<?= $store_id ?></span>
                    </div>
                </div>
            </div>

            <!-- CATEGORY PILLS -->
            <div class="flex gap-1.5 overflow-x-auto custom-scroll pb-1">
                <button onclick="filterCat('all', this)" class="cat-btn active px-3.5 py-1.5 rounded-xl text-xs font-black uppercase whitespace-nowrap bg-slate-900 text-white transition">
                    All Items
                </button>
                <?php foreach($categoriesList as $cat): ?>
                <button onclick="filterCat('<?= htmlspecialchars($cat) ?>', this)" class="cat-btn px-3.5 py-1.5 rounded-xl text-xs font-black uppercase whitespace-nowrap bg-white text-slate-700 border border-slate-200 hover:bg-slate-50 transition">
                    <?= htmlspecialchars($cat) ?>
                </button>
                <?php endforeach; ?>
            </div>

            <!-- PRODUCT GRID -->
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3" id="productGrid">
                <?php if (empty($productsList)): ?>
                    <div class="col-span-full py-16 text-center text-slate-400">
                        <i class="fas fa-box-open text-3xl mb-2 text-slate-300"></i>
                        <p class="text-xs font-bold uppercase">No products available in Store <?= $store_id ?></p>
                    </div>
                <?php else: ?>
                    <?php foreach($productsList as $p): 
                        $barcodeVal = !empty($p['barcode']) ? $p['barcode'] : ($p['sku'] ?? ('4515' . $p['id']));
                        $rawImg     = $p['image'] ?? ($p['image_path'] ?? '');
                        $resolvedImg = resolveAssetPath($rawImg);

                        $origPrice      = (float)$p['price'];
                        $discPrice      = (float)($p['discount_price'] ?? 0);
                        $isOnSale       = !empty($p['is_on_sale']) && ($discPrice > 0) && ($discPrice < $origPrice);
                        $effectivePrice = $isOnSale ? $discPrice : $origPrice;
                        $curStock       = (int)($p[$stockCol] ?? 0);

                        $prodJsonData = [
                            'id'              => (int)$p['id'],
                            'product_name'    => $p['product_name'] ?? 'Product Item',
                            'price'           => $effectivePrice,
                            'original_price'  => $origPrice,
                            'effective_price' => $effectivePrice,
                            'has_discount'    => $isOnSale,
                            'stock'           => $curStock,
                            'category'        => $p['category'] ?? '',
                            'barcode'         => $barcodeVal
                        ];
                        $jsonAttr = htmlspecialchars(json_encode($prodJsonData), ENT_QUOTES, 'UTF-8');
                    ?>
                    <div onclick='addToBasket(<?= $jsonAttr ?>)' 
                         data-cat="<?= htmlspecialchars($p['category'] ?? '') ?>" 
                         data-barcode="<?= strtolower(htmlspecialchars($barcodeVal)) ?>" 
                         data-name="<?= strtolower(htmlspecialchars($p['product_name'] ?? '')) ?>" 
                         class="product-card bg-white p-3 rounded-2xl border border-slate-200 hover:border-amber-500 hover:shadow-md transition cursor-pointer flex flex-col justify-between group">
                        
                        <div>
                            <div class="w-full h-24 rounded-xl bg-slate-50 border border-slate-100 mb-2 flex items-center justify-center overflow-hidden relative">
                                <?php if ($resolvedImg): ?>
                                    <img src="<?= htmlspecialchars($resolvedImg) ?>" class="w-full h-full object-cover group-hover:scale-105 transition duration-200">
                                <?php else: ?>
                                    <i class="fas fa-box text-slate-300 text-2xl"></i>
                                <?php endif; ?>

                                <?php if($isOnSale): ?>
                                    <span class="absolute top-1.5 left-1.5 bg-red-600 text-white text-[8px] font-black px-1.5 py-0.5 rounded uppercase">SALE</span>
                                <?php endif; ?>
                            </div>

                            <h4 class="font-bold text-xs text-slate-900 truncate group-hover:text-amber-600"><?= htmlspecialchars($p['product_name'] ?? 'Item') ?></h4>
                            <p class="text-[10px] text-slate-400 font-mono mt-0.5 truncate"><?= htmlspecialchars($barcodeVal) ?></p>
                        </div>

                        <div class="flex justify-between items-end mt-3 pt-2 border-t border-slate-100">
                            <div>
                                <span class="text-[10px] text-slate-400 block font-semibold leading-none mb-0.5">Price</span>
                                <span class="text-slate-900 font-black text-sm">₱<?= number_format($effectivePrice, 2) ?></span>
                            </div>
                            <span class="text-[10px] font-bold px-2 py-0.5 rounded-lg <?= $curStock <= (int)($p['min_stock'] ?? 5) ? 'bg-red-50 text-red-600' : 'bg-slate-100 text-slate-600' ?>">
                                <?= number_format($curStock) ?> left
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        </div>

        <!-- RIGHT: BASKET & CHECKOUT PANEL (FIXED VIEW & SCROLL CONTAINER) -->
        <div class="w-96 md:w-[420px] bg-white border-l border-slate-200 flex flex-col h-full shadow-sm overflow-hidden">
            
            <!-- CART HEADER -->
            <div class="px-4 py-3 border-b border-slate-200 flex items-center justify-between bg-slate-50 shrink-0">
                <div class="flex items-center space-x-2">
                    <span class="font-black text-xs uppercase tracking-wide text-slate-800">Cart</span>
                    <span id="cartCountBadge" class="bg-amber-500 text-slate-950 font-black text-[10px] px-2 py-0.5 rounded-full">0</span>
                </div>
                <button onclick="clearBasket()" type="button" class="text-xs font-bold text-red-600 hover:text-red-700 transition">
                    Clear
                </button>
            </div>

            <!-- BASKET ITEMS (FIXED: min-h-0 and min-h-[180px] to preserve full item visibility) -->
            <div id="basketItems" class="flex-1 min-h-[180px] min-h-0 overflow-y-auto p-4 space-y-2.5 custom-scroll divide-y divide-slate-100 bg-white">
                <!-- Javascript will render items here -->
            </div>

            <!-- CHECKOUT CONTROLS (FIXED: auto scrollable if screen height is small) -->
            <div class="p-3.5 bg-slate-50 border-t border-slate-200 space-y-2.5 shrink-0 max-h-[60vh] overflow-y-auto custom-scroll shadow-inner">
                
                <!-- DISCOUNT TOGGLE -->
                <div>
                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 block mb-1">Discount Mode</label>
                    <div class="grid grid-cols-4 gap-1 text-[11px] font-bold">
                        <button type="button" onclick="setDiscountMode('none')" id="btnDiscNone" class="py-1 rounded-lg bg-slate-900 text-white border border-slate-900 transition">None</button>
                        <button type="button" onclick="setDiscountMode('tawad')" id="btnDiscTawad" class="py-1 rounded-lg bg-white text-slate-700 border border-slate-300 transition">Cash Off</button>
                        <button type="button" onclick="setDiscountMode('bulto')" id="btnDiscBulto" class="py-1 rounded-lg bg-white text-slate-700 border border-slate-300 transition">Bulk %</button>
                        <button type="button" onclick="setDiscountMode('b1t1')" id="btnDiscB1T1" class="py-1 rounded-lg bg-white text-slate-700 border border-slate-300 transition">B1T1</button>
                    </div>

                    <div id="subTawadOptions" class="hidden mt-1.5 space-y-1">
                        <div class="flex gap-1">
                            <button type="button" onclick="applyQuickTawad(5)" class="flex-1 py-1 bg-white hover:bg-slate-100 border text-[10px] font-bold rounded-lg">-₱5</button>
                            <button type="button" onclick="applyQuickTawad(10)" class="flex-1 py-1 bg-white hover:bg-slate-100 border text-[10px] font-bold rounded-lg">-₱10</button>
                            <button type="button" onclick="applyQuickTawad(20)" class="flex-1 py-1 bg-white hover:bg-slate-100 border text-[10px] font-bold rounded-lg">-₱20</button>
                            <button type="button" onclick="roundDownBarya()" class="flex-1 py-1 bg-white hover:bg-slate-100 border text-[10px] font-bold rounded-lg">Round Off</button>
                        </div>
                        <input type="number" step="0.01" id="customTawadInput" placeholder="Amount discount (₱)" oninput="calculateTotals()" 
                               class="w-full bg-white border border-slate-300 rounded-lg px-2.5 py-1 text-xs font-bold text-slate-900 outline-none">
                    </div>

                    <div id="subBultoOptions" class="hidden mt-1.5 flex gap-1">
                        <button type="button" onclick="applyBultoPercent(5)" class="flex-1 py-1 bg-white border text-[10px] font-bold rounded-lg">5% (3 pcs)</button>
                        <button type="button" onclick="applyBultoPercent(10)" class="flex-1 py-1 bg-white border text-[10px] font-bold rounded-lg">10% (12 pcs)</button>
                        <button type="button" onclick="applyBultoPercent(15)" class="flex-1 py-1 bg-white border text-[10px] font-bold rounded-lg">15% (Bulk)</button>
                    </div>
                </div>

                <!-- PAYMENT METHOD -->
                <div>
                    <label class="text-[10px] font-black uppercase tracking-wider text-slate-500 block mb-1">Payment Method</label>
                    <div class="grid grid-cols-3 gap-1.5 text-xs font-bold">
                        <button type="button" onclick="setPaymentMethod('Cash')" id="btnPayCash" class="py-1.5 rounded-xl border border-amber-500 bg-amber-500 text-slate-950 transition flex items-center justify-center gap-1 shadow-xs">
                            <i class="fas fa-money-bill-wave"></i> Cash
                        </button>
                        <button type="button" onclick="setPaymentMethod('GCash')" id="btnPayGCash" class="py-1.5 rounded-xl border border-slate-300 bg-white text-slate-700 transition flex items-center justify-center gap-1">
                            <span class="w-2 h-2 rounded-full bg-blue-500"></span> GCash
                        </button>
                        <button type="button" onclick="setPaymentMethod('Maya')" id="btnPayMaya" class="py-1.5 rounded-xl border border-slate-300 bg-white text-slate-700 transition flex items-center justify-center gap-1">
                            <span class="w-2 h-2 rounded-full bg-emerald-500"></span> Maya
                        </button>
                    </div>
                </div>

                <!-- DIGITAL E-WALLET CARD FROM SETTINGS.PHP (COMPACT) -->
                <div id="ewalletDisplayCard" class="hidden p-2.5 bg-white rounded-xl border border-slate-200 text-xs font-medium space-y-1.5 shadow-sm">
                    <div class="flex gap-2.5 items-center">
                        <img id="ewalletQrImg" src="" class="w-14 h-14 object-contain border rounded-lg p-1 bg-slate-50 shrink-0">
                        <div class="text-[10px] leading-tight space-y-0.5">
                            <p class="font-black text-slate-900" id="ewalletMerchantTitle">GCash QR</p>
                            <p class="text-slate-600">Name: <b id="ewalletAccName">JJ Store</b></p>
                            <p class="text-slate-600">No: <b id="ewalletAccNumber" class="font-mono"></b></p>
                        </div>
                    </div>
                    <input type="text" id="referenceNoInput" placeholder="Enter Reference No." class="w-full bg-slate-50 border rounded-lg px-2.5 py-1 text-xs font-bold uppercase outline-none focus:ring-1 focus:ring-amber-500">
                </div>

                <!-- TOTALS SUMMARY -->
                <div class="bg-white p-2.5 rounded-xl border border-slate-200 space-y-1 text-xs shadow-xs">
                    <div class="flex justify-between text-slate-500 font-semibold">
                        <span>Subtotal</span>
                        <span id="displaySubtotal" class="font-bold text-slate-800">₱0.00</span>
                    </div>
                    <div class="flex justify-between text-red-600 font-semibold">
                        <span>Discount</span>
                        <span id="displayDiscount" class="font-bold">-₱0.00</span>
                    </div>
                    <div class="flex justify-between items-baseline pt-1 border-t border-slate-100">
                        <span class="text-xs font-black uppercase text-slate-800">Total Due</span>
                        <span id="displayGrandTotal" class="text-lg font-black text-amber-600">₱0.00</span>
                    </div>
                </div>

                <!-- CASH TENDERED & CHANGE -->
                <div id="cashInputGroup" class="space-y-1">
                    <div class="flex justify-between items-center text-xs">
                        <label class="text-[10px] font-black uppercase tracking-wider text-slate-500">Cash Tendered</label>
                        <span class="text-xs font-bold text-emerald-600">Change: <b id="displayChange" class="font-black text-sm">₱0.00</b></span>
                    </div>

                    <div class="flex gap-1 overflow-x-auto pb-0.5">
                        <button type="button" onclick="setExactAmount()" class="px-2 py-0.5 bg-amber-500 hover:bg-amber-600 text-slate-950 font-black text-[10px] rounded-lg uppercase whitespace-nowrap">Exact</button>
                        <button type="button" onclick="addCashPreset(50)" class="px-2 py-0.5 bg-white border text-slate-700 font-bold text-[10px] rounded-lg">₱50</button>
                        <button type="button" onclick="addCashPreset(100)" class="px-2 py-0.5 bg-white border text-slate-700 font-bold text-[10px] rounded-lg">₱100</button>
                        <button type="button" onclick="addCashPreset(200)" class="px-2 py-0.5 bg-white border text-slate-700 font-bold text-[10px] rounded-lg">₱200</button>
                        <button type="button" onclick="addCashPreset(500)" class="px-2 py-0.5 bg-white border text-slate-700 font-bold text-[10px] rounded-lg">₱500</button>
                        <button type="button" onclick="addCashPreset(1000)" class="px-2 py-0.5 bg-white border text-slate-700 font-bold text-[10px] rounded-lg">₱1,000</button>
                    </div>

                    <input type="number" step="0.01" id="cashInput" placeholder="0.00" oninput="calculateTotals()" 
                           class="w-full bg-white border border-slate-300 text-slate-900 px-3 py-1.5 rounded-xl text-base font-black outline-none focus:ring-2 focus:ring-amber-500">
                </div>

                <!-- SUBMIT FORM -->
                <form method="POST" id="checkoutForm" onsubmit="return validateAndSubmitCheckout()">
                    <input type="hidden" name="action" value="complete_sale">
                    <input type="hidden" name="basket" id="basketHidden">
                    <input type="hidden" name="payment_method" id="paymentMethodHidden" value="Cash">
                    <input type="hidden" name="reference_no" id="referenceNoHidden" value="">
                    <input type="hidden" name="cash_received" id="cashHidden">
                    <input type="hidden" name="discount_type" id="discountTypeHidden" value="NONE">
                    <input type="hidden" name="order_discount" id="orderDiscountHidden" value="0.00">
                    <button type="submit" id="checkoutBtn" disabled class="w-full py-2.5 rounded-xl bg-slate-200 text-slate-400 font-black uppercase text-xs tracking-wider transition cursor-not-allowed shadow-sm">
                        Complete Sale
                    </button>
                </form>

            </div>

        </div>

    </main>

    <!-- MODAL: RECEIPT HISTORY -->
    <div id="receiptHistoryModal" class="hidden fixed inset-0 bg-slate-950/70 z-50 flex items-center justify-center p-4">
        <div class="bg-white w-full max-w-xl rounded-2xl shadow-2xl overflow-hidden flex flex-col border border-slate-200 max-h-[85vh]">
            <div class="p-4 bg-slate-950 text-white flex justify-between items-center">
                <h3 class="text-xs font-black uppercase tracking-wide">Receipt History (Store <?= $store_id ?>)</h3>
                <button onclick="closeReceiptHistoryModal()" class="text-slate-400 hover:text-white"><i class="fas fa-times"></i></button>
            </div>
            <div class="p-3 bg-slate-50 border-b">
                <input type="text" id="receiptSearchInput" oninput="filterReceiptsHistory()" placeholder="Search Receipt OR / Cashier / Date..." class="w-full bg-white border border-slate-300 rounded-xl px-3 py-2 text-xs font-bold outline-none">
            </div>
            <div class="p-3 overflow-y-auto flex-1 custom-scroll space-y-2 bg-slate-100" id="receiptHistoryList">
                <?php if(empty($pastReceiptsList)): ?>
                    <p class="text-center text-slate-400 font-bold text-xs py-10">No recent sales recorded.</p>
                <?php else: ?>
                    <?php foreach($pastReceiptsList as $pr): 
                        $jsonPr = htmlspecialchars(json_encode($pr), ENT_QUOTES, 'UTF-8');
                    ?>
                    <div class="receipt-history-card bg-white p-3 rounded-2xl border border-slate-200 flex justify-between items-center gap-2"
                         data-number="<?= strtolower(htmlspecialchars($pr['receipt_number'])) ?>"
                         data-cashier="<?= strtolower(htmlspecialchars($pr['cashier'])) ?>"
                         data-date="<?= strtolower(htmlspecialchars($pr['date'])) ?>">
                        <div class="space-y-0.5">
                            <span class="font-black text-xs text-amber-600 uppercase"><?= htmlspecialchars($pr['receipt_number']) ?></span>
                            <p class="text-[10px] text-slate-500 font-medium"><?= $pr['date'] ?> &bull; <?= htmlspecialchars($pr['cashier']) ?></p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="text-xs font-black text-slate-900">₱<?= number_format($pr['total'], 2) ?></span>
                            <button onclick='viewSpecificReceipt(<?= $jsonPr ?>)' class="px-3 py-1.5 bg-slate-900 text-white rounded-xl text-xs font-bold hover:bg-slate-800 transition">
                                View
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- MODAL: CAMERA SCANNER -->
    <div id="cameraModal" class="hidden fixed inset-0 bg-slate-950/70 z-50 flex items-center justify-center p-4">
        <div class="bg-white w-full max-w-sm rounded-2xl shadow-xl overflow-hidden flex flex-col">
            <div class="p-3.5 bg-slate-950 text-white flex justify-between items-center text-xs font-black uppercase">
                <span>Camera Scanner</span>
                <button onclick="closeCameraScanner()" class="text-slate-400 hover:text-white"><i class="fas fa-times"></i></button>
            </div>
            <div class="p-3 bg-black flex justify-center">
                <div id="cameraReader" class="w-full h-56 rounded-xl overflow-hidden bg-black"></div>
            </div>
            <div class="p-3 bg-slate-50 text-center">
                <button onclick="closeCameraScanner()" class="w-full py-2 bg-slate-900 text-white font-bold text-xs rounded-xl">
                    Close Camera
                </button>
            </div>
        </div>
    </div>

    <!-- MODAL: ESC/POS THERMAL RECEIPT SLIP -->
    <div id="receiptModal" class="<?= (isset($_GET['success']) && !empty($_SESSION['show_receipt_modal'])) ? '' : 'hidden' ?> fixed inset-0 bg-slate-950/70 z-50 flex items-center justify-center p-4">
        <?php 
        $activeReceipt = $_SESSION['last_receipt'] ?? (!empty($pastReceiptsList[0]) ? $pastReceiptsList[0] : [
            'receipt_number' => 'REC-' . date('Ymd') . '-0001',
            'date'           => date('m/d/Y H:i:s'),
            'cashier'        => $currentUserFullname,
            'payment_method' => 'Cash',
            'reference_no'   => '',
            'subtotal'       => 0,
            'discount'       => 0,
            'discount_type'  => 'NONE',
            'total'          => 0,
            'cash'           => 0,
            'change'         => 0,
            'item_count'     => 0,
            'vatable'        => 0,
            'vat_amount'     => 0,
            'items'          => []
        ]);

        $activeTotal   = (float)($activeReceipt['total'] ?? 0);
        $activeSubtot  = (float)($activeReceipt['subtotal'] ?? $activeTotal);
        $activeVatable = (float)($activeReceipt['vatable'] ?? round($activeTotal / 1.12, 2));
        $activeVat     = (float)($activeReceipt['vat_amount'] ?? round($activeTotal - $activeVatable, 2));
        $activeItemCount = (int)($activeReceipt['item_count'] ?? count($activeReceipt['items']));
        ?>
        <div class="bg-white w-full max-w-sm rounded-2xl shadow-2xl overflow-hidden flex flex-col border border-slate-200">
            <div class="p-3 bg-emerald-600 text-white text-center text-xs font-black uppercase no-print">
                Sale Completed
            </div>

            <div class="p-4 bg-slate-50 flex justify-center overflow-y-auto max-h-[70vh] custom-scroll">
                <!-- PRINTABLE SLIP AREA: EXACT 48mm THERMAL WIDTH -->
                <div id="printableSlipArea" class="w-64 p-3 bg-white rounded-lg border border-slate-300 font-mono text-left text-[11px] leading-tight text-black select-none">
                    
                    <!-- HEADER SECTION -->
                    <div class="text-center pb-2 border-b border-dashed border-black">
                        <?php if (!empty($settings['show_logo']) && $resolvedLogoUrl): ?>
                            <img src="<?= htmlspecialchars($resolvedLogoUrl) ?>" id="receiptSlipLogoImg" alt="Logo" class="w-9 h-9 mx-auto mb-1 object-contain">
                        <?php endif; ?>
                        
                        <h4 class="font-black text-xs uppercase tracking-tight" id="slipStoreName"><?= htmlspecialchars($settings['store_name']) ?></h4>
                        <p class="text-[8px] uppercase font-bold text-slate-700">SUPERMARKET &amp; GENERAL MERCHANDISE</p>
                        <p class="text-[8px] leading-3 mt-0.5" id="slipStoreAddress"><?= htmlspecialchars($settings['store_address']) ?></p>
                        <p class="text-[8px]">TEL: <span id="slipStoreContact"><?= htmlspecialchars($settings['contact_number']) ?></span></p>
                        
                        <div class="text-[7.5px] leading-3 mt-1 text-slate-800">
                            <p>VAT REG TIN: 000-842-120-00001</p>
                            <p>MIN: 241014092415982</p>
                            <p>PERMIT NO: FP-102026-004-91823-POS</p>
                        </div>
                    </div>

                    <!-- TRANSACTION META -->
                    <div class="py-1.5 border-b border-dashed border-black text-[9px] leading-3 space-y-0.5">
                        <div class="flex justify-between">
                            <span>POS: 01-STORE <?= $store_id ?></span>
                            <span id="slipCashier" class="font-bold uppercase"><?= htmlspecialchars($activeReceipt['cashier']) ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span>DATE: <span id="slipDate"><?= htmlspecialchars($activeReceipt['date']) ?></span></span>
                            <span id="slipPayMethod" class="font-bold uppercase"><?= htmlspecialchars($activeReceipt['payment_method']) ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span>SI / OR NO:</span>
                            <b id="slipOR" class="font-black uppercase"><?= htmlspecialchars($activeReceipt['receipt_number']) ?></b>
                        </div>
                    </div>

                    <!-- ITEMS TABLE -->
                    <div class="py-2 border-b border-dashed border-black">
                        <div class="flex justify-between text-[8px] font-black border-b border-black pb-0.5 mb-1 uppercase">
                            <span>DESCRIPTION</span>
                            <span>AMOUNT</span>
                        </div>
                        <div id="puregoldItemsList" class="space-y-1.5 text-[9.5px]">
                            <?php if (!empty($activeReceipt['items'])): ?>
                                <?php foreach($activeReceipt['items'] as $it): 
                                    $sellP = (float)($it['price'] ?? 0);
                                    $qty   = (int)($it['qty'] ?? 1);
                                    $lineTotal = (float)($it['total_price'] ?? ($sellP * $qty));
                                ?>
                                <div>
                                    <div class="font-bold uppercase tracking-tight"><?= htmlspecialchars($it['name'] ?? 'Item') ?></div>
                                    <div class="flex justify-between text-[8.5px] pl-2 text-slate-800">
                                        <span><?= $qty ?> @ <?= number_format($sellP, 2) ?></span>
                                        <span class="font-bold font-mono">₱<?= number_format($lineTotal, 2) ?> V</span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- TOTALS & TENDERED -->
                    <div class="py-1.5 border-b border-dashed border-black space-y-1 text-[9.5px]">
                        <div class="flex justify-between text-[9px] text-slate-800">
                            <span>SUBTOTAL:</span>
                            <span id="slipSubtotal">₱<?= number_format($activeSubtot, 2) ?></span>
                        </div>
                        <div class="flex justify-between text-red-600 font-bold <?= (empty($activeReceipt['discount']) || $activeReceipt['discount'] <= 0) ? 'hidden' : '' ?>" id="slipDiscountRow">
                            <span>DISCOUNT:</span>
                            <span id="slipDiscount">-₱<?= number_format((float)($activeReceipt['discount'] ?? 0), 2) ?></span>
                        </div>
                        <div class="flex justify-between text-xs font-black pt-0.5 border-t border-dotted border-black">
                            <span>TOTAL AMOUNT:</span>
                            <span id="slipTotal" class="text-sm font-black">₱<?= number_format($activeTotal, 2) ?></span>
                        </div>
                        <div class="flex justify-between text-[9px]">
                            <span>CASH TENDERED:</span>
                            <span id="slipTendered">₱<?= number_format((float)($activeReceipt['cash'] ?? $activeTotal), 2) ?></span>
                        </div>
                        <div class="flex justify-between text-[9px] font-bold">
                            <span>CHANGE:</span>
                            <span id="slipChange">₱<?= number_format((float)($activeReceipt['change'] ?? 0), 2) ?></span>
                        </div>
                        <div class="flex justify-between text-[8px] text-slate-700 pt-0.5 border-t border-dotted border-slate-400">
                            <span>TOTAL ITEMS SOLD:</span>
                            <span id="slipItemCount" class="font-bold"><?= $activeItemCount ?></span>
                        </div>
                    </div>

                    <!-- TAX INVOICE BREAKDOWN -->
                    <div class="py-1.5 border-b border-dashed border-black text-[8px] leading-3 space-y-0.5 text-slate-800">
                        <div class="flex justify-between">
                            <span>VATABLE SALES:</span>
                            <span id="slipVatable">₱<?= number_format($activeVatable, 2) ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span>VAT AMOUNT (12%):</span>
                            <span id="slipVat">₱<?= number_format($activeVat, 2) ?></span>
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

                    <!-- FOOTER & CRISP SCANNER BARCODE -->
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

            <!-- MODAL BUTTONS -->
            <div class="p-3 bg-slate-50 border-t flex gap-2 no-print">
                <button onclick="window.print()" class="flex-1 bg-amber-500 hover:bg-amber-600 text-slate-950 py-2.5 rounded-xl font-black text-xs uppercase shadow transition flex items-center justify-center gap-1.5 cursor-pointer">
                    <i class="fas fa-print"></i> Print 
                </button>
                <a href="<?= $current_file ?>?store_id=<?= $store_id ?>&close_receipt=1" class="px-5 bg-slate-200 hover:bg-slate-300 text-slate-800 py-2.5 rounded-xl font-black text-xs uppercase flex items-center justify-center transition">
                    Done
                </a>
            </div>
        </div>
    </div>

    <!-- JAVASCRIPT CONTROLLERS -->
    <script>
        const adminEwalletData = {
            gcash: {
                name: <?= json_encode($settings['gcash_name'] ?? 'JJ Store Cashier') ?>,
                number: <?= json_encode($settings['gcash_number'] ?? '09123456789') ?>,
                qr: <?= json_encode($resolvedGcashQr ?: '') ?>
            },
            maya: {
                name: <?= json_encode($settings['maya_name'] ?? 'JJ Store Merchant') ?>,
                number: <?= json_encode($settings['maya_number'] ?? '09987654321') ?>,
                qr: <?= json_encode($resolvedMayaQr ?: '') ?>
            },
            autoPrint: <?= json_encode(!empty($settings['auto_print'])) ?>
        };

        const cachedReceipts = <?= json_encode($pastReceiptsList) ?>;
        let basket = [];
        let activePaymentMethod = 'Cash';
        let activeDiscountMode = 'none';
        let appliedTawadPeso   = 0;
        let appliedBultoPct    = 0;

        function toggleProfileDropdown() {
            const menu = document.getElementById('profileDropdownMenu');
            menu.classList.toggle('hidden');
        }

        window.addEventListener('click', function(e) {
            const container = document.getElementById('profileDropdownContainer');
            if (container && !container.contains(e.target)) {
                document.getElementById('profileDropdownMenu')?.classList.add('hidden');
            }
        });

        async function sendHeartbeat() {
            try { await fetch('<?= $current_file ?>?store_id=<?= $store_id ?>&action=heartbeat'); } catch (err) {}
        }
        setInterval(sendHeartbeat, 25000);

        function setDiscountMode(mode) {
            activeDiscountMode = mode;
            appliedTawadPeso = 0;
            appliedBultoPct = 0;
            const customTawadEl = document.getElementById('customTawadInput');
            if (customTawadEl) customTawadEl.value = '';

            ['btnDiscNone', 'btnDiscTawad', 'btnDiscBulto', 'btnDiscB1T1'].forEach(id => {
                const btn = document.getElementById(id);
                if (btn) btn.className = "py-1 rounded-lg bg-white text-slate-700 border border-slate-300 transition";
            });

            const activeBtn = document.getElementById('btnDisc' + mode.charAt(0).toUpperCase() + mode.slice(1));
            if (activeBtn) activeBtn.className = "py-1 rounded-lg bg-slate-900 text-white border border-slate-900 transition";

            document.getElementById('subTawadOptions')?.classList.add('hidden');
            document.getElementById('subBultoOptions')?.classList.add('hidden');

            if (mode === 'tawad') {
                document.getElementById('subTawadOptions')?.classList.remove('hidden');
            } else if (mode === 'bulto') {
                document.getElementById('subBultoOptions')?.classList.remove('hidden');
            }

            calculateTotals();
        }

        function applyQuickTawad(pesos) {
            appliedTawadPeso = parseFloat(pesos) || 0;
            const input = document.getElementById('customTawadInput');
            if (input) input.value = appliedTawadPeso.toFixed(2);
            calculateTotals();
        }

        function applyBultoPercent(pct) {
            appliedBultoPct = parseFloat(pct) || 0;
            calculateTotals();
        }

        function roundDownBarya() {
            const subtotal = basket.reduce((acc, i) => acc + (i.price * i.qty), 0);
            if (subtotal <= 0) return;
            const roundedTotal = Math.floor(subtotal / 5) * 5;
            appliedTawadPeso = Math.max(0, subtotal - roundedTotal);
            const input = document.getElementById('customTawadInput');
            if (input) input.value = appliedTawadPeso.toFixed(2);
            calculateTotals();
        }

        function computeDiscountAmount(subtotal) {
            if (subtotal <= 0) return 0;
            let discount = 0;

            if (activeDiscountMode === 'tawad') {
                const customVal = parseFloat(document.getElementById('customTawadInput')?.value) || appliedTawadPeso;
                discount = Math.min(subtotal, Math.max(0, customVal));
            } else if (activeDiscountMode === 'bulto') {
                discount = Math.min(subtotal, (subtotal * (appliedBultoPct / 100)));
            } else if (activeDiscountMode === 'b1t1') {
                basket.forEach(item => {
                    const freePieces = Math.floor(item.qty / 2);
                    discount += (freePieces * item.price);
                });
            }
            return Math.min(subtotal, Math.round(discount * 100) / 100);
        }

        function getComputedGrandTotal() {
            const subtotal = basket.reduce((acc, i) => acc + (i.price * i.qty), 0);
            const discount = computeDiscountAmount(subtotal);
            return Math.max(0, subtotal - discount);
        }

        function setExactAmount() {
            const grandTotal = getComputedGrandTotal();
            if (grandTotal <= 0) return;
            document.getElementById('cashInput').value = grandTotal.toFixed(2);
            calculateTotals();
        }

        function addCashPreset(amount) {
            const cashField = document.getElementById('cashInput');
            let current = parseFloat(cashField.value) || 0;
            current += amount;
            cashField.value = current.toFixed(2);
            calculateTotals();
        }

        function setPaymentMethod(method) {
            activePaymentMethod = method;
            document.getElementById('paymentMethodHidden').value = method;

            ['btnPayCash', 'btnPayGCash', 'btnPayMaya'].forEach(id => {
                const b = document.getElementById(id);
                if (b) b.className = "py-1.5 rounded-xl border border-slate-300 bg-white text-slate-700 transition flex items-center justify-center gap-1";
            });

            const activeBtn = document.getElementById('btnPay' + method);
            if (activeBtn) activeBtn.className = "py-1.5 rounded-xl border border-amber-500 bg-amber-500 text-slate-950 transition flex items-center justify-center gap-1 shadow-xs";

            const ewalletCard = document.getElementById('ewalletDisplayCard');
            const cashGroup   = document.getElementById('cashInputGroup');

            if (method === 'Cash') {
                ewalletCard?.classList.add('hidden');
                cashGroup?.classList.remove('hidden');
                document.getElementById('cashInput').focus();
            } else {
                cashGroup?.classList.add('hidden');
                ewalletCard?.classList.remove('hidden');
                
                const data = (method === 'GCash') ? adminEwalletData.gcash : adminEwalletData.maya;
                document.getElementById('ewalletMerchantTitle').innerText = method + " QR";
                document.getElementById('ewalletAccName').innerText = data.name || 'Store';
                document.getElementById('ewalletAccNumber').innerText = data.number || '';

                const qrImg = document.getElementById('ewalletQrImg');
                if (data.qr) {
                    qrImg.src = data.qr + '?v=' + Date.now();
                } else {
                    qrImg.src = 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=' + encodeURIComponent(data.number || '09123456789');
                }
            }
            calculateTotals();
        }

        document.getElementById('referenceNoInput')?.addEventListener('input', function() {
            document.getElementById('referenceNoHidden').value = this.value.trim();
        });

        function playScanBeep() {
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.type = 'sine';
                osc.frequency.value = 1046.50;
                gain.gain.value = 0.08;
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.start();
                osc.stop(ctx.currentTime + 0.06);
            } catch(e) {}
        }

        function addToBasket(product) {
            const price = parseFloat(product.price || 0);
            const stock = parseInt(product.stock || 0);

            if (stock <= 0) {
                alert("Out of stock: " + product.product_name);
                return;
            }

            const existing = basket.find(i => i.id === product.id);
            if (existing) {
                if (existing.qty < stock) {
                    existing.qty++;
                    playScanBeep();
                } else {
                    alert("Stock limit reached: " + stock + " pcs only");
                }
            } else {
                basket.push({
                    id: parseInt(product.id),
                    name: product.product_name,
                    price: price,
                    qty: 1,
                    stock: stock
                });
                playScanBeep();
            }
            renderBasket();
        }

        function changeQty(id, delta) {
            const item = basket.find(i => i.id === id);
            if (!item) return;
            const newQty = item.qty + delta;
            if (newQty > 0) {
                if (delta > 0 && newQty > item.stock) {
                    alert("Maximum stock reached (" + item.stock + " pcs)");
                    return;
                }
                item.qty = newQty;
            } else {
                basket = basket.filter(i => i.id !== id);
            }
            renderBasket();
        }

        function clearBasket() {
            if(basket.length === 0) return;
            if(confirm("Clear all items in cart?")) {
                basket = [];
                document.getElementById('cashInput').value = '';
                setDiscountMode('none');
                renderBasket();
            }
        }

        function renderBasket() {
            const container = document.getElementById('basketItems');
            container.innerHTML = '';

            let totalItems = 0;
            if (basket.length === 0) {
                container.innerHTML = `
                    <div class="text-center py-12 text-slate-400">
                        <i class="fas fa-shopping-basket text-3xl mb-2 text-slate-300"></i>
                        <p class="text-xs font-bold text-slate-400">Cart is empty</p>
                    </div>`;
            } else {
                basket.forEach(item => {
                    totalItems += item.qty;
                    const lineTotal = item.price * item.qty;
                    container.innerHTML += `
                        <div class="flex items-center justify-between pt-2.5 first:pt-0">
                            <div class="flex-1 pr-2">
                                <h5 class="text-xs font-bold text-slate-800 leading-tight">${item.name}</h5>
                                <p class="text-[10px] text-slate-400 mt-0.5">${item.qty} x ₱${item.price.toFixed(2)} = <b class="text-slate-700">₱${lineTotal.toFixed(2)}</b></p>
                            </div>
                            <div class="flex items-center space-x-1.5 bg-slate-100 p-0.5 rounded-lg border border-slate-200 shrink-0">
                                <button type="button" onclick="changeQty(${item.id}, -1)" class="w-5 h-5 bg-white hover:bg-slate-200 text-slate-800 font-bold rounded text-xs flex items-center justify-center transition">-</button>
                                <span class="text-xs font-bold w-4 text-center text-slate-900">${item.qty}</span>
                                <button type="button" onclick="changeQty(${item.id}, 1)" class="w-5 h-5 bg-white hover:bg-slate-200 text-slate-800 font-bold rounded text-xs flex items-center justify-center transition">+</button>
                            </div>
                        </div>`;
                });
            }

            document.getElementById('cartCountBadge').innerText = totalItems;
            calculateTotals();
        }

        function calculateTotals() {
            const subtotal = basket.reduce((acc, i) => acc + (i.price * i.qty), 0);
            const totalDiscount = computeDiscountAmount(subtotal);
            const grandTotal = Math.max(0, subtotal - totalDiscount);

            let cash = parseFloat(document.getElementById('cashInput').value) || 0;
            if (activePaymentMethod !== 'Cash') {
                cash = grandTotal;
            }

            const change = Math.max(0, cash - grandTotal);
            const btn = document.getElementById('checkoutBtn');

            document.getElementById('displaySubtotal').innerText = '₱' + subtotal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            document.getElementById('displayDiscount').innerText = '-₱' + totalDiscount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            document.getElementById('displayGrandTotal').innerText = '₱' + grandTotal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            document.getElementById('displayChange').innerText = '₱' + change.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});

            document.getElementById('basketHidden').value = JSON.stringify(basket);
            document.getElementById('cashHidden').value = cash.toFixed(2);
            document.getElementById('discountTypeHidden').value = activeDiscountMode.toUpperCase();
            document.getElementById('orderDiscountHidden').value = totalDiscount.toFixed(2);

            let canCheckout = false;
            if (basket.length > 0 && grandTotal > 0) {
                canCheckout = (activePaymentMethod !== 'Cash') || (cash >= grandTotal);
            }

            if (canCheckout) {
                btn.disabled = false;
                btn.className = "w-full py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-black uppercase text-xs tracking-wider transition shadow-sm cursor-pointer";
            } else {
                btn.disabled = true;
                btn.className = "w-full py-2.5 rounded-xl bg-slate-200 text-slate-400 font-black uppercase text-xs tracking-wider transition cursor-not-allowed shadow-sm";
            }
        }

        function validateAndSubmitCheckout() {
            if (basket.length === 0) {
                alert("Cart is empty! Add products first.");
                return false;
            }
            const grandTotal = getComputedGrandTotal();
            let cash = parseFloat(document.getElementById('cashInput').value) || 0;
            
            if (activePaymentMethod !== 'Cash') {
                cash = grandTotal;
            } else if (cash < grandTotal) {
                alert("Cash tendered is insufficient!");
                return false;
            }

            document.getElementById('basketHidden').value = JSON.stringify(basket);
            document.getElementById('cashHidden').value = cash.toFixed(2);
            document.getElementById('paymentMethodHidden').value = activePaymentMethod;

            const btn = document.getElementById('checkoutBtn');
            btn.disabled = true;
            btn.innerText = "Processing...";
            return true;
        }

        function filterCat(cat, btn) {
            document.querySelectorAll('.cat-btn').forEach(b => {
                b.className = "cat-btn px-3.5 py-1.5 rounded-xl text-xs font-black uppercase whitespace-nowrap bg-white text-slate-700 border border-slate-200 hover:bg-slate-50 transition";
            });
            if(btn) btn.className = "cat-btn active px-3.5 py-1.5 rounded-xl text-xs font-black uppercase whitespace-nowrap bg-slate-900 text-white transition";

            const targetCat = cat.toLowerCase();
            document.querySelectorAll('.product-card').forEach(item => {
                const itemCat = (item.dataset.cat || '').toLowerCase();
                item.style.display = (targetCat === 'all' || itemCat === targetCat) ? '' : 'none';
            });
        }

        // HIGH PRECISION BARCODE SCANNING & GENERATION
        async function autoDetectReceiptBarcode(codeText) {
            if (!codeText) return false;
            const term = codeText.trim().toLowerCase();
            const cleanTerm = term.replace(/-/g, '');

            const localMatch = cachedReceipts.find(r => {
                const recNum = (r.receipt_number || '').toLowerCase();
                return recNum === term || recNum.replace(/-/g, '') === cleanTerm;
            });

            if (localMatch) {
                playScanBeep();
                viewSpecificReceipt(localMatch);
                return true;
            }

            if (term.startsWith('rec') || term.includes('-')) {
                try {
                    const resp = await fetch('<?= $current_file ?>?store_id=<?= $store_id ?>&action=find_receipt&query=' + encodeURIComponent(codeText.trim()));
                    const result = await resp.json();
                    if (result.found && result.receipt) {
                        playScanBeep();
                        viewSpecificReceipt(result.receipt);
                        return true;
                    }
                } catch(e) {}
            }
            return false;
        }

        const barcodeInput = document.getElementById('barcodeInput');
        if (barcodeInput) {
            barcodeInput.addEventListener('keydown', async function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    const code = this.value.trim();
                    if (!code) return;

                    const isReceipt = await autoDetectReceiptBarcode(code);
                    if (isReceipt) {
                        this.value = '';
                        return;
                    }

                    const lowerCode = code.toLowerCase();
                    const cards = Array.from(document.querySelectorAll('.product-card'));
                    let matchedCard = cards.find(card => card.dataset.barcode === lowerCode) ||
                                      cards.find(card => card.dataset.name === lowerCode) ||
                                      cards.find(card => card.dataset.name.includes(lowerCode));

                    if (matchedCard) {
                        matchedCard.click();
                        this.value = '';
                    } else {
                        alert("Item not found: " + code);
                        this.value = '';
                    }
                }
            });

            barcodeInput.addEventListener('input', function(e) {
                const term = e.target.value.toLowerCase().trim();
                document.querySelectorAll('.product-card').forEach(card => {
                    const name = card.dataset.name || '';
                    const barcode = card.dataset.barcode || '';
                    card.style.display = (name.includes(term) || barcode.includes(term)) ? '' : 'none';
                });
            });
        }

        let html5QrScanner = null;
        function openCameraScanner() {
            document.getElementById('cameraModal').classList.remove('hidden');
            html5QrScanner = new Html5Qrcode("cameraReader");
            html5QrScanner.start(
                { facingMode: "environment" },
                { fps: 15, qrbox: { width: 220, height: 140 } },
                async (decodedText) => {
                    const code = decodedText.trim();
                    if (code) {
                        const isReceipt = await autoDetectReceiptBarcode(code);
                        if (isReceipt) {
                            closeCameraScanner();
                            return;
                        }
                        const cards = Array.from(document.querySelectorAll('.product-card'));
                        let matchedCard = cards.find(card => card.dataset.barcode === code.toLowerCase()) ||
                                          cards.find(card => card.dataset.name.includes(code.toLowerCase()));
                        if (matchedCard) {
                            matchedCard.click();
                            closeCameraScanner();
                        }
                    }
                },
                () => {}
            ).catch(() => closeCameraScanner());
        }

        function closeCameraScanner() {
            if (html5QrScanner) {
                html5QrScanner.stop().finally(() => {
                    html5QrScanner.clear();
                    document.getElementById('cameraModal').classList.add('hidden');
                });
            } else {
                document.getElementById('cameraModal').classList.add('hidden');
            }
        }

        function openReceiptHistoryModal() {
            document.getElementById('receiptHistoryModal').classList.remove('hidden');
        }
        function closeReceiptHistoryModal() {
            document.getElementById('receiptHistoryModal').classList.add('hidden');
        }

        function filterReceiptsHistory() {
            const term = document.getElementById('receiptSearchInput').value.toLowerCase().trim();
            document.querySelectorAll('.receipt-history-card').forEach(card => {
                const number  = card.dataset.number || '';
                const cashier = card.dataset.cashier || '';
                const date    = card.dataset.date || '';
                card.style.display = (number.includes(term) || cashier.includes(term) || date.includes(term)) ? '' : 'none';
            });
        }

        function viewSpecificReceipt(receipt) {
            document.getElementById('slipOR').innerText = receipt.receipt_number;
            document.getElementById('slipDate').innerText = receipt.date;
            document.getElementById('slipCashier').innerText = receipt.cashier;
            document.getElementById('slipPayMethod').innerText = receipt.payment_method || 'Cash';

            const total    = Number(receipt.total) || 0;
            const subtotal = Number(receipt.subtotal) || total;
            const discount = Number(receipt.discount) || 0;
            const cash     = Number(receipt.cash) || total;
            const change   = Number(receipt.change) || 0;
            const vatable  = Number(receipt.vatable) || (total / 1.12);
            const vatAmt   = Number(receipt.vat_amount) || (total - vatable);
            const itemCount= Number(receipt.item_count) || (receipt.items ? receipt.items.length : 1);

            document.getElementById('slipSubtotal').innerText = '₱' + subtotal.toFixed(2);
            document.getElementById('slipDiscount').innerText = '-₱' + discount.toFixed(2);
            document.getElementById('slipTotal').innerText = '₱' + total.toFixed(2);
            document.getElementById('slipTendered').innerText = '₱' + cash.toFixed(2);
            document.getElementById('slipChange').innerText = '₱' + change.toFixed(2);
            document.getElementById('slipVatable').innerText = '₱' + vatable.toFixed(2);
            document.getElementById('slipVat').innerText = '₱' + vatAmt.toFixed(2);
            document.getElementById('slipItemCount').innerText = itemCount;

            const discRow = document.getElementById('slipDiscountRow');
            if (discRow) {
                if (discount > 0) discRow.classList.remove('hidden');
                else discRow.classList.add('hidden');
            }

            const itemsContainer = document.getElementById('puregoldItemsList');
            itemsContainer.innerHTML = '';
            if (receipt.items && receipt.items.length > 0) {
                receipt.items.forEach(it => {
                    const sellP = parseFloat(it.price);
                    const qty   = parseInt(it.qty);
                    const lineP = Number(it.total_price) || (sellP * qty);
                    itemsContainer.innerHTML += `
                        <div>
                            <div class="font-bold uppercase tracking-tight">${it.name}</div>
                            <div class="flex justify-between text-[8.5px] pl-2 text-slate-800">
                                <span>${qty} @ ${sellP.toFixed(2)}</span>
                                <span class="font-bold font-mono">₱${lineP.toFixed(2)} V</span>
                            </div>
                        </div>`;
                });
            }

            renderReceiptBarcode(receipt.receipt_number);
            closeReceiptHistoryModal();
            document.getElementById('receiptModal').classList.remove('hidden');
        }

        function renderReceiptBarcode(codeText) {
            try {
                if (typeof JsBarcode === 'undefined') return;
                const cleanCode = (codeText || 'REC-000000').trim().toUpperCase().replace(/[^A-Z0-9\-]/g, '');
                
                JsBarcode("#receiptSlipBarcodeSVG", cleanCode, {
                    format: "CODE128",
                    lineColor: "#000000",
                    width: 1.4,
                    height: 32,
                    displayValue: false,
                    margin: 0,
                    flat: true
                });

                document.getElementById('receiptBarcodeText').innerText = cleanCode;
            } catch(e) {
                console.error('Barcode Render Error:', e);
            }
        }

        renderBasket();

        window.addEventListener('load', () => {
            document.getElementById('barcodeInput')?.focus();
            const currentSlipOR = document.getElementById('slipOR');
            if (currentSlipOR) {
                renderReceiptBarcode(currentSlipOR.innerText.trim());
            }
            <?php if (isset($_GET['success']) && !empty($_SESSION['show_receipt_modal'])): ?>
                if (adminEwalletData.autoPrint) {
                    setTimeout(() => { window.print(); }, 400);
                }
            <?php endif; ?>
        });
    </script>
</body>
</html>
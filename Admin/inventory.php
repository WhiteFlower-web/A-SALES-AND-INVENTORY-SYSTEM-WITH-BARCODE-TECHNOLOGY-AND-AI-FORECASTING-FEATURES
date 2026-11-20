
<?php
// JJ JUDEJUSH STORES - ADMIN INVENTORY & AUDIT CONTROL 

session_start();
date_default_timezone_set('Asia/Manila');

// 1. AUTHENTICATION & ACCESS CONTROL
$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$allowed_roles = ['admin', 'store owner', 'owner', 'administrator'];

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

// 2. DATABASE CONNECTION & SETTINGS
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
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
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

// User Heartbeat & Online Presence
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

// 3. SCHEMA MIGRATIONS (FAIL-SAFE)
$auto_columns = [
    "ALTER TABLE products ADD COLUMN cost_price DECIMAL(10,2) DEFAULT 0.00",
    "ALTER TABLE products ADD COLUMN unit_cost DECIMAL(10,2) DEFAULT 0.00",
    "ALTER TABLE products ADD COLUMN min_stock INT DEFAULT 5",
    "ALTER TABLE products ADD COLUMN barcode VARCHAR(100) DEFAULT NULL",
    "ALTER TABLE products ADD COLUMN sku VARCHAR(100) DEFAULT NULL",
    "ALTER TABLE products ADD COLUMN store_id INT DEFAULT 1",
    "ALTER TABLE products ADD COLUMN packaging_type VARCHAR(50) DEFAULT 'Box'",
    "ALTER TABLE products ADD COLUMN pcs_per_box INT NOT NULL DEFAULT 1",
    "ALTER TABLE products ADD COLUMN is_deleted TINYINT(1) DEFAULT 0",
    "ALTER TABLE products ADD COLUMN image VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE products ADD COLUMN is_verified TINYINT(1) DEFAULT 0",
    "ALTER TABLE products ADD COLUMN verified_by VARCHAR(100) DEFAULT NULL",
    "ALTER TABLE products ADD COLUMN verified_at DATETIME DEFAULT NULL"
];
foreach ($auto_columns as $colSql) {
    try { $pdo->exec($colSql); } catch (Throwable $t) {}
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS audit_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT DEFAULT 1,
            action VARCHAR(100) NOT NULL,
            page_module VARCHAR(100) DEFAULT 'Inventory',
            details TEXT NULL,
            ip_address VARCHAR(45) NULL,
            store_id INT DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pending_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            store_id INT DEFAULT 1,
            product_id INT NOT NULL,
            request_type VARCHAR(50) NOT NULL,
            user_id INT DEFAULT 1,
            requested_by VARCHAR(255) DEFAULT 'Staff',
            payload TEXT NULL,
            status VARCHAR(50) DEFAULT 'PENDING',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS stock_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            product_name VARCHAR(255) NOT NULL DEFAULT '',
            type VARCHAR(50) NOT NULL,
            quantity INT NOT NULL,
            stock_after INT NOT NULL DEFAULT 0,
            notes TEXT NULL,
            remarks TEXT NULL,
            user_id INT DEFAULT 1,
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

try { $pdo->exec("ALTER TABLE stock_history ADD COLUMN notes TEXT NULL"); } catch (Throwable $t) {}
try { $pdo->exec("ALTER TABLE stock_history ADD COLUMN remarks TEXT NULL"); } catch (Throwable $t) {}
try { $pdo->exec("ALTER TABLE stock_history ADD COLUMN product_name VARCHAR(255) DEFAULT ''"); } catch (Throwable $t) {}
try { $pdo->exec("ALTER TABLE stock_history ADD COLUMN user_id INT DEFAULT 1"); } catch (Throwable $t) {}
try { $pdo->exec("ALTER TABLE stock_history ADD COLUMN store_id INT DEFAULT 1"); } catch (Throwable $t) {}
try { $pdo->exec("ALTER TABLE stock_history ADD COLUMN stock_after INT NOT NULL DEFAULT 0"); } catch (Throwable $t) {}

// 4. UNIFIED LOGGING & NOTIFICATION HELPERS
if (!function_exists('logActivity')) {
    function logActivity($pdo, $action, $details, $store_id = 1) {
        try {
            $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, page_module, details, store_id, created_at) VALUES (?, ?, 'Inventory', ?, ?, NOW())");
            $stmt->execute([$_SESSION['user_id'] ?? 1, $action, $details, $store_id]);
        } catch (Throwable $t) {}
    }
}

if (!function_exists('createNotification')) {
    function createNotification($pdo, $title, $message, $store_id = 1) {
        try {
            $stmt = $pdo->prepare("INSERT INTO notifications (title, message, is_read, store_id, created_at) VALUES (?, ?, 0, ?, NOW())");
            $stmt->execute([$title, $message, $store_id]);
        } catch (Throwable $t) {}
    }
}

if (!function_exists('logStockHistory')) {
    function logStockHistory($pdo, $product_id, $product_name, $type, $qty, $stock_after, $notes, $store_id = 1) {
        try {
            $uid = (int)($_SESSION['user_id'] ?? 1);
            $stmt = $pdo->prepare("INSERT INTO stock_history (product_id, product_name, type, quantity, stock_after, notes, remarks, user_id, store_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$product_id, $product_name, $type, $qty, $stock_after, $notes, $notes, $uid, $store_id]);
        } catch (Throwable $t) {}
    }
}

if (!function_exists('getValidAvatarUrl')) {
    function getValidAvatarUrl($avatarPath) {
        if (empty($avatarPath)) return null;
        $cleanPath = ltrim(str_replace(['\\', '/'], '/', $avatarPath), '/');
        $fileName = basename($cleanPath);
        $candidates = [
            $cleanPath,
            'uploads/avatars/' . $fileName,
            '../uploads/avatars/' . $fileName,
            'admin/uploads/avatars/' . $fileName,
            '../admin/uploads/avatars/' . $fileName
        ];
        foreach ($candidates as $cand) {
            $fullPath = __DIR__ . '/' . $cand;
            if (file_exists($fullPath) && !is_dir($fullPath)) return $cand;
        }
        return null;
    }
}

if (!function_exists('getValidProductImageUrl')) {
    function getValidProductImageUrl($imgPath) {
        if (empty($imgPath)) return null;
        $cleanPath = ltrim(str_replace(['\\', '/'], '/', $imgPath), '/');
        $fileName = basename($cleanPath);
        $candidates = [
            $cleanPath,
            'uploads/products/' . $fileName,
            '../uploads/products/' . $fileName,
            'admin/uploads/products/' . $fileName,
            '../admin/uploads/products/' . $fileName
        ];
        foreach ($candidates as $cand) {
            $fullPath = __DIR__ . '/' . $cand;
            if (file_exists($fullPath) && !is_dir($fullPath)) return $cand;
        }
        return null;
    }
}

// 5. REQUEST PARAMETERS & USER SESSION
$store_param = strtolower(trim($_GET['store_id'] ?? 'both'));
$selected_store = in_array($store_param, ['1', '2', 'both']) ? $store_param : 'both';
$active_tab = isset($_GET['tab']) ? trim($_GET['tab']) : 'products';
$target_nav_store = ($selected_store === '2') ? '2' : '1';

// Profile Data
$currentUserFullname = $_SESSION['fullname'] ?? 'KHERLEEN MAE';
$currentUserRole = $_SESSION['role'] ?? 'Admin';
$current_user_avatar = $_SESSION['avatar'] ?? null;
try {
    $stmtUser = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmtUser->execute([$user_id]);
    $uRow = $stmtUser->fetch();
    if ($uRow) {
        $currentUserFullname = $uRow['fullname'] ?? $currentUserFullname;
        $current_user_avatar = $uRow['avatar'] ?? ($uRow['profile_image'] ?? $current_user_avatar);
    }
} catch (Throwable $t) {}

$nameParts = explode(' ', trim($currentUserFullname));
$userInitials = count($nameParts) >= 2 
    ? strtoupper(substr($nameParts[0], 0, 1) . substr($nameParts[count($nameParts)-1], 0, 1))
    : strtoupper(substr($currentUserFullname, 0, 2));
$validAvatarUrl = getValidAvatarUrl($current_user_avatar);

// Settings
$settings = [
    'store_name'  => 'JJ JUDEJUSH',
    'store_logo'  => null,
    'ai_analysis' => 1,
    'ai_enabled'  => 1
];
try {
    $chkSettings = $pdo->query("SELECT * FROM settings WHERE id = 1")->fetch();
    if ($chkSettings) $settings = array_merge($settings, $chkSettings);
} catch (Throwable $t) {}

$ai_enabled = !empty($settings['ai_analysis']) && !empty($settings['ai_enabled']);

// Clear Notifications
if (isset($_GET['mark_read']) && $_GET['mark_read'] === 'all') {
    try {
        $notifClearFilter = ($selected_store === 'both') ? "" : " WHERE (store_id = " . (int)$selected_store . " OR store_id IS NULL OR store_id = 0)";
        $pdo->query("UPDATE notifications SET is_read = 1 {$notifClearFilter}");
    } catch (Throwable $t) {}
    header("Location: inventory.php?store_id=" . urlencode($selected_store) . "&tab=" . urlencode($active_tab));
    exit();
}

// 6. POST ACTIONS HANDLER
$is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || (isset($_POST['ajax']) && $_POST['ajax'] == '1');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // 1. REORDER BY BOX (ADMIN RESTOCK)
    if ($action === 'reorder_box') {
        $pid = (int)($_POST['id'] ?? 0);
        $boxes_to_add = (int)($_POST['box_quantity'] ?? 0);

        if ($pid <= 0 || $boxes_to_add <= 0) {
            if ($is_ajax) { echo json_encode(['status' => 'error', 'message' => 'Invalid box count.']); exit; }
        } else {
            $st = $pdo->prepare("SELECT product_name, stock, min_stock, pcs_per_box, store_id FROM products WHERE id = ?");
            $st->execute([$pid]);
            $p_info = $st->fetch();

            if ($p_info) {
                $p_store = $p_info['store_id'] ?: 1;
                $pcs_per_box = max(1, (int)$p_info['pcs_per_box']);
                $total_pcs_added = $boxes_to_add * $pcs_per_box;
                $new_stock = (int)$p_info['stock'] + $total_pcs_added;

                $pdo->prepare("UPDATE products SET stock = ?, is_verified = 0 WHERE id = ?")->execute([$new_stock, $pid]);
                $note = "Restocked: +{$boxes_to_add} Boxes ({$pcs_per_box} pcs  = +{$total_pcs_added} pieces) by {$currentUserFullname}";
                logActivity($pdo, 'ADMIN_REORDER_BOX', "Restocked {$boxes_to_add} boxes (+{$total_pcs_added} pcs) for {$p_info['product_name']}", $p_store);
                logStockHistory($pdo, $pid, $p_info['product_name'], 'IN', $total_pcs_added, $new_stock, $note, $p_store);
                createNotification($pdo, "RESTOCKED: {$p_info['product_name']}", "+{$boxes_to_add} Boxes (+{$total_pcs_added} pcs) added by {$currentUserFullname}. Total: {$new_stock} pcs.", $p_store);

                if ($is_ajax) {
                    echo json_encode([
                        'status' => 'success',
                        'message' => "Successfully added +{$boxes_to_add} Boxes (+{$total_pcs_added} pcs) for {$p_info['product_name']}!",
                        'product_id' => $pid,
                        'new_stock' => $new_stock,
                        'avail_boxes' => floor($new_stock / $pcs_per_box),
                        'loose_pcs' => ($new_stock % $pcs_per_box),
                        'pcs_per_box' => $pcs_per_box,
                        'is_low' => ($new_stock <= (int)$p_info['min_stock'])
                    ]);
                    exit;
                }
            }
        }
    }

    // 2. VERIFY STOCK AUDIT
    if ($action === 'verify_stock') {
        $pid = (int)($_POST['id'] ?? 0);
        if ($pid > 0) {
            $st_v = $pdo->prepare("SELECT product_name, stock, store_id FROM products WHERE id = ?");
            $st_v->execute([$pid]);
            $prod_v = $st_v->fetch();

            $stmt_v = $pdo->prepare("UPDATE products SET is_verified = 1, verified_by = ?, verified_at = NOW() WHERE id = ?");
            $stmt_v->execute([$currentUserFullname, $pid]);
            
            $p_name = $prod_v['product_name'] ?? "Item #$pid";
            $p_store = $prod_v['store_id'] ?: 1;
            $cur_stock = (int)($prod_v['stock'] ?? 0);

            logActivity($pdo, 'STOCK_VERIFY', "Verified inventory count for {$p_name} by {$currentUserFullname}", $p_store);
            logStockHistory($pdo, $pid, $p_name, 'AUDIT', 0, $cur_stock, "Audit verified by {$currentUserFullname}", $p_store);

            if ($is_ajax) {
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Stock count has been audited and verified!',
                    'product_id' => $pid,
                    'verified_by' => $currentUserFullname
                ]);
                exit;
            }
        }
    }

    // 3. APPROVE STAFF REQUEST
    if ($action === 'approve_request') {
        $req_id = (int)($_POST['request_id'] ?? 0);
        $admin_pass = $_POST['admin_password'] ?? '';

        $stmt_curr = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt_curr->execute([$user_id]);
        $curr_user = $stmt_curr->fetch();

        $is_auth = ($curr_user && password_verify($admin_pass, $curr_user['password']));
        if (!$is_auth) {
            $stmt_admins = $pdo->query("SELECT password FROM users WHERE role LIKE '%admin%' OR role LIKE '%owner%'");
            foreach ($stmt_admins->fetchAll() as $adm) {
                if (!empty($adm['password']) && password_verify($admin_pass, $adm['password'])) {
                    $is_auth = true; break;
                }
            }
        }

        if ($is_auth) {
            $stmt_req = $pdo->prepare("SELECT * FROM pending_requests WHERE id = ? AND status = 'PENDING'");
            $stmt_req->execute([$req_id]);
            $req = $stmt_req->fetch();

            if ($req) {
                $payload = json_decode($req['payload'] ?? '{}', true);
                $product_id = (int)$req['product_id'];
                $req_type = strtoupper(trim($req['request_type']));
                $pname = $payload['name'] ?? $payload['product_name'] ?? "Item #$product_id";
                $p_store = $req['store_id'] ?: 1;

                if ($req_type === 'EDIT') {
                    $p_name = $payload['name'] ?? $pname;
                    $p_cat = $payload['category'] ?? 'General';
                    $p_stock = (int)($payload['stock'] ?? 0);
                    $p_min = (int)($payload['min_stock'] ?? 5);
                    $p_price = (float)($payload['price'] ?? 0);
                    $p_cost = (float)($payload['unit_cost'] ?? ($payload['cost_price'] ?? 0));
                    $p_barcode = $payload['barcode'] ?? "SKU-" . rand(1000, 9999);
                    $p_pkg = $payload['packaging_type'] ?? 'Box';
                    $p_pcs_box = max(1, (int)($payload['pcs_per_box'] ?? 1));

                    $sql = "UPDATE products SET product_name=?, category=?, stock=?, min_stock=?, price=?, cost_price=?, unit_cost=?, barcode=?, sku=?, packaging_type=?, pcs_per_box=?, is_verified=1, verified_by=?, verified_at=NOW() WHERE id=?";
                    $pdo->prepare($sql)->execute([$p_name, $p_cat, $p_stock, $p_min, $p_price, $p_cost, $p_cost, $p_barcode, $p_barcode, $p_pkg, $p_pcs_box, $currentUserFullname, $product_id]);
                    logStockHistory($pdo, $product_id, $p_name, 'ADJUST', $p_stock, $p_stock, "Staff edit approved by Admin $currentUserFullname", $p_store);
                } elseif ($req_type === 'DELETE') {
                    $pdo->prepare("UPDATE products SET is_deleted = 1 WHERE id=?")->execute([$product_id]);
                    logStockHistory($pdo, $product_id, $pname, 'DELETE', 0, 0, "Deletion approved by Admin $currentUserFullname", $p_store);
                } elseif ($req_type === 'RESTORE') {
                    $pdo->prepare("UPDATE products SET is_deleted = 0 WHERE id=?")->execute([$product_id]);
                    logStockHistory($pdo, $product_id, $pname, 'RESTORE', 0, 0, "Restoration approved by Admin $currentUserFullname", $p_store);
                }

                $pdo->prepare("UPDATE pending_requests SET status = 'APPROVED' WHERE id = ?")->execute([$req_id]);
                logActivity($pdo, 'REQUEST_APPROVE', "Approved {$req_type} request for {$pname} (#{$product_id})", $p_store);
                createNotification($pdo, "REQUEST APPROVED: {$pname}", "Admin {$currentUserFullname} approved the {$req_type} request.", $p_store);

                if ($is_ajax) {
                    echo json_encode(['status' => 'success', 'message' => "Successfully approved {$req_type} request!", 'request_id' => $req_id]);
                    exit;
                }
            }
        } else {
            if ($is_ajax) { echo json_encode(['status' => 'error', 'message' => 'Incorrect Admin Password. Approval denied.']); exit; }
        }
    }

    // 4. REJECT STAFF REQUEST
    if ($action === 'reject_request') {
        $req_id = (int)($_POST['request_id'] ?? 0);
        $st_r = $pdo->prepare("SELECT store_id, product_id, request_type, payload FROM pending_requests WHERE id = ?");
        $st_r->execute([$req_id]);
        $r_info = $st_r->fetch();

        $pdo->prepare("UPDATE pending_requests SET status = 'REJECTED' WHERE id = ?")->execute([$req_id]);
        logActivity($pdo, 'REQUEST_REJECT', "Rejected pending request #$req_id", $r_info['store_id'] ?? 1);

        if ($r_info) {
            createNotification($pdo, "REQUEST REJECTED", "Admin {$currentUserFullname} rejected request #{$req_id}.", $r_info['store_id'] ?? 1);
        }

        if ($is_ajax) {
            echo json_encode(['status' => 'success', 'message' => 'Request has been rejected.', 'request_id' => $req_id]);
            exit;
        }
    }

    // 5. RESTORE PRODUCT DIRECTLY
    if ($action === 'restore') {
        $product_id = (int)($_POST['id'] ?? 0);
        $st_res = $pdo->prepare("SELECT product_name, stock, store_id FROM products WHERE id = ?");
        $st_res->execute([$product_id]);
        $p_res = $st_res->fetch();

        $pdo->prepare("UPDATE products SET is_deleted = 0 WHERE id = ?")->execute([$product_id]);
        $pname = $p_res['product_name'] ?? "Item #$product_id";
        $p_store = $p_res['store_id'] ?: 1;
        $p_stock = (int)($p_res['stock'] ?? 0);

        logActivity($pdo, 'PRODUCT_RESTORE', "Directly restored product #$product_id ({$pname})", $p_store);
        logStockHistory($pdo, $product_id, $pname, 'RESTORE', 0, $p_stock, "Product restored directly by Admin {$currentUserFullname}", $p_store);
        createNotification($pdo, "PRODUCT RESTORED", "{$pname} has been restored to catalog by {$currentUserFullname}.", $p_store);

        if ($is_ajax) {
            echo json_encode(['status' => 'success', 'message' => 'Product restored to active list!', 'product_id' => $product_id]);
            exit;
        }
    }
}

// 7. QUERY ACTIVE INVENTORY DATA
$store_clause = "";
$req_store_clause = "";
$hist_store_clause = "";

if ($selected_store === '1') {
    $store_clause = " AND (store_id = 1 OR store_id IS NULL OR store_id = 0)";
    $req_store_clause = " AND (store_id = 1 OR store_id IS NULL OR store_id = 0)";
    $hist_store_clause = " AND (sh.store_id = 1 OR sh.store_id IS NULL OR sh.store_id = 0 OR p.store_id = 1)";
} elseif ($selected_store === '2') {
    $store_clause = " AND store_id = 2";
    $req_store_clause = " AND store_id = 2";
    $hist_store_clause = " AND (sh.store_id = 2 OR p.store_id = 2)";
}

$products = [];
try {
    $st = $pdo->prepare("SELECT * FROM products WHERE (is_deleted = 0 OR is_deleted IS NULL) {$store_clause} ORDER BY id DESC");
    $st->execute();
    $products = $st->fetchAll();
} catch (Throwable $t) {}

$reorder_items = [];
$total_units_count = 0;
$total_cost_value = 0.0;
$total_retail_value = 0.0;

$po_total_boxes = 0;
$po_total_pieces = 0;
$po_total_est_cost = 0.0;

foreach ($products as $p) {
    $sqty = (int)$p['stock'];
    $smin = (int)($p['min_stock'] ?? 5);
    $total_units_count += $sqty;
    $cPrice = (float)($p['cost_price'] ?: ($p['unit_cost'] ?: ($p['price'] * 0.7)));
    $total_cost_value += ($sqty * $cPrice);
    $total_retail_value += ($sqty * (float)$p['price']);

    if ($sqty <= $smin) {
        $pcs_box = max(1, (int)$p['pcs_per_box']);
        $p['boxes_remaining'] = floor($sqty / $pcs_box);
        $p['loose_remaining'] = $sqty % $pcs_box;
        $target_stock = max($smin * 3, 20);
        $deficit = max(1, $target_stock - $sqty);
        $p['boxes_to_order'] = (int)ceil($deficit / $pcs_box);
        $p['suggested_units'] = $p['boxes_to_order'] * $pcs_box;
        $p['est_unit_cost'] = $cPrice;
        $p['est_line_cost'] = $p['suggested_units'] * $cPrice;
        
        $po_total_boxes += $p['boxes_to_order'];
        $po_total_pieces += $p['suggested_units'];
        $po_total_est_cost += $p['est_line_cost'];

        $reorder_items[] = $p;
    }
}

$pending_requests = [];
try {
    $st = $pdo->prepare("SELECT * FROM pending_requests WHERE status = 'PENDING' {$req_store_clause} ORDER BY id DESC");
    $st->execute();
    $pending_requests = $st->fetchAll();
} catch (Throwable $t) {}

$stock_history = [];
try {
    $st = $pdo->prepare("
        SELECT sh.*, 
               COALESCE(NULLIF(TRIM(sh.product_name), ''), p.product_name, CONCAT('Item #', sh.product_id)) AS clean_name,
               COALESCE(u.fullname, u.username, 'System') AS logged_by_name,
               COALESCE(sh.store_id, p.store_id, 1) AS resolved_store_id
        FROM stock_history sh
        LEFT JOIN products p ON sh.product_id = p.id
        LEFT JOIN users u ON sh.user_id = u.id
        WHERE 1=1 {$hist_store_clause}
        ORDER BY sh.id DESC LIMIT 120
    ");
    $st->execute();
    $stock_history = $st->fetchAll();
} catch (Throwable $t) {}

$deleted_products = [];
try {
    $st = $pdo->prepare("SELECT * FROM products WHERE is_deleted = 1 {$store_clause} ORDER BY id DESC");
    $st->execute();
    $deleted_products = $st->fetchAll();
} catch (Throwable $t) {}

$unreadCount = 0; $notifs = [];
try {
    $notifStoreFilter = ($selected_store === 'both') ? " WHERE 1=1" : " WHERE (store_id = " . (int)$selected_store . " OR store_id IS NULL OR store_id = 0)";
    $unreadCount = (int)($pdo->query("SELECT COUNT(*) FROM notifications {$notifStoreFilter} AND is_read = 0")->fetchColumn() ?: 0);
    $notifs = $pdo->query("SELECT * FROM notifications {$notifStoreFilter} AND is_read = 0 ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $t) {}
$total_alerts_badge = max(count($notifs), $unreadCount + (count($reorder_items) > 0 ? 1 : 0));

$po_ref_number = "PO-JJ-" . date('Ymd') . "-" . str_pad((string)($selected_store === 'both' ? 'ALL' : $selected_store), 2, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($settings['store_name']) ?> - Inventory &amp; Stock Intelligence</title>
    <!-- Fonts & Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/js/all.min.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    
    <style id="dynamicPrintStyle"></style>

    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #0f172a; }
        .font-mono { font-family: 'Space Mono', monospace; }
        .custom-scroll::-webkit-scrollbar { width: 5px; height: 5px; }
        .custom-scroll::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 6px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 6px; }
        .custom-scroll::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        
        .modal { display: none; position: fixed; inset: 0; background: rgba(7, 10, 18, 0.82); align-items: center; justify-content: center; z-index: 1000; backdrop-filter: blur(5px); }
        .modal.active { display: flex; }
        .custom-input { background-color: #f8fafc; border: 1.5px solid #cbd5e1; border-radius: 12px; padding: 9px 14px; font-size: 13px; font-weight: 700; color: #0f172a; width: 100%; outline: none; transition: all 0.2s; }
        .custom-input:focus { border-color: #f59e0b; background-color: #fff; box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.2); }
        #toastNotice { position: fixed; top: 20px; right: 20px; z-index: 9999; transform: translateX(120%); transition: transform 0.3s ease-in-out; }
        #toastNotice.show { transform: translateX(0); }

        svg.barcode-svg {
            shape-rendering: crispEdges !important;
            image-rendering: pixelated !important;
        }

        .po-table th, .po-table td {
            border: 1px solid #0f172a !important;
        }

        /* PRINT STYLING */
        @media print {
            .no-print { display: none !important; }

            /* 1. PURCHASE ORDER SLIP PRINTING */
            body.print-reorder-active * { visibility: hidden !important; }
            body.print-reorder-active #reorderPrintArea, 
            body.print-reorder-active #reorderPrintArea * { 
                visibility: visible !important; 
            }
            body.print-reorder-active #reorderPrintArea {
                position: absolute !important;
                left: 0 !important;
                top: 0 !important;
                width: 100% !important;
                display: block !important;
                background: white !important;
                color: black !important;
                padding: 10mm 12mm !important;
                box-sizing: border-box !important;
            }

            /* 2. EXACT 48MM THERMAL BARCODE */
            body.print-barcode-active * { visibility: hidden !important; }
            body.print-barcode-active #barcodePrintContainer, 
            body.print-barcode-active #barcodePrintContainer * { 
                visibility: visible !important; 
            }
            body.print-barcode-active #barcodePrintContainer {
                position: static !important;
                width: 48mm !important;
                max-width: 48mm !important;
                margin: 0 auto !important;
                padding: 0 !important;
                display: block !important;
                background: #ffffff !important;
                color: #000000 !important;
            }

            .barcode-label-item {
                width: 48mm !important;
                max-width: 48mm !important;
                box-sizing: border-box !important;
                display: flex !important;
                flex-direction: column !important;
                align-items: center !important;
                justify-content: center !important;
                text-align: center !important;
                background: #ffffff !important;
                padding: 2.5mm 1.5mm !important;
                margin: 0 !important;
                border: none !important;
                page-break-after: always !important;
                break-after: page !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
                overflow: hidden !important;
            }

            .barcode-label-item:last-child {
                page-break-after: auto !important;
                break-after: auto !important;
            }

            .barcode-label-item svg {
                max-width: 45mm !important;
                display: block !important;
                margin: 0 auto !important;
                shape-rendering: crispEdges !important;
                image-rendering: pixelated !important;
            }
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-800 flex flex-col h-screen overflow-hidden relative">

    <!-- FLOATING TOAST -->
    <div id="toastNotice" class="bg-[#0f172a] text-white px-5 py-3.5 rounded-2xl shadow-2xl border border-amber-500/50 flex items-center space-x-3 max-w-sm pointer-events-none">
        <div id="toastIcon" class="w-8 h-8 rounded-xl bg-amber-500 text-slate-950 flex items-center justify-center font-black text-sm shrink-0">
            <i class="fa-solid fa-check"></i>
        </div>
        <div>
            <p id="toastTitle" class="text-xs font-black uppercase text-amber-400">Inventory Update</p>
            <p id="toastMessage" class="text-[11px] font-bold text-slate-200 mt-0.5 leading-tight">Action completed successfully.</p>
        </div>
    </div>

    <!-- TOP HEADER -->
    <header class="flex items-center justify-between bg-[#070a12] text-white px-5 py-3 shadow-md z-30 flex-shrink-0 border-b border-slate-900 no-print">
        <div class="flex items-center space-x-4">
            <button id="toggleSidebar" class="p-2 bg-slate-900 hover:bg-slate-800 rounded-xl text-white transition-colors" title="Toggle Navigation">
                <i class="fas fa-bars text-sm"></i>
            </button>
            <a href="index.php?store_id=<?= urlencode($selected_store) ?>" class="flex items-center space-x-3 group" title="Return to Dashboard">
                <div class="w-9 h-9 bg-amber-500 rounded-xl flex items-center justify-center text-slate-950 font-black text-sm shadow overflow-hidden border border-amber-400 group-hover:scale-105 transition-transform" id="headerStoreLogoWrap">
                    <?php if (!empty($settings['store_logo']) && file_exists(__DIR__ . '/' . $settings['store_logo'])): ?>
                        <img src="<?= htmlspecialchars($settings['store_logo']) ?>?v=<?= time() ?>" alt="Logo" class="w-full h-full object-cover">
                    <?php else: ?>
                        <span>JJ</span>
                    <?php endif; ?>
                </div>
                <div>
                    <h1 class="text-sm font-black tracking-wider text-white uppercase leading-none group-hover:text-amber-400 transition-colors">
                        <?= htmlspecialchars($settings['store_name']) ?>
                    </h1>
                </div>
            </a>
        </div>

        <div class="flex items-center space-x-3 md:space-x-4">
            <!-- STORE SWITCHER -->
            <div class="flex bg-slate-900/90 p-1 rounded-xl border border-slate-800 shadow-inner">
                <a href="inventory.php?store_id=both&tab=<?= urlencode($active_tab) ?>" 
                   class="px-3.5 py-1.5 text-xs font-black rounded-lg transition-all <?= $selected_store === 'both' ? 'bg-amber-500 text-slate-950 shadow' : 'text-slate-300 hover:text-white' ?>">
                    Both Stores
                </a>
                <a href="inventory.php?store_id=1&tab=<?= urlencode($active_tab) ?>" 
                   class="px-3.5 py-1.5 text-xs font-black rounded-lg transition-all <?= $selected_store === '1' ? 'bg-amber-500 text-slate-950 shadow' : 'text-slate-300 hover:text-white' ?>">
                    Store 1
                </a>
                <a href="inventory.php?store_id=2&tab=<?= urlencode($active_tab) ?>" 
                   class="px-3.5 py-1.5 text-xs font-black rounded-lg transition-all <?= $selected_store === '2' ? 'bg-amber-500 text-slate-950 shadow' : 'text-slate-300 hover:text-white' ?>">
                    Store 2
                </a>
            </div>

            <!-- NOTIFICATIONS DROPDOWN -->
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
                            <?= $total_alerts_badge ?> Alerts
                        </span>
                    </div>

                    <div class="max-h-72 overflow-y-auto custom-scroll divide-y divide-slate-100 bg-white">
                        <?php if (count($reorder_items) > 0): ?>
                            <div class="p-3.5 bg-red-50 hover:bg-red-100 transition-colors cursor-pointer" onclick="switchTab('reorder')">
                                <h4 class="text-xs font-black text-red-900 uppercase">⚠️ <?= count($reorder_items) ?> Products Need Restock</h4>
                                <p class="text-[11px] font-bold text-slate-600 mt-0.5">Critical units below buffer. Click to view order list.</p>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($notifs)): ?>
                            <?php foreach($notifs as $n): ?>
                            <div class="p-4 hover:bg-slate-50 transition-colors">
                                <h4 class="text-xs font-black text-slate-900 tracking-tight leading-snug uppercase"><?= htmlspecialchars($n['title']) ?></h4>
                                <p class="text-[11px] font-bold text-slate-600 mt-1 leading-normal"><?= htmlspecialchars($n['message']) ?></p>
                            </div>
                            <?php endforeach; ?>
                        <?php elseif (count($reorder_items) === 0): ?>
                            <div class="p-4 text-center text-xs font-bold text-slate-500">No active critical alerts.</div>
                        <?php endif; ?>
                    </div>

                    <div class="grid grid-cols-2 border-t border-slate-200 bg-white text-center font-black text-[11px] uppercase tracking-wider">
                        <a href="inventory.php?store_id=<?= urlencode($selected_store) ?>&mark_read=all&tab=<?= urlencode($active_tab) ?>" class="py-3 text-emerald-600 hover:text-emerald-700 hover:bg-emerald-50 transition-colors border-r border-slate-200">
                            Clear All
                        </a>
                        <button type="button" onclick="switchTab('reorder')" class="py-3 text-amber-600 hover:text-amber-700 hover:bg-amber-50 transition-colors">
                            Reorder Box
                        </button>
                    </div>
                </div>
            </div>

            <!-- PROFILE -->
            <a href="settings.php?store_id=<?= urlencode($selected_store) ?>#profile" class="w-9 h-9 rounded-xl bg-amber-500 text-slate-950 flex items-center justify-center font-black text-xs shadow hover:ring-2 hover:ring-amber-400 transition-all overflow-hidden relative border border-amber-400" title="Profile Settings">
                <?php if ($validAvatarUrl): ?>
                    <img src="<?= htmlspecialchars($validAvatarUrl) ?>?v=<?= time() ?>" alt="Avatar" class="w-full h-full object-cover">
                <?php else: ?>
                    <?= htmlspecialchars($userInitials) ?>
                <?php endif; ?>
            </a>
        </div>
    </header>

    <!-- MAIN BODY WRAPPER -->
    <div class="flex flex-1 overflow-hidden" id="appRoot">
        
        <!-- SIDEBAR -->
        <div id="sidebar" class="bg-[#070a12] text-slate-300 h-full w-64 flex flex-col transition-all duration-300 flex-shrink-0 border-r border-slate-900 no-print">
            <div class="p-3 space-y-1.5 flex-1 overflow-y-auto custom-scroll">
                <a href="index.php?store_id=<?= urlencode($selected_store) ?>" class="flex items-center px-4 py-3 rounded-xl font-black text-xs transition-all text-slate-300 hover:bg-slate-900 hover:text-white">
                    <i class="fa-solid fa-th-large w-5 text-center text-sm"></i><span class="ml-3 sidebar-text">Dashboard</span>
                </a>
                <a href="inventory.php?store_id=<?= urlencode($selected_store) ?>" class="flex items-center px-4 py-3 rounded-xl font-black text-xs transition-all bg-amber-500 text-slate-950 shadow-md">
                    <i class="fa-solid fa-box w-5 text-center text-sm"></i><span class="ml-3 sidebar-text">Inventory</span>
                </a>
                <a href="reports.php?store_id=<?= $target_nav_store ?>" class="flex items-center px-4 py-3 rounded-xl font-black text-xs transition-all text-slate-300 hover:bg-slate-900 hover:text-white">
                    <i class="fa-solid fa-file-invoice-dollar w-5 text-center text-sm"></i><span class="ml-3 sidebar-text">Reports</span>
                </a>
                <a href="audit_logs.php?store_id=<?= $target_nav_store ?>" class="flex items-center px-4 py-3 rounded-xl font-black text-xs transition-all text-slate-300 hover:bg-slate-900 hover:text-white">
                    <i class="fa-solid fa-shield-halved w-5 text-center text-sm"></i><span class="ml-3 sidebar-text">Activity Logs</span>
                </a>
                <a href="accounts.php?store_id=<?= $target_nav_store ?>" class="flex items-center px-4 py-3 rounded-xl font-black text-xs transition-all text-slate-300 hover:bg-slate-900 hover:text-white">
                    <i class="fa-solid fa-users w-5 text-center text-sm"></i><span class="ml-3 sidebar-text">Users</span>
                </a>
                <a href="settings.php?store_id=<?= urlencode($selected_store) ?>" class="flex items-center px-4 py-3 rounded-xl font-black text-xs transition-all text-slate-300 hover:bg-slate-900 hover:text-white">
                    <i class="fa-solid fa-gear w-5 text-center text-sm"></i><span class="ml-3 sidebar-text">Settings</span>
                </a>
            </div>
            <div class="border-t border-slate-900 p-3">
                <a href="../logout.php" class="w-full py-2.5 px-4 bg-slate-900 hover:bg-red-600 hover:text-white rounded-xl flex items-center justify-center font-black text-xs text-slate-300 transition-all">
                    <i class="fa-solid fa-right-from-bracket"></i><span class="ml-2 sidebar-text">Logout</span>
                </a>
            </div>
        </div>

        <!-- MAIN VIEW CONTAINER -->
        <main class="flex-1 p-6 md:p-8 overflow-y-auto custom-scroll space-y-6 bg-slate-100" id="mainContentContainer">

            <!-- HEADER BANNER & SCOPE SUMMARY -->
            <div class="bg-white p-5 md:p-6 rounded-2xl shadow-sm border border-slate-200/80 flex flex-col md:flex-row justify-between items-start md:items-center gap-4 no-print">
                <div class="flex items-center space-x-4">
                    <div class="w-12 h-12 rounded-2xl bg-[#070a12] text-amber-500 font-black flex items-center justify-center border-2 border-slate-800 shadow text-lg">
                        <i class="fas fa-boxes-stacked"></i>
                    </div>
                    <div>
                        <h1 class="text-xl font-black text-slate-900 tracking-tight flex items-center gap-2">
                            <span>Inventory</span>
                        </h1>
                        <p class="text-xs font-bold text-slate-500 mt-0.5">
                            Scope: <b class="text-amber-600 uppercase" id="labelActiveStoreScope"><?= ($selected_store === 'both') ? 'Both Stores' : 'Store ' . $selected_store ?></b> 
                            | Total Pieces: <span class="text-slate-900 font-black"><?= number_format($total_units_count) ?> pcs</span>
                        </p>
                    </div>
                </div>

                <div class="flex items-center gap-2 flex-wrap">
                    <div class="bg-slate-50 border border-slate-200 px-4 py-2 rounded-xl text-right">
                        <span class="text-[10px] font-black uppercase text-slate-500 block">Total Cost</span>
                        <span class="text-xs font-black text-slate-900">₱<?= number_format($total_cost_value, 2) ?></span>
                    </div>
                    <div class="bg-slate-50 border border-slate-200 px-4 py-2 rounded-xl text-right">
                        <span class="text-[10px] font-black uppercase text-slate-500 block">Retail Value</span>
                        <span class="text-xs font-black text-emerald-700">₱<?= number_format($total_retail_value, 2) ?></span>
                    </div>
                </div>
            </div>

            <!-- TAB CONTROLS -->
            <div class="flex items-center justify-between flex-wrap gap-3 no-print">
                <div class="inline-flex items-center bg-white p-1.5 rounded-2xl shadow-sm border border-slate-200/90 gap-1.5 flex-wrap">
                    <button type="button" onclick="switchTab('products')" id="tabBtn-products" class="tab-nav-btn flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-black transition-all <?= ($active_tab === 'products') ? 'bg-[#070a12] text-white shadow' : 'text-slate-600 hover:bg-slate-100' ?>">
                        <i class="fas fa-box text-xs"></i>
                        <span>Products (<?= count($products) ?>)</span>
                    </button>
                    <button type="button" onclick="switchTab('reorder')" id="tabBtn-reorder" class="tab-nav-btn flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-black transition-all <?= ($active_tab === 'reorder') ? 'bg-amber-500 text-slate-950 shadow' : 'text-slate-600 hover:bg-slate-100' ?>">
                        <i class="fas fa-boxes-packing text-xs"></i>
                        <span>Reorder List</span>
                        <span id="tabBadgeReorder" class="<?= count($reorder_items) > 0 ? 'bg-red-600 text-white text-[10px] font-black px-2 py-0.5 rounded-full' : 'hidden' ?>">
                            <?= count($reorder_items) ?>
                        </span>
                    </button>
                    <button type="button" onclick="switchTab('requests')" id="tabBtn-requests" class="tab-nav-btn flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-black transition-all <?= ($active_tab === 'requests') ? 'bg-[#070a12] text-white shadow' : 'text-slate-600 hover:bg-slate-100' ?>">
                        <i class="fas fa-shield-halved text-xs"></i>
                        <span>Staff Requests</span>
                        <span id="tabBadgeRequests" class="<?= count($pending_requests) > 0 ? 'bg-amber-500 text-slate-950 text-[10px] font-black px-2 py-0.5 rounded-full' : 'text-slate-400 text-xs' ?>">
                            <?= count($pending_requests) > 0 ? count($pending_requests) : '(0)' ?>
                        </span>
                    </button>
                    <button type="button" onclick="switchTab('history')" id="tabBtn-history" class="tab-nav-btn flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-black transition-all <?= ($active_tab === 'history') ? 'bg-[#070a12] text-white shadow' : 'text-slate-600 hover:bg-slate-100' ?>">
                        <i class="fas fa-clock-rotate-left text-xs"></i>
                        <span>Stock History (<?= count($stock_history) ?>)</span>
                    </button>
                    <button type="button" onclick="switchTab('trash')" id="tabBtn-trash" class="tab-nav-btn flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-black transition-all <?= ($active_tab === 'trash') ? 'bg-[#070a12] text-white shadow' : 'text-slate-600 hover:bg-slate-100' ?>">
                        <i class="fas fa-trash-can text-xs"></i>
                        <span>Trash (<?= count($deleted_products) ?>)</span>
                    </button>
                </div>
            </div>

            <!-- TAB 1: PRODUCT CATALOG -->
            <div id="tabContent-products" class="tab-pane <?= ($active_tab === 'products') ? '' : 'hidden' ?>">
                <div class="bg-white rounded-2xl shadow-sm border border-slate-200/80 overflow-hidden no-print">
                    <div class="p-4 px-5 border-b border-slate-200 flex flex-col md:flex-row justify-between items-start md:items-center gap-3 bg-slate-50">
                        <div>
                            <h3 class="text-xs font-black text-slate-900 uppercase tracking-wider">Product Catalog</h3>
                            <p class="text-[11px] font-bold text-slate-500">Live box to piece conversion, barcode generator, and stock verification.</p>
                        </div>
                        <div class="relative w-full md:w-80">
                            <i class="fas fa-search absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs pointer-events-none"></i>
                            <input type="text" id="inventorySearch" placeholder="Search by name or barcode..." class="pl-9 pr-4 py-2 bg-white border border-slate-300 rounded-xl text-xs font-bold text-slate-800 w-full focus:outline-none focus:ring-2 focus:ring-amber-500">
                        </div>
                    </div>

                    <div class="overflow-x-auto max-h-[620px] overflow-y-auto custom-scroll">
                        <table class="w-full text-left text-xs" id="inventoryTable">
                            <thead class="bg-slate-100 text-slate-900 uppercase font-black border-b border-slate-200 sticky top-0 z-10">
                                <tr>
                                    <th class="p-3.5 text-center w-14">Image</th>
                                    <th class="p-3.5">Name &amp; Barcode</th>
                                    <th class="p-3.5 text-center">Pieces</th>
                                    <th class="p-3.5 text-center bg-amber-50 text-amber-950">Full Boxes</th>
                                    <th class="p-3.5 text-center bg-blue-50 text-blue-950">Loose Pieces</th>
                                    <th class="p-3.5 text-center">Total Stock</th>
                                    <th class="p-3.5 text-right">Price</th>
                                    <th class="p-3.5 text-center w-36">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 font-extrabold text-slate-700" id="inventoryTableBody">
                                <?php foreach($products as $product): 
                                    $barcode_val = !empty($product['barcode']) ? $product['barcode'] : ($product['sku'] ?? 'N/A');
                                    $img_src = getValidProductImageUrl($product['image'] ?? null);
                                    $pcs_box = max(1, (int)$product['pcs_per_box']);
                                    $avail_boxes = floor($product['stock'] / $pcs_box);
                                    $loose_pcs = $product['stock'] % $pcs_box;
                                    $is_low = ($product['stock'] <= $product['min_stock']);
                                ?>
                                <tr class="hover:bg-amber-50/20 transition-colors product-row" id="row-prod-<?= $product['id'] ?>">
                                    <td class="p-3 text-center">
                                        <div class="w-10 h-10 rounded-xl bg-slate-100 border border-slate-200 flex items-center justify-center overflow-hidden mx-auto shadow-xs">
                                            <?php if ($img_src): ?>
                                                <img src="<?= htmlspecialchars($img_src) ?>?v=<?= time() ?>" alt="Product" class="w-full h-full object-cover">
                                            <?php else: ?>
                                                <i class="fas fa-box text-slate-300 text-sm"></i>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="p-3">
                                        <div class="flex items-center gap-2">
                                            <span class="font-black text-slate-900 text-xs p-name"><?= htmlspecialchars($product['product_name']) ?></span>
                                            <span class="text-[9px] font-black uppercase px-1.5 py-0.5 rounded bg-slate-100 text-slate-600">Store #<?= $product['store_id'] ?: 1 ?></span>
                                        </div>
                                        <p class="text-[10px] text-slate-500 font-bold uppercase mt-0.5">
                                            <?= htmlspecialchars($product['category'] ?: 'General') ?> &bull; <span class="font-mono bg-slate-100 px-1 rounded"><?= htmlspecialchars($barcode_val) ?></span>
                                        </p>
                                        <span class="audit-badge-wrap">
                                            <?php if (!empty($product['is_verified'])): ?>
                                                <p class="text-[9px] font-black text-emerald-600 uppercase mt-0.5">
                                                    <i class="fas fa-check-circle"></i> Audited by <?= htmlspecialchars($product['verified_by'] ?? 'Admin') ?>
                                                </p>
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td class="p-3 text-center">
                                        <span class="text-[10px] font-black px-2.5 py-1 rounded-md bg-slate-100 border border-slate-200 text-slate-700">
                                            📦 <?= $pcs_box ?> pcs
                                        </span>
                                    </td>
                                    <td class="p-3 text-center bg-amber-50/40 box-status-col">
                                        <?php if ($avail_boxes <= 0): ?>
                                            <span class="px-2 py-0.5 rounded text-[10px] font-black bg-red-100 text-red-800">0 Boxes</span>
                                        <?php else: ?>
                                            <span class="px-2 py-0.5 rounded text-[10px] font-black bg-emerald-100 text-emerald-900">
                                                📦 <?= number_format($avail_boxes) ?> Boxes
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-3 text-center loose-pieces-col bg-blue-50/30 font-black text-slate-700">
                                        <?= number_format($loose_pcs) ?> pcs
                                    </td>
                                    <td class="p-3 text-center stock-pieces-col">
                                        <span class="px-2.5 py-1 rounded-md text-xs font-black <?= $is_low ? 'bg-red-100 text-red-800' : 'bg-slate-900 text-white' ?>">
                                            <?= number_format($product['stock']) ?> pcs
                                        </span>
                                    </td>
                                    <td class="p-3 text-right font-black text-slate-900 text-xs">
                                        ₱<?= number_format($product['price'], 2) ?>
                                    </td>
                                    <td class="p-3 text-center">
                                        <div class="flex items-center justify-center gap-1.5">
                                            <button type="button" onclick="openBoxReorderModal(<?= $product['id'] ?>)" class="w-8 h-8 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 flex items-center justify-center shadow-xs" title="Reorder Boxes">
                                                <i class="fas fa-boxes-stacked text-xs"></i>
                                            </button>
                                            <button type="button" onclick="openPrintModal(<?= $product['id'] ?>)" class="w-8 h-8 rounded-xl bg-slate-100 text-slate-900 hover:bg-amber-100 hover:text-amber-800 flex items-center justify-center border border-slate-300 transition-colors shadow-xs" title="Print Barcode Tag (Scanner-Ready)">
                                                <i class="fas fa-barcode text-xs"></i>
                                            </button>
                                            <button type="button" onclick="verifyAuditAjax(<?= $product['id'] ?>)" class="w-8 h-8 rounded-xl bg-emerald-50 text-emerald-700 hover:bg-emerald-100 flex items-center justify-center border border-emerald-200 btn-verify-<?= $product['id'] ?>" title="Audit Verification">
                                                <i class="fas <?= !empty($product['is_verified']) ? 'fa-check-double' : 'fa-check' ?> text-xs"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; if(empty($products)): ?>
                                    <tr><td colspan="8" class="p-8 text-center text-slate-400 font-bold">No products found in this scope.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- TAB 2: LOW STOCK & CRITICAL REORDER LIST -->
            <div id="tabContent-reorder" class="tab-pane <?= ($active_tab === 'reorder') ? '' : 'hidden' ?>">
                <div class="bg-white rounded-2xl shadow-sm border border-slate-200/90 overflow-hidden">
                    <div class="p-4 px-6 border-b border-slate-200 flex flex-col md:flex-row justify-between items-start md:items-center gap-3 bg-[#fefce8]/60 no-print">
                        <div class="flex items-center space-x-3">
                            <span class="text-xl text-amber-500">🛒</span>
                            <div>
                                <h2 class="text-sm font-black text-slate-900 tracking-wide uppercase">
                                    Reorder List
                                </h2>
                                <p class="text-xs font-bold text-slate-500 mt-0.5">
                                    Low Stock &amp; Purchase Order
                                </p>
                            </div>
                        </div>

                        <div class="flex items-center space-x-2 flex-wrap">
                            <span id="reorderCountBadge" class="bg-[#dc2626] text-white text-xs font-black px-4 py-2 rounded-xl shadow-xs inline-flex items-center">
                                <?= count($reorder_items) ?> Items Needed
                            </span>
                            <button type="button" onclick="printReorderSheet()" class="px-4 py-2 bg-[#0f172a] hover:bg-black text-amber-400 text-xs font-black rounded-xl shadow flex items-center gap-2 transition-all">
                                <i class="fas fa-print"></i> <span>Print PO Slip</span>
                            </button>
                            <button type="button" onclick="exportReorderToExcel()" class="px-4 py-2 bg-[#059669] hover:bg-emerald-700 text-white text-xs font-black rounded-xl shadow flex items-center gap-2 transition-all">
                                <i class="fas fa-file-excel"></i> <span>Excel</span>
                            </button>
                            <button type="button" onclick="exportReorderToDoc()" class="px-4 py-2 bg-[#2563eb] hover:bg-blue-700 text-white text-xs font-black rounded-xl shadow flex items-center gap-2 transition-all">
                                <i class="fas fa-file-word"></i> <span>Word</span>
                            </button>
                        </div>
                    </div>

                    <div class="overflow-x-auto max-h-[600px] overflow-y-auto custom-scroll">
                        <table class="w-full text-left text-xs border-collapse" id="reorderTable">
                            <thead class="bg-[#f8fafc] text-slate-800 uppercase font-black text-[11px] border-b border-slate-200 sticky top-0 z-10 tracking-wider">
                                <tr>
                                    <th class="p-3.5 font-black text-slate-800">Product Name</th>
                                    <th class="p-3.5 text-center font-black text-slate-800">Pcs</th>
                                    <th class="p-3.5 text-center font-black text-slate-800">Boxes Left</th>
                                    <th class="p-3.5 text-center font-black text-slate-800">Stock Left</th>
                                    <th class="p-3.5 text-center font-black text-amber-950 bg-[#fef9c3]">Suggested Order</th>
                                    <th class="p-3.5 text-center font-black text-slate-800 no-print">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 font-extrabold" id="reorderTableBody">
                                <?php foreach($reorder_items as $item): ?>
                                <tr class="hover:bg-amber-50/30 transition-colors" id="reorder-row-<?= $item['id'] ?>">
                                    <td class="p-3.5">
                                        <p class="text-xs font-black text-slate-900"><?= htmlspecialchars($item['product_name']) ?></p>
                                        <p class="text-[10px] text-slate-500 font-bold uppercase mt-0.5">
                                            Store #<?= $item['store_id'] ?: 1 ?> &bull; <?= htmlspecialchars($item['category'] ?: 'General') ?>
                                        </p>
                                    </td>
                                    
                                    <td class="p-3.5 text-center">
                                        <span class="bg-slate-100 text-slate-700 px-2.5 py-1 rounded-lg border border-slate-200 text-xs font-black">
                                            <?= $item['pcs_per_box'] ?> pcs
                                        </span>
                                    </td>

                                    <td class="p-3.5 text-center reorder-boxes-count">
                                        <?php if ($item['boxes_remaining'] == 0): ?>
                                            <span class="bg-red-100 text-red-800 px-2.5 py-0.5 rounded-lg text-xs font-black uppercase">
                                                0 Boxes
                                            </span>
                                        <?php else: ?>
                                            <span class="bg-emerald-100 text-emerald-900 px-2.5 py-0.5 rounded-lg text-xs font-black uppercase">
                                                📦 <?= $item['boxes_remaining'] ?> Boxes
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="p-3.5 text-center font-black text-red-600 reorder-current-stock text-xs">
                                        <?= number_format($item['stock']) ?> pcs
                                    </td>

                                    <td class="p-3.5 text-center bg-[#fef9c3]/50">
                                        <div class="inline-flex flex-col items-center">
                                            <span class="text-xs font-black text-amber-950">
                                                📦 <?= $item['boxes_to_order'] ?> Boxes
                                            </span>
                                            <span class="text-[10px] font-bold text-amber-800">
                                                (<?= number_format($item['suggested_units']) ?> pcs)
                                            </span>
                                        </div>
                                    </td>

                                    <td class="p-3.5 text-center no-print">
                                        <form onsubmit="submitInlineBoxReorder(event, <?= $item['id'] ?>)" class="flex items-center justify-center gap-2">
                                            <input type="number" id="inlineBoxInput-<?= $item['id'] ?>" value="<?= max(1, $item['boxes_to_order']) ?>" min="1" max="1000" class="custom-input py-1 text-center w-20 text-xs font-black" required>
                                            <button type="submit" class="bg-amber-500 hover:bg-amber-600 text-slate-950 font-black px-3.5 py-1.5 rounded-xl text-xs uppercase shadow transition-all">
                                                + Add
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; if(empty($reorder_items)): ?>
                                    <tr id="emptyReorderRow">
                                        <td colspan="6" class="p-8 text-center text-emerald-700 font-bold bg-emerald-50">
                                            <i class="fas fa-check-circle mr-1"></i> All stock levels are sufficient. No items require reordering at this time.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- TAB 3: STAFF REQUESTS -->
            <div id="tabContent-requests" class="tab-pane <?= ($active_tab === 'requests') ? '' : 'hidden' ?>">
                <div class="bg-white rounded-2xl shadow-sm border border-slate-200/80 p-6 space-y-4 no-print">
                    <div class="flex justify-between items-center border-b border-slate-200 pb-3">
                        <div>
                            <h3 class="text-xs font-black text-slate-900 uppercase tracking-wider flex items-center gap-2">
                                <i class="fas fa-shield-halved text-amber-500"></i> Pending Staff Requests
                            </h3>
                            <p class="text-[11px] font-bold text-slate-500">Admin password is required to approve item changes or removals.</p>
                        </div>
                        <span id="requestsHeaderBadge" class="bg-amber-100 text-amber-900 text-xs font-black px-3 py-1 rounded-xl">
                            <?= count($pending_requests) ?> PENDING
                        </span>
                    </div>

                    <div class="space-y-3 max-h-[480px] overflow-y-auto custom-scroll" id="pendingRequestsContainer">
                        <?php foreach($pending_requests as $pr): 
                            $payload = json_decode($pr['payload'] ?? '{}', true);
                            $pname = $payload['name'] ?? $payload['product_name'] ?? 'Item #' . $pr['product_id'];
                        ?>
                        <div class="p-4 bg-slate-50 rounded-2xl border border-slate-200 space-y-3" id="req-card-<?= $pr['id'] ?>">
                            <div class="flex justify-between items-start">
                                <div>
                                    <span class="px-2.5 py-0.5 rounded text-[9px] font-black uppercase <?= $pr['request_type'] === 'DELETE' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-800' ?>">
                                        <?= htmlspecialchars($pr['request_type']) ?> REQUEST
                                    </span>
                                    <h4 class="text-sm font-black text-slate-900 mt-1"><?= htmlspecialchars($pname) ?></h4>
                                    <p class="text-[10px] text-slate-500 font-bold">Requested by: <b><?= htmlspecialchars($pr['requested_by'] ?? 'Staff') ?></b> on <?= date('M d, Y h:i A', strtotime($pr['created_at'])) ?></p>
                                </div>
                            </div>

                            <div class="flex items-center gap-3 pt-1">
                                <form onsubmit="submitAjaxApprove(event, <?= $pr['id'] ?>)" class="flex-1 flex gap-2">
                                    <input type="password" id="adminPass-<?= $pr['id'] ?>" placeholder="Enter Admin Password to approve" required class="custom-input py-1.5 text-xs flex-1">
                                    <button type="submit" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-black uppercase shadow">
                                        <i class="fas fa-check"></i> Approve
                                    </button>
                                </form>
                                <button type="button" onclick="submitAjaxReject(<?= $pr['id'] ?>)" class="px-3.5 py-2 bg-slate-200 hover:bg-slate-300 text-slate-700 rounded-xl text-xs font-black uppercase">
                                    <i class="fas fa-times"></i> Reject
                                </button>
                            </div>
                        </div>
                        <?php endforeach; if(empty($pending_requests)): ?>
                            <p class="text-center py-10 text-slate-400 font-bold text-xs" id="emptyRequestsMsg">No pending requests from staff.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- TAB 4: STOCK HISTORY -->
            <div id="tabContent-history" class="tab-pane <?= ($active_tab === 'history') ? '' : 'hidden' ?>">
                <div class="bg-white rounded-2xl shadow-sm border border-slate-200/80 overflow-hidden no-print">
                    <div class="p-4 px-5 border-b border-slate-200 bg-slate-50 flex flex-col md:flex-row justify-between items-start md:items-center gap-3">
                        <div>
                            <h3 class="text-xs font-black text-slate-900 uppercase tracking-wider flex items-center gap-2">
                                <i class="fas fa-clock-rotate-left text-amber-500"></i> Stock Movement &amp; Audit Logs
                            </h3>
                            <p class="text-[11px] font-bold text-slate-500">All restocks, staff damage deductions, and audit verifications across stores.</p>
                        </div>
                        <div class="flex items-center gap-2 w-full md:w-auto">
                            <div class="relative w-full md:w-64">
                                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs pointer-events-none"></i>
                                <input type="text" id="historySearch" placeholder="Filter history logs..." class="pl-8 pr-3 py-1.5 bg-white border border-slate-300 rounded-xl text-xs font-bold text-slate-800 w-full focus:outline-none focus:ring-2 focus:ring-amber-500">
                            </div>
                            <span class="px-3 py-1.5 bg-slate-900 text-white rounded-xl text-xs font-black uppercase whitespace-nowrap shadow-xs">
                                <?= count($stock_history) ?> Records
                            </span>
                        </div>
                    </div>
                    
                    <div class="overflow-x-auto max-h-[620px] overflow-y-auto custom-scroll">
                        <table class="w-full text-left text-xs" id="historyTable">
                            <thead class="bg-slate-100 text-slate-800 uppercase font-black border-b border-slate-200 sticky top-0 z-10 shadow-xs">
                                <tr>
                                    <th class="p-3.5">Date &amp; Time</th>
                                    <th class="p-3.5">Product &amp; Store</th>
                                    <th class="p-3.5 text-center">Actor / User</th>
                                    <th class="p-3.5 text-center">Type</th>
                                    <th class="p-3.5 text-center">Quantity</th>
                                    <th class="p-3.5 text-center">Stock After</th>
                                    <th class="p-3.5">Description / Remarks</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 font-extrabold text-slate-700" id="historyTableBody">
                                <?php foreach($stock_history as $h): 
                                    $type_up = strtoupper(trim($h['type']));
                                    $is_pos = in_array($type_up, ['IN', 'INITIAL', 'RESTOCK']);
                                    $is_audit = in_array($type_up, ['AUDIT', 'VERIFY']);
                                    $qty_val = (int)$h['quantity'];
                                    $remark_text = $h['notes'] ?: ($h['remarks'] ?: 'Stock adjustment record');
                                ?>
                                <tr class="hover:bg-slate-50 transition-colors history-row">
                                    <td class="p-3.5 text-slate-500 font-bold whitespace-nowrap">
                                        <?= date('M d, Y h:i A', strtotime($h['created_at'])) ?>
                                    </td>
                                    
                                    <td class="p-3.5">
                                        <div class="flex items-center gap-1.5">
                                            <span class="font-black text-slate-900 text-xs"><?= htmlspecialchars($h['clean_name']) ?></span>
                                            <span class="text-[9px] font-black uppercase px-1.5 py-0.5 rounded bg-slate-100 text-slate-600">
                                                Store #<?= $h['resolved_store_id'] ?>
                                            </span>
                                        </div>
                                    </td>

                                    <td class="p-3.5 text-center">
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-slate-100 text-slate-700 border border-slate-200">
                                            <?= htmlspecialchars($h['logged_by_name'] ?? 'User') ?>
                                        </span>
                                    </td>

                                    <td class="p-3.5 text-center">
                                        <?php if ($is_audit): ?>
                                            <span class="px-2 py-0.5 rounded text-[10px] font-black uppercase bg-blue-100 text-blue-800">
                                                AUDIT
                                            </span>
                                        <?php else: ?>
                                            <span class="px-2 py-0.5 rounded text-[10px] font-black uppercase <?= $is_pos ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800' ?>">
                                                <?= htmlspecialchars($type_up) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="p-3.5 text-center font-black">
                                        <?php if ($qty_val == 0): ?>
                                            <span class="text-slate-400">0 pcs</span>
                                        <?php elseif ($is_pos): ?>
                                            <span class="text-emerald-700">+<?= number_format(abs($qty_val)) ?> pcs</span>
                                        <?php else: ?>
                                            <span class="text-red-700">-<?= number_format(abs($qty_val)) ?> pcs</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="p-3.5 text-center font-black text-slate-900">
                                        <?= number_format($h['stock_after']) ?> pcs
                                    </td>

                                    <td class="p-3.5 text-slate-600 font-bold">
                                        <?= htmlspecialchars($remark_text) ?>
                                    </td>
                                </tr>
                                <?php endforeach; if(empty($stock_history)): ?>
                                    <tr><td colspan="7" class="p-10 text-center text-slate-400 font-bold">No stock movement records found in this scope.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- TAB 5: TRASH -->
            <div id="tabContent-trash" class="tab-pane <?= ($active_tab === 'trash') ? '' : 'hidden' ?>">
                <div class="bg-white rounded-2xl shadow-sm border border-slate-200/80 overflow-hidden no-print">
                    <div class="p-4 px-5 border-b border-slate-200 bg-slate-50">
                        <h3 class="text-xs font-black text-slate-900 uppercase tracking-wider">Trash Recovery Bin</h3>
                        <p class="text-[11px] font-bold text-slate-500">Admins can restore deleted items back to the active catalog.</p>
                    </div>
                    <div class="overflow-x-auto max-h-[600px] overflow-y-auto custom-scroll">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-slate-100 text-slate-800 uppercase font-black border-b border-slate-200">
                                <tr>
                                    <th class="p-3.5">Product Name</th>
                                    <th class="p-3.5">Category</th>
                                    <th class="p-3.5 text-center">Last Stock</th>
                                    <th class="p-3.5 text-right">Price</th>
                                    <th class="p-3.5 text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 font-extrabold" id="trashTableBody">
                                <?php foreach($deleted_products as $dp): ?>
                                <tr class="hover:bg-slate-50" id="trash-row-<?= $dp['id'] ?>">
                                    <td class="p-3.5 text-slate-900 font-black"><?= htmlspecialchars($dp['product_name']) ?></td>
                                    <td class="p-3.5 text-slate-500 uppercase"><?= htmlspecialchars($dp['category'] ?: 'General') ?></td>
                                    <td class="p-3.5 text-center font-black"><?= number_format($dp['stock']) ?> pcs</td>
                                    <td class="p-3.5 text-right font-black">₱<?= number_format($dp['price'], 2) ?></td>
                                    <td class="p-3.5 text-center">
                                        <button type="button" onclick="submitAjaxRestore(<?= $dp['id'] ?>)" class="px-3.5 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-black uppercase shadow">
                                            <i class="fas fa-rotate-left"></i> Restore Item
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; if(empty($deleted_products)): ?>
                                    <tr><td colspan="5" class="p-8 text-center text-slate-400 font-bold">Trash bin is empty.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </main>
    </div>

    <!-- AI CHATBOT WIDGET -->
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
                        <p class="text-[10px] font-bold text-slate-400 mt-0.5">Inventory &amp; Stock Intelligence Active</p>
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
                <button type="button" onclick="sendAiQuickPrompt('Ilista ang mga low stock o kailangang i-reorder')" class="bg-white hover:bg-slate-100 hover:border-slate-300 border border-slate-200/90 px-3 py-1 rounded-lg transition-all shrink-0 shadow-2xs">
                    ⚠️ Low Stocks &amp; Reorder
                </button>
                <button type="button" onclick="sendAiQuickPrompt('Ilan ang available full boxes at loose pieces?')" class="bg-white hover:bg-slate-100 hover:border-slate-300 border border-slate-200/90 px-3 py-1 rounded-lg transition-all shrink-0 shadow-2xs">
                    📦 Box Calculator
                </button>
                <button type="button" onclick="sendAiQuickPrompt('I-kumpara ang stocks ng Store 1 at Store 2')" class="bg-white hover:bg-slate-100 hover:border-slate-300 border border-slate-200/90 px-3 py-1 rounded-lg transition-all shrink-0 shadow-2xs">
                    🏪 Store 1 vs Store 2
                </button>
                <button type="button" onclick="sendAiQuickPrompt('Magkano ang kabuuang Inventory Value at Retail Value?')" class="bg-white hover:bg-slate-100 hover:border-slate-300 border border-slate-200/90 px-3 py-1 rounded-lg transition-all shrink-0 shadow-2xs">
                    💰 Stock Valuation
                </button>
                <button type="button" onclick="sendAiQuickPrompt('May pending ba na staff requests para sa edit o delete?')" class="bg-white hover:bg-slate-100 hover:border-slate-300 border border-slate-200/90 px-3 py-1 rounded-lg transition-all shrink-0 shadow-2xs">
                    🛡️ Pending Requests
                </button>
            </div>

            <div id="aiChatMessages" class="flex-1 p-4 overflow-y-auto custom-scroll space-y-3.5 bg-[#f8fafc] text-xs">
                <div class="flex items-start gap-2.5">
                    <div class="w-7 h-7 rounded-lg bg-[#0f172a] text-amber-500 flex items-center justify-center text-[11px] font-black shrink-0 border border-slate-700">
                        AI
                    </div>
                    <div class="bg-white p-3.5 rounded-2xl rounded-tl-sm border border-slate-200/90 text-slate-800 shadow-2xs leading-relaxed max-w-[88%]">
                        Magandang araw! Ako ang inyong <b>JJ Enterprise AI Assistant</b>. Naka-link ako sa imbentaryo ng <b><?= ($selected_store === 'both') ? 'Both Stores' : 'Store ' . $selected_store ?></b>. May <?= count($reorder_items) ?> aytem na nasa critical reorder level ngayon. Paano ko kayo matutulungan?
                    </div>
                </div>
            </div>

            <form id="aiChatForm" class="p-3 bg-white border-t border-slate-200 flex items-center gap-2">
                <input type="text" id="aiChatInput" placeholder="Magtanong tungkol sa box conversions, reorder, o stocks..." class="flex-1 bg-slate-50 focus:bg-white border border-slate-300 focus:border-amber-500 text-slate-800 text-xs rounded-xl px-3.5 py-2.5 outline-none font-semibold transition-all">
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

    <!-- ======================================================== -->
    <!-- PURCHASE ORDER & REPLENISHMENT FORM                      -->
    <!-- (CLEAN OFFICIAL ORDER SLIP - PRODUCT ONLY, NO BARCODE)   -->
    <!-- ======================================================== -->
    <div id="reorderPrintArea" style="display:none; font-family: Arial, Helvetica, sans-serif; color: #000;">
        
        <!-- P.O. HEADER & STORE IDENTITY -->
        <div style="border-bottom: 2px solid #000; padding-bottom: 8px; margin-bottom: 12px; display: flex; justify-content: space-between; align-items: flex-start;">
            <div>
                <h1 style="font-size: 19px; font-weight: 900; margin: 0; text-transform: uppercase; letter-spacing: 0.5px; line-height: 1.1;">
                    <?= htmlspecialchars($settings['store_name']) ?>
                </h1>
                <p style="font-size: 11px; margin: 2px 0 0; font-weight: 700; text-transform: uppercase;">
                    Purchase Order Slip
                </p>
                <p style="font-size: 10px; margin: 2px 0 0; color: #333;">
                    Branch: <b><?= ($selected_store === 'both') ? 'Store 1 &amp; Store 2' : 'Store #' . $selected_store ?></b>
                </p>
            </div>
            <div style="text-align: right; min-width: 220px;">
                <div style="display: inline-block; border: 1.5px solid #000; padding: 4px 10px; text-align: right; background-color: #f8fafc;">
                    <div style="font-size: 9px; font-weight: bold; text-transform: uppercase; color: #555;">Order Slip No.</div>
                    <div style="font-size: 13px; font-weight: 900; font-family: monospace; letter-spacing: 0.5px;"><?= $po_ref_number ?></div>
                </div>
                <div style="font-size: 10px; font-weight: bold; margin-top: 3px;">Date: <?= date('M d, Y h:i A') ?></div>
            </div>
        </div>

        <!-- ORDER INFORMATION MATRIX -->
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 12px; font-size: 10px;">
            <tr>
                <td style="width: 50%; vertical-align: top; padding-right: 12px;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <tr>
                            <td style="width: 110px; font-weight: bold; text-transform: uppercase; color: #444;">Supplier:</td>
                            <td style="border-bottom: 1px dotted #000; font-weight: bold;"><?= !empty($settings['supplier_name']) ? htmlspecialchars($settings['supplier_name']) : 'AUTHORIZED DISTRIBUTOR' ?></td>
                        </tr>
                        <tr>
                            <td style="font-weight: bold; text-transform: uppercase; color: #444; padding-top: 4px;">Delivery Address:</td>
                            <td style="border-bottom: 1px dotted #000; font-weight: bold; padding-top: 4px;">Main Receiving Bay / Warehouse</td>
                        </tr>
                        <tr>
                            <td style="font-weight: bold; text-transform: uppercase; color: #444; padding-top: 4px;">Terms:</td>
                            <td style="border-bottom: 1px dotted #000; font-weight: bold; padding-top: 4px;">COD / Terms</td>
                        </tr>
                    </table>
                </td>
                <td style="width: 50%; vertical-align: top; padding-left: 12px;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <tr>
                            <td style="width: 110px; font-weight: bold; text-transform: uppercase; color: #444;">Status:</td>
                            <td style="border-bottom: 1px dotted #000; font-weight: 900; color: #000;">APPROVED ORDER</td>
                        </tr>
                        <tr>
                            <td style="font-weight: bold; text-transform: uppercase; color: #444; padding-top: 4px;">Target Delivery:</td>
                            <td style="border-bottom: 1px dotted #000; font-weight: bold; padding-top: 4px;">24 to 48 Hours</td>
                        </tr>
                        <tr>
                            <td style="font-weight: bold; text-transform: uppercase; color: #444; padding-top: 4px;">Requested By:</td>
                            <td style="border-bottom: 1px dotted #000; font-weight: bold; padding-top: 4px;"><?= htmlspecialchars($currentUserFullname) ?> (<?= htmlspecialchars($currentUserRole) ?>)</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <!-- PURCHASE ORDER ITEMS TABLE -->
        <table class="po-table" style="width: 100%; border-collapse: collapse; font-size: 10px; text-align: left;">
            <thead>
                <tr style="background-color: #f1f5f9; font-weight: 900; text-transform: uppercase; text-align: center;">
                    <th style="padding: 6px 4px; width: 32px;">#</th>
                    <th style="padding: 6px 6px; text-align: left;">Product Name</th>
                    <th style="padding: 6px 4px; width: 60px;">Store</th>
                    <th style="padding: 6px 4px; width: 65px;">Pcs </th>
                    <th style="padding: 6px 4px; width: 65px;">Stock Left</th>
                    <th style="padding: 6px 4px; width: 75px; background-color: #fef08a;">Order Boxes</th>
                    <th style="padding: 6px 4px; width: 65px;">Total Pcs</th>
                    <th style="padding: 6px 4px; width: 75px; text-align: right;">Unit Cost</th>
                    <th style="padding: 6px 4px; width: 85px; text-align: right;">Total Cost</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $line_num = 1; 
                foreach($reorder_items as $itm): 
                ?>
                <tr style="page-break-inside: avoid;">
                    <td style="padding: 5px 3px; text-align: center; font-weight: bold;"><?= $line_num++ ?></td>
                    <td style="padding: 5px 6px; text-align: left;">
                        <span style="font-weight: 900; text-transform: uppercase; font-size: 10.5px;"><?= htmlspecialchars($itm['product_name']) ?></span>
                        <span style="font-size: 8.5px; color: #555; display: block;"><?= htmlspecialchars($itm['category'] ?: 'General') ?></span>
                    </td>
                    <td style="padding: 5px 3px; text-align: center; font-weight: bold;">
                        Store #<?= $itm['store_id'] ?: 1 ?>
                    </td>
                    <td style="padding: 5px 3px; text-align: center; font-weight: bold;">
                        <?= $itm['pcs_per_box'] ?> pcs
                    </td>
                    <td style="padding: 5px 3px; text-align: center; color: #b91c1c; font-weight: bold;">
                        <?= number_format($itm['stock']) ?> pcs
                    </td>
                    <td style="padding: 5px 3px; text-align: center; font-weight: 900; background-color: #fef9c3; font-size: 11px;">
                        <?= number_format($itm['boxes_to_order']) ?> Boxes
                    </td>
                    <td style="padding: 5px 3px; text-align: center; font-weight: bold;">
                        <?= number_format($itm['suggested_units']) ?> pcs
                    </td>
                    <td style="padding: 5px 4px; text-align: right; font-family: monospace;">
                        ₱<?= number_format($itm['est_unit_cost'], 2) ?>
                    </td>
                    <td style="padding: 5px 4px; text-align: right; font-weight: 900; font-family: monospace;">
                        ₱<?= number_format($itm['est_line_cost'], 2) ?>
                    </td>
                </tr>
                <?php endforeach; if(empty($reorder_items)): ?>
                <tr>
                    <td colspan="9" style="padding: 20px; text-align: center; font-weight: bold; font-size: 11px;">
                        ALL PRODUCTS ARE IN STOCK. ZERO REORDER REQUIRED.
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr style="background-color: #f8fafc; font-weight: 900;">
                    <td colspan="5" style="padding: 7px 6px; text-align: right; text-transform: uppercase;">
                        Order Summary:
                    </td>
                    <td style="padding: 7px 3px; text-align: center; font-size: 11.5px; background-color: #fef08a;">
                        <?= number_format($po_total_boxes) ?> Boxes
                    </td>
                    <td style="padding: 7px 3px; text-align: center; font-size: 11px;">
                        <?= number_format($po_total_pieces) ?> Pcs
                    </td>
                    <td style="padding: 7px 4px; text-align: right; font-size: 9.5px; text-transform: uppercase;">
                        Total Cost:
                    </td>
                    <td style="padding: 7px 4px; text-align: right; font-size: 12px; font-family: monospace;">
                        ₱<?= number_format($po_total_est_cost, 2) ?>
                    </td>
                </tr>
            </tfoot>
        </table>

        <!-- RECEIVING INSTRUCTIONS -->
        <div style="margin-top: 10px; font-size: 8.5px; color: #333; line-height: 1.35; border: 1px solid #cbd5e1; padding: 6px 8px; background-color: #f8fafc;">
            <b>Instructions:</b> Check all delivered quantities, pack counts, and dates. Attach this official purchase slip to the delivery receipt upon arrival.
        </div>

        <!-- 4-WAY SIGN-OFF BLOCK -->
        <div style="margin-top: 28px; display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; font-size: 10px; text-align: center;">
            <div>
                <p style="margin: 0 0 32px 0; font-size: 9px; font-weight: bold; text-transform: uppercase; color: #555;">Prepared By:</p>
                <div style="border-top: 1.5px solid #000; padding-top: 3px; font-weight: 900; text-transform: uppercase;">
                    <?= htmlspecialchars($currentUserFullname) ?>
                </div>
                <div style="font-size: 8.5px; color: #555;">Store Admin</div>
            </div>

            <div>
                <p style="margin: 0 0 32px 0; font-size: 9px; font-weight: bold; text-transform: uppercase; color: #555;">Checked By:</p>
                <div style="border-top: 1.5px solid #000; padding-top: 3px; font-weight: 900; text-transform: uppercase;">
                    Stock Auditor
                </div>
                <div style="font-size: 8.5px; color: #555;">Inventory Audit</div>
            </div>

            <div>
                <p style="margin: 0 0 32px 0; font-size: 9px; font-weight: bold; text-transform: uppercase; color: #555;">Approved By:</p>
                <div style="border-top: 1.5px solid #000; padding-top: 3px; font-weight: 900; text-transform: uppercase;">
                    Store Manager
                </div>
                <div style="font-size: 8.5px; color: #555;">Management</div>
            </div>

            <div>
                <p style="margin: 0 0 32px 0; font-size: 9px; font-weight: bold; text-transform: uppercase; color: #555;">Received By:</p>
                <div style="border-top: 1.5px solid #000; padding-top: 3px; font-weight: 900; text-transform: uppercase;">
                    Supplier Signature
                </div>
                <div style="font-size: 8.5px; color: #555;">Distributor / Driver</div>
            </div>
        </div>
    </div>

    <!-- 48MM BARCODE CONTAINER -->
    <div id="barcodePrintContainer" style="display:none;"></div>

    <!-- MODAL: REORDER BY BOX -->
    <div id="boxReorderModal" class="modal">
        <div class="bg-white text-slate-800 w-full max-w-md rounded-3xl shadow-2xl border border-slate-200 overflow-hidden p-6">
            <div class="flex justify-between items-start mb-4 border-b border-slate-200 pb-3">
                <div>
                    <h2 class="text-xs font-black text-amber-600 uppercase flex items-center gap-1.5 tracking-wider">
                        <i class="fas fa-boxes-stacked"></i> Reorder by Box
                    </h2>
                    <p id="boxProdName" class="text-sm font-black text-slate-900 uppercase mt-0.5"></p>
                </div>
                <button onclick="toggleModal('boxReorderModal')" class="text-slate-400 hover:text-slate-600 font-black"><i class="fas fa-xmark"></i></button>
            </div>
            <form id="ajaxBoxReorderForm" onsubmit="submitAjaxBoxReorder(event)" class="space-y-4">
                <input type="hidden" name="action" value="reorder_box">
                <input type="hidden" name="id" id="boxReorderId">
                <input type="hidden" name="ajax" value="1">
                <div class="p-3.5 bg-slate-50 rounded-2xl border border-slate-200 text-xs font-bold text-slate-700 space-y-1.5">
                    <p>• Items per Box: <b id="boxPcsPerBox" class="text-slate-950 font-black">1</b> pcs</p>
                    <p>• Boxes in Stock: <b id="boxRemainingCount" class="text-slate-950 font-black">0</b> Boxes</p>
                    <p>• Current Total Stock: <b id="boxCurrentStock" class="text-slate-950 font-black">0</b> pcs</p>
                </div>
                <div>
                    <label class="text-[11px] font-black uppercase text-slate-600 block mb-1">Number of Boxes:</label>
                    <input type="number" name="box_quantity" id="boxQtyInput" min="1" max="1000" required class="custom-input text-base font-black text-center" value="1" oninput="updateBoxCalculation()">
                    <p class="text-[11px] font-bold text-emerald-700 mt-1.5" id="boxCalcPreview">= Add 0 pieces to inventory.</p>
                </div>
                <button type="submit" id="btnSubmitBoxOrder" class="w-full py-3 rounded-xl font-black text-slate-950 text-xs uppercase bg-amber-500 hover:bg-amber-600 transition-colors shadow">
                    Confirm Restock
                </button>
            </form>
        </div>
    </div>

    <!-- MODAL: PRINT BARCODE TAG (NAME, STORE, PRICE, BARCODE ONLY) -->
    <div id="printModal" class="modal">
        <div class="bg-white text-slate-800 w-full max-w-md rounded-3xl shadow-2xl border border-slate-200 p-6 text-center">
            <div class="flex justify-between items-center mb-4 border-b border-slate-200 pb-3 no-print">
                <div class="text-left">
                    <h3 class="text-xs font-black uppercase text-slate-900 tracking-wider">Barcode Tag</h3>
                </div>
                <button onclick="toggleModal('printModal')" class="text-slate-400 hover:text-slate-600 font-black p-1">
                    <i class="fas fa-xmark text-base"></i>
                </button>
            </div>

            <!-- PREVIEW CARD (STORE, PRODUCT NAME, PRICE, BARCODE ONLY) -->
            <div id="singleTagPreviewCard" class="flex flex-col items-center bg-white p-3.5 rounded-2xl border-2 border-slate-200 shadow-inner w-[240px] mx-auto">
                <p class="text-[9px] font-black tracking-widest text-slate-400 uppercase mb-0.5"><?= htmlspecialchars($settings['store_name']) ?></p>
                <h4 id="printProductName" class="text-xs font-black text-black uppercase mb-0.5 max-w-[210px] truncate leading-tight">PRODUCT</h4>
                <p id="printProductPrice" class="text-sm font-black text-black mb-1.5 font-mono">₱0.00</p>
                
                <div class="bg-white p-1 rounded flex justify-center w-full overflow-hidden">
                    <svg id="printBarcodeSVG" class="barcode-svg"></svg>
                </div>
            </div>

            <!-- CONTROLS & PRINT COPIES -->
            <div class="mt-5 space-y-3 no-print">
                <div class="flex items-center justify-between bg-slate-50 p-2.5 rounded-xl border border-slate-200 text-xs font-bold text-slate-700">
                    <label for="barcodeCopiesInput" class="font-black text-slate-900 text-xs">Number of Copies:</label>
                    <div class="flex items-center gap-1.5">
                        <button type="button" onclick="adjustCopies(-1)" class="w-7 h-7 bg-white border border-slate-300 rounded-lg font-black hover:bg-slate-100">-</button>
                        <input type="number" id="barcodeCopiesInput" min="1" max="100" value="1" class="w-12 text-center py-1 bg-white border border-slate-300 rounded-lg font-black text-xs">
                        <button type="button" onclick="adjustCopies(1)" class="w-7 h-7 bg-white border border-slate-300 rounded-lg font-black hover:bg-slate-100">+</button>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <button onclick="printBarcodeLabels()" class="flex-1 py-3 bg-[#070a12] hover:bg-black text-amber-400 rounded-xl font-black text-xs uppercase shadow flex items-center justify-center gap-2 transition-all">
                        <i class="fas fa-print text-sm"></i> Print 
                    </button>
                    <button onclick="toggleModal('printModal')" class="px-4 py-3 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl font-black text-xs uppercase">
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- JAVASCRIPT LOGIC -->
    <script>
        const productsMap = <?= json_encode(array_column($products, null, 'id'), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG) ?>;
        const reorderList = <?= json_encode($reorder_items, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG) ?>;
        const currentStore = <?= json_encode($selected_store) ?>;
        const totalUnitsCount = <?= (int)$total_units_count ?>;
        const totalCostVal = <?= (float)$total_cost_value ?>;
        const totalRetailVal = <?= (float)$total_retail_value ?>;
        const storeScopeName = <?= json_encode(($selected_store === 'both') ? 'Both Stores' : 'Store ' . $selected_store) ?>;
        const storeBrandName = <?= json_encode($settings['store_name']) ?>;

        let activePrintProduct = null;

        const sidebar = document.getElementById('sidebar');
        document.getElementById('toggleSidebar').addEventListener('click', () => {
            sidebar.classList.toggle('w-64');
            sidebar.classList.toggle('w-16');
            document.querySelectorAll('.sidebar-text').forEach(s => s.classList.toggle('hidden'));
        });

        function sendUserHeartbeat() {
            fetch(`inventory.php?store_id=${encodeURIComponent(currentStore)}&action=heartbeat`).catch(() => {});
        }
        sendUserHeartbeat();
        setInterval(sendUserHeartbeat, 10000);

        window.addEventListener('beforeunload', () => {
            if (navigator.sendBeacon) {
                navigator.sendBeacon(`inventory.php?store_id=${encodeURIComponent(currentStore)}&action=set_offline`);
            }
        });

        function switchTab(tabName) {
            document.querySelectorAll('.tab-pane').forEach(el => el.classList.add('hidden'));
            const pane = document.getElementById('tabContent-' + tabName);
            if (pane) pane.classList.remove('hidden');

            document.querySelectorAll('.tab-nav-btn').forEach(btn => {
                btn.className = "tab-nav-btn flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-black transition-all text-slate-600 hover:bg-slate-100";
            });

            const activeBtn = document.getElementById('tabBtn-' + tabName);
            if (activeBtn) {
                if (tabName === 'reorder') {
                    activeBtn.className = "tab-nav-btn flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-black transition-all bg-amber-500 text-slate-950 shadow";
                } else {
                    activeBtn.className = "tab-nav-btn flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-black transition-all bg-[#070a12] text-white shadow";
                }
            }

            const currentUrl = new URL(window.location.href);
            currentUrl.searchParams.set('tab', tabName);
            window.history.pushState({ tab: tabName }, '', currentUrl.toString());
        }

        const initialTab = new URLSearchParams(window.location.search).get('tab');
        if (initialTab && ['products', 'reorder', 'requests', 'history', 'trash'].includes(initialTab)) {
            switchTab(initialTab);
        }

        function showToast(title, message, isError = false) {
            const toast = document.getElementById('toastNotice');
            const toastTitle = document.getElementById('toastTitle');
            const toastMsg = document.getElementById('toastMessage');
            const toastIcon = document.getElementById('toastIcon');

            toastTitle.innerText = title;
            toastMsg.innerText = message;
            if (isError) {
                toastIcon.className = "w-8 h-8 rounded-xl bg-red-600 text-white flex items-center justify-center font-black text-sm shrink-0";
                toastIcon.innerHTML = '<i class="fas fa-triangle-exclamation"></i>';
                toastTitle.className = "text-xs font-black uppercase text-red-400";
            } else {
                toastIcon.className = "w-8 h-8 rounded-xl bg-amber-500 text-slate-950 flex items-center justify-center font-black text-sm shrink-0";
                toastIcon.innerHTML = '<i class="fas fa-check"></i>';
                toastTitle.className = "text-xs font-black uppercase text-amber-400";
            }
            toast.classList.add('show');
            setTimeout(() => toast.classList.remove('show'), 3500);
        }

        function toggleModal(id) {
            const m = document.getElementById(id);
            if (m) m.classList.toggle('active');
        }

        let currentPcsPerBox = 1;
        function openBoxReorderModal(pid) {
            const data = productsMap[pid];
            if (!data) return;
            document.getElementById('boxReorderId').value = data.id;
            document.getElementById('boxProdName').innerText = data.product_name;
            currentPcsPerBox = Math.max(1, parseInt(data.pcs_per_box) || 1);
            const stock = parseInt(data.stock) || 0;

            document.getElementById('boxPcsPerBox').innerText = currentPcsPerBox;
            document.getElementById('boxCurrentStock').innerText = stock.toLocaleString();
            document.getElementById('boxRemainingCount').innerText = Math.floor(stock / currentPcsPerBox);
            document.getElementById('boxQtyInput').value = 1;
            updateBoxCalculation();
            toggleModal('boxReorderModal');
        }

        function updateBoxCalculation() {
            const boxes = parseInt(document.getElementById('boxQtyInput').value) || 0;
            const total = boxes * currentPcsPerBox;
            document.getElementById('boxCalcPreview').innerText = `= Adding ${total.toLocaleString()} pieces (${boxes} Boxes × ${currentPcsPerBox} pcs).`;
        }

        function getOptimalBarcodeWidth(codeLength) {
            if (codeLength <= 6) return 1.6;
            if (codeLength <= 8) return 1.4;
            if (codeLength <= 11) return 1.3;
            if (codeLength <= 14) return 1.15;
            return 1.0;
        }

        // OPEN BARCODE PRINT MODAL
        function openPrintModal(pid) {
            const data = productsMap[pid];
            if (!data) return;
            activePrintProduct = data;

            let codeVal = (data.barcode && data.barcode.trim() !== '') ? data.barcode.trim() : ((data.sku && data.sku.trim() !== '') ? data.sku.trim() : 'SKU-' + pid);
            codeVal = codeVal.replace(/[^A-Za-z0-9\-_.]/g, '');
            if (!codeVal) codeVal = 'PROD-' + pid;

            document.getElementById('printProductName').innerText = data.product_name;
            document.getElementById('printProductPrice').innerText = "₱" + parseFloat(data.price || 0).toLocaleString(undefined, {minimumFractionDigits: 2});
            document.getElementById('barcodeCopiesInput').value = 1;

            toggleModal('printModal');

            setTimeout(() => {
                try {
                    const optWidth = getOptimalBarcodeWidth(codeVal.length);
                    JsBarcode("#printBarcodeSVG", codeVal, {
                        format: "CODE128",
                        lineColor: "#000000",
                        background: "#ffffff",
                        width: optWidth,
                        height: 40,
                        displayValue: true,
                        font: "monospace",
                        fontOptions: "bold",
                        fontSize: 10,
                        textMargin: 3,
                        margin: 4
                    });
                } catch(e) {
                    console.error("Barcode generation error:", e);
                }
            }, 80);
        }

        function adjustCopies(delta) {
            const input = document.getElementById('barcodeCopiesInput');
            let val = parseInt(input.value) || 1;
            val = Math.max(1, Math.min(100, val + delta));
            input.value = val;
        }

        function applyDynamicPrintSize(sizeRule) {
            const styleTag = document.getElementById('dynamicPrintStyle');
            if (styleTag) {
                styleTag.innerHTML = `@page { size: ${sizeRule}; margin: 0mm !important; }`;
            }
        }

        // PRINT ONLY: STORE NAME, PRODUCT NAME, PRICE, BARCODE (NO PCS)
        function printBarcodeLabels() {
            if (!activePrintProduct) return;
            const copies = parseInt(document.getElementById('barcodeCopiesInput').value) || 1;
            const container = document.getElementById('barcodePrintContainer');
            container.innerHTML = '';

            let codeVal = (activePrintProduct.barcode && activePrintProduct.barcode.trim() !== '') 
                ? activePrintProduct.barcode.trim() 
                : ((activePrintProduct.sku && activePrintProduct.sku.trim() !== '') ? activePrintProduct.sku.trim() : 'SKU-' + activePrintProduct.id);
            codeVal = codeVal.replace(/[^A-Za-z0-9\-_.]/g, '');
            if (!codeVal) codeVal = 'PROD-' + activePrintProduct.id;

            const priceFormatted = "₱" + parseFloat(activePrintProduct.price || 0).toLocaleString(undefined, {minimumFractionDigits: 2});
            const optWidth = getOptimalBarcodeWidth(codeVal.length);

            for (let i = 0; i < copies; i++) {
                const tag = document.createElement('div');
                tag.className = 'barcode-label-item';
                tag.innerHTML = `
                    <div style="font-size: 7.5px; font-weight: 900; text-transform: uppercase; color: #444; margin-bottom: 2px; letter-spacing: 0.5px; width: 100%; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                        ${storeBrandName}
                    </div>
                    <div style="font-size: 9.5px; font-weight: 900; color: #000; text-transform: uppercase; line-height: 1.1; max-width: 45mm; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 2px;">
                        ${activePrintProduct.product_name}
                    </div>
                    <div style="font-size: 13px; font-weight: 900; color: #000; font-family: monospace; margin-bottom: 2px;">
                        ${priceFormatted}
                    </div>
                    <div style="background: #ffffff; width: 100%; display: flex; justify-content: center; overflow: hidden;">
                        <svg class="barcode-svg" id="printSvgInst_${i}"></svg>
                    </div>
                `;
                container.appendChild(tag);

                JsBarcode(`#printSvgInst_${i}`, codeVal, {
                    format: "CODE128",
                    lineColor: "#000000",
                    background: "#ffffff",
                    width: optWidth,
                    height: 38,
                    displayValue: true,
                    font: "monospace",
                    fontOptions: "bold",
                    fontSize: 9,
                    textMargin: 2,
                    margin: 4
                });
            }

            applyDynamicPrintSize('48mm auto');
            document.body.classList.remove('print-reorder-active');
            document.body.classList.add('print-barcode-active');

            setTimeout(() => {
                window.print();
            }, 100);
        }

        // TRIGGER OFFICIAL PURCHASE ORDER SLIP PRINTING
        function printReorderSheet() {
            applyDynamicPrintSize('auto');
            document.body.classList.remove('print-barcode-active');
            document.body.classList.add('print-reorder-active');
            window.print();
        }

        window.addEventListener('afterprint', () => {
            document.body.classList.remove('print-barcode-active');
            document.body.classList.remove('print-reorder-active');
            const styleTag = document.getElementById('dynamicPrintStyle');
            if (styleTag) styleTag.innerHTML = '';
        });

        function updateTableRowStock(pid, newStock, availBoxes, loosePcs, isLow) {
            if (productsMap[pid]) productsMap[pid].stock = newStock;
            const row = document.getElementById('row-prod-' + pid);
            if (row) {
                row.querySelector('.stock-pieces-col').innerHTML = `<span class="px-2.5 py-1 rounded-md text-xs font-black ${isLow ? 'bg-red-100 text-red-800' : 'bg-slate-900 text-white'}">${newStock.toLocaleString()} pcs</span>`;
                row.querySelector('.loose-pieces-col').innerText = `${loosePcs.toLocaleString()} pcs`;
                row.querySelector('.box-status-col').innerHTML = availBoxes === 0 ? '<span class="px-2 py-0.5 rounded text-[10px] font-black bg-red-100 text-red-800">0 Boxes</span>' : `<span class="px-2 py-0.5 rounded text-[10px] font-black bg-emerald-100 text-emerald-900">📦 ${availBoxes.toLocaleString()} Boxes</span>`;
            }

            const reorderRow = document.getElementById('reorder-row-' + pid);
            if (reorderRow && !isLow) {
                reorderRow.remove();
                const remaining = document.querySelectorAll('#reorderTableBody tr:not(#emptyReorderRow)').length;
                const badge = document.getElementById('reorderCountBadge');
                const tabBadge = document.getElementById('tabBadgeReorder');
                if (badge) badge.innerText = `${remaining} Items Needed`;
                if (tabBadge) {
                    tabBadge.innerText = remaining;
                    if (remaining === 0) tabBadge.classList.add('hidden');
                }
            }
        }

        function submitAjaxBoxReorder(e) {
            e.preventDefault();
            const form = document.getElementById('ajaxBoxReorderForm');
            const formData = new FormData(form);
            const btn = document.getElementById('btnSubmitBoxOrder');
            btn.disabled = true;

            fetch('inventory.php', { method: 'POST', body: formData, headers: {'X-Requested-With': 'XMLHttpRequest'} })
            .then(r => r.json())
            .then(data => {
                btn.disabled = false;
                if (data.status === 'success') {
                    showToast('Restocked!', data.message);
                    toggleModal('boxReorderModal');
                    updateTableRowStock(data.product_id, data.new_stock, data.avail_boxes, data.loose_pcs, data.is_low);
                } else showToast('Error', data.message, true);
            }).catch(() => { btn.disabled = false; showToast('Error', 'Server connection error.', true); });
        }

        function submitInlineBoxReorder(e, pid) {
            e.preventDefault();
            const boxQty = document.getElementById('inlineBoxInput-' + pid).value || 1;
            const formData = new FormData();
            formData.append('action', 'reorder_box');
            formData.append('id', pid);
            formData.append('box_quantity', boxQty);
            formData.append('ajax', '1');

            fetch('inventory.php', { method: 'POST', body: formData, headers: {'X-Requested-With': 'XMLHttpRequest'} })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    showToast('Restocked!', data.message);
                    updateTableRowStock(data.product_id, data.new_stock, data.avail_boxes, data.loose_pcs, data.is_low);
                } else showToast('Error', data.message, true);
            }).catch(() => showToast('Error', 'Server connection error.', true));
        }

        function verifyAuditAjax(pid) {
            const formData = new FormData();
            formData.append('action', 'verify_stock');
            formData.append('id', pid);
            formData.append('ajax', '1');

            fetch('inventory.php', { method: 'POST', body: formData, headers: {'X-Requested-With': 'XMLHttpRequest'} })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    showToast('Audited!', 'Product stock has been verified.');
                    const btn = document.querySelector('.btn-verify-' + pid);
                    if (btn) btn.innerHTML = '<i class="fas fa-check-double text-xs"></i>';
                    const row = document.getElementById('row-prod-' + pid);
                    if (row) row.querySelector('.audit-badge-wrap').innerHTML = `<p class="text-[9px] font-black text-emerald-600 uppercase mt-0.5"><i class="fas fa-check-circle"></i> Audited by ${data.verified_by}</p>`;
                } else showToast('Error', data.message, true);
            });
        }

        function submitAjaxApprove(e, reqId) {
            e.preventDefault();
            const pass = document.getElementById('adminPass-' + reqId).value;
            const formData = new FormData();
            formData.append('action', 'approve_request');
            formData.append('request_id', reqId);
            formData.append('admin_password', pass);
            formData.append('ajax', '1');

            fetch('inventory.php', { method: 'POST', body: formData, headers: {'X-Requested-With': 'XMLHttpRequest'} })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    showToast('Approved!', data.message);
                    const card = document.getElementById('req-card-' + reqId);
                    if (card) card.remove();
                } else showToast('Error', data.message, true);
            });
        }

        function submitAjaxReject(reqId) {
            const formData = new FormData();
            formData.append('action', 'reject_request');
            formData.append('request_id', reqId);
            formData.append('ajax', '1');

            fetch('inventory.php', { method: 'POST', body: formData, headers: {'X-Requested-With': 'XMLHttpRequest'} })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    showToast('Rejected', data.message);
                    const card = document.getElementById('req-card-' + reqId);
                    if (card) card.remove();
                } else showToast('Error', data.message, true);
            });
        }

        function submitAjaxRestore(pid) {
            const formData = new FormData();
            formData.append('action', 'restore');
            formData.append('id', pid);
            formData.append('ajax', '1');

            fetch('inventory.php', { method: 'POST', body: formData, headers: {'X-Requested-With': 'XMLHttpRequest'} })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    showToast('Restored!', data.message);
                    const trow = document.getElementById('trash-row-' + pid);
                    if (trow) trow.remove();
                } else showToast('Error', data.message, true);
            });
        }

        function exportReorderToExcel() {
            if (!reorderList || reorderList.length === 0) {
                alert("No critical items to export.");
                return;
            }

            let html = `
                <html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
                <head><meta charset="UTF-8"></head>
                <body>
                    <table border="1">
                        <tr>
                            <th colspan="8" style="background:#f59e0b; font-size:15px; font-weight:bold; text-align:center;">
                                <?= addslashes($settings['store_name']) ?> - PURCHASE ORDER
                            </th>
                        </tr>
                        <tr>
                            <th colspan="8" style="text-align:center; font-size:11px;">
                                PO Ref: <?= $po_ref_number ?> | Store: ${storeScopeName} | Date: ${new Date().toLocaleDateString()}
                            </th>
                        </tr>
                        <tr style="background:#f1f5f9; font-weight:bold; text-align:center;">
                            <th>#</th>
                            <th>Product Name</th>
                            <th>Store</th>
                            <th>Pcs </th>
                            <th>Boxes Left</th>
                            <th>Stock Left</th>
                            <th>Order Boxes</th>
                            <th>Total Pcs</th>
                        </tr>
            `;

            reorderList.forEach((item, idx) => {
                html += `
                    <tr>
                        <td align="center">${idx + 1}</td>
                        <td>${item.product_name}</td>
                        <td align="center">Store #${item.store_id || 1}</td>
                        <td align="center">${item.pcs_per_box}</td>
                        <td align="center">${item.boxes_remaining}</td>
                        <td align="center">${item.stock}</td>
                        <td align="center" style="background:#fef08a; font-weight:bold;">${item.boxes_to_order}</td>
                        <td align="center">${item.suggested_units}</td>
                    </tr>
                `;
            });

            html += `</table></body></html>`;

            const blob = new Blob([html], { type: 'application/vnd.ms-excel;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `PO_${new Date().toISOString().slice(0, 10)}.xls`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }

        function exportReorderToDoc() {
            if (!reorderList || reorderList.length === 0) {
                alert("No critical items to export.");
                return;
            }

            let content = `
                <html xmlns:o='urn:schemas-microsoft-com:office:office' xmlns:w='urn:schemas-microsoft-com:office:word' xmlns='http://www.w3.org/TR/REC-html40'>
                <head><meta charset='utf-8'><title>Purchase Order Slip</title>
                <style>
                    body { font-family: Arial, sans-serif; }
                    table { width: 100%; border-collapse: collapse; margin-top: 15px; }
                    th, td { border: 1px solid #333; padding: 7px; font-size: 10pt; }
                    th { background-color: #f2f2f2; font-weight: bold; }
                </style>
                </head>
                <body>
                    <h2 style="text-align:center; margin-bottom:2px;"><?= addslashes($settings['store_name']) ?></h2>
                    <h3 style="text-align:center; margin-top:2px; color:#d97706;">PURCHASE ORDER SLIP</h3>
                    <p style="text-align:center; font-size:9.5pt;">PO Ref: <b><?= $po_ref_number ?></b> &bull; Store: <b>${storeScopeName}</b> &bull; Date: ${new Date().toLocaleDateString()}</p>
                    
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Product Name</th>
                                <th>Store</th>
                                <th>PcsS</th>
                                <th>Boxes Left</th>
                                <th>Stock Left</th>
                                <th>Order Boxes</th>
                                <th>Total Pcs</th>
                            </tr>
                        </thead>
                        <tbody>
            `;

            reorderList.forEach((item, idx) => {
                content += `
                    <tr>
                        <td style="text-align:center;">${idx + 1}</td>
                        <td><b>${item.product_name}</b></td>
                        <td style="text-align:center;">Store #${item.store_id || 1}</td>
                        <td style="text-align:center;">${item.pcs_per_box} pcs</td>
                        <td style="text-align:center;">${item.boxes_remaining} Boxes</td>
                        <td style="text-align:center;">${item.stock} pcs</td>
                        <td style="text-align:center; font-weight:bold; background:#fef9c3;">${item.boxes_to_order} Boxes</td>
                        <td style="text-align:center; font-weight:bold;">${item.suggested_units} pcs</td>
                    </tr>
                `;
            });

            content += `
                        </tbody>
                    </table>
                    <br><br>
                    <table style="border:none; width:100%;">
                        <tr style="border:none;">
                            <td style="border:none; width:33%; text-align:center;">
                                <p>Prepared By:</p><br><br>
                                <b><?= addslashes($currentUserFullname) ?></b><br>
                                <small>Store Admin</small>
                            </td>
                            <td style="border:none; width:33%; text-align:center;">
                                <p>Approved By:</p><br><br>
                                <b>Authorized Manager</b><br>
                                <small>Store Management</small>
                            </td>
                            <td style="border:none; width:33%; text-align:center;">
                                <p>Received By:</p><br><br>
                                <b>Supplier Signature</b><br>
                                <small>Distributor Representative</small>
                            </td>
                        </tr>
                    </table>
                </body>
                </html>
            `;

            const blob = new Blob(['\ufeff', content], { type: 'application/msword' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `PO_Slip_${new Date().toISOString().slice(0, 10)}.doc`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }

        const searchInput = document.getElementById('inventorySearch');
        if (searchInput) {
            searchInput.addEventListener('input', (e) => {
                const term = e.target.value.toLowerCase();
                document.querySelectorAll('.product-row').forEach(row => {
                    row.style.display = row.innerText.toLowerCase().includes(term) ? '' : 'none';
                });
            });
        }

        const histInput = document.getElementById('historySearch');
        if (histInput) {
            histInput.addEventListener('input', (e) => {
                const term = e.target.value.toLowerCase();
                document.querySelectorAll('.history-row').forEach(row => {
                    row.style.display = row.innerText.toLowerCase().includes(term) ? '' : 'none';
                });
            });
        }

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
                if (!aiChatWindow.classList.contains('hidden')) {
                    aiChatInput.focus();
                }
            });
            closeAiChat.addEventListener('click', () => aiChatWindow.classList.add('hidden'));
        }

        if (clearAiChat) {
            clearAiChat.addEventListener('click', () => {
                aiChatMessages.innerHTML = `
                    <div class="flex items-start gap-2.5">
                        <div class="w-7 h-7 rounded-lg bg-[#0f172a] text-amber-500 flex items-center justify-center text-[11px] font-black shrink-0 border border-slate-700">AI</div>
                        <div class="bg-white p-3.5 rounded-2xl rounded-tl-sm border border-slate-200/90 text-slate-800 shadow-2xs leading-relaxed max-w-[88%]">
                            Nalinis na ang usapan. Handa akong magbigay ng panibagong pagsusuri sa imbentaryo, mga kahon, o mga paubos na stocks!
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

            if (q.includes('reorder') || q.includes('low stock') || q.includes('ubos') || q.includes('paubos')) {
                if (reorderList.length === 0) {
                    return `
                        <div class="text-emerald-800 bg-emerald-50 p-2.5 rounded-xl border border-emerald-200">
                            <b>✅ Malusog ang Imbentaryo:</b> Walang produkto sa <b>${storeScopeName}</b> ang kailangang i-reorder ngayon. Lahat ay lagpas sa minimum buffer.
                        </div>
                    `;
                }

                let itemsHtml = reorderList.slice(0, 4).map(i => `• <b>${i.product_name}</b> (Store #${i.store_id || 1}): ${i.stock} pcs left. Order: <b>${i.boxes_to_order} boxes</b> (${i.suggested_units} pcs)`).join('<br>');
                return `
                    <div class="space-y-1.5">
                        <div class="font-extrabold text-red-700 border-b border-red-100 pb-1">
                            ⚠️ Kritikal na Imbentaryo (${reorderList.length} Produkto)
                        </div>
                        <p class="text-slate-700 leading-normal">
                            Narito ang mga nangungunang kailangan i-reorder:<br>
                            ${itemsHtml}<br><br>
                            Maaari mong pindutin ang <b>Print PO Slip</b> sa Reorder List tab upang mai-print ang opisyal na slip.
                        </p>
                    </div>
                `;
            }

            if (q.includes('box') || q.includes('kahon') || q.includes('loose') || q.includes('calculator')) {
                let sampleItem = Object.values(productsMap)[0];
                let sampleName = sampleItem ? sampleItem.product_name : 'Produkto';
                let samplePcs = sampleItem ? sampleItem.pcs_per_box : 24;
                return `
                    <div class="space-y-1.5">
                        <div class="font-extrabold text-slate-900 border-b border-slate-100 pb-1">
                            📦 Instant Box Calculator &amp; Pcs Breakdown
                        </div>
                        <p class="text-slate-700 leading-normal">
                            Awtomatikong kino-convert ng system ang total pieces patungong <b>Full Boxes</b> at <b>Loose Pieces</b>.<br>
                            • Halimbawa: Kay <b>${sampleName}</b>, may <b>${samplePcs} pcs</b> bawat kahon.<br>
                            • I-click lamang ang orange na kahon <i class="fas fa-boxes-stacked text-amber-500"></i> sa Actions column upang magdagdag ng kahon nang direkta.
                        </p>
                    </div>
                `;
            }

            if (q.includes('value') || q.includes('puhunan') || q.includes('retail') || q.includes('cost') || q.includes('halaga')) {
                return `
                    <div class="space-y-1.5">
                        <div class="font-extrabold text-slate-900 border-b border-slate-100 pb-1">
                            💰 Stock Valuation (${storeScopeName})
                        </div>
                        <p class="text-slate-700 leading-normal">
                            • Kabuuang Dami ng Paninda: <b class="text-slate-950">${totalUnitsCount.toLocaleString()} piraso</b><br>
                            • Kabuuang Puhunan (Cost): <b class="text-slate-950 font-black">₱${totalCostVal.toLocaleString(undefined, {minimumFractionDigits: 2})}</b><br>
                            • Kabuuang Retail Value: <b class="text-emerald-700 font-black">₱${totalRetailVal.toLocaleString(undefined, {minimumFractionDigits: 2})}</b>
                        </p>
                    </div>
                `;
            }

            if (q.includes('store 1') || q.includes('store 2') || q.includes('sangay') || q.includes('kumpara')) {
                return `
                    <div class="space-y-1.5">
                        <div class="font-extrabold text-slate-900 border-b border-slate-100 pb-1">
                            🏪 Store 1 vs Store 2 Stocks
                        </div>
                        <p class="text-slate-700 leading-normal">
                            Maaari mong gamitin ang <b>Store Switcher</b> sa itaas para salain ang listahan sa <b>Store 1</b> o <b>Store 2</b>. Parehong may hiwalay na minimum buffer at box counter upang maiwasan ang stockouts.
                        </p>
                    </div>
                `;
            }

            return `
                Naka-konekta ako sa live inventory database. Maaari mong itanong:<br>
                • <i>"Ilista ang mga low stock o kailangang i-reorder"</i><br>
                • <i>"Paano gumagana ang box calculator?"</i><br>
                • <i>"Magkano ang kabuuang cost at retail value ng stock?"</i><br>
                • <i>"Ilan ang stock sa Store 1 kumpara sa Store 2?"</i>
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
                typingMsg.className = 'flex items-start gap-2.5 temp-typing';
                typingMsg.innerHTML = `
                    <div class="w-7 h-7 rounded-lg bg-[#0f172a] text-amber-500 flex items-center justify-center text-[11px] font-black shrink-0 border border-slate-700">AI</div>
                    <div class="bg-white p-3 rounded-2xl rounded-tl-sm border border-slate-200 text-slate-500 text-xs shadow-2xs flex items-center gap-1.5 font-bold">
                        <span class="animate-pulse">Kinukuha ang pinakahuling imbentaryo at box counts...</span>
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

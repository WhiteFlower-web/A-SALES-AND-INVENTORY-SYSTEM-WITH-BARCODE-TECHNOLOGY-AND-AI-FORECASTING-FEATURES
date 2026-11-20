<?php
// JJ JUDEJUSH STORES - REPORTS & BUSINESS INTELLIGENCE 
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
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]);
    } catch (PDOException $e) {
        die("<div style='padding:40px; font-family:sans-serif; text-align:center; background-color:#070a12; color:white; height:100vh;'>
                <h1 style='color:#EF4444; font-weight:900;'>Database Connection Error</h1>
                <p>Unable to connect: " . htmlspecialchars($e->getMessage()) . "</p>
             </div>");
    }
}

// Mark notifications as read action
if (isset($_GET['mark_read']) && $_GET['mark_read'] === 'all') {
    try {
        $pdo->query("UPDATE notifications SET is_read = 1 WHERE is_read = 0");
    } catch (Throwable $t) {}
    $redirect_store = isset($_GET['store_id']) ? '?store_id=' . urlencode($_GET['store_id']) : '';
    header("Location: reports.php" . $redirect_store);
    exit();
}

// 3. COMMON LOGGING & AVATAR HELPERS
if (!function_exists('logActivity')) {
    function logActivity($pdo, $action, $details, $store_id = 1) {
        try {
            $uid = (int)($_SESSION['user_id'] ?? 1);
            $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, details, store_id, created_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->execute([$uid, $action, $details, $store_id]);
        } catch (Throwable $t) {}
    }
}

if (!function_exists('getValidAvatarUrl')) {
    function getValidAvatarUrl($avatarPath) {
        if (empty($avatarPath)) return null;
        $cleanPath = ltrim(str_replace(['\\', '/'], '/', $avatarPath), '/');
        $baseName = basename($cleanPath);
        $candidates = [
            $cleanPath,
            'uploads/avatars/' . $baseName,
            '../uploads/avatars/' . $baseName,
            'admin/uploads/avatars/' . $baseName,
            '../admin/uploads/avatars/' . $baseName
        ];
        foreach ($candidates as $cand) {
            $fullPath = __DIR__ . '/' . $cand;
            if (file_exists($fullPath) && !is_dir($fullPath)) return $cand;
        }
        return null;
    }
}

// 4. REQUEST PARAMETERS & STORE SYNC
$user_id = (int)($_SESSION['user_id'] ?? 1);
$store_param = strtolower(trim($_GET['store_id'] ?? 'both'));
$selected_store = in_array($store_param, ['1', '2', 'both']) ? $store_param : 'both';
$nav_store_id = ($selected_store === '2') ? '2' : '1';
$store_label = ($selected_store === 'both') ? 'Both Stores' : "Store {$selected_store}";

$active_view = $_GET['view'] ?? 'overview';
$start_date  = $_GET['start_date'] ?? date('Y-m-01');
$end_date    = $_GET['end_date'] ?? date('Y-m-d');

// Profile & System Settings
$currentUserFullname = $_SESSION['fullname'] ?? 'KHERLEEN MAE';
$currentUserRole     = $_SESSION['role'] ?? 'Store Owner';
$current_user_avatar = $_SESSION['avatar'] ?? null;
try {
    $stmtUser = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmtUser->execute([$user_id]);
    if ($uRow = $stmtUser->fetch()) {
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

$settings = ['store_name' => 'JJ JUDEJUSH'];
try {
    $sys = $pdo->query("SELECT * FROM settings WHERE id = 1")->fetch();
    if ($sys) $settings = array_merge($settings, $sys);
} catch (Throwable $t) {}

// Check Table Availability
$hasSaleItems = false;
try {
    $hasSaleItems = ($pdo->query("SHOW TABLES LIKE 'sale_items'")->rowCount() > 0);
} catch (Throwable $t) {}

$hasStockHistory = false;
try {
    $hasStockHistory = ($pdo->query("SHOW TABLES LIKE 'stock_history'")->rowCount() > 0);
} catch (Throwable $t) {}

// Store Clauses (Multi-Store Filter Synchronization)
if ($selected_store === 'both') {
    $sales_filter  = "";
    $s_join_filter = "";
    $prod_filter   = "";
    $hist_filter   = "";
} elseif ($selected_store === '2') {
    $sales_filter  = " AND store_id = 2";
    $s_join_filter = " AND s.store_id = 2";
    $prod_filter   = " AND p.store_id = 2";
    $hist_filter   = " AND (sh.store_id = 2 OR p.store_id = 2)";
} else {
    $sales_filter  = " AND (store_id = 1 OR store_id IS NULL OR store_id = 0)";
    $s_join_filter = " AND (s.store_id = 1 OR s.store_id IS NULL OR s.store_id = 0)";
    $prod_filter   = " AND (p.store_id = 1 OR p.store_id IS NULL OR p.store_id = 0)";
    $hist_filter   = " AND (sh.store_id = 1 OR sh.store_id IS NULL OR sh.store_id = 0 OR p.store_id = 1)";
}

// Notifications Count & Low Stock Alert Badge
$unreadCount = 0; $notifs = [];
try {
    $notifStoreFilter = ($selected_store === 'both') ? " WHERE 1=1" : " WHERE (store_id = " . (int)$selected_store . " OR store_id IS NULL OR store_id = 0)";
    $unreadCount = (int)($pdo->query("SELECT COUNT(*) FROM notifications {$notifStoreFilter} AND is_read = 0")->fetchColumn() ?: 0);
    $notifs = $pdo->query("SELECT * FROM notifications {$notifStoreFilter} AND is_read = 0 ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $t) {}

$low_stock_badge_count = 0;
try {
    $low_stock_badge_count = (int)($pdo->query("SELECT COUNT(*) FROM products WHERE (is_deleted = 0 OR is_deleted IS NULL) AND stock <= COALESCE(NULLIF(min_stock, 0), 5)")->fetchColumn() ?: 0);
} catch (Throwable $t) {}
$total_alerts_badge = max(count($notifs), $unreadCount + ($low_stock_badge_count > 0 ? 1 : 0));

// Data Containers
$dataResults = [];
$reportTitle = "";
$reportSub = "";
$kpi1_title = ""; $kpi1_val = "0";
$kpi2_title = ""; $kpi2_val = "0";
$kpi3_title = ""; $kpi3_val = "0";
$kpi4_title = ""; $kpi4_val = "0";

$chartTrendLabels = [];
$chartTrendValues = [];
$chartCatLabels   = [];
$chartCatValues   = [];
$chartProdLabels  = [];
$chartProdValues  = [];

// Summary metrics for Chatbot intelligence
$cb_today_sales = 0.0;
$cb_store1_sales = 0.0;
$cb_store2_sales = 0.0;
$cb_capital_val = 0.0;
$cb_retail_val = 0.0;
$cb_top_product = "N/A";
$cb_top_product_qty = 0;

try {
    $cb_today_st = $pdo->query("SELECT COALESCE(SUM(total_price), 0) FROM sales WHERE DATE(created_at) = CURDATE() {$sales_filter}");
    $cb_today_sales = (float)($cb_today_st->fetchColumn() ?: 0);

    $s1_st = $pdo->query("SELECT COALESCE(SUM(total_price), 0) FROM sales WHERE (store_id = 1 OR store_id IS NULL OR store_id = 0)");
    $cb_store1_sales = (float)($s1_st->fetchColumn() ?: 0);

    $s2_st = $pdo->query("SELECT COALESCE(SUM(total_price), 0) FROM sales WHERE store_id = 2");
    $cb_store2_sales = (float)($s2_st->fetchColumn() ?: 0);

    $inv_st = $pdo->query("SELECT SUM(stock * COALESCE(NULLIF(cost_price, 0), (price * 0.70))) as c_val, SUM(stock * price) as r_val FROM products WHERE (is_deleted = 0 OR is_deleted IS NULL)");
    $inv_data = $inv_st->fetch();
    $cb_capital_val = (float)($inv_data['c_val'] ?? 0);
    $cb_retail_val  = (float)($inv_data['r_val'] ?? 0);
} catch (Throwable $t) {}

// ============================================================================
// DATA ENGINE ACCORDING TO VIEW
// ============================================================================

// TAB 1: OVERVIEW & CHARTS
if ($active_view === 'overview') {
    $reportTitle = "Sales Analytics";
    $reportSub   = "Visual trajectories, categories, and top merchandise";

    try {
        $stTrend = $pdo->prepare("
            SELECT DATE(created_at) as sale_day, COALESCE(SUM(total_price), 0) as daily_total
            FROM sales 
            WHERE DATE(created_at) BETWEEN ? AND ? {$sales_filter}
            GROUP BY DATE(created_at)
            ORDER BY sale_day ASC
        ");
        $stTrend->execute([$start_date, $end_date]);
        foreach ($stTrend->fetchAll() as $tr) {
            $chartTrendLabels[] = date('M d', strtotime($tr['sale_day']));
            $chartTrendValues[] = (float)$tr['daily_total'];
        }

        $stCat = $pdo->prepare("
            SELECT COALESCE(NULLIF(TRIM(p.category), ''), 'General') as cat_name,
                   COALESCE(SUM(" . ($hasSaleItems ? "si.total_price" : "s.total_price") . "), 0) as cat_rev
            FROM sales s
            " . ($hasSaleItems ? "JOIN sale_items si ON si.sale_id = s.id LEFT JOIN products p ON si.product_id = p.id" : "LEFT JOIN products p ON s.product_id = p.id") . "
            WHERE DATE(s.created_at) BETWEEN ? AND ? {$s_join_filter}
            GROUP BY cat_name
            ORDER BY cat_rev DESC LIMIT 6
        ");
        $stCat->execute([$start_date, $end_date]);
        foreach ($stCat->fetchAll() as $cr) {
            $chartCatLabels[] = $cr['cat_name'];
            $chartCatValues[] = (float)$cr['cat_rev'];
        }

        $stTop = $pdo->prepare("
            SELECT COALESCE(" . ($hasSaleItems ? "si.product_name" : "p.product_name") . ", 'Product') as pname,
                   COALESCE(SUM(" . ($hasSaleItems ? "si.quantity" : "s.quantity") . "), 0) as qty_sold
            FROM sales s
            " . ($hasSaleItems ? "JOIN sale_items si ON si.sale_id = s.id LEFT JOIN products p ON si.product_id = p.id" : "LEFT JOIN products p ON s.product_id = p.id") . "
            WHERE DATE(s.created_at) BETWEEN ? AND ? {$s_join_filter}
            GROUP BY pname
            ORDER BY qty_sold DESC LIMIT 5
        ");
        $stTop->execute([$start_date, $end_date]);
        $topList = $stTop->fetchAll();
        if (!empty($topList)) {
            $cb_top_product = $topList[0]['pname'];
            $cb_top_product_qty = (int)$topList[0]['qty_sold'];
        }
        foreach ($topList as $pr) {
            $chartProdLabels[] = strlen($pr['pname']) > 16 ? substr($pr['pname'], 0, 16) . '..' : $pr['pname'];
            $chartProdValues[] = (int)$pr['qty_sold'];
        }

        $stTot = $pdo->prepare("
            SELECT COUNT(id) as total_txns, COALESCE(SUM(total_price), 0) as total_rev, COALESCE(SUM(discount_amount), 0) as total_discounts
            FROM sales
            WHERE DATE(created_at) BETWEEN ? AND ? {$sales_filter}
        ");
        $stTot->execute([$start_date, $end_date]);
        $tot = $stTot->fetch() ?: ['total_txns' => 0, 'total_rev' => 0, 'total_discounts' => 0];

        $kpi1_title = "Total Sales";
        $kpi1_val   = "₱" . number_format($tot['total_rev'], 2);
        $kpi2_title = "Orders";
        $kpi2_val   = number_format($tot['total_txns']);
        $kpi3_title = "Discounts";
        $kpi3_val   = "₱" . number_format($tot['total_discounts'], 2);
        $kpi4_title = "Avg Ticket";
        $kpi4_val   = ($tot['total_txns'] > 0) ? "₱" . number_format($tot['total_rev'] / $tot['total_txns'], 2) : "₱0.00";
    } catch (Throwable $t) {}
}

// TAB 2: RECEIPTS
elseif ($active_view === 'orders') {
    $reportTitle = "Receipts Log";
    $reportSub   = "Cashier transactions, payment methods, and subtotals";

    try {
        $st = $pdo->prepare("
            SELECT s.id as sale_id,
                   COALESCE(NULLIF(s.receipt_number, ''), NULLIF(s.receipt_no, ''), CONCAT('REC-', LPAD(s.id, 6, '0'))) as receipt_no,
                   COALESCE(NULLIF(s.customer_name, ''), 'Walk-in') as customer_name,
                   COALESCE(u.fullname, u.username, 'Cashier') as cashier_name,
                   COALESCE(s.payment_method, 'Cash') as payment_method,
                   s.created_at,
                   COALESCE(s.subtotal, s.total_price) as subtotal,
                   COALESCE(s.discount_amount, 0) as discount_amount,
                   s.total_price
            FROM sales s
            LEFT JOIN users u ON s.user_id = u.id
            WHERE DATE(s.created_at) BETWEEN ? AND ? {$s_join_filter}
            ORDER BY s.id DESC
        ");
        $st->execute([$start_date, $end_date]);
        $dataResults = $st->fetchAll();

        $rev = array_sum(array_column($dataResults, 'total_price'));
        $disc = array_sum(array_column($dataResults, 'discount_amount'));
        $count = count($dataResults);

        $kpi1_title = "Total Sales";
        $kpi1_val   = "₱" . number_format($rev, 2);
        $kpi2_title = "Receipts";
        $kpi2_val   = number_format($count);
        $kpi3_title = "Discounts";
        $kpi3_val   = "₱" . number_format($disc, 2);
        $kpi4_title = "Avg Order";
        $kpi4_val   = $count > 0 ? "₱" . number_format($rev / $count, 2) : "₱0.00";
    } catch (Throwable $t) {}
}

// TAB 3: PRODUCTS
elseif ($active_view === 'product') {
    $reportTitle = "Product Sales";
    $reportSub   = "Units sold, revenue contribution, and estimated gross margin";

    try {
        if ($hasSaleItems) {
            $st = $pdo->prepare("
                SELECT COALESCE(si.product_name, p.product_name, 'Product') as product_name,
                       COALESCE(NULLIF(p.barcode, ''), NULLIF(p.sku, ''), 'N/A') as sku,
                       COALESCE(NULLIF(TRIM(p.category), ''), 'General') as category,
                       COALESCE(SUM(si.quantity), 0) as units_sold,
                       COALESCE(AVG(si.unit_price), p.price, 0) as avg_price,
                       COALESCE(p.cost_price, (p.price * 0.7)) as unit_cost,
                       COALESCE(SUM(si.total_price), SUM(si.quantity * si.unit_price), 0) as total_sales
                FROM sales s
                JOIN sale_items si ON si.sale_id = s.id
                LEFT JOIN products p ON si.product_id = p.id
                WHERE DATE(s.created_at) BETWEEN ? AND ? {$s_join_filter}
                GROUP BY p.id, si.product_name, p.product_name, p.barcode, p.sku, p.category, p.price, p.cost_price
                ORDER BY total_sales DESC
            ");
        } else {
            $st = $pdo->prepare("
                SELECT COALESCE(p.product_name, 'Product') as product_name,
                       COALESCE(NULLIF(p.barcode, ''), NULLIF(p.sku, ''), 'N/A') as sku,
                       COALESCE(NULLIF(TRIM(p.category), ''), 'General') as category,
                       COALESCE(SUM(s.quantity), COUNT(s.id)) as units_sold,
                       COALESCE(AVG(p.price), 0) as avg_price,
                       COALESCE(p.cost_price, (p.price * 0.7)) as unit_cost,
                       COALESCE(SUM(s.total_price), 0) as total_sales
                FROM sales s
                LEFT JOIN products p ON s.product_id = p.id
                WHERE DATE(s.created_at) BETWEEN ? AND ? {$s_join_filter}
                GROUP BY p.id, p.product_name, p.barcode, p.sku, p.category, p.price, p.cost_price
                ORDER BY total_sales DESC
            ");
        }
        $st->execute([$start_date, $end_date]);
        $dataResults = $st->fetchAll();

        $totSales = array_sum(array_column($dataResults, 'total_sales'));
        $totUnits = array_sum(array_column($dataResults, 'units_sold'));

        $kpi1_title = "Product Sales";
        $kpi1_val   = "₱" . number_format($totSales, 2);
        $kpi2_title = "Units Sold";
        $kpi2_val   = number_format($totUnits) . " pcs";
        $kpi3_title = "Active SKUs";
        $kpi3_val   = number_format(count($dataResults));
        $kpi4_title = "Top Seller";
        $kpi4_val   = !empty($dataResults) ? substr($dataResults[0]['product_name'], 0, 15) : 'None';
    } catch (Throwable $t) {}
}

// TAB 4: STOCK LOGS
elseif ($active_view === 'stock_history') {
    $reportTitle = "Stock Logs";
    $reportSub   = "Restocks, audits, adjustments, and movement trail";

    try {
        if ($hasStockHistory) {
            $st = $pdo->prepare("
                SELECT sh.id, sh.created_at, sh.type as movement_type, sh.quantity, sh.stock_after,
                       COALESCE(sh.notes, sh.remarks, 'Adjustment') as notes,
                       COALESCE(NULLIF(TRIM(sh.product_name), ''), p.product_name, CONCAT('Item #', sh.product_id)) as product_name,
                       COALESCE(NULLIF(p.barcode, ''), NULLIF(p.sku, ''), 'N/A') as sku,
                       COALESCE(u.fullname, u.username, 'System') as user_name,
                       COALESCE(sh.store_id, p.store_id, 1) as item_store_id
                FROM stock_history sh
                LEFT JOIN products p ON sh.product_id = p.id
                LEFT JOIN users u ON sh.user_id = u.id
                WHERE DATE(sh.created_at) BETWEEN ? AND ? {$hist_filter}
                ORDER BY sh.id DESC LIMIT 150
            ");
            $st->execute([$start_date, $end_date]);
            $dataResults = $st->fetchAll();
        } else {
            $st = $pdo->prepare("
                SELECT a.id, a.created_at, a.action as movement_type, 1 as quantity, 0 as stock_after,
                       a.details as notes, 'Audit Trail' as product_name, 'LOG' as sku,
                       COALESCE(u.fullname, 'System') as user_name, a.store_id as item_store_id
                FROM audit_logs a
                LEFT JOIN users u ON a.user_id = u.id
                WHERE DATE(a.created_at) BETWEEN ? AND ? {$hist_filter}
                ORDER BY a.id DESC LIMIT 150
            ");
            $st->execute([$start_date, $end_date]);
            $dataResults = $st->fetchAll();
        }

        $kpi1_title = "Total Logs";
        $kpi1_val   = number_format(count($dataResults));
        $kpi2_title = "Date Range";
        $kpi2_val   = date('M d', strtotime($start_date)) . " - " . date('M d', strtotime($end_date));
        $kpi3_title = "Store Scope";
        $kpi3_val   = $store_label;
        $kpi4_title = "Module";
        $kpi4_val   = "Stock Control";
    } catch (Throwable $t) {}
}

// TAB 5: CATEGORIES
elseif ($active_view === 'category') {
    $reportTitle = "Category Sales";
    $reportSub   = "Revenue share and unit turnover per department";

    try {
        $st = $pdo->prepare("
            SELECT COALESCE(NULLIF(TRIM(p.category), ''), 'General') as category_name,
                   COUNT(DISTINCT s.id) as transactions_count,
                   COALESCE(SUM(" . ($hasSaleItems ? "si.quantity" : "s.quantity") . "), 0) as total_units_sold,
                   COALESCE(SUM(" . ($hasSaleItems ? "si.total_price" : "s.total_price") . "), 0) as total_revenue
            FROM sales s
            " . ($hasSaleItems ? "JOIN sale_items si ON si.sale_id = s.id LEFT JOIN products p ON si.product_id = p.id" : "LEFT JOIN products p ON s.product_id = p.id") . "
            WHERE DATE(s.created_at) BETWEEN ? AND ? {$s_join_filter}
            GROUP BY category_name
            ORDER BY total_revenue DESC
        ");
        $st->execute([$start_date, $end_date]);
        $dataResults = $st->fetchAll();

        $totRev = array_sum(array_column($dataResults, 'total_revenue'));
        $totUnits = array_sum(array_column($dataResults, 'total_units_sold'));

        $kpi1_title = "Total Sales";
        $kpi1_val   = "₱" . number_format($totRev, 2);
        $kpi2_title = "Units Sold";
        $kpi2_val   = number_format($totUnits) . " pcs";
        $kpi3_title = "Categories";
        $kpi3_val   = number_format(count($dataResults));
        $kpi4_title = "Top Category";
        $kpi4_val   = !empty($dataResults) ? $dataResults[0]['category_name'] : 'None';
    } catch (Throwable $t) {}
}

// TAB 6: MONTHLY
elseif ($active_view === 'month') {
    $reportTitle = "Monthly Sales";
    $reportSub   = "12-month performance, order volume, and net intake";

    try {
        $st = $pdo->prepare("
            SELECT DATE_FORMAT(created_at, '%Y-%m') as year_month,
                   DATE_FORMAT(created_at, '%M %Y') as formatted_month,
                   COUNT(id) as transactions_count,
                   COALESCE(SUM(subtotal), SUM(total_price), 0) as gross_sales,
                   COALESCE(SUM(discount_amount), 0) as total_discounts,
                   COALESCE(SUM(total_price), 0) as net_sales
            FROM sales
            WHERE 1=1 {$sales_filter}
            GROUP BY DATE_FORMAT(created_at, '%Y-%m'), DATE_FORMAT(created_at, '%M %Y')
            ORDER BY year_month DESC LIMIT 12
        ");
        $st->execute();
        $dataResults = $st->fetchAll();

        $totNet = array_sum(array_column($dataResults, 'net_sales'));
        $totTxns = array_sum(array_column($dataResults, 'transactions_count'));

        $kpi1_title = "12-Month Sales";
        $kpi1_val   = "₱" . number_format($totNet, 2);
        $kpi2_title = "Orders";
        $kpi2_val   = number_format($totTxns);
        $kpi3_title = "Avg Ticket";
        $kpi3_val   = $totTxns > 0 ? "₱" . number_format($totNet / $totTxns, 2) : "₱0.00";
        $kpi4_title = "Logged Months";
        $kpi4_val   = number_format(count($dataResults));
    } catch (Throwable $t) {}
}

// TAB 7: VALUATION
elseif ($active_view === 'inventory') {
    $reportTitle = "Inventory Valuation";
    $reportSub   = "Capital investment, retail potential, and profit spread";

    try {
        $st = $pdo->prepare("
            SELECT p.id, p.product_name,
                   COALESCE(NULLIF(p.barcode, ''), NULLIF(p.sku, ''), 'N/A') as sku,
                   COALESCE(NULLIF(TRIM(p.category), ''), 'General') as category,
                   COALESCE(p.stock, 0) as stock,
                   COALESCE(NULLIF(p.cost_price, 0), (p.price * 0.70), 0) as unit_cost,
                   p.price as retail_price,
                   (COALESCE(p.stock, 0) * COALESCE(NULLIF(p.cost_price, 0), (p.price * 0.70), 0)) as total_cost_value,
                   (COALESCE(p.stock, 0) * p.price) as total_retail_value,
                   COALESCE(p.store_id, 1) as item_store_id
            FROM products p
            WHERE (p.is_deleted = 0 OR p.is_deleted IS NULL) {$prod_filter}
            ORDER BY total_retail_value DESC
        ");
        $st->execute();
        $dataResults = $st->fetchAll();

        $costVal = array_sum(array_column($dataResults, 'total_cost_value'));
        $retVal  = array_sum(array_column($dataResults, 'total_retail_value'));
        $units   = array_sum(array_column($dataResults, 'stock'));

        $kpi1_title = "Capital Cost";
        $kpi1_val   = "₱" . number_format($costVal, 2);
        $kpi2_title = "Retail Value";
        $kpi2_val   = "₱" . number_format($retVal, 2);
        $kpi3_title = "Gross Profit";
        $kpi3_val   = "₱" . number_format($retVal - $costVal, 2);
        $kpi4_title = "Stock Units";
        $kpi4_val   = number_format($units) . " pcs";
    } catch (Throwable $t) {}
}

// TAB 8: LOW STOCK
elseif ($active_view === 'stock_risk') {
    $reportTitle = "Low Stock Alert";
    $reportSub   = "Critical inventory requiring replenishment";

    try {
        $st = $pdo->prepare("
            SELECT p.id, p.product_name,
                   COALESCE(NULLIF(p.barcode, ''), NULLIF(p.sku, ''), 'N/A') as sku,
                   COALESCE(NULLIF(TRIM(p.category), ''), 'General') as category,
                   COALESCE(p.stock, 0) as stock,
                   COALESCE(NULLIF(p.min_stock, 0), 5) as min_stock,
                   COALESCE(NULLIF(p.cost_price, 0), (p.price * 0.70), 0) as unit_cost,
                   p.price,
                   COALESCE(p.store_id, 1) as item_store_id,
                   CASE 
                       WHEN COALESCE(p.stock, 0) <= 0 THEN 'OUT'
                       WHEN COALESCE(p.stock, 0) <= 2 THEN 'CRITICAL'
                       ELSE 'LOW'
                   END as risk_status,
                   GREATEST(0, (COALESCE(NULLIF(p.min_stock, 0), 5) * 2) - COALESCE(p.stock, 0)) as suggested_reorder
            FROM products p
            WHERE (p.is_deleted = 0 OR p.is_deleted IS NULL)
              AND COALESCE(p.stock, 0) <= COALESCE(NULLIF(p.min_stock, 0), 5) {$prod_filter}
            ORDER BY p.stock ASC, p.product_name ASC
        ");
        $st->execute();
        $dataResults = $st->fetchAll();

        $outCount = 0; $critCount = 0; $estCost = 0;
        foreach ($dataResults as $r) {
            if ($r['stock'] <= 0) $outCount++;
            elseif ($r['stock'] <= 2) $critCount++;
            $estCost += ($r['suggested_reorder'] * (float)$r['unit_cost']);
        }

        $kpi1_title = "At Risk";
        $kpi1_val   = number_format(count($dataResults)) . " items";
        $kpi2_title = "Out of Stock";
        $kpi2_val   = number_format($outCount) . " items";
        $kpi3_title = "Critical";
        $kpi3_val   = number_format($critCount) . " items";
        $kpi4_title = "Restock Cost";
        $kpi4_val   = "₱" . number_format($estCost, 2);
    } catch (Throwable $t) {}
}

$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($settings['store_name']) ?> - Reports &amp; Intelligence</title>
    <!-- Tailwind CSS, FontAwesome 6, Chart.js & Fonts -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/js/all.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        .custom-input { background: #fff; border: 1.5px solid #cbd5e1; border-radius: 10px; padding: 6px 12px; font-size: 12px; font-weight: 700; color: #0f172a; outline: none; }
        .custom-input:focus { border-color: #f59e0b; box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.15); }
        @media print {
            @page { size: A4 landscape; margin: 8mm; }
            body { background: #fff !important; color: #000 !important; overflow: visible !important; height: auto !important; }
            .no-print, header, #sidebar, form, button, .top-nav-tabs, #aiChatbotContainer { display: none !important; }
            #printArea { border: none !important; box-shadow: none !important; width: 100% !important; margin: 0 !important; }
            table { width: 100% !important; border-collapse: collapse !important; }
            th { background: #f8fafc !important; color: #000 !important; border-bottom: 2px solid #000 !important; padding: 6px !important; font-size: 10px !important; }
            td { padding: 6px !important; border-bottom: 1px solid #cbd5e1 !important; font-size: 10px !important; }
            .print-signature { display: flex !important; }
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-800 flex flex-col h-screen overflow-hidden relative">

    <!-- TOP HEADER -->
    <header class="flex items-center justify-between bg-[#070a12] text-white px-5 py-3 shadow-md z-30 flex-shrink-0 border-b border-slate-900 no-print">
        <div class="flex items-center space-x-4">
            <button id="toggleSidebar" class="p-2 bg-slate-900 hover:bg-slate-800 rounded-xl text-white transition-colors" title="Toggle Navigation">
                <i class="fas fa-bars text-sm"></i>
            </button>
            <div class="flex items-center space-x-3">
                <div class="w-9 h-9 bg-amber-500 rounded-xl flex items-center justify-center text-slate-950 font-black text-sm shadow">JJ</div>
                <div>
                    <h1 class="text-sm font-black tracking-wider text-white uppercase leading-none">
                        <?= htmlspecialchars($settings['store_name']) ?>
                    </h1>
                </div>
            </div>
        </div>

        <div class="flex items-center space-x-3 md:space-x-4">
            <!-- STORE SWITCHER -->
            <div class="flex bg-slate-900/90 p-1 rounded-xl border border-slate-800 shadow-inner">
                <a href="?store_id=both&view=<?= $active_view ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>" class="px-3.5 py-1.5 text-xs font-black rounded-lg transition-all <?= $selected_store === 'both' ? 'bg-amber-500 text-slate-950 shadow' : 'text-slate-300 hover:text-white' ?>">Both Stores</a>
                <a href="?store_id=1&view=<?= $active_view ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>" class="px-3.5 py-1.5 text-xs font-black rounded-lg transition-all <?= $selected_store === '1' ? 'bg-amber-500 text-slate-950 shadow' : 'text-slate-300 hover:text-white' ?>">Store 1</a>
                <a href="?store_id=2&view=<?= $active_view ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>" class="px-3.5 py-1.5 text-xs font-black rounded-lg transition-all <?= $selected_store === '2' ? 'bg-amber-500 text-slate-950 shadow' : 'text-slate-300 hover:text-white' ?>">Store 2</a>
            </div>

            <!-- NOTIFICATIONS -->
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
                        <?php if ($low_stock_badge_count > 0): ?>
                            <a href="inventory.php?store_id=<?= $nav_store_id ?>&tab=reorder" class="p-3.5 bg-red-50 hover:bg-red-100 transition-colors block">
                                <h4 class="text-xs font-black text-red-900 uppercase">⚠️ <?= $low_stock_badge_count ?> Items Running Low</h4>
                                <p class="text-[11px] font-bold text-slate-600 mt-0.5">Critical stock below safety limit requiring restock.</p>
                            </a>
                        <?php endif; ?>
                        <?php if (!empty($notifs)): ?>
                            <?php foreach($notifs as $n): ?>
                            <div class="p-4 hover:bg-slate-50 transition-colors">
                                <h4 class="text-xs font-black text-slate-900 tracking-tight leading-snug uppercase"><?= htmlspecialchars($n['title']) ?></h4>
                                <p class="text-[11px] font-bold text-slate-600 mt-1 leading-normal"><?= htmlspecialchars($n['message']) ?></p>
                            </div>
                            <?php endforeach; ?>
                        <?php elseif ($low_stock_badge_count === 0): ?>
                            <div class="p-4 text-center text-xs font-bold text-slate-500">No active alerts.</div>
                        <?php endif; ?>
                    </div>

                    <div class="grid grid-cols-2 border-t border-slate-200 bg-white text-center font-black text-[11px] uppercase tracking-wider">
                        <a href="reports.php?store_id=<?= urlencode($selected_store) ?>&mark_read=all" class="py-3 text-emerald-600 hover:text-emerald-700 hover:bg-emerald-50 transition-colors border-r border-slate-200">
                            CLEAR ALL
                        </a>
                        <a href="inventory.php?store_id=<?= $nav_store_id ?>&tab=reorder" class="py-3 text-amber-600 hover:text-amber-700 hover:bg-amber-50 transition-colors">
                            REORDER
                        </a>
                    </div>
                </div>
            </div>

            <!-- PROFILE -->
            <a href="settings.php?store_id=<?= $nav_store_id ?>" class="w-9 h-9 rounded-xl bg-amber-500 text-slate-950 flex items-center justify-center font-black text-xs shadow hover:ring-2 hover:ring-amber-400 transition-all overflow-hidden border border-amber-400">
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
                <a href="reports.php?store_id=<?= urlencode($selected_store) ?>" class="flex items-center px-4 py-3 rounded-xl font-black text-xs transition-all bg-amber-500 text-slate-950 shadow-md">
                    <i class="fa-solid fa-file-invoice-dollar w-5 text-center text-sm"></i><span class="ml-3 sidebar-text">Reports</span>
                </a>
                <a href="audit_logs.php?store_id=<?= $nav_store_id ?>" class="flex items-center px-4 py-3 rounded-xl font-black text-xs transition-all text-slate-300 hover:bg-slate-900 hover:text-white">
                    <i class="fa-solid fa-shield-halved w-5 text-center text-sm"></i><span class="ml-3 sidebar-text">Activity Logs</span>
                </a>
                <a href="accounts.php?store_id=<?= $nav_store_id ?>" class="flex items-center px-4 py-3 rounded-xl font-black text-xs transition-all text-slate-300 hover:bg-slate-900 hover:text-white">
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

        <!-- MAIN REPORTS WORKSPACE -->
        <div class="flex-1 p-5 md:p-6 overflow-y-auto custom-scroll space-y-4 bg-slate-100">
            
            <!-- DIRECT 1-2 WORD TABS -->
            <div class="bg-white p-2 rounded-2xl shadow-sm border border-slate-200 no-print top-nav-tabs">
                <div class="flex items-center space-x-1.5 overflow-x-auto no-scrollbar">
                    <a href="?store_id=<?= urlencode($selected_store) ?>&view=overview&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>" 
                       class="flex items-center gap-2 px-3.5 py-2.5 rounded-xl text-xs font-black whitespace-nowrap transition-all <?= $active_view === 'overview' ? 'bg-amber-500 text-slate-950 shadow' : 'text-slate-600 hover:bg-slate-100' ?>">
                        <i class="fas fa-chart-line text-xs"></i> <span>Overview</span>
                    </a>
                    <a href="?store_id=<?= urlencode($selected_store) ?>&view=orders&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>" 
                       class="flex items-center gap-2 px-3.5 py-2.5 rounded-xl text-xs font-black whitespace-nowrap transition-all <?= $active_view === 'orders' ? 'bg-amber-500 text-slate-950 shadow' : 'text-slate-600 hover:bg-slate-100' ?>">
                        <i class="fas fa-receipt text-xs"></i> <span>Receipts</span>
                    </a>
                    <a href="?store_id=<?= urlencode($selected_store) ?>&view=product&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>" 
                       class="flex items-center gap-2 px-3.5 py-2.5 rounded-xl text-xs font-black whitespace-nowrap transition-all <?= $active_view === 'product' ? 'bg-amber-500 text-slate-950 shadow' : 'text-slate-600 hover:bg-slate-100' ?>">
                        <i class="fas fa-box-open text-xs"></i> <span>Products</span>
                    </a>
                    <a href="?store_id=<?= urlencode($selected_store) ?>&view=stock_history&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>" 
                       class="flex items-center gap-2 px-3.5 py-2.5 rounded-xl text-xs font-black whitespace-nowrap transition-all <?= $active_view === 'stock_history' ? 'bg-amber-500 text-slate-950 shadow' : 'text-slate-600 hover:bg-slate-100' ?>">
                        <i class="fas fa-clock-rotate-left text-xs"></i> <span>Stock Logs</span>
                    </a>
                    <a href="?store_id=<?= urlencode($selected_store) ?>&view=category&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>" 
                       class="flex items-center gap-2 px-3.5 py-2.5 rounded-xl text-xs font-black whitespace-nowrap transition-all <?= $active_view === 'category' ? 'bg-amber-500 text-slate-950 shadow' : 'text-slate-600 hover:bg-slate-100' ?>">
                        <i class="fas fa-layer-group text-xs"></i> <span>Categories</span>
                    </a>
                    <a href="?store_id=<?= urlencode($selected_store) ?>&view=month" 
                       class="flex items-center gap-2 px-3.5 py-2.5 rounded-xl text-xs font-black whitespace-nowrap transition-all <?= $active_view === 'month' ? 'bg-amber-500 text-slate-950 shadow' : 'text-slate-600 hover:bg-slate-100' ?>">
                        <i class="fas fa-calendar-days text-xs"></i> <span>Monthly</span>
                    </a>
                    <a href="?store_id=<?= urlencode($selected_store) ?>&view=inventory" 
                       class="flex items-center gap-2 px-3.5 py-2.5 rounded-xl text-xs font-black whitespace-nowrap transition-all <?= $active_view === 'inventory' ? 'bg-amber-500 text-slate-950 shadow' : 'text-slate-600 hover:bg-slate-100' ?>">
                        <i class="fas fa-vault text-xs"></i> <span>Valuation</span>
                    </a>
                    <a href="?store_id=<?= urlencode($selected_store) ?>&view=stock_risk" 
                       class="flex items-center gap-2 px-3.5 py-2.5 rounded-xl text-xs font-black whitespace-nowrap transition-all <?= $active_view === 'stock_risk' ? 'bg-amber-500 text-slate-950 shadow' : 'text-slate-600 hover:bg-slate-100' ?>">
                        <i class="fas fa-triangle-exclamation text-xs"></i> <span>Low Stock</span>
                    </a>
                </div>
            </div>

            <!-- SUB-HEADER -->
            <div class="bg-white p-4 md:p-5 rounded-2xl shadow-sm border border-slate-200 flex flex-col md:flex-row justify-between items-start md:items-center gap-3 no-print">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="px-2 py-0.5 bg-amber-100 text-amber-900 text-[10px] font-black rounded uppercase"><?= htmlspecialchars($store_label) ?></span>
                        <span class="text-xs font-bold text-slate-400">• Verified Records</span>
                    </div>
                    <h2 class="text-base md:text-lg font-black text-slate-900 uppercase tracking-tight mt-1"><?= htmlspecialchars($reportTitle) ?></h2>
                    <p class="text-xs font-semibold text-slate-500"><?= htmlspecialchars($reportSub) ?></p>
                </div>

                <div class="flex items-center gap-2 self-end md:self-auto">
                    <?php if($active_view !== 'overview'): ?>
                    <button type="button" onclick="exportTableToCSV('<?= $active_view ?>_<?= $selected_store ?>.csv')" class="bg-[#070a12] hover:bg-slate-800 text-white font-black px-3.5 py-2 rounded-xl text-xs uppercase transition shadow flex items-center gap-2">
                        <i class="fas fa-file-excel text-amber-400"></i> Export CSV
                    </button>
                    <?php endif; ?>
                    <button type="button" onclick="window.print()" class="bg-amber-500 hover:bg-amber-600 text-slate-950 font-black px-3.5 py-2 rounded-xl text-xs uppercase transition shadow flex items-center gap-2">
                        <i class="fas fa-print"></i> Print
                    </button>
                </div>
            </div>

            <!-- 4 SUMMARY CARDS (1-2 WORDS DIRECT ENGLISH) -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 no-print">
                <div class="bg-white p-4 rounded-2xl shadow-sm border border-slate-200">
                    <p class="text-[10px] font-black uppercase text-slate-400 tracking-wider"><?= $kpi1_title ?></p>
                    <p class="text-xl font-black text-slate-900 mt-1"><?= $kpi1_val ?></p>
                </div>
                <div class="bg-white p-4 rounded-2xl shadow-sm border border-slate-200">
                    <p class="text-[10px] font-black uppercase text-slate-400 tracking-wider"><?= $kpi2_title ?></p>
                    <p class="text-xl font-black text-slate-900 mt-1"><?= $kpi2_val ?></p>
                </div>
                <div class="bg-white p-4 rounded-2xl shadow-sm border border-slate-200">
                    <p class="text-[10px] font-black uppercase text-slate-400 tracking-wider"><?= $kpi3_title ?></p>
                    <p class="text-xl font-black text-amber-600 mt-1"><?= $kpi3_val ?></p>
                </div>
                <div class="bg-white p-4 rounded-2xl shadow-sm border border-slate-200">
                    <p class="text-[10px] font-black uppercase text-slate-400 tracking-wider"><?= $kpi4_title ?></p>
                    <p class="text-xl font-black text-slate-900 mt-1 truncate" title="<?= $kpi4_val ?>"><?= $kpi4_val ?></p>
                </div>
            </div>

            <!-- DATE FILTER -->
            <?php if(in_array($active_view, ['overview', 'orders', 'product', 'category', 'stock_history'])): ?>
            <div class="bg-white p-3 rounded-2xl shadow-sm border border-slate-200 no-print flex flex-col md:flex-row justify-between items-center gap-3">
                <form method="GET" class="flex flex-wrap items-center gap-2 w-full md:w-auto">
                    <input type="hidden" name="store_id" value="<?= htmlspecialchars($selected_store) ?>">
                    <input type="hidden" name="view" value="<?= htmlspecialchars($active_view) ?>">

                    <div class="flex items-center gap-1.5">
                        <span class="text-[11px] font-black text-slate-500 uppercase">From:</span>
                        <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" class="custom-input">
                    </div>

                    <div class="flex items-center gap-1.5">
                        <span class="text-[11px] font-black text-slate-500 uppercase">To:</span>
                        <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" class="custom-input">
                    </div>

                    <button type="submit" class="bg-[#070a12] hover:bg-slate-800 text-white py-1.5 px-3.5 rounded-xl text-xs font-black uppercase transition shadow">
                        <i class="fas fa-filter text-amber-400 mr-1"></i> Filter
                    </button>
                </form>

                <div class="flex items-center gap-1.5 self-end md:self-auto">
                    <a href="?store_id=<?= urlencode($selected_store) ?>&view=<?= $active_view ?>&start_date=<?= date('Y-m-d') ?>&end_date=<?= date('Y-m-d') ?>" class="px-2.5 py-1 text-[10px] font-black rounded-lg bg-slate-100 hover:bg-amber-100 text-slate-600">Today</a>
                    <a href="?store_id=<?= urlencode($selected_store) ?>&view=<?= $active_view ?>&start_date=<?= date('Y-m-d', strtotime('-7 days')) ?>&end_date=<?= date('Y-m-d') ?>" class="px-2.5 py-1 text-[10px] font-black rounded-lg bg-slate-100 hover:bg-amber-100 text-slate-600">7 Days</a>
                    <a href="?store_id=<?= urlencode($selected_store) ?>&view=<?= $active_view ?>&start_date=<?= date('Y-m-01') ?>&end_date=<?= date('Y-m-d') ?>" class="px-2.5 py-1 text-[10px] font-black rounded-lg bg-slate-100 hover:bg-amber-100 text-slate-600">This Month</a>
                </div>
            </div>
            <?php endif; ?>

            <!-- ======================================================== -->
            <!-- TAB 1 CONTENT: OVERVIEW (CHARTS)                         -->
            <!-- ======================================================== -->
            <?php if ($active_view === 'overview'): ?>
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                    <!-- Daily Trend -->
                    <div class="bg-white p-5 rounded-2xl shadow-sm border border-slate-200 lg:col-span-2">
                        <div class="flex justify-between items-center mb-4">
                            <div>
                                <h3 class="text-xs font-black text-slate-900 uppercase">Sales Trend</h3>
                                <p class="text-[10px] text-slate-500 font-bold">Revenue timeline for date range</p>
                            </div>
                            <span class="text-xs font-black text-amber-600">₱<?= number_format(array_sum($chartTrendValues), 2) ?></span>
                        </div>
                        <div class="h-64">
                            <canvas id="trendChart"></canvas>
                        </div>
                    </div>

                    <!-- Category Share -->
                    <div class="bg-white p-5 rounded-2xl shadow-sm border border-slate-200">
                        <h3 class="text-xs font-black text-slate-900 uppercase mb-1">Categories</h3>
                        <p class="text-[10px] text-slate-500 font-bold mb-3">Revenue percentage by category</p>
                        <div class="h-56 relative flex items-center justify-center">
                            <canvas id="categoryChart"></canvas>
                        </div>
                    </div>

                    <!-- Top 5 Products -->
                    <div class="bg-white p-5 rounded-2xl shadow-sm border border-slate-200 lg:col-span-3">
                        <h3 class="text-xs font-black text-slate-900 uppercase mb-1">Top Products</h3>
                        <p class="text-[10px] text-slate-500 font-bold mb-4">Fastest moving merchandise units</p>
                        <div class="h-56">
                            <canvas id="productChart"></canvas>
                        </div>
                    </div>
                </div>

            <!-- ======================================================== -->
            <!-- TAB 2-8 CONTENT: DATA TABLES & PRINT LEDGER              -->
            <!-- ======================================================== -->
            <?php else: ?>

                <!-- REAL-TIME FILTER -->
                <div class="flex justify-between items-center no-print">
                    <div class="relative w-full md:w-80">
                        <i class="fas fa-search absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                        <input type="text" id="tableSearch" onkeyup="filterReportTable()" placeholder="Search records..." class="w-full pl-9 pr-4 py-2 bg-white border border-slate-200 rounded-xl text-xs font-bold focus:outline-none focus:border-amber-500">
                    </div>
                    <span class="text-xs font-bold text-slate-400 ml-4 hidden md:inline"><?= count($dataResults) ?> records</span>
                </div>

                <div id="printArea" class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
                    
                    <!-- PRINT HEADER -->
                    <div class="p-5 border-b border-slate-200 bg-white">
                        <div class="flex justify-between items-start">
                            <div>
                                <h2 class="text-lg font-black uppercase text-slate-900"><?= htmlspecialchars($settings['store_name']) ?></h2>
                                <p class="text-[11px] font-black text-amber-600 uppercase mt-0.5"><?= htmlspecialchars($store_label) ?> &bull; <?= htmlspecialchars($reportTitle) ?></p>
                            </div>
                            <div class="text-right text-[10px] font-bold text-slate-600 uppercase leading-relaxed">
                                <p>Date: <?= date('M d, Y h:i A') ?></p>
                                <p>Report By: <?= htmlspecialchars($currentUserFullname) ?></p>
                            </div>
                        </div>
                    </div>

                    <!-- TABLE AREA -->
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs" id="dataTable">
                            
                            <!-- 1. RECEIPTS -->
                            <?php if ($active_view === 'orders'): ?>
                                <thead class="bg-slate-50 text-slate-900 uppercase font-black border-b border-slate-200 text-[11px]">
                                    <tr>
                                        <th class="p-3">Receipt</th>
                                        <th class="p-3">Customer</th>
                                        <th class="p-3">Cashier</th>
                                        <th class="p-3">Date</th>
                                        <th class="p-3 text-center">Payment</th>
                                        <th class="p-3 text-right">Subtotal</th>
                                        <th class="p-3 text-right">Discount</th>
                                        <th class="p-3 text-right">Total</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 font-bold" id="tableBody">
                                    <?php foreach($dataResults as $r): ?>
                                    <tr class="hover:bg-slate-50 transition">
                                        <td class="p-3 font-mono font-black text-slate-900"><?= htmlspecialchars($r['receipt_no']) ?></td>
                                        <td class="p-3 text-slate-700"><?= htmlspecialchars($r['customer_name']) ?></td>
                                        <td class="p-3 text-slate-900"><?= htmlspecialchars($r['cashier_name']) ?></td>
                                        <td class="p-3 text-slate-500 text-[11px]"><?= date('M d, Y h:i A', strtotime($r['created_at'])) ?></td>
                                        <td class="p-3 text-center">
                                            <span class="px-2 py-0.5 bg-slate-100 text-slate-700 rounded text-[10px] font-black uppercase"><?= htmlspecialchars($r['payment_method']) ?></span>
                                        </td>
                                        <td class="p-3 text-right text-slate-600">₱<?= number_format($r['subtotal'], 2) ?></td>
                                        <td class="p-3 text-right text-red-500">-₱<?= number_format($r['discount_amount'], 2) ?></td>
                                        <td class="p-3 text-right font-black text-slate-900">₱<?= number_format($r['total_price'], 2) ?></td>
                                    </tr>
                                    <?php endforeach; if(empty($dataResults)) echo '<tr><td colspan="8" class="p-8 text-center text-slate-400 font-bold">No receipt records found.</td></tr>'; ?>
                                </tbody>

                            <!-- 2. PRODUCTS -->
                            <?php elseif ($active_view === 'product'): ?>
                                <thead class="bg-slate-50 text-slate-900 uppercase font-black border-b border-slate-200 text-[11px]">
                                    <tr>
                                        <th class="p-3">Product Name</th>
                                        <th class="p-3 text-center">Category</th>
                                        <th class="p-3 text-center">Units Sold</th>
                                        <th class="p-3 text-right">Avg Price</th>
                                        <th class="p-3 text-right">Est Profit</th>
                                        <th class="p-3 text-right">Total Sales</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 font-bold" id="tableBody">
                                    <?php foreach($dataResults as $r): 
                                        $profit = (float)$r['total_sales'] - ((float)$r['unit_cost'] * (int)$r['units_sold']);
                                    ?>
                                    <tr class="hover:bg-slate-50 transition">
                                        <td class="p-3">
                                            <p class="font-black text-slate-900"><?= htmlspecialchars($r['product_name']) ?></p>
                                            <p class="text-[10px] text-slate-400 uppercase font-mono">SKU: <?= htmlspecialchars($r['sku']) ?></p>
                                        </td>
                                        <td class="p-3 text-center text-slate-600 uppercase text-[11px]"><?= htmlspecialchars($r['category']) ?></td>
                                        <td class="p-3 text-center font-black text-slate-900"><?= number_format($r['units_sold']) ?> pcs</td>
                                        <td class="p-3 text-right text-slate-600">₱<?= number_format($r['avg_price'], 2) ?></td>
                                        <td class="p-3 text-right text-emerald-600 font-black">+₱<?= number_format($profit, 2) ?></td>
                                        <td class="p-3 text-right font-black text-slate-900">₱<?= number_format($r['total_sales'], 2) ?></td>
                                    </tr>
                                    <?php endforeach; if(empty($dataResults)) echo '<tr><td colspan="6" class="p-8 text-center text-slate-400 font-bold">No product records found.</td></tr>'; ?>
                                </tbody>

                            <!-- 3. STOCK HISTORY & AUDIT LOGS -->
                            <?php elseif ($active_view === 'stock_history'): ?>
                                <thead class="bg-slate-50 text-slate-900 uppercase font-black border-b border-slate-200 text-[11px]">
                                    <tr>
                                        <th class="p-3">Date</th>
                                        <th class="p-3">Product</th>
                                        <th class="p-3 text-center">Store</th>
                                        <th class="p-3 text-center">Type</th>
                                        <th class="p-3 text-center">Quantity</th>
                                        <th class="p-3 text-center">Stock After</th>
                                        <th class="p-3">Auditor</th>
                                        <th class="p-3">Notes</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 font-bold" id="tableBody">
                                    <?php foreach($dataResults as $r): 
                                        $type = strtoupper(trim($r['movement_type']));
                                        $is_pos = in_array($type, ['IN', 'INITIAL', 'RESTOCK']);
                                    ?>
                                    <tr class="hover:bg-slate-50 transition">
                                        <td class="p-3 text-slate-500 font-bold text-[11px] whitespace-nowrap"><?= date('M d, Y h:i A', strtotime($r['created_at'])) ?></td>
                                        <td class="p-3 font-black text-slate-900"><?= htmlspecialchars($r['product_name']) ?></td>
                                        <td class="p-3 text-center"><span class="px-2 py-0.5 rounded text-[10px] font-black uppercase bg-slate-100 text-slate-700">Store #<?= $r['item_store_id'] ?></span></td>
                                        <td class="p-3 text-center">
                                            <span class="px-2 py-0.5 rounded text-[10px] font-black uppercase <?= $is_pos ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' ?>"><?= htmlspecialchars($type) ?></span>
                                        </td>
                                        <td class="p-3 text-center font-black <?= $is_pos ? 'text-emerald-700' : 'text-slate-900' ?>"><?= number_format($r['quantity']) ?> pcs</td>
                                        <td class="p-3 text-center font-black text-slate-900"><?= number_format($r['stock_after'] ?? 0) ?> pcs</td>
                                        <td class="p-3 text-slate-700"><?= htmlspecialchars($r['user_name']) ?></td>
                                        <td class="p-3 text-slate-500 text-[11px] max-w-xs truncate" title="<?= htmlspecialchars($r['notes']) ?>"><?= htmlspecialchars($r['notes']) ?></td>
                                    </tr>
                                    <?php endforeach; if(empty($dataResults)) echo '<tr><td colspan="8" class="p-8 text-center text-slate-400 font-bold">No stock logs found.</td></tr>'; ?>
                                </tbody>

                            <!-- 4. CATEGORIES -->
                            <?php elseif ($active_view === 'category'): ?>
                                <thead class="bg-slate-50 text-slate-900 uppercase font-black border-b border-slate-200 text-[11px]">
                                    <tr>
                                        <th class="p-3">Category</th>
                                        <th class="p-3 text-center">Orders</th>
                                        <th class="p-3 text-center">Units Sold</th>
                                        <th class="p-3 text-right">Share (%)</th>
                                        <th class="p-3 text-right">Total Sales</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 font-bold" id="tableBody">
                                    <?php 
                                    $allCatRev = array_sum(array_column($dataResults, 'total_revenue'));
                                    foreach($dataResults as $r): 
                                        $share = $allCatRev > 0 ? (($r['total_revenue'] / $allCatRev) * 100) : 0;
                                    ?>
                                    <tr class="hover:bg-slate-50 transition">
                                        <td class="p-3 font-black text-slate-900"><?= htmlspecialchars($r['category_name']) ?></td>
                                        <td class="p-3 text-center text-slate-600"><?= number_format($r['transactions_count']) ?></td>
                                        <td class="p-3 text-center font-black text-slate-900"><?= number_format($r['total_units_sold']) ?> pcs</td>
                                        <td class="p-3 text-right text-slate-500 font-bold"><?= number_format($share, 1) ?>%</td>
                                        <td class="p-3 text-right font-black text-slate-900">₱<?= number_format($r['total_revenue'], 2) ?></td>
                                    </tr>
                                    <?php endforeach; if(empty($dataResults)) echo '<tr><td colspan="5" class="p-8 text-center text-slate-400 font-bold">No category records.</td></tr>'; ?>
                                </tbody>

                            <!-- 5. MONTHLY -->
                            <?php elseif ($active_view === 'month'): ?>
                                <thead class="bg-slate-50 text-slate-900 uppercase font-black border-b border-slate-200 text-[11px]">
                                    <tr>
                                        <th class="p-3">Month</th>
                                        <th class="p-3 text-center">Orders</th>
                                        <th class="p-3 text-right">Gross Sales</th>
                                        <th class="p-3 text-right">Discounts</th>
                                        <th class="p-3 text-right">Net Sales</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 font-bold" id="tableBody">
                                    <?php foreach($dataResults as $r): ?>
                                    <tr class="hover:bg-slate-50 transition">
                                        <td class="p-3 font-black text-slate-900"><?= htmlspecialchars($r['formatted_month']) ?></td>
                                        <td class="p-3 text-center text-slate-600"><?= number_format($r['transactions_count']) ?></td>
                                        <td class="p-3 text-right text-slate-600">₱<?= number_format($r['gross_sales'], 2) ?></td>
                                        <td class="p-3 text-right text-red-500">-₱<?= number_format($r['total_discounts'], 2) ?></td>
                                        <td class="p-3 text-right font-black text-slate-900">₱<?= number_format($r['net_sales'], 2) ?></td>
                                    </tr>
                                    <?php endforeach; if(empty($dataResults)) echo '<tr><td colspan="5" class="p-8 text-center text-slate-400 font-bold">No monthly data.</td></tr>'; ?>
                                </tbody>

                            <!-- 6. VALUATION -->
                            <?php elseif ($active_view === 'inventory'): ?>
                                <thead class="bg-slate-50 text-slate-900 uppercase font-black border-b border-slate-200 text-[11px]">
                                    <tr>
                                        <th class="p-3">Product Name</th>
                                        <th class="p-3 text-center">Category</th>
                                        <th class="p-3 text-center">In Stock</th>
                                        <th class="p-3 text-right">Cost</th>
                                        <th class="p-3 text-right">Retail</th>
                                        <th class="p-3 text-right">Capital</th>
                                        <th class="p-3 text-right">Retail Value</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 font-bold" id="tableBody">
                                    <?php foreach($dataResults as $r): ?>
                                    <tr class="hover:bg-slate-50 transition">
                                        <td class="p-3">
                                            <p class="font-black text-slate-900"><?= htmlspecialchars($r['product_name']) ?></p>
                                            <p class="text-[10px] text-slate-400 font-mono">Store #<?= $r['item_store_id'] ?> &bull; <?= htmlspecialchars($r['sku']) ?></p>
                                        </td>
                                        <td class="p-3 text-center text-slate-600 uppercase text-[11px]"><?= htmlspecialchars($r['category']) ?></td>
                                        <td class="p-3 text-center font-black text-slate-900"><?= number_format($r['stock']) ?> pcs</td>
                                        <td class="p-3 text-right text-slate-500">₱<?= number_format($r['unit_cost'], 2) ?></td>
                                        <td class="p-3 text-right text-slate-700">₱<?= number_format($r['retail_price'], 2) ?></td>
                                        <td class="p-3 text-right text-slate-600 font-black">₱<?= number_format($r['total_cost_value'], 2) ?></td>
                                        <td class="p-3 text-right font-black text-slate-900">₱<?= number_format($r['total_retail_value'], 2) ?></td>
                                    </tr>
                                    <?php endforeach; if(empty($dataResults)) echo '<tr><td colspan="7" class="p-8 text-center text-slate-400 font-bold">No inventory recorded.</td></tr>'; ?>
                                </tbody>

                            <!-- 7. LOW STOCK -->
                            <?php elseif ($active_view === 'stock_risk'): ?>
                                <thead class="bg-slate-50 text-slate-900 uppercase font-black border-b border-slate-200 text-[11px]">
                                    <tr>
                                        <th class="p-3">Product Name</th>
                                        <th class="p-3 text-center">Category</th>
                                        <th class="p-3 text-center">Current Stock</th>
                                        <th class="p-3 text-center">Safety Limit</th>
                                        <th class="p-3 text-center">Reorder Box</th>
                                        <th class="p-3 text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 font-bold" id="tableBody">
                                    <?php foreach($dataResults as $r): ?>
                                    <tr class="hover:bg-slate-50 transition">
                                        <td class="p-3">
                                            <p class="font-black text-slate-900"><?= htmlspecialchars($r['product_name']) ?></p>
                                            <p class="text-[10px] text-slate-400 font-mono">Store #<?= $r['item_store_id'] ?> &bull; <?= htmlspecialchars($r['sku']) ?></p>
                                        </td>
                                        <td class="p-3 text-center text-slate-600 uppercase text-[11px]"><?= htmlspecialchars($r['category']) ?></td>
                                        <td class="p-3 text-center font-black text-slate-900"><?= number_format($r['stock']) ?> pcs</td>
                                        <td class="p-3 text-center text-slate-500 font-bold"><?= number_format($r['min_stock']) ?> pcs</td>
                                        <td class="p-3 text-center text-amber-600 font-black">+<?= number_format($r['suggested_reorder']) ?> pcs</td>
                                        <td class="p-3 text-center">
                                            <?php if($r['risk_status'] === 'OUT'): ?>
                                                <span class="px-2 py-0.5 rounded bg-red-600 text-white text-[10px] font-black uppercase">OUT</span>
                                            <?php elseif($r['risk_status'] === 'CRITICAL'): ?>
                                                <span class="px-2 py-0.5 rounded bg-amber-500 text-slate-950 text-[10px] font-black uppercase">CRITICAL</span>
                                            <?php else: ?>
                                                <span class="px-2 py-0.5 rounded bg-yellow-100 text-yellow-800 text-[10px] font-black uppercase">LOW</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; if(empty($dataResults)) echo '<tr><td colspan="6" class="p-8 text-center text-slate-400 font-bold">All product stocks are healthy.</td></tr>'; ?>
                                </tbody>
                            <?php endif; ?>

                        </table>
                    </div>

                    <!-- PRINT SIGNATURE -->
                    <div class="hidden print-signature p-6 mt-8 justify-between items-end border-t border-slate-300 text-xs">
                        <div class="text-center">
                            <div class="w-48 border-b border-slate-900 mb-1.5"></div>
                            <p class="font-black uppercase text-slate-900"><?= strtoupper($currentUserFullname) ?></p>
                            <p class="text-[9px] font-bold text-slate-500 uppercase">Prepared By</p>
                        </div>
                        <div class="text-center">
                            <div class="w-48 border-b border-slate-900 mb-1.5"></div>
                            <p class="font-black uppercase text-slate-900"><?= date('F d, Y h:i A') ?></p>
                            <p class="text-[9px] font-bold text-slate-500 uppercase">Verified Date</p>
                        </div>
                    </div>

                </div>

            <?php endif; ?>

        </div>
    </div>

    <!-- ======================================================== -->
    <!-- FORMAL EXECUTIVE AI CHATBOT WIDGET                       -->
    <!-- ======================================================== -->
    <div id="aiChatbotContainer" class="fixed bottom-6 right-6 z-50 no-print flex flex-col items-end">
        
        <!-- AI CHAT WINDOW -->
        <div id="aiChatWindow" class="w-[94vw] sm:w-[440px] bg-white rounded-3xl shadow-2xl border border-slate-300/80 overflow-hidden mb-3 transition-all duration-300 origin-bottom-right hidden flex flex-col" style="height: 550px; max-height: 85vh;">
            
            <!-- Executive Header -->
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
                        <p class="text-[10px] font-bold text-slate-400 mt-0.5">Real-time Retail Analytics &amp; Advisory</p>
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

            <!-- Suggestion Action Chips -->
            <div class="bg-slate-50 border-b border-slate-200/90 px-3.5 py-2.5 flex items-center gap-1.5 overflow-x-auto custom-scroll text-[11px] font-bold text-slate-700 whitespace-nowrap">
                <button type="button" onclick="sendAiQuickPrompt('Ulat sa benta ngayon at mga transaksyon')" class="bg-white hover:bg-slate-100 hover:border-slate-300 border border-slate-200/90 px-3 py-1 rounded-lg transition-all shrink-0">
                    📊 Benta Ngayon
                </button>
                <button type="button" onclick="sendAiQuickPrompt('Alin ang mga produkto na kailangang i-reorder at low stock?')" class="bg-white hover:bg-slate-100 hover:border-slate-300 border border-slate-200/90 px-3 py-1 rounded-lg transition-all shrink-0">
                    ⚠️ Low Stocks
                </button>
                <button type="button" onclick="sendAiQuickPrompt('Ano ang mga nangungunang produkto at best sellers?')" class="bg-white hover:bg-slate-100 hover:border-slate-300 border border-slate-200/90 px-3 py-1 rounded-lg transition-all shrink-0">
                    🏆 Best Sellers
                </button>
                <button type="button" onclick="sendAiQuickPrompt('I-kumpara ang benta at performance ng Store 1 at Store 2')" class="bg-white hover:bg-slate-100 hover:border-slate-300 border border-slate-200/90 px-3 py-1 rounded-lg transition-all shrink-0">
                    🏪 Store 1 vs Store 2
                </button>
                <button type="button" onclick="sendAiQuickPrompt('Magkano ang kabuuang puhunan, retail value, at gross margin?')" class="bg-white hover:bg-slate-100 hover:border-slate-300 border border-slate-200/90 px-3 py-1 rounded-lg transition-all shrink-0">
                    💰 Puhunan &amp; Tubo
                </button>
                <button type="button" onclick="sendAiQuickPrompt('Magbigay ng opisyal na rekomendasyon para mapalago ang benta')" class="bg-white hover:bg-slate-100 hover:border-slate-300 border border-slate-200/90 px-3 py-1 rounded-lg transition-all shrink-0">
                    💡 Rekomendasyon
                </button>
            </div>

            <!-- Chat Messages Log -->
            <div id="aiChatMessages" class="flex-1 p-4 overflow-y-auto custom-scroll space-y-3.5 bg-[#f8fafc] text-xs">
                <div class="flex items-start gap-2.5">
                    <div class="w-7 h-7 rounded-lg bg-[#0f172a] text-amber-500 flex items-center justify-center text-[11px] font-black shrink-0 border border-slate-700">
                        AI
                    </div>
                    <div class="bg-white p-3.5 rounded-2xl rounded-tl-sm border border-slate-200/90 text-slate-800 shadow-2xs leading-relaxed max-w-[88%]">
                        Magandang araw! Ako ang inyong <b>JJ Enterprise AI Assistant</b>. Naka-link ako sa live point-of-sale at mga ulat ng <b>Both Stores</b>, <b>Store 1</b>, at <b>Store 2</b>. Paano ko kayo matutulungan sa mga ulat at benta ngayon?
                    </div>
                </div>
            </div>

            <!-- Chat Input Form -->
            <form id="aiChatForm" class="p-3 bg-white border-t border-slate-200 flex items-center gap-2">
                <input type="text" id="aiChatInput" placeholder="Magtanong tungkol sa benta, stocks, o branch analysis..." class="flex-1 bg-slate-50 focus:bg-white border border-slate-300 focus:border-amber-500 text-slate-800 text-xs rounded-xl px-3.5 py-2.5 outline-none font-semibold transition-all">
                <button type="submit" class="bg-[#0f172a] hover:bg-slate-800 text-amber-400 font-black px-4 py-2.5 rounded-xl text-xs transition-all flex items-center gap-1.5 shadow-sm">
                    <span>Send</span>
                    <i class="fas fa-arrow-right text-[11px]"></i>
                </button>
            </form>
        </div>

        <!-- FLOATING TRIGGER BUTTON -->
        <button id="toggleAiChat" type="button" title="Open Enterprise AI Assistant" class="relative w-14 h-14 bg-[#0f172a] hover:bg-slate-800 text-amber-400 rounded-2xl shadow-xl flex items-center justify-center border border-slate-700 transition-all transform hover:scale-105 active:scale-95 group focus:outline-none focus:ring-4 focus:ring-amber-500/20">
            <span class="absolute -top-1 -right-1 flex h-3.5 w-3.5">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                <span class="relative inline-flex rounded-full h-3.5 w-3.5 bg-emerald-500 border-2 border-[#0f172a]"></span>
            </span>
            <i class="fas fa-brain text-xl transition-transform duration-300 group-hover:scale-110"></i>
        </button>
    </div>

    <!-- JAVASCRIPT LOGIC -->
    <script>
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

        // Live Table Filter
        function filterReportTable() {
            const input = document.getElementById("tableSearch");
            if (!input) return;
            const filter = input.value.toLowerCase();
            const tableBody = document.getElementById("tableBody");
            if (!tableBody) return;
            const rows = tableBody.getElementsByTagName("tr");

            for (let i = 0; i < rows.length; i++) {
                const text = rows[i].textContent || rows[i].innerText;
                rows[i].style.display = text.toLowerCase().indexOf(filter) > -1 ? "" : "none";
            }
        }

        // CSV Export
        function exportTableToCSV(filename) {
            const table = document.getElementById("dataTable");
            if (!table) return;

            let csv = [];
            const rows = table.querySelectorAll("tr");
            for (let i = 0; i < rows.length; i++) {
                if (rows[i].style.display === 'none') continue;
                let row = [], cols = rows[i].querySelectorAll("td, th");
                for (let j = 0; j < cols.length; j++) {
                    let text = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, " ").replace(/"/g, '""');
                    row.push('"' + text.trim() + '"');
                }
                csv.push(row.join(","));
            }
            const blob = new Blob([csv.join("\n")], { type: "text/csv;charset=utf-8;" });
            const link = document.createElement("a");
            link.download = filename;
            link.href = window.URL.createObjectURL(blob);
            link.style.display = "none";
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        // Chart.js Setup
        <?php if ($active_view === 'overview'): ?>
        document.addEventListener('DOMContentLoaded', () => {
            // Trend
            const ctxTrend = document.getElementById('trendChart');
            if (ctxTrend) {
                new Chart(ctxTrend, {
                    type: 'line',
                    data: {
                        labels: <?= json_encode($chartTrendLabels) ?>,
                        datasets: [{
                            label: 'Sales (₱)',
                            data: <?= json_encode($chartTrendValues) ?>,
                            borderColor: '#f59e0b',
                            backgroundColor: 'rgba(245, 158, 11, 0.1)',
                            borderWidth: 2.5,
                            fill: true,
                            tension: 0.35,
                            pointBackgroundColor: '#070a12',
                            pointBorderColor: '#f59e0b',
                            pointRadius: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            y: { grid: { color: '#f1f5f9' }, ticks: { font: { weight: 'bold' } } },
                            x: { grid: { display: false }, ticks: { font: { weight: 'bold' } } }
                        }
                    }
                });
            }

            // Category Share
            const ctxCat = document.getElementById('categoryChart');
            if (ctxCat) {
                new Chart(ctxCat, {
                    type: 'doughnut',
                    data: {
                        labels: <?= json_encode($chartCatLabels) ?>,
                        datasets: [{
                            data: <?= json_encode($chartCatValues) ?>,
                            backgroundColor: ['#f59e0b', '#070a12', '#10b981', '#3b82f6', '#8b5cf6', '#ec4899'],
                            borderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { weight: 'bold', size: 10 } } } }
                    }
                });
            }

            // Top Products
            const ctxProd = document.getElementById('productChart');
            if (ctxProd) {
                new Chart(ctxProd, {
                    type: 'bar',
                    data: {
                        labels: <?= json_encode($chartProdLabels) ?>,
                        datasets: [{
                            label: 'Units Sold',
                            data: <?= json_encode($chartProdValues) ?>,
                            backgroundColor: '#070a12',
                            hoverBackgroundColor: '#f59e0b',
                            borderRadius: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            y: { grid: { color: '#f1f5f9' }, ticks: { font: { weight: 'bold' } } },
                            x: { grid: { display: false }, ticks: { font: { weight: 'bold' } } }
                        }
                    }
                });
            }
        });
        <?php endif; ?>

        // ========================================================
        // EXECUTIVE AI CHATBOT LOGIC (SYNCHRONIZED TO STORE DATA)
        // ========================================================
        const toggleAiChat = document.getElementById('toggleAiChat');
        const closeAiChat = document.getElementById('closeAiChat');
        const clearAiChat = document.getElementById('clearAiChat');
        const aiChatWindow = document.getElementById('aiChatWindow');
        const aiChatForm = document.getElementById('aiChatForm');
        const aiChatInput = document.getElementById('aiChatInput');
        const aiChatMessages = document.getElementById('aiChatMessages');

        const liveData = {
            storeLabel: <?= json_encode($store_label) ?>,
            todaySales: <?= (float)$cb_today_sales ?>,
            store1Sales: <?= (float)$cb_store1_sales ?>,
            store2Sales: <?= (float)$cb_store2_sales ?>,
            lowStockCount: <?= (int)$low_stock_badge_count ?>,
            topProduct: <?= json_encode($cb_top_product) ?>,
            topQty: <?= (int)$cb_top_product_qty ?>,
            capitalVal: <?= (float)$cb_capital_val ?>,
            retailVal: <?= (float)$cb_retail_val ?>
        };

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
                            Nalinis na ang usapan. Handa akong magbigay ng panibagong ulat sa benta, stocks, o branch metrics ng inyong tindahan!
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
            const d = liveData;

            // 1. Sales
            if (q.includes('benta') || q.includes('sale') || q.includes('kita') || q.includes('revenue') || q.includes('today')) {
                return `
                    <div class="space-y-1.5">
                        <div class="font-extrabold text-slate-900 border-b border-slate-100 pb-1 flex items-center justify-between">
                            <span>📊 Ulat sa Benta Ngayon</span>
                            <span class="text-[10px] text-amber-600 uppercase font-black">${d.storeLabel}</span>
                        </div>
                        <p class="text-slate-700 leading-normal">
                            • Kabuuang Benta Ngayong Araw: <b class="text-slate-950 font-black">₱${d.todaySales.toLocaleString(undefined, {minimumFractionDigits: 2})}</b><br>
                            • Store 1 All-Time Sales: <b>₱${d.store1Sales.toLocaleString(undefined, {minimumFractionDigits: 2})}</b><br>
                            • Store 2 All-Time Sales: <b>₱${d.store2Sales.toLocaleString(undefined, {minimumFractionDigits: 2})}</b>
                        </p>
                    </div>
                `;
            }

            // 2. Low Stocks & Reorder
            if (q.includes('low stock') || q.includes('ubos') || q.includes('paubos') || q.includes('reorder') || q.includes('stock')) {
                if (d.lowStockCount === 0) {
                    return `<div class="text-emerald-800 bg-emerald-50 p-2.5 rounded-xl border border-emerald-200"><b>✅ Malusog ang Imbentaryo:</b> Walang produkto sa <b>${d.storeLabel}</b> ang nasa critical o reorder limit sa ngayon.</div>`;
                }
                return `
                    <div class="space-y-1.5">
                        <div class="font-extrabold text-red-700 border-b border-red-100 pb-1">
                            ⚠️ Kritikal na Imbentaryo (${d.lowStockCount} Produkto)
                        </div>
                        <p class="text-slate-700 leading-normal">
                            Mayroong <b class="text-red-600 font-black">${d.lowStockCount} produkto</b> na nasa critical o mababang level ng stock.
                        </p>
                        <div class="pt-1.5 border-t border-slate-100">
                            <a href="inventory.php?store_id=1&tab=reorder" class="text-amber-700 font-extrabold hover:underline">
                                Buksan ang Reorder List Section →
                            </a>
                        </div>
                    </div>
                `;
            }

            // 3. Best Sellers
            if (q.includes('mabenta') || q.includes('best seller') || q.includes('top') || q.includes('malakas')) {
                return `
                    <div class="space-y-1.5">
                        <div class="font-extrabold text-slate-900 border-b border-slate-100 pb-1">
                            🏆 Nangungunang Paninda (${d.storeLabel})
                        </div>
                        <p class="text-slate-700 leading-normal">
                            Nangunguna ang <b>${d.topProduct}</b> na may naitalang <b>${d.topQty.toLocaleString()} pirasong</b> nabenta sa kasalukuyang saklaw.
                        </p>
                    </div>
                `;
            }

            // 4. Branch comparison
            if (q.includes('store 1') || q.includes('store 2') || q.includes('kumpara') || q.includes('compare') || q.includes('branch')) {
                const leader = d.store1Sales >= d.store2Sales ? "Store 1" : "Store 2";
                return `
                    <div class="space-y-1.5">
                        <div class="font-extrabold text-slate-900 border-b border-slate-100 pb-1">
                            🏪 Store 1 laban sa Store 2
                        </div>
                        <p class="text-slate-700 leading-normal">
                            • <b>Store 1 Sales:</b> ₱${d.store1Sales.toLocaleString(undefined, {minimumFractionDigits: 2})}<br>
                            • <b>Store 2 Sales:</b> ₱${d.store2Sales.toLocaleString(undefined, {minimumFractionDigits: 2})}<br>
                            • Nangunguna sa kabuuan: <b class="text-amber-700">${leader}</b>
                        </p>
                    </div>
                `;
            }

            // 5. Valuation
            if (q.includes('puhunan') || q.includes('capital') || q.includes('valuation') || q.includes('margin') || q.includes('tubo')) {
                const profit = d.retailVal - d.capitalVal;
                return `
                    <div class="space-y-1.5">
                        <div class="font-extrabold text-slate-900 border-b border-slate-100 pb-1">
                            💰 Halaga ng Imbentaryo &amp; Puhunan
                        </div>
                        <p class="text-slate-700 leading-normal">
                            • Kabuuang Puhunan (Cost): <b>₱${d.capitalVal.toLocaleString(undefined, {minimumFractionDigits: 2})}</b><br>
                            • Retail Valuation: <b>₱${d.retailVal.toLocaleString(undefined, {minimumFractionDigits: 2})}</b><br>
                            • Projected Tubo: <b class="text-emerald-700">₱${profit.toLocaleString(undefined, {minimumFractionDigits: 2})}</b>
                        </p>
                    </div>
                `;
            }

            // 6. Recommendations
            if (q.includes('rekomendasyon') || q.includes('advice') || q.includes('payo') || q.includes('suggest')) {
                return `
                    <div class="space-y-1.5">
                        <div class="font-extrabold text-slate-900 border-b border-slate-100 pb-1">
                            💡 Opisyal na Payo at Rekomendasyon ng AI
                        </div>
                        <p class="text-slate-700 leading-normal">
                            1. <b>Mabilisang Pag-restock:</b> Aksyunan agad ang ${d.lowStockCount} items na nasa reorder alert.<br>
                            2. <b>Tutok sa Top Performer:</b> Panatilihing may sapat na stock ang '${d.topProduct}' upang hindi magka-missed sales.<br>
                            3. <b>Balanseng Imbentaryo:</b> Maglipat ng mga mabagal na produkto patungo sa mas mabilis na sangay.
                        </p>
                    </div>
                `;
            }

            return `
                Naka-konekta ako sa live database ng tindahan. Maaari mong itanong:<br>
                • <i>"Magkano ang benta at kita ngayon?"</i><br>
                • <i>"Ilista ang mga low stock o kailangang i-reorder"</i><br>
                • <i>"Ano ang pinakamabiling produkto?"</i><br>
                • <i>"Paghambingin ang Store 1 at Store 2"</i><br>
                • <i>"Magkano ang kabuuang puhunan at inaasahang tubo?"</i>
            `;
        }

        if (aiChatForm) {
            aiChatForm.addEventListener('submit', (e) => {
                e.preventDefault();
                const text = aiChatInput.value.trim();
                if (!text) return;
                aiChatInput.value = '';

                // Add User Bubble
                const userMsg = document.createElement('div');
                userMsg.className = 'flex items-start justify-end gap-2.5';
                userMsg.innerHTML = `
                    <div class="bg-[#0f172a] text-white p-3 rounded-2xl rounded-tr-sm font-semibold text-xs max-w-[85%] leading-relaxed shadow-sm">${text}</div>
                    <div class="w-7 h-7 rounded-lg bg-amber-500 text-slate-950 flex items-center justify-center text-[10px] font-black shrink-0">YOU</div>
                `;
                aiChatMessages.appendChild(userMsg);
                aiChatMessages.scrollTop = aiChatMessages.scrollHeight;

                // Typing Indicator
                const typingMsg = document.createElement('div');
                typingMsg.className = 'flex items-start gap-2.5';
                typingMsg.innerHTML = `
                    <div class="w-7 h-7 rounded-lg bg-[#0f172a] text-amber-500 flex items-center justify-center text-[11px] font-black shrink-0 border border-slate-700">AI</div>
                    <div class="bg-white p-3 rounded-2xl rounded-tl-sm border border-slate-200 text-slate-500 text-xs shadow-2xs flex items-center gap-1.5 font-bold">
                        <span class="animate-pulse">Kinukuha ang pinakahuling ulat ng tindahan...</span>
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
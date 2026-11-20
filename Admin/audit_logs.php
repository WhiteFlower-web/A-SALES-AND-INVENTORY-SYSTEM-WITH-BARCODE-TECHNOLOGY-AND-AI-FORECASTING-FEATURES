<?php
// JJ JUDEJUSH STORES - AUDIT & TRANSACTION LOGS (audit_logs.php)

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

// 3. SCHEMA MIGRATIONS (FAIL-SAFE & COMPATIBILITY)
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS audit_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT DEFAULT NULL,
            action VARCHAR(255) NOT NULL,
            page_module VARCHAR(100) DEFAULT 'System',
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
    $redirect_store = isset($_GET['store_id']) ? '?store_id=' . urlencode($_GET['store_id']) : '';
    header("Location: audit_logs.php" . $redirect_store);
    exit();
}

// 4. COMMON HELPERS
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

if (!function_exists('cleanActionText')) {
    function cleanActionText($text) {
        if (!$text) return '';
        $text = str_ireplace('Soft deleted', 'Deleted', $text);
        $text = str_ireplace('SOFT', '', $text);
        $text = str_replace('_', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }
}

// 5. PARAMETERS & USER CONTEXT
$user_id = (int)($_SESSION['user_id'] ?? 1);
$store_param = strtolower(trim($_GET['store_id'] ?? 'both'));
$selected_store = in_array($store_param, ['1', '2', 'both']) ? $store_param : 'both';
$nav_store_id = ($selected_store === '2') ? '2' : '1';
$store_label = ($selected_store === 'both') ? 'Both Stores' : "Store {$selected_store}";

// Settings & Profile
$settings = ['store_name' => 'JJ JUDEJUSH'];
try {
    $sys = $pdo->query("SELECT * FROM settings WHERE id = 1")->fetch();
    if ($sys) $settings = array_merge($settings, $sys);
} catch (Throwable $t) {}

$currentUserFullname = $_SESSION['fullname'] ?? 'KHERLEEN MAE';
$currentUserRole     = $_SESSION['role'] ?? 'ADMIN';
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

// Notification Alert Counts
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

// Date & Filter Criteria
$start_date = isset($_GET['start_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-30 days'));
$end_date   = isset($_GET['end_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
if ($start_date > $end_date) $start_date = date('Y-m-d', strtotime('-30 days', strtotime($end_date)));

$start_dt = $start_date . ' 00:00:00';
$end_dt   = $end_date . ' 23:59:59';
$action_type = $_GET['action_type'] ?? 'ALL';
$search      = isset($_GET['search']) ? trim($_GET['search']) : '';

// 6. STORE CLAUSES FOR MULTI-STORE FILTERING
if ($selected_store === 'both') {
    $audit_store_clause = "";
    $sales_store_clause = "";
    $sh_store_clause    = "";
} elseif ($selected_store === '2') {
    $audit_store_clause = " AND a.store_id = 2";
    $sales_store_clause = " AND s.store_id = 2";
    $sh_store_clause    = " AND sh.store_id = 2";
} else {
    $audit_store_clause = " AND (a.store_id = 1 OR a.store_id IS NULL OR a.store_id = 0)";
    $sales_store_clause = " AND (s.store_id = 1 OR s.store_id IS NULL OR s.store_id = 0)";
    $sh_store_clause    = " AND (sh.store_id = 1 OR sh.store_id IS NULL OR sh.store_id = 0)";
}

// 7. COMPREHENSIVE EVENT AGGREGATION (AUDIT LOGS + SALES + STOCK HISTORY)
$raw_logs = [];
try {
    $st_a = $pdo->prepare("
        SELECT a.id, a.action as log_type, COALESCE(a.page_module, 'System') as page_module,
               a.details as log_details, a.user_id, a.created_at, COALESCE(a.store_id, 1) as store_id,
               u.fullname as performer, u.role as performer_role, u.avatar as performer_avatar, u.profile_image as performer_profile_image
        FROM audit_logs a
        LEFT JOIN users u ON a.user_id = u.id
        WHERE a.created_at BETWEEN ? AND ? {$audit_store_clause}
    ");
    $st_a->execute([$start_dt, $end_dt]);
    $raw_logs = $st_a->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $t) {}

// Incorporate Cashier Sales Transactions
$sales_logs = [];
try {
    $hasSales = ($pdo->query("SHOW TABLES LIKE 'sales'")->rowCount() > 0);
    if ($hasSales) {
        $st_s = $pdo->prepare("
            SELECT CONCAT('sale_', s.id) as id, 'TRANSACTION_SALE' as log_type, 'Cashier' as page_module,
                   CONCAT('Receipt #', COALESCE(NULLIF(s.receipt_number, ''), NULLIF(s.receipt_no, ''), CONCAT('REC-', LPAD(s.id, 6, '0'))),
                          ' | Total: ₱', FORMAT(s.total_price, 2), ' | Paid via: ', COALESCE(s.payment_method, 'Cash')) as log_details,
                   s.user_id, s.created_at, COALESCE(s.store_id, 1) as store_id,
                   COALESCE(u.fullname, 'Cashier') as performer, COALESCE(u.role, 'Cashier') as performer_role,
                   u.avatar as performer_avatar, u.profile_image as performer_profile_image
            FROM sales s
            LEFT JOIN users u ON s.user_id = u.id
            WHERE s.created_at BETWEEN ? AND ? {$sales_store_clause}
        ");
        $st_s->execute([$start_dt, $end_dt]);
        $sales_logs = $st_s->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $t) {}

// Incorporate Stock Movement History
$stock_logs = [];
try {
    $hasStockHist = ($pdo->query("SHOW TABLES LIKE 'stock_history'")->rowCount() > 0);
    if ($hasStockHist) {
        $st_sh = $pdo->prepare("
            SELECT CONCAT('stock_', sh.id) as id, CONCAT('STOCK_', UPPER(sh.type)) as log_type, 'Inventory' as page_module,
                   CONCAT('Item: ', COALESCE(NULLIF(TRIM(sh.product_name), ''), CONCAT('#', sh.product_id)), 
                          ' | Qty: ', sh.quantity, ' pcs | Stock After: ', sh.stock_after, ' pcs | ', COALESCE(sh.notes, 'Stock logged')) as log_details,
                   sh.user_id, sh.created_at, COALESCE(sh.store_id, 1) as store_id,
                   COALESCE(u.fullname, 'Inventory Admin') as performer, COALESCE(u.role, 'Admin') as performer_role,
                   u.avatar as performer_avatar, u.profile_image as performer_profile_image
            FROM stock_history sh
            LEFT JOIN users u ON sh.user_id = u.id
            WHERE sh.created_at BETWEEN ? AND ? {$sh_store_clause}
        ");
        $st_sh->execute([$start_dt, $end_dt]);
        $stock_logs = $st_sh->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $t) {}

// Merge & Sort Chronologically
$combined_logs = array_merge($raw_logs, $sales_logs, $stock_logs);
usort($combined_logs, function($a, $b) {
    return strtotime($b['created_at']) <=> strtotime($a['created_at']);
});

// Process Metrics & Live Filter
$audit_logs = [];
$total_all_count     = 0;
$total_logins        = 0;
$total_failed_logins = 0;
$total_sales_tx      = 0;
$total_edits         = 0;
$total_deletes       = 0;

foreach ($combined_logs as $row) {
    $atype = strtoupper($row['log_type'] ?? '');
    $det   = strtoupper($row['log_details'] ?? '');

    $isLoginAttempt = (strpos($atype, 'LOGIN') !== false || strpos($atype, 'AUTH') !== false || strpos($atype, 'LOGOUT') !== false || strpos($det, 'LOGIN') !== false || strpos($det, 'PASSWORD') !== false);
    $isFailedLogin  = ($isLoginAttempt && (strpos($atype, 'FAIL') !== false || strpos($det, 'FAIL') !== false || strpos($det, 'WRONG') !== false || strpos($det, 'INCORRECT') !== false || strpos($det, 'INVALID') !== false || strpos($det, 'UNAUTHORIZED') !== false));
    $isSaleTx       = (strpos($atype, 'SALE') !== false || strpos($atype, 'TRANSACTION') !== false || strpos($atype, 'CHECKOUT') !== false || strpos($det, 'RECEIPT') !== false);
    $isEdit         = (strpos($atype, 'PRICE') !== false || strpos($atype, 'UPDATE') !== false || strpos($atype, 'EDIT') !== false || strpos($atype, 'RESTOCK') !== false || strpos($atype, 'STOCK') !== false);
    $isDelete       = (strpos($atype, 'DELETE') !== false || strpos($atype, 'REMOVE') !== false || strpos($atype, 'TRASH') !== false || strpos($atype, 'VOID') !== false);

    $total_all_count++;
    if ($isLoginAttempt) {
        $total_logins++;
        if ($isFailedLogin) $total_failed_logins++;
    }
    if ($isSaleTx) $total_sales_tx++;
    if ($isEdit) $total_edits++;
    if ($isDelete) $total_deletes++;

    $match = false;
    if ($action_type === 'ALL') {
        $match = true;
    } elseif ($action_type === 'LOGIN' && $isLoginAttempt) {
        $match = true;
    } elseif ($action_type === 'FAILED_LOGIN' && $isFailedLogin) {
        $match = true;
    } elseif ($action_type === 'TRANSACTION' && $isSaleTx) {
        $match = true;
    } elseif ($action_type === 'PRICE_EDIT' && $isEdit) {
        $match = true;
    } elseif ($action_type === 'DELETE' && $isDelete) {
        $match = true;
    } elseif ($action_type === 'SECURITY' && (strpos($atype, 'USER') !== false || strpos($atype, 'SETTING') !== false || strpos($atype, 'AVATAR') !== false || strpos($atype, 'PASSWORD') !== false)) {
        $match = true;
    }

    if ($match && !empty($search)) {
        $haystack = strtolower($row['log_type'] . ' ' . $row['log_details'] . ' ' . ($row['performer'] ?? '') . ' ' . $row['page_module']);
        if (strpos($haystack, strtolower($search)) === false) {
            $match = false;
        }
    }

    if ($match) {
        if (empty($row['performer']) || (int)($row['user_id'] ?? 0) === 0) {
            if (preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $row['log_details'], $mEmail)) {
                $row['performer'] = $mEmail[0];
                $row['performer_role'] = 'ATTEMPTED';
            } else {
                $row['performer'] = $isFailedLogin ? 'Unauthorized Guest' : 'System';
                $row['performer_role'] = $isFailedLogin ? 'SECURITY ALERT' : 'SYSTEM';
            }
        }
        $row['is_failed_login'] = $isFailedLogin;
        $row['is_sale_tx']      = $isSaleTx;
        $row['is_delete']       = $isDelete;
        $row['is_login']        = $isLoginAttempt;
        $audit_logs[] = $row;
    }
}

// Data for AI Chatbot
$cb_today_sales = 0.0;
try {
    $cb_today_sales = (float)($pdo->query("SELECT COALESCE(SUM(total_price), 0) FROM sales WHERE DATE(created_at) = CURDATE() {$sales_store_clause}")->fetchColumn() ?: 0);
} catch (Throwable $t) {}

$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($settings['store_name']) ?> - Audit &amp; Activity Logs</title>
    <!-- Tailwind CSS, FontAwesome 6 & Fonts -->
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
        .custom-input { background: #fff; border: 1.5px solid #cbd5e1; border-radius: 10px; padding: 6px 12px; font-size: 12px; font-weight: 700; color: #0f172a; outline: none; }
        .custom-input:focus { border-color: #f59e0b; box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.15); }

        @media print {
            @page { size: A4 landscape; margin: 8mm 10mm; }
            body { background: #fff !important; color: #000 !important; overflow: visible !important; height: auto !important; }
            .no-print, header, #sidebar, form, button, #aiChatbotContainer { display: none !important; }
            #printableLogArea { border: none !important; box-shadow: none !important; width: 100% !important; margin: 0 !important; }
            table { width: 100% !important; border-collapse: collapse !important; }
            th { background: #f8fafc !important; color: #000 !important; border-bottom: 2px solid #000 !important; font-size: 10px !important; padding: 6px !important; }
            td { padding: 6px !important; border-bottom: 1px solid #cbd5e1 !important; font-size: 10px !important; }
            .print-only-header { display: block !important; }
        }
        .print-only-header { display: none; }
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
                <a href="?store_id=both&action_type=<?= $action_type ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>" class="px-3.5 py-1.5 text-xs font-black rounded-lg transition-all <?= $selected_store === 'both' ? 'bg-amber-500 text-slate-950 shadow' : 'text-slate-300 hover:text-white' ?>">Both Stores</a>
                <a href="?store_id=1&action_type=<?= $action_type ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>" class="px-3.5 py-1.5 text-xs font-black rounded-lg transition-all <?= $selected_store === '1' ? 'bg-amber-500 text-slate-950 shadow' : 'text-slate-300 hover:text-white' ?>">Store 1</a>
                <a href="?store_id=2&action_type=<?= $action_type ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>" class="px-3.5 py-1.5 text-xs font-black rounded-lg transition-all <?= $selected_store === '2' ? 'bg-amber-500 text-slate-950 shadow' : 'text-slate-300 hover:text-white' ?>">Store 2</a>
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
                        <a href="audit_logs.php?store_id=<?= urlencode($selected_store) ?>&mark_read=all" class="py-3 text-emerald-600 hover:text-emerald-700 hover:bg-emerald-50 transition-colors border-r border-slate-200">
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
                <a href="reports.php?store_id=<?= urlencode($selected_store) ?>" class="flex items-center px-4 py-3 rounded-xl font-black text-xs transition-all text-slate-300 hover:bg-slate-900 hover:text-white">
                    <i class="fa-solid fa-file-invoice-dollar w-5 text-center text-sm"></i><span class="ml-3 sidebar-text">Reports</span>
                </a>
                <a href="audit_logs.php?store_id=<?= urlencode($selected_store) ?>" class="flex items-center px-4 py-3 rounded-xl font-black text-xs transition-all bg-amber-500 text-slate-950 shadow-md">
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

        <!-- MAIN LOGS WORKSPACE -->
        <div class="flex-1 p-5 md:p-6 overflow-y-auto custom-scroll space-y-4 bg-slate-100">

            <!-- SUB-HEADER BANNER -->
            <div class="bg-white p-5 rounded-2xl shadow-sm border border-slate-200 flex flex-col md:flex-row justify-between items-start md:items-center gap-3 no-print">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="px-2 py-0.5 bg-amber-100 text-amber-900 text-[10px] font-black rounded uppercase"><?= htmlspecialchars($store_label) ?></span>
                        <span class="text-xs font-bold text-slate-400">• Complete Audit Trail</span>
                    </div>
                    <h2 class="text-base md:text-lg font-black text-slate-900 uppercase tracking-tight mt-1">Audit &amp; Transaction Logs</h2>
                    <p class="text-xs font-semibold text-slate-500">Live trace of cashier receipts, restocks, logins, security alerts, and master changes.</p>
                </div>

                <div class="flex items-center gap-2 self-end md:self-auto">
                    <button type="button" onclick="exportTableToCSV('audit_logs_<?= $selected_store ?>.csv')" class="bg-[#070a12] hover:bg-slate-800 text-white font-black px-3.5 py-2 rounded-xl text-xs uppercase transition shadow flex items-center gap-2">
                        <i class="fas fa-file-excel text-amber-400"></i> Export CSV
                    </button>
                    <button type="button" onclick="window.print()" class="bg-amber-500 hover:bg-amber-600 text-slate-950 font-black px-3.5 py-2 rounded-xl text-xs uppercase transition shadow flex items-center gap-2">
                        <i class="fas fa-print"></i> Print
                    </button>
                </div>
            </div>

            <!-- 4 SUMMARY CARDS (1-2 WORDS DIRECT ENGLISH) -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 no-print">
                <div onclick="filterByAction('ALL')" class="bg-white p-4 rounded-2xl shadow-sm border border-slate-200 cursor-pointer hover:border-amber-500 transition-all <?= $action_type === 'ALL' ? 'ring-2 ring-amber-500 bg-amber-50/20' : '' ?>">
                    <p class="text-[10px] font-black uppercase text-slate-400 tracking-wider">Total Logs</p>
                    <p class="text-xl font-black text-slate-900 mt-1"><?= number_format($total_all_count) ?></p>
                </div>

                <div onclick="filterByAction('LOGIN')" class="bg-white p-4 rounded-2xl shadow-sm border border-slate-200 cursor-pointer hover:border-amber-500 transition-all <?= $action_type === 'LOGIN' ? 'ring-2 ring-amber-500 bg-amber-50/20' : '' ?>">
                    <p class="text-[10px] font-black uppercase text-slate-400 tracking-wider">Logins</p>
                    <p class="text-xl font-black text-slate-900 mt-1">
                        <?= number_format($total_logins) ?>
                        <?php if ($total_failed_logins > 0): ?>
                            <span class="text-xs font-bold text-red-600">(<?= $total_failed_logins ?> failed)</span>
                        <?php endif; ?>
                    </p>
                </div>

                <div onclick="filterByAction('TRANSACTION')" class="bg-white p-4 rounded-2xl shadow-sm border border-slate-200 cursor-pointer hover:border-emerald-500 transition-all <?= $action_type === 'TRANSACTION' ? 'ring-2 ring-emerald-500 bg-emerald-50/20' : '' ?>">
                    <p class="text-[10px] font-black uppercase text-slate-400 tracking-wider">Sales Receipts</p>
                    <p class="text-xl font-black text-emerald-600 mt-1"><?= number_format($total_sales_tx) ?></p>
                </div>

                <div onclick="filterByAction('PRICE_EDIT')" class="bg-white p-4 rounded-2xl shadow-sm border border-slate-200 cursor-pointer hover:border-amber-500 transition-all <?= in_array($action_type, ['PRICE_EDIT', 'DELETE']) ? 'ring-2 ring-amber-500 bg-amber-50/20' : '' ?>">
                    <p class="text-[10px] font-black uppercase text-slate-400 tracking-wider">Modifications</p>
                    <p class="text-xl font-black text-slate-900 mt-1"><?= number_format($total_edits + $total_deletes) ?></p>
                </div>
            </div>

            <!-- SEARCH AND FILTER CONTROLS -->
            <div class="bg-white p-4 rounded-2xl shadow-sm border border-slate-200 no-print">
                <form id="auditFilterForm" method="GET" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-5 gap-3 items-end">
                    <input type="hidden" name="store_id" value="<?= htmlspecialchars($selected_store) ?>">

                    <div>
                        <span class="text-[10px] font-black text-slate-500 uppercase block mb-1">Action Type</span>
                        <select name="action_type" id="actionTypeSelect" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-3 py-1.5 text-xs font-bold text-slate-900 focus:outline-none focus:ring-2 focus:ring-amber-500 cursor-pointer">
                            <option value="ALL" <?= $action_type === 'ALL' ? 'selected' : '' ?>>All Actions &amp; Sales</option>
                            <option value="LOGIN" <?= $action_type === 'LOGIN' ? 'selected' : '' ?>>Logins &amp; Auth</option>
                            <option value="FAILED_LOGIN" <?= $action_type === 'FAILED_LOGIN' ? 'selected' : '' ?>>Failed Logins</option>
                            <option value="TRANSACTION" <?= $action_type === 'TRANSACTION' ? 'selected' : '' ?>>Sales &amp; Receipts</option>
                            <option value="PRICE_EDIT" <?= $action_type === 'PRICE_EDIT' ? 'selected' : '' ?>>Restocks &amp; Edits</option>
                            <option value="DELETE" <?= $action_type === 'DELETE' ? 'selected' : '' ?>>Deletions &amp; Voids</option>
                            <option value="SECURITY" <?= $action_type === 'SECURITY' ? 'selected' : '' ?>>Security &amp; Users</option>
                        </select>
                    </div>

                    <div>
                        <span class="text-[10px] font-black text-slate-500 uppercase block mb-1">From</span>
                        <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" class="custom-input w-full">
                    </div>

                    <div>
                        <span class="text-[10px] font-black text-slate-500 uppercase block mb-1">To</span>
                        <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" class="custom-input w-full">
                    </div>

                    <div>
                        <span class="text-[10px] font-black text-slate-500 uppercase block mb-1">Search</span>
                        <div class="relative">
                            <i class="fas fa-search absolute left-3 top-2.5 text-slate-400 text-xs"></i>
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Receipt, user, item..." class="w-full bg-slate-50 border border-slate-300 rounded-xl pl-8 pr-3 py-1.5 text-xs font-bold text-slate-900 focus:outline-none focus:ring-2 focus:ring-amber-500">
                        </div>
                    </div>

                    <div class="flex items-center gap-1.5">
                        <button type="submit" class="flex-1 bg-[#070a12] hover:bg-slate-800 text-white font-black py-2 rounded-xl text-xs uppercase transition shadow flex items-center justify-center gap-1.5">
                            <i class="fas fa-filter text-amber-400"></i> Filter
                        </button>
                        <a href="audit_logs.php?store_id=<?= urlencode($selected_store) ?>" class="bg-slate-200 hover:bg-slate-300 text-slate-800 px-3 py-2 rounded-xl text-xs font-black uppercase transition border border-slate-300">
                            Reset
                        </a>
                    </div>
                </form>
            </div>

            <!-- AUDIT TABLE -->
            <div id="printableLogArea" class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
                
                <!-- PRINT HEADER -->
                <div class="print-only-header p-5 border-b-2 border-slate-900 bg-white">
                    <div class="flex justify-between items-start">
                        <div>
                            <h1 class="text-xl font-black text-slate-950 uppercase"><?= htmlspecialchars($settings['store_name']) ?></h1>
                            <h2 class="text-xs font-black text-slate-700 uppercase">OFFICIAL AUDIT LEDGER &bull; <?= htmlspecialchars($store_label) ?></h2>
                            <p class="text-[10px] text-slate-500 font-bold mt-1">Period: <?= date('F d, Y', strtotime($start_date)) ?> to <?= date('F d, Y', strtotime($end_date)) ?></p>
                        </div>
                        <div class="text-right text-[10px] font-bold text-slate-700">
                            <p>Printed: <?= date('M d, Y h:i A') ?></p>
                            <p>Auditor: <?= htmlspecialchars($currentUserFullname) ?></p>
                        </div>
                    </div>
                </div>

                <div class="p-4 border-b border-slate-200 flex justify-between items-center bg-slate-50 no-print">
                    <div>
                        <h3 class="text-xs font-black text-slate-900 uppercase">Activity Ledger</h3>
                        <p class="text-[10px] font-bold text-slate-500 uppercase mt-0.5">Chronological Trace &bull; <?= htmlspecialchars($store_label) ?></p>
                    </div>
                    <span class="text-xs font-black text-slate-700 bg-slate-200 px-3 py-1 rounded-lg">
                        <?= count($audit_logs) ?> records
                    </span>
                </div>

                <div class="overflow-x-auto max-h-[620px] overflow-y-auto custom-scroll">
                    <table class="w-full text-left text-xs" id="dataTable">
                        <thead class="bg-slate-100 text-slate-900 uppercase font-black border-b border-slate-200 sticky top-0 z-10 text-[11px]">
                            <tr>
                                <th class="p-3">Date</th>
                                <th class="p-3">User</th>
                                <th class="p-3">Module</th>
                                <th class="p-3 text-center">Store</th>
                                <th class="p-3 text-center">Type</th>
                                <th class="p-3">Activity Details</th>
                                <th class="p-3 text-right">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 font-bold text-slate-800">
                            <?php if (!empty($audit_logs)): ?>
                                <?php foreach ($audit_logs as $log): 
                                    $isFailed = !empty($log['is_failed_login']);
                                    $isSale   = !empty($log['is_sale_tx']);
                                    $isDel    = !empty($log['is_delete']);
                                ?>
                                <tr class="hover:bg-slate-50 transition <?= $isFailed ? 'bg-red-50/40' : '' ?>">
                                    <td class="p-3 whitespace-nowrap">
                                        <p class="font-black text-slate-900"><?= date('M d, Y', strtotime($log['created_at'])) ?></p>
                                        <p class="text-[10px] text-slate-400 font-mono"><?= date('h:i:s A', strtotime($log['created_at'])) ?></p>
                                    </td>

                                    <td class="p-3 whitespace-nowrap">
                                        <p class="font-black text-xs <?= $isFailed ? 'text-red-700' : 'text-slate-900' ?>">
                                            <?= htmlspecialchars($log['performer']) ?>
                                        </p>
                                        <span class="text-[9px] font-black uppercase text-slate-500">
                                            <?= htmlspecialchars($log['performer_role']) ?>
                                        </span>
                                    </td>

                                    <td class="p-3 whitespace-nowrap">
                                        <span class="px-2 py-0.5 rounded bg-slate-100 text-slate-700 text-[10px] font-black uppercase">
                                            <?= htmlspecialchars($log['page_module']) ?>
                                        </span>
                                    </td>

                                    <td class="p-3 text-center whitespace-nowrap">
                                        <span class="px-2 py-0.5 rounded text-[10px] font-black uppercase bg-slate-100 text-slate-600">
                                            Store #<?= $log['store_id'] ?>
                                        </span>
                                    </td>

                                    <td class="p-3 text-center whitespace-nowrap">
                                        <span class="px-2 py-0.5 rounded border text-[9px] font-black uppercase <?= $isFailed ? 'bg-red-100 text-red-800 border-red-300' : ($isSale ? 'bg-emerald-50 text-emerald-800 border-emerald-300' : ($isDel ? 'bg-rose-50 text-rose-800 border-rose-300' : 'bg-white text-slate-800 border-slate-300')) ?>">
                                            <?= htmlspecialchars(cleanActionText($log['log_type'])) ?>
                                        </span>
                                    </td>

                                    <td class="p-3 leading-relaxed break-words">
                                        <?= htmlspecialchars(cleanActionText($log['log_details'])) ?>
                                    </td>

                                    <td class="p-3 text-right whitespace-nowrap">
                                        <?php if ($isFailed): ?>
                                            <span class="px-2 py-0.5 rounded-full text-[9px] font-black uppercase bg-red-100 text-red-800">FAILED</span>
                                        <?php elseif ($isSale): ?>
                                            <span class="px-2 py-0.5 rounded-full text-[9px] font-black uppercase bg-emerald-100 text-emerald-800">SALE</span>
                                        <?php elseif ($isDel): ?>
                                            <span class="px-2 py-0.5 rounded-full text-[9px] font-black uppercase bg-rose-100 text-rose-800">VOID</span>
                                        <?php else: ?>
                                            <span class="px-2 py-0.5 rounded-full text-[9px] font-black uppercase bg-slate-100 text-slate-700">LOGGED</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="p-8 text-center text-slate-400 font-bold">
                                        No audit records found in this timeframe.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

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
                        <p class="text-[10px] font-bold text-slate-400 mt-0.5">Audit Trail &amp; Store Intelligence</p>
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
                <button type="button" onclick="sendAiQuickPrompt('I-audit ang mga logins at failed security attempts')" class="bg-white hover:bg-slate-100 hover:border-slate-300 border border-slate-200/90 px-3 py-1 rounded-lg transition-all shrink-0">
                    🛡️ Security &amp; Logins
                </button>
                <button type="button" onclick="sendAiQuickPrompt('Ilan ang naitalang sales transactions at kita ngayon?')" class="bg-white hover:bg-slate-100 hover:border-slate-300 border border-slate-200/90 px-3 py-1 rounded-lg transition-all shrink-0">
                    💳 Benta &amp; Cashier
                </button>
                <button type="button" onclick="sendAiQuickPrompt('Ano ang mga huling pagbabago sa imbentaryo at stock restock?')" class="bg-white hover:bg-slate-100 hover:border-slate-300 border border-slate-200/90 px-3 py-1 rounded-lg transition-all shrink-0">
                    📦 Stock Adjustments
                </button>
                <button type="button" onclick="sendAiQuickPrompt('Mayroon bang mga deletion o void na nangyari?')" class="bg-white hover:bg-slate-100 hover:border-slate-300 border border-slate-200/90 px-3 py-1 rounded-lg transition-all shrink-0">
                    🗑️ Deletions / Voids
                </button>
                <button type="button" onclick="sendAiQuickPrompt('Magbigay ng payo para sa seguridad at auditing ng tindahan')" class="bg-white hover:bg-slate-100 hover:border-slate-300 border border-slate-200/90 px-3 py-1 rounded-lg transition-all shrink-0">
                    💡 Audit Advice
                </button>
            </div>

            <!-- Chat Messages Log -->
            <div id="aiChatMessages" class="flex-1 p-4 overflow-y-auto custom-scroll space-y-3.5 bg-[#f8fafc] text-xs">
                <div class="flex items-start gap-2.5">
                    <div class="w-7 h-7 rounded-lg bg-[#0f172a] text-amber-500 flex items-center justify-center text-[11px] font-black shrink-0 border border-slate-700">
                        AI
                    </div>
                    <div class="bg-white p-3.5 rounded-2xl rounded-tl-sm border border-slate-200/90 text-slate-800 shadow-2xs leading-relaxed max-w-[88%]">
                        Magandang araw! Ako ang inyong <b>JJ Enterprise AI Assistant</b>. Naka-link ako sa live audit logs, point-of-sale transactions, at stock movements ng <b><?= htmlspecialchars($store_label) ?></b>. Paano ko kayo matutulungan sa pag-audit ngayon?
                    </div>
                </div>
            </div>

            <!-- Chat Input Form -->
            <form id="aiChatForm" class="p-3 bg-white border-t border-slate-200 flex items-center gap-2">
                <input type="text" id="aiChatInput" placeholder="Magtanong tungkol sa security, logins, cashier sales, o audits..." class="flex-1 bg-slate-50 focus:bg-white border border-slate-300 focus:border-amber-500 text-slate-800 text-xs rounded-xl px-3.5 py-2.5 outline-none font-semibold transition-all">
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

        // Action Quick Filter
        function filterByAction(type) {
            const select = document.getElementById('actionTypeSelect');
            const form = document.getElementById('auditFilterForm');
            if (select && form) {
                select.value = type;
                form.submit();
            }
        }

        // CSV Exporter
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

        // EXECUTIVE AI CHATBOT LOGIC
        const toggleAiChat = document.getElementById('toggleAiChat');
        const closeAiChat = document.getElementById('closeAiChat');
        const clearAiChat = document.getElementById('clearAiChat');
        const aiChatWindow = document.getElementById('aiChatWindow');
        const aiChatForm = document.getElementById('aiChatForm');
        const aiChatInput = document.getElementById('aiChatInput');
        const aiChatMessages = document.getElementById('aiChatMessages');

        const liveMetrics = {
            storeLabel: <?= json_encode($store_label) ?>,
            totalLogs: <?= (int)$total_all_count ?>,
            logins: <?= (int)$total_logins ?>,
            failedLogins: <?= (int)$total_failed_logins ?>,
            salesCount: <?= (int)$total_sales_tx ?>,
            editsCount: <?= (int)$total_edits ?>,
            deletesCount: <?= (int)$total_deletes ?>,
            todaySales: <?= (float)$cb_today_sales ?>
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
                            Nalinis na ang usapan. Handa akong magbigay ng pagsusuri sa audit logs at security trail ng inyong tindahan!
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
            const m = liveMetrics;

            if (q.includes('security') || q.includes('login') || q.includes('fail') || q.includes('auth')) {
                return `
                    <div class="space-y-1.5">
                        <div class="font-extrabold text-slate-900 border-b border-slate-100 pb-1">
                            🛡️ Security &amp; Login Audit (${m.storeLabel})
                        </div>
                        <p class="text-slate-700 leading-normal">
                            • Kabuuang Login Attempts: <b>${m.logins.toLocaleString()} na beses</b><br>
                            • Failed / Invalid Attempts: <b class="${m.failedLogins > 0 ? 'text-red-600' : 'text-emerald-700'}">${m.failedLogins} na insidente</b><br>
                            ${m.failedLogins > 0 ? '⚠️ Paalala: May mga naitalang maling password o hindi awtorisadong pagtatangka.' : '✅ Malusog ang security audit logs.'}
                        </p>
                    </div>
                `;
            }

            if (q.includes('benta') || q.includes('sale') || q.includes('cashier') || q.includes('transaction') || q.includes('receipt')) {
                return `
                    <div class="space-y-1.5">
                        <div class="font-extrabold text-slate-900 border-b border-slate-100 pb-1">
                            💳 Sales &amp; POS Activity (${m.storeLabel})
                        </div>
                        <p class="text-slate-700 leading-normal">
                            • Naitalang Cashier Receipts: <b>${m.salesCount.toLocaleString()} transaksyon</b> sa saklaw ng filter.<br>
                            • Tinatayang Benta Ngayong Araw: <b>₱${m.todaySales.toLocaleString(undefined, {minimumFractionDigits: 2})}</b>
                        </p>
                    </div>
                `;
            }

            if (q.includes('stock') || q.includes('edit') || q.includes('restock') || q.includes('adjust') || q.includes('imbentaryo')) {
                return `
                    <div class="space-y-1.5">
                        <div class="font-extrabold text-slate-900 border-b border-slate-100 pb-1">
                            📦 Inventory &amp; Stock Modifications (${m.storeLabel})
                        </div>
                        <p class="text-slate-700 leading-normal">
                            • Naitalang Product Edits &amp; Box Restocks: <b>${m.editsCount.toLocaleString()} events</b>.<br>
                            • Naka-sync ang lahat ng galaw sa <b>stock_history</b> at inventory master table.
                        </p>
                    </div>
                `;
            }

            if (q.includes('delete') || q.includes('void') || q.includes('bura') || q.includes('tanggal')) {
                return `
                    <div class="space-y-1.5">
                        <div class="font-extrabold text-slate-900 border-b border-slate-100 pb-1">
                            🗑️ Deletion &amp; Void Trail (${m.storeLabel})
                        </div>
                        <p class="text-slate-700 leading-normal">
                            • Kabuuang Deletions / Voids: <b class="${m.deletesCount > 0 ? 'text-amber-700' : 'text-slate-900'}">${m.deletesCount} na tala</b>.<br>
                            Maaaring i-restore ng Admin ang mga na-delete na produkto mula sa Trash bin ng Inventory module.
                        </p>
                    </div>
                `;
            }

            if (q.includes('payo') || q.includes('advice') || q.includes('suggest') || q.includes('rekomendasyon')) {
                return `
                    <div class="space-y-1.5">
                        <div class="font-extrabold text-slate-900 border-b border-slate-100 pb-1">
                            💡 Opisyal na Payo sa Auditing
                        </div>
                        <p class="text-slate-700 leading-normal">
                            1. <b>Bantayan ang Failed Logins:</b> Kung may higit sa 3 sunod-sunod na failed attempt sa iisang account, i-update agad ang credentials.<br>
                            2. <b>I-cross check ang Resibo:</b> Ikumpara ang Sales ledger sa actual cashier cash turnover bago magsara ng shift.<br>
                            3. <b>Regular na Stock Audit:</b> Gamitin ang 'Audit Verification' button sa Inventory page pagkatapos mag-count ng boxes.
                        </p>
                    </div>
                `;
            }

            return `
                Naka-konekta ako sa live audit records ng <b>${m.storeLabel}</b> (${m.totalLogs.toLocaleString()} total events). Maaari mong itanong:<br>
                • <i>"I-audit ang logins at failed security attempts"</i><br>
                • <i>"Ilan ang naitalang sales transactions?"</i><br>
                • <i>"Mayroon bang mga deletion o void?"</i><br>
                • <i>"Ano ang mga huling stock adjustments?"</i>
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
                        <span class="animate-pulse">Sinusuri ang audit trail at logs...</span>
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
<?php
// 1. Session lifetime settings 
ini_set('session.gc_maxlifetime', 86400); // 24 oras
session_set_cookie_params([
    'lifetime' => 86400,
    'path'     => '/',
    'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
    'httponly' => true,
    'samesite' => 'Lax'
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Manila');

// Anti-cache headers
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function isValidCsrfToken(): bool {
    return isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token']);
}

// Database connection
function getDbConnection(): PDO {
    $host = 'localhost'; 
    $dbname = 'JJ_Stores'; 
    $username = 'root'; 
    $password = '';
    try {
        return new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, 
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false
        ]);
    } catch (PDOException $e) { 
        die("<div style='text-align:center; padding:50px; font-family:sans-serif;'>
                <h2>Database Connection Failed</h2>
                <p>Cannot connect to MySQL server. Please make sure MySQL is running.</p>
             </div>"); 
    }
}

// Temporary password generator
function generateSecurePassword(int $length = 10): string {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $password;
}

// Safe redirect function
function redirectUserByRole($rawRole) {
    $role = strtolower(trim($rawRole ?? 'customer'));
    $cleanRole = str_replace([' ', '_', '-'], '', $role);

    $destination = "sales/index.php";
    if (in_array($cleanRole, ['admin', 'administrator'], true)) {
        $destination = "Admin/index.php";
    } elseif (in_array($cleanRole, ['inventorystaff', 'inventory', 'stockstaff', 'stock'], true)) {
        $destination = "Inventorystaff/index.php";
    }

    session_write_close();

    if (!headers_sent()) {
        header("Location: " . $destination);
    }
    echo "<script>window.location.href = " . json_encode($destination) . ";</script>";
    exit();
}

$error = ""; 
$success = "";

// URL alerts & existing session check
// FIX: I-check lamang ang $_GET['error'] kapag GET request at WALANG aktibong OTP process para hindi mabura ang session kapag nag-submit ng form.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['error']) && empty($_SESSION['pending_otp'])) {
    if ($_GET['error'] === 'login') {
        $error = "Session expired. Please sign in again.";
    } elseif ($_GET['error'] === 'unauthorized') {
        $error = "Access denied or account suspended.";
    }
    unset($_SESSION['user_id'], $_SESSION['role'], $_SESSION['pending_otp'], $_SESSION['otp_code'], $_SESSION['otp_expires'], $_SESSION['otp_attempts']);
} elseif (!empty($_SESSION['user_id']) && !empty($_SESSION['role'])) {
    redirectUserByRole($_SESSION['role']);
}

// PHPMailer loader
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (file_exists(__DIR__ . '/PHPMailer/src/Exception.php')) {
    require_once __DIR__ . '/PHPMailer/src/Exception.php';
    require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
    require_once __DIR__ . '/PHPMailer/src/SMTP.php';
}

function sendEmail($toEmail, $subject, $message) {
    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) return false;
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP(); 
        $mail->Host       = 'smtp.gmail.com'; 
        $mail->SMTPAuth   = true;
        $mail->Username   = 'user@gmail.com'; 
        $mail->Password   = 'your_password'; 
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; 
        $mail->Port       = 587;

        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true
            ]
        ];

        $mail->setFrom('user@gmail.com', 'SIGN IN');
        $mail->addAddress($toEmail); 
        $mail->isHTML(true);
        $mail->Subject = $subject; 
        $mail->Body    = $message;
        return $mail->send(); 
    } catch (Exception $e) { 
        return false; 
    }
}

function sendOtpEmail($email, $fullname, $otp) {
    $subject = "Your Verification Code: {$otp}";
    $message = "
        <div style='font-family:sans-serif; max-width:400px; margin:20px auto; padding:20px; border:1px solid #ddd; border-radius:8px;'>
            <h2 style='color:#d97706; margin:0 0 10px 0;'>JJ Judejush Store</h2>
            <p style='color:#333; font-size:14px;'>Hello <b>" . htmlspecialchars($fullname) . "</b>,</p>
            <p style='color:#555; font-size:14px;'>Here is your verification code:</p>
            <div style='background:#fef3c7; border:1px dashed #f59e0b; border-radius:6px; padding:15px; text-align:center; margin:15px 0;'>
                <span style='font-size:32px; font-weight:bold; letter-spacing:6px; color:#b45309;'>{$otp}</span>
            </div>
            <p style='color:#777; font-size:12px;'>This code will expire in 10 minutes. Do not share it with anyone.</p>
        </div>
    ";
    return sendEmail($email, $subject, $message);
}


// 1. Cancel OTP
if (isset($_POST['action']) && $_POST['action'] === 'cancel_otp') {
    unset($_SESSION['pending_otp'], $_SESSION['otp_code'], $_SESSION['otp_expires'], $_SESSION['otp_attempts'], $_SESSION['last_otp_resend']);
    header("Location: login.php");
    exit();
}

// 2. Resend OTP
if (isset($_POST['action']) && $_POST['action'] === 'resend_otp') {
    if (!isValidCsrfToken()) {
        $error = "Session expired. Please try again.";
    } elseif (empty($_SESSION['pending_otp'])) {
        $error = "Session expired. Please sign in again.";
    } elseif (isset($_SESSION['last_otp_resend']) && (time() - $_SESSION['last_otp_resend'] < 60)) {
        $wait = 60 - (time() - $_SESSION['last_otp_resend']);
        $error = "Please wait {$wait} seconds before resending.";
    } else {
        $otp = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        $_SESSION['otp_code']        = $otp;
        $_SESSION['otp_expires']     = time() + 600; // 10 minutes
        $_SESSION['otp_attempts']    = 0;
        $_SESSION['last_otp_resend'] = time();

        if (sendOtpEmail($_SESSION['pending_otp']['email'], $_SESSION['pending_otp']['fullname'], $otp)) {
            $success = "A new verification code has been sent to your email.";
        } else {
            $error = "Failed to send email. Please check your connection.";
        }
    }
}

// 3. Verify OTP
if (isset($_POST['action']) && $_POST['action'] === 'verify_otp') {
    if (!isValidCsrfToken()) {
        $error = "Security token mismatch. Please try again.";
    } elseif (empty($_SESSION['pending_otp']) || !isset($_SESSION['otp_code'])) {
        $error = "Verification session expired. Please sign in again.";
    } else {
        $enteredOtp = preg_replace('/[^0-9]/', '', (string)($_POST['otp_code'] ?? ''));
        $correctOtp = trim((string)$_SESSION['otp_code']);
        
        $_SESSION['otp_attempts'] = ($_SESSION['otp_attempts'] ?? 0) + 1;

        if (empty($_SESSION['otp_expires']) || time() > $_SESSION['otp_expires']) {
            $error = "Code expired. Please click Resend Code.";
        } elseif ($_SESSION['otp_attempts'] > 5) {
            $error = "Too many wrong attempts. Please click Resend Code for a new one.";
        } elseif ($enteredOtp !== $correctOtp) {
            $left = max(0, 5 - $_SESSION['otp_attempts']);
            $error = "Wrong code. You have {$left} attempt(s) remaining.";
        } else {
            // SUCCESS VERIFICATION
            $pending = $_SESSION['pending_otp'];
            $userId  = (int)$pending['id'];
            $role    = strtolower(trim($pending['role'] ?? 'customer'));

            $_SESSION['user_id']        = $userId; 
            $_SESSION['role']           = $role;
            $_SESSION['fullname']       = $pending['fullname'];
            $_SESSION['email']          = $pending['email'];
            $_SESSION['store_id']       = (int)$pending['store_id'];
            $_SESSION['contact_number'] = $pending['contact_number'];

            unset($_SESSION['pending_otp'], $_SESSION['otp_code'], $_SESSION['otp_expires'], $_SESSION['otp_attempts'], $_SESSION['last_otp_resend']);

            $db = getDbConnection();
            try {
                $db->prepare("UPDATE users SET status = 'Online', failed_attempts = 0, lockout_until = NULL WHERE id = ?")->execute([$userId]);
            } catch (Throwable $t) {
                try {
                    $db->prepare("UPDATE users SET status = 'Online', failed_attempts = 0, lockout_until = NULL WHERE user_id = ?")->execute([$userId]);
                } catch (Throwable $t2) {}
            }

            redirectUserByRole($role);
        }
    }
}

// 4. Forgot Password
if (isset($_POST['action']) && $_POST['action'] === 'forgot_password') {
    if (!isValidCsrfToken()) {
        $error = "Security token mismatch.";
    } else {
        $email = strtolower(trim($_POST['forgot_email'] ?? ''));
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $db = getDbConnection();
            $stmt = $db->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            if ($user) {
                $uid = $user['id'] ?? ($user['user_id'] ?? 0);
                $newPass = generateSecurePassword(10);
                $hashed = password_hash($newPass, PASSWORD_DEFAULT);
                try {
                    $db->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hashed, $uid]);
                } catch (Throwable $e) {
                    try {
                        $db->prepare("UPDATE users SET password = ? WHERE user_id = ?")->execute([$hashed, $uid]);
                    } catch (Throwable $t) {}
                }
                $body = "
                    <div style='font-family:sans-serif; padding:16px;'>
                        <p>Your temporary password is: <b>{$newPass}</b></p>
                        <p>Please change your password immediately after signing in.</p>
                    </div>";
                sendEmail($email, "Password Reset", $body);
            }
        }
        $success = "If this email is registered, temporary credentials have been sent.";
    }
}

// 5. Login
if (isset($_POST['action']) && $_POST['action'] === 'login') {
    if (!isValidCsrfToken()) {
        $error = "Invalid form submission.";
    } else {
        $identifier = trim($_POST['email'] ?? '');
        $password   = $_POST['password'] ?? '';

        $db = getDbConnection();
        $user = false;
        try {
            $stmt = $db->prepare("SELECT * FROM users WHERE email = ? OR username = ? LIMIT 1");
            $stmt->execute([$identifier, $identifier]);
            $user = $stmt->fetch();
        } catch (Throwable $t) {
            $stmt = $db->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$identifier]);
            $user = $stmt->fetch();
        }
        
        $userId = $user ? ($user['id'] ?? ($user['user_id'] ?? 0)) : 0;

        $isLocked = false;
        if ($user && !empty($user['lockout_until'])) {
            if (strtotime($user['lockout_until']) > time()) {
                $isLocked = true;
                $mins = ceil((strtotime($user['lockout_until']) - time()) / 60);
                $error = "Account is locked. Please try again after {$mins} minute(s).";
            }
        }

        if (!$isLocked) {
            $auth = false;
            if ($user && !empty($user['password'])) {
                if (password_verify($password, $user['password']) || $user['password'] === $password) {
                    $auth = true;
                }
            }

            if ($auth) {
                if (isset($user['failed_attempts'])) {
                    try {
                        $db->prepare("UPDATE users SET failed_attempts = 0, lockout_until = NULL WHERE id = ?")->execute([$userId]);
                    } catch (Throwable $e) {}
                }

                $targetEmail = $user['email'] ?? $identifier;
                if (!filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) {
                    $error = "Account has no valid email address for OTP.";
                } else {
                    $otp = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
                    $fullname = $user['fullname'] ?? ($user['username'] ?? 'User');

                    $_SESSION['pending_otp'] = [
                        'id'             => $userId,
                        'role'           => $user['role'] ?? 'customer',
                        'fullname'       => $fullname,
                        'email'          => $targetEmail,
                        'store_id'       => (int)($user['store_id'] ?? 1),
                        'contact_number' => $user['contact_number'] ?? ($user['phone'] ?? '')
                    ];
                    $_SESSION['otp_code']        = $otp;
                    $_SESSION['otp_expires']     = time() + 600; // 10 minutes (sapat na oras kahit may delay)
                    $_SESSION['otp_attempts']    = 0;
                    $_SESSION['last_otp_resend'] = time();

                    if (sendOtpEmail($targetEmail, $fullname, $otp)) {
                        $success = "Verification code sent to your email.";
                    } else {
                        $error = "Failed to send code. Please check your email settings.";
                        unset($_SESSION['pending_otp'], $_SESSION['otp_code'], $_SESSION['otp_expires']);
                    }
                }
            } else {
                if ($user && isset($user['failed_attempts'])) {
                    $attempts = (int)$user['failed_attempts'] + 1;
                    if ($attempts >= 5) {
                        $lock = date('Y-m-d H:i:s', time() + 900);
                        try {
                            $db->prepare("UPDATE users SET failed_attempts = ?, lockout_until = ? WHERE id = ?")->execute([$attempts, $lock, $userId]);
                        } catch (Throwable $e) {}
                        $error = "Too many failed attempts. Account locked for 15 minutes.";
                    } else {
                        try {
                            $db->prepare("UPDATE users SET failed_attempts = ? WHERE id = ?")->execute([$attempts, $userId]);
                        } catch (Throwable $e) {}
                        $left = 5 - $attempts;
                        $error = "Incorrect credentials. {$left} attempt(s) remaining.";
                    }
                } else {
                    $error = "Incorrect email or password.";
                }
            }
        }
    }
}

$isOtpStep = !empty($_SESSION['pending_otp']) && !empty($_SESSION['otp_code']);
$resendCooldown = $isOtpStep ? max(0, 60 - (time() - ($_SESSION['last_otp_resend'] ?? 0))) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isOtpStep ? 'Verify Code' : 'Sign In' ?> - SIGN IN</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen p-4 text-gray-800">

    <div class="w-full max-w-sm bg-white rounded-xl shadow-md p-6 border border-gray-200">
        
        <!-- Header -->
        <div class="text-center mb-6">
            <h1 class="text-xl font-bold text-amber-600 uppercase tracking-wide">
                <?= $isOtpStep ? 'Verify Code' : 'SIGN IN' ?>
            </h1>
            <p class="text-xs text-gray-500 mt-1">
                <?= $isOtpStep ? 'Enter the 6-digit code sent to your email' : 'Sign in to your account' ?>
            </p>
        </div>

        <!-- Alert messages -->
        <?php if(!empty($error)): ?>
            <div class="mb-4 p-2.5 bg-red-50 border-l-4 border-red-500 text-red-600 text-xs rounded flex justify-between items-center">
                <span><?= htmlspecialchars($error) ?></span>
                <button type="button" onclick="this.parentElement.remove()" class="text-red-400 hover:text-red-700 ml-2">&times;</button>
            </div>
        <?php endif; ?>

        <?php if(!empty($success)): ?>
            <div class="mb-4 p-2.5 bg-green-50 border-l-4 border-green-500 text-green-700 text-xs rounded flex justify-between items-center">
                <span><?= htmlspecialchars($success) ?></span>
                <button type="button" onclick="this.parentElement.remove()" class="text-green-400 hover:text-green-700 ml-2">&times;</button>
            </div>
        <?php endif; ?>

        <?php if ($isOtpStep): ?>
            <!-- OTP Form -->
            <form method="POST" action="login.php" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <input type="hidden" name="action" value="verify_otp">

                <div class="text-center bg-gray-50 p-2 rounded border border-gray-100 text-xs">
                    <span class="text-gray-500">Sent to:</span>
                    <span class="font-medium text-gray-700 block truncate"><?= htmlspecialchars($_SESSION['pending_otp']['email'] ?? '') ?></span>
                </div>

                <div>
                    <input type="text" name="otp_code" id="otp_code" maxlength="6" inputmode="numeric" 
                           placeholder="••••••" required autofocus autocomplete="one-time-code"
                           class="w-full text-center tracking-[0.4em] text-2xl font-bold py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-500">
                </div>

                <button type="submit" class="w-full py-2 bg-amber-500 hover:bg-amber-600 text-white font-semibold rounded-lg text-sm transition">
                    Verify Code
                </button>
            </form>

            <!-- Actions: Resend & Cancel -->
            <div class="mt-4 pt-3 border-t border-gray-100 flex items-center justify-between text-xs">
                <form method="POST" action="login.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="action" value="resend_otp">
                    <button type="submit" id="resendBtn" class="text-amber-600 font-medium hover:underline disabled:opacity-50 disabled:no-underline">
                        Resend Code <span id="resendTimer"></span>
                    </button>
                </form>

                <form method="POST" action="login.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="action" value="cancel_otp">
                    <button type="submit" class="text-gray-500 hover:text-gray-800">
                        Cancel
                    </button>
                </form>
            </div>

        <?php else: ?>
            <!-- Login Form -->
            <form method="POST" action="login.php" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <input type="hidden" name="action" value="login">

                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Email or Username</label>
                    <input type="text" name="email" placeholder="Enter your email " required
                           class="w-full px-3 py-2 text-sm border rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-500">
                </div>

                <div>
                    <div class="flex justify-between items-center mb-1">
                        <label class="block text-xs font-medium text-gray-600">Password</label>
                        <button type="button" onclick="document.getElementById('forgotModal').classList.remove('hidden')" class="text-xs text-amber-600 hover:underline">
                            Forgot?
                        </button>
                    </div>
                    <div class="relative">
                        <input type="password" name="password" id="loginPassword" placeholder="••••••••" required
                               class="w-full px-3 py-2 text-sm border rounded-lg pr-9 focus:outline-none focus:ring-2 focus:ring-amber-500">
                        <button type="button" onclick="togglePass()" class="absolute right-3 top-2.5 text-gray-400 hover:text-gray-600">
                            <i id="eyeIcon" class="fa-solid fa-eye text-sm"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="w-full py-2 bg-amber-500 hover:bg-amber-600 text-white font-semibold rounded-lg text-sm transition">
                    Sign In
                </button>
            </form>
        <?php endif; ?>

    </div>

    <!-- Forgot Password Modal -->
    <div id="forgotModal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-xl w-full max-w-xs p-5 shadow-lg">
            <h3 class="font-bold text-gray-800 text-sm mb-1">Reset Password</h3>
            <p class="text-xs text-gray-500 mb-3">Enter your registered email.</p>
            <form method="POST" action="login.php" class="space-y-3">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <input type="hidden" name="action" value="forgot_password">
                <input type="email" name="forgot_email" placeholder="name@gmail.com" required
                       class="w-full px-3 py-2 text-xs border rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-500">
                <div class="flex gap-2">
                    <button type="button" onclick="document.getElementById('forgotModal').classList.add('hidden')" 
                            class="w-1/2 py-1.5 text-xs border rounded-lg text-gray-600 hover:bg-gray-50">Cancel</button>
                    <button type="submit" 
                            class="w-1/2 py-1.5 text-xs bg-amber-500 hover:bg-amber-600 text-white font-semibold rounded-lg">Send</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function togglePass() {
            const pass = document.getElementById('loginPassword');
            const icon = document.getElementById('eyeIcon');
            if (pass.type === 'password') {
                pass.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                pass.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        }

        <?php if ($isOtpStep): ?>
        let resendSeconds = <?= (int)$resendCooldown ?>;
        const resendBtn = document.getElementById('resendBtn');
        const resendTimerEl = document.getElementById('resendTimer');

        function updateResendTimer() {
            if (resendSeconds > 0) {
                resendBtn.disabled = true;
                resendTimerEl.textContent = `(${resendSeconds}s)`;
                resendSeconds--;
            } else {
                resendBtn.disabled = false;
                resendTimerEl.textContent = '';
            }
        }

        updateResendTimer();
        setInterval(updateResendTimer, 1000);

        const otpInput = document.getElementById('otp_code');
        if (otpInput) {
            otpInput.addEventListener('input', function() {
                this.value = this.value.replace(/[^0-9]/g, '');
            });
        }
        <?php endif; ?>
    </script>
</body>
</html>
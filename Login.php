<?php
session_start();
require_once 'db.php';
require_once 'mail_borrow.php';

const ADMIN_EMAIL = 'kingfahadmalon06@gmail.com';

// Sends a "you logged in" email to the person who just signed in
function sendLoginNotice($email) {
    $time = date('M d, Y h:i A');
    $body = "<p>Hello,</p>
             <p>You just logged in to the Loan System on <b>$time</b>.</p>
             <p>If this wasn't you, please contact the Loan Office.</p>";
    sendLibraryEmail($email, 'Loan System: Login notice', $body);
}

// Admin goes to the full dashboard, everyone else sees only their own loans
function destinationFor($email) {
    return (strcasecmp($email, ADMIN_EMAIL) === 0) ? 'dashboard.php' : 'my_loans.php';
}

// Already logged in? Send them to the right page (no email sent again)
if (isset($_SESSION['user_id'])) {
    header('Location: ' . destinationFor($_SESSION['email'] ?? ''));
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $st = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $st->execute([$email]);
    $user = $st->fetch();
    if ($user && !empty($user['password']) && password_verify($_POST['password'] ?? '', $user['password'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['email']   = $user['email'];

        // Email the user that they logged in
        sendLoginNotice($user['email']);

        header('Location: ' . destinationFor($user['email']));
        exit;
    }
    $error = 'Invalid email or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f9f9f9; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .box { background: #fff; padding: 30px; width: 320px; border: 1px solid #eaeaea; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        h2 { margin: 0 0 20px; font-weight: 400; text-align: center; }
        input { width: 100%; padding: 10px; margin-bottom: 12px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
        button, .google { display: block; width: 100%; padding: 10px; background: #111; color: #fff; border: none; border-radius: 4px; font-weight: 600; cursor: pointer; text-align: center; text-decoration: none; box-sizing: border-box; font-size: 14px; }
        .google { background: #fff; color: #111; border: 1px solid #111; margin-top: 12px; }
        .error { background: #fdecea; color: #c62828; padding: 8px; border-radius: 4px; font-size: 14px; margin-bottom: 12px; }
        p { text-align: center; font-size: 14px; }
    </style>
</head>
<body>
<div class="box">
    <h2>Loan Login</h2>
    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="POST">
        <input type="email" name="email" placeholder="Email" required>
        <input type="password" name="password" placeholder="Password" required>
        <button type="submit">Log In</button>
    </form>
    <a href="google_login.php" class="google">Sign in with Google</a>
    <p>No account? <a href="register.php">Register</a></p>
</div>
</body>
</html>
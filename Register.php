<?php
session_start();
require_once 'db.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $pw    = $_POST['password'] ?? '';

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pw) < 6) {
        $error = 'Enter your name, a valid email, and a password of at least 6 characters.';
    } else {
        $st = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $st->execute([$email]);
        if ($st->fetch()) {
            $error = 'That email is already registered.';
        } else {
            // Adapts to the columns your users table has
            $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
            $data = ['email' => $email, 'password' => password_hash($pw, PASSWORD_DEFAULT)];
            foreach (['name', 'fullname', 'full_name', 'username'] as $c) {
                if (in_array($c, $cols)) $data[$c] = $name;
            }
            $sql = 'INSERT INTO users (' . implode(',', array_keys($data)) . ') VALUES (' . rtrim(str_repeat('?,', count($data)), ',') . ')';
            $pdo->prepare($sql)->execute(array_values($data));
            header('Location: login.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Register</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f9f9f9; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .box { background: #fff; padding: 30px; width: 320px; border: 1px solid #eaeaea; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        h2 { margin: 0 0 20px; font-weight: 400; text-align: center; }
        input { width: 100%; padding: 10px; margin-bottom: 12px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
        button { width: 100%; padding: 10px; background: #111; color: #fff; border: none; border-radius: 4px; font-weight: 600; cursor: pointer; }
        .error { background: #fdecea; color: #c62828; padding: 8px; border-radius: 4px; font-size: 14px; margin-bottom: 12px; }
        p { text-align: center; font-size: 14px; }
    </style>
</head>
<body>
<div class="box">
    <h2>Create Account</h2>
    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="POST">
        <input type="text" name="name" placeholder="Full name" required>
        <input type="email" name="email" placeholder="Email" required>
        <input type="password" name="password" placeholder="Password (min 6)" required>
        <button type="submit">Register</button>
    </form>
    <p><a href="login.php">Back to login</a></p>
</div>
</body>
</html>
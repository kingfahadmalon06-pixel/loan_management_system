<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require_once 'db.php';
require_once 'mail_borrow.php';   // sendLibraryEmail($to, $subject, $htmlBody)
require_once 'jaas_jwt.php';

// A fresh private room for each call. "New room" makes another one.
if (isset($_GET['new']) || empty($_SESSION['call_room'])) {
    $_SESSION['call_room'] = 'LoanOffice-' . bin2hex(random_bytes(8));
    header('Location: video_call.php' . (isset($_GET['borrower_id']) ? '?borrower_id=' . (int)$_GET['borrower_id'] : ''));
    exit;
}
$room = $_SESSION['call_room'];

// Link the borrower receives (guest, no host powers, no login needed)
$link = rtrim(SITE_URL, '/') . '/join_call.php?room=' . urlencode($room);

// Host token: YOU are the moderator
$hostName = $_SESSION['email'] ?? 'Host';
$tokenError = '';
try {
    $hostJwt = jaas_token('Loan Office (Host)', $hostName, $room, true);
} catch (Throwable $e) {
    $hostJwt = '';
    $tokenError = $e->getMessage();
}

// Optional: opened from a borrower row -> prefill their email
$prefill = '';
if (!empty($_GET['borrower_id'])) {
    $st = $pdo->prepare("SELECT email FROM borrowers WHERE id = ?");
    $st->execute([(int)$_GET['borrower_id']]);
    $prefill = $st->fetchColumn() ?: '';
}
$emails = $pdo->query("SELECT name, email FROM borrowers ORDER BY name")->fetchAll();

$msg = ''; $type = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $to   = trim($_POST['email'] ?? '');
    $note = trim($_POST['note'] ?? '');
    $prefill = $to;
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $msg = 'Please enter a valid email address.'; $type = 'error';
    } else {
        $nm = $pdo->prepare("SELECT name FROM borrowers WHERE email = ?");
        $nm->execute([$to]);
        $bn = $nm->fetchColumn();
        $emailLink = $link . ($bn ? '&name=' . urlencode($bn) : '');
        $body = '<h3>Hello,</h3>'
              . '<p>You are invited to a video call.</p>'
              . ($note !== '' ? '<p>' . nl2br(htmlspecialchars($note)) . '</p>' : '')
              . '<p><a href="' . htmlspecialchars($emailLink) . '" style="background:#111;color:#fff;padding:12px 22px;border-radius:4px;text-decoration:none;font-weight:bold">Join Video Call</a></p>'
              . '<p>Or open this link: ' . htmlspecialchars($emailLink) . '</p><p>— Loan Office</p>';
        if (sendLibraryEmail($to, 'Video call invitation', $body)) {
            $msg = 'Link sent to ' . $to . '. Now click "Start Video Call Now" to be in the room as host.';
        } else {
            $msg = 'Email could not be sent (check Gmail settings in mail_borrow.php).'; $type = 'error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Video Call</title>
    <script src="https://8x8.vc/<?= JAAS_APP_ID ?>/external_api.js"></script>
    <style>
        body { font-family: Arial, sans-serif; background: #f9f9f9; color: #333; margin: 0; }
        header { background: #fff; border-bottom: 1px solid #eaeaea; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; }
        header h2 { margin: 0; font-weight: 400; color: #111; }
        header a { padding: 8px 16px; background: #fff; color: #111; border: 1px solid #111; border-radius: 4px; text-decoration: none; font-weight: 600; font-size: 13px; }
        header a:hover { background: #111; color: #fff; }
        main { max-width: 1000px; margin: 30px auto; padding: 0 20px; }
        .card { background: #fff; padding: 26px; border-radius: 8px; border: 1px solid #eaeaea; box-shadow: 0 4px 12px rgba(0,0,0,0.05); max-width: 520px; margin: 0 auto; }
        .start { display: block; width: 100%; padding: 16px; background: #111; color: #fff; border: none; border-radius: 6px; text-align: center; font-weight: 700; font-size: 17px; cursor: pointer; }
        .start:hover { background: #333; color: #fff; }
        .or { text-align: center; color: #888; font-size: 13px; margin: 22px 0 14px; }
        label { display: block; font-size: 13px; color: #666; margin-bottom: 5px; }
        input, textarea { width: 100%; padding: 10px; margin-bottom: 12px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; font-size: 14px; font-family: Arial, sans-serif; }
        button { width: 100%; padding: 11px; background: #fff; color: #111; border: 1px solid #111; border-radius: 4px; cursor: pointer; font-weight: 600; font-size: 14px; }
        button:hover { background: #111; color: #fff; }
        .msg { padding: 10px 14px; border-radius: 4px; font-size: 14px; margin: 0 auto 18px; max-width: 520px; }
        .msg.success { background: #eaf7ea; color: #2e7d32; }
        .msg.error { background: #fdecea; color: #c62828; }
        .row { display: flex; gap: 8px; } .row input { margin: 0; } .row button { width: 90px; }
        .foot { text-align: center; font-size: 12px; color: #888; margin-top: 16px; }
        .foot a { color: #1a56db; }
        #callPanel { display: none; margin-bottom: 24px; }
        #callPanel .bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; font-size: 14px; color: #666; }
        #callPanel .bar button { width: auto; padding: 6px 14px; font-size: 13px; }
        #meet { height: 560px; border-radius: 8px; overflow: hidden; background: #111; }
    </style>
</head>
<body>
<header>
    <h2>Video Call</h2>
    <a href="dashboard.php">&larr; Dashboard</a>
</header>
<main>
    <?php if ($msg): ?><div class="msg <?= $type ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($tokenError): ?><div class="msg error"><?= htmlspecialchars($tokenError) ?></div><?php endif; ?>

    <div id="callPanel">
        <div class="bar"><span>You are the host of this call.</span><button type="button" id="leaveBtn">Close call</button></div>
        <div id="meet"></div>
    </div>

    <div class="card">
        <button type="button" class="start" id="startBtn" <?= $hostJwt ? '' : 'disabled' ?>>📹 Start Video Call Now</button>

        <div class="or">— or send the call link to anyone —</div>

        <form method="POST">
            <label>Their email</label>
            <input type="email" name="email" list="clients" value="<?= htmlspecialchars($prefill) ?>" required>
            <datalist id="clients">
                <?php foreach ($emails as $e): ?>
                    <option value="<?= htmlspecialchars($e['email']) ?>"><?= htmlspecialchars($e['name']) ?></option>
                <?php endforeach; ?>
            </datalist>
            <label>Message (optional)</label>
            <textarea name="note" rows="2"></textarea>
            <button type="submit">Send Link</button>
        </form>

        <label style="margin-top:18px">Or copy the link</label>
        <div class="row">
            <input type="text" id="lnk" value="<?= htmlspecialchars($link) ?>" readonly>
            <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('lnk').value);this.textContent='Copied!'">Copy</button>
        </div>

        <div class="foot">Guests join without signing in; only you have host controls. &nbsp;<a href="?new=1">Create a new room</a></div>
    </div>
</main>

<script>
(function () {
    var api = null;
    var panel = document.getElementById('callPanel');
    var startBtn = document.getElementById('startBtn');

    startBtn.addEventListener('click', function () {
        if (api) { panel.scrollIntoView({behavior: 'smooth'}); return; }
        panel.style.display = 'block';
        api = new JitsiMeetExternalAPI('8x8.vc', {
            roomName: <?= json_encode(JAAS_APP_ID . '/' . $room) ?>,
            jwt: <?= json_encode($hostJwt) ?>,
            parentNode: document.getElementById('meet'),
            width: '100%',
            height: '100%',
            lang: 'en'
        });
        api.addListener('readyToClose', closeCall);
        panel.scrollIntoView({behavior: 'smooth'});
    });

    function closeCall() {
        if (api) { api.dispose(); api = null; }
        panel.style.display = 'none';
    }
    document.getElementById('leaveBtn').addEventListener('click', closeCall);
})();
</script>
</body>
</html>
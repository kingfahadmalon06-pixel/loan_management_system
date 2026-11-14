<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
require_once 'db.php';
require_once 'mail_borrow.php';   // uses sendLibraryEmail($to, $subject, $htmlBody)

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function flash($type, $text) {
    $_SESSION['flash'] = [$type, $text];
    header("Location: dashboard.php");
    exit;
}
function peso($n) { return '₱' . number_format((float)$n, 2); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_borrower') {
        $name  = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Enter a name and a valid email.');
        }
        try {
            $pdo->prepare("INSERT INTO borrowers (name, email) VALUES (?, ?)")->execute([$name, $email]);
            flash('success', 'Borrower added.');
        } catch (PDOException $e) {
            flash('error', 'That borrower email already exists.');
        }
    }

    if ($action === 'add_loan') {
        $bid      = (int)($_POST['borrower_id'] ?? 0);
        $amount   = round((float)($_POST['amount'] ?? 0), 2);
        $interest = min(100, max(0, (float)($_POST['interest'] ?? 0)));
        $days     = min(365, max(1, (int)($_POST['days'] ?? 30)));

        $st = $pdo->prepare("SELECT * FROM borrowers WHERE id = ?");
        $st->execute([$bid]);
        $borrower = $st->fetch();
        if (!$borrower || $amount <= 0) flash('error', 'Choose a borrower and enter a valid amount.');

        $total = round($amount * (1 + $interest / 100), 2);
        $due   = date('Y-m-d', strtotime("+$days days"));
        $pdo->prepare("INSERT INTO loans (borrower_id, principal, interest_percent, total_due, loan_date, due_date)
                       VALUES (?, ?, ?, ?, CURDATE(), ?)")
            ->execute([$bid, $amount, $interest, $total, $due]);

        $body = '<h3>Hello ' . htmlspecialchars($borrower['name']) . ',</h3>'
              . '<p>Your loan has been recorded:</p><ul>'
              . '<li>Amount borrowed: <b>' . peso($amount) . '</b></li>'
              . '<li>Interest: ' . $interest . '%</li>'
              . '<li>Total to pay: <b>' . peso($total) . '</b></li>'
              . '<li>Due date: <b>' . $due . '</b></li></ul><p>— Loan Office</p>';
        $sent = sendLibraryEmail($borrower['email'], 'Your loan details', $body);
        flash('success', 'Loan recorded. ' . ($sent
            ? 'Email sent to ' . $borrower['email'] . '.'
            : 'Email could not be sent (check Gmail settings in mail_borrow.php).'));
    }

    if ($action === 'payment') {
        $lid  = (int)($_POST['loan_id'] ?? 0);
        $amt  = round((float)($_POST['amount'] ?? 0), 2);
        $note = trim($_POST['note'] ?? '');

        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT l.*, b.name, b.email FROM loans l
                             JOIN borrowers b ON b.id = l.borrower_id
                             WHERE l.id = ? AND l.status = 'Active' FOR UPDATE");
        $st->execute([$lid]);
        $loan = $st->fetch();
        if (!$loan) { $pdo->rollBack(); flash('error', 'Loan not found or already paid.'); }

        $balance = round($loan['total_due'] - $loan['amount_paid'], 2);
        if ($amt <= 0 || $amt > $balance) {
            $pdo->rollBack();
            flash('error', 'Payment must be between ₱0.01 and ' . peso($balance) . '.');
        }

        $newPaid   = round($loan['amount_paid'] + $amt, 2);
        $newBal    = round($loan['total_due'] - $newPaid, 2);
        $newStatus = $newBal <= 0 ? 'Paid' : 'Active';
        $pdo->prepare("INSERT INTO payments (loan_id, amount, note) VALUES (?, ?, ?)")->execute([$lid, $amt, $note]);
        $pdo->prepare("UPDATE loans SET amount_paid = ?, status = ? WHERE id = ?")->execute([$newPaid, $newStatus, $lid]);
        $pdo->commit();

        $body = '<h3>Hello ' . htmlspecialchars($loan['name']) . ',</h3>'
              . '<p>We received your payment of <b>' . peso($amt) . '</b>.</p>'
              . '<p>' . ($newStatus === 'Paid'
                    ? 'Your loan is now <b>fully paid</b>. Thank you!'
                    : 'Remaining balance: <b>' . peso($newBal) . '</b> (due ' . htmlspecialchars($loan['due_date']) . ').')
              . '</p><p>— Loan Office</p>';
        $sent = sendLibraryEmail($loan['email'], 'Payment received', $body);
        flash('success', 'Payment recorded. ' . ($sent ? 'Receipt emailed.' : 'Email could not be sent.'));
    }

    if ($action === 'send_message') {
        $bid     = (int)($_POST['borrower_id'] ?? 0); // 0 = all borrowers, -1 = those with unpaid loans
        $subject = trim($_POST['subject'] ?? '');
        $message = trim($_POST['message'] ?? '');
        if ($subject === '' || $message === '') flash('error', 'Subject and message are required.');

        if ($bid === 0) {
            $targets = $pdo->query("SELECT name, email FROM borrowers")->fetchAll();
        } elseif ($bid === -1) {
            $targets = $pdo->query("SELECT DISTINCT b.name, b.email FROM borrowers b
                                    JOIN loans l ON l.borrower_id = b.id AND l.status = 'Active'")->fetchAll();
        } else {
            $st = $pdo->prepare("SELECT name, email FROM borrowers WHERE id = ?");
            $st->execute([$bid]);
            $targets = $st->fetchAll();
        }
        if (!$targets) flash('error', 'No borrower found.');

        $ok = 0;
        foreach ($targets as $t) {
            $body = '<h3>Hello ' . htmlspecialchars($t['name']) . ',</h3>'
                  . '<p>' . nl2br(htmlspecialchars($message)) . '</p>'
                  . '<p>— Loan Office</p>';
            if (sendLibraryEmail($t['email'], $subject, $body)) $ok++;
        }
        flash($ok ? 'success' : 'error',
            "Message sent to $ok of " . count($targets) . " borrower(s)."
            . ($ok < count($targets) ? ' Check Gmail settings in mail_borrow.php.' : ''));
    }
}

$borrowers = $pdo->query("SELECT id, name, email FROM borrowers ORDER BY name")->fetchAll();
$active = $pdo->query("SELECT l.*, b.name, b.email, (l.total_due - l.amount_paid) AS balance
                       FROM loans l JOIN borrowers b ON b.id = l.borrower_id
                       WHERE l.status = 'Active' ORDER BY l.due_date")->fetchAll();
$recent = $pdo->query("SELECT p.amount, p.paid_at, p.note, b.name
                       FROM payments p JOIN loans l ON l.id = p.loan_id
                       JOIN borrowers b ON b.id = l.borrower_id
                       ORDER BY p.paid_at DESC LIMIT 5")->fetchAll();
$tot = $pdo->query("SELECT COALESCE(SUM(principal),0) AS lent, COALESCE(SUM(amount_paid),0) AS paid,
                           COALESCE(SUM(total_due - amount_paid),0) AS outstanding FROM loans")->fetch();
$today = date('Y-m-d');
$overdue = count(array_filter($active, fn($r) => $r['due_date'] < $today));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Loan Dashboard</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f9f9f9; color: #333; margin: 0; }
        header { background: #fff; border-bottom: 1px solid #eaeaea; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; }
        header h2 { margin: 0; font-weight: 400; color: #111; }
        header span { font-size: 14px; color: #666; margin-right: 15px; }
        a.logout-btn { padding: 8px 16px; background: #fff; color: #111; border: 1px solid #111; border-radius: 4px; text-decoration: none; font-weight: 600; font-size: 13px; }
        a.logout-btn:hover { background: #111; color: #fff; }
        a.call-btn { padding: 8px 14px; background: #fff; color: #111; border: 1px solid #111; border-radius: 4px; text-decoration: none; font-weight: 600; font-size: 13px; }
        a.call-btn:hover { background: #111; color: #fff; }
        main { max-width: 1000px; margin: 25px auto; padding: 0 20px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 20px; }
        .card { background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #eaeaea; box-shadow: 0 4px 12px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .grid .card { margin-bottom: 0; }
        .stat .label { font-size: 13px; color: #888; }
        .stat .value { font-size: 22px; font-weight: 600; color: #111; margin-top: 4px; }
        h3 { margin: 0 0 15px; font-weight: 400; color: #111; }
        input, select, textarea { width: 100%; font-family: Arial, sans-serif; resize: vertical; padding: 9px; margin-bottom: 10px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; font-size: 14px; }
        button { padding: 9px 16px; background: #111; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-weight: 600; }
        button:hover { background: #333; }
        .full { width: 100%; }
        .msg { padding: 10px 14px; border-radius: 4px; font-size: 14px; margin-bottom: 20px; }
        .msg.success { background: #eaf7ea; color: #2e7d32; }
        .msg.error { background: #fdecea; color: #c62828; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { text-align: left; padding: 10px; border-bottom: 1px solid #eee; vertical-align: middle; }
        th { color: #666; font-weight: 600; }
        .overdue { color: #c62828; font-weight: 600; }
        .scroll { overflow-x: auto; }
        .small { padding: 6px 12px; font-size: 13px; }
        .muted { color: #888; font-size: 14px; }
        .payform { display: flex; gap: 6px; align-items: center; }
        .payform input { margin: 0; width: 110px; padding: 6px; }
    </style>
</head>
<body>
<header>
    <div style="display:flex;align-items:center;gap:14px">
        <h2>Loan Dashboard</h2>
        <a href="video_call.php" class="call-btn" title="Video call a client">📹 Video Call</a>
    </div>
    <div>
        <span>Logged in as <strong><?= htmlspecialchars($_SESSION['email']) ?></strong></span>
        <a href="logout.php" class="logout-btn">Log Out</a>
    </div>
</header>

<main>
    <?php if ($flash): ?>
        <div class="msg <?= htmlspecialchars($flash[0]) ?>"><?= htmlspecialchars($flash[1]) ?></div>
    <?php endif; ?>

    <div class="grid">
        <div class="card stat"><div class="label">Total Lent</div><div class="value"><?= peso($tot['lent']) ?></div></div>
        <div class="card stat"><div class="label">Collected</div><div class="value"><?= peso($tot['paid']) ?></div></div>
        <div class="card stat"><div class="label">Outstanding</div><div class="value"><?= peso($tot['outstanding']) ?></div></div>
        <div class="card stat"><div class="label">Overdue Loans</div><div class="value <?= $overdue ? 'overdue' : '' ?>"><?= $overdue ?></div></div>
    </div>

    <div class="card">
        <h3>New Loan</h3>
        <?php if (!$borrowers): ?>
            <p class="muted">Add at least one borrower below first.</p>
        <?php else: ?>
        <form method="POST">
            <input type="hidden" name="action" value="add_loan">
            <select name="borrower_id" required>
                <option value="">Select borrower</option>
                <?php foreach ($borrowers as $b): ?>
                    <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['name'] . ' (' . $b['email'] . ')') ?></option>
                <?php endforeach; ?>
            </select>
            <input type="number" name="amount" step="0.01" min="0.01" placeholder="Amount (₱)" required>
            <input type="number" name="interest" step="0.01" min="0" max="100" value="0" placeholder="Interest % (total, not per month)">
            <input type="number" name="days" value="30" min="1" max="365" placeholder="Days until due">
            <button type="submit" class="full">Create Loan &amp; Send Email</button>
        </form>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>Active Loans (<?= count($active) ?>)</h3>
        <div class="scroll">
        <table>
            <tr><th>Borrower</th><th>Total Due</th><th>Paid</th><th>Balance</th><th>Due</th><th>Record Payment</th></tr>
            <?php foreach ($active as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['name']) ?><br><span class="muted"><?= htmlspecialchars($r['email']) ?></span></td>
                <td><?= peso($r['total_due']) ?></td>
                <td><?= peso($r['amount_paid']) ?></td>
                <td><strong><?= peso($r['balance']) ?></strong></td>
                <td class="<?= $r['due_date'] < $today ? 'overdue' : '' ?>">
                    <?= htmlspecialchars($r['due_date']) ?><?= $r['due_date'] < $today ? ' (overdue)' : '' ?>
                </td>
                <td>
                    <form method="POST" class="payform">
                        <input type="hidden" name="action" value="payment">
                        <input type="hidden" name="loan_id" value="<?= $r['id'] ?>">
                        <input type="number" name="amount" step="0.01" min="0.01" max="<?= $r['balance'] ?>" placeholder="₱ amount" required>
                        <button type="submit" class="small">Pay</button>
                    </form>
                    <form method="POST" action="send_email.php" style="margin-top:6px">
                        <input type="hidden" name="loan_id" value="<?= $r['id'] ?>">
                        <button type="submit" class="small" style="background:#fff;color:#111;border:1px solid #111">Remind</button>
                    </form>
                    <a href="video_call.php?borrower_id=<?= (int)$r['borrower_id'] ?>" style="display:inline-block;margin-top:6px;padding:6px 12px;background:#fff;color:#111;border:1px solid #111;border-radius:4px;text-decoration:none;font-size:13px;font-weight:600">📹 Call</a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$active): ?><tr><td colspan="6" class="muted">No active loans.</td></tr><?php endif; ?>
        </table>
        </div>
    </div>

    <div class="card">
        <h3>Recent Payments</h3>
        <div class="scroll">
        <table>
            <tr><th>Borrower</th><th>Amount</th><th>Date</th></tr>
            <?php foreach ($recent as $p): ?>
            <tr><td><?= htmlspecialchars($p['name']) ?></td><td><?= peso($p['amount']) ?></td><td><?= htmlspecialchars($p['paid_at']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$recent): ?><tr><td colspan="3" class="muted">No payments yet.</td></tr><?php endif; ?>
        </table>
        </div>
    </div>

    <div class="card">
        <h3>Send Message / Payment Reminder</h3>
        <?php if (!$borrowers): ?>
            <p class="muted">Add a borrower first.</p>
        <?php else: ?>
        <form method="POST">
            <input type="hidden" name="action" value="send_message">
            <select name="borrower_id" required>
                <option value="-1">Borrowers with unpaid loans</option>
                <option value="0">All borrowers</option>
                <?php foreach ($borrowers as $b): ?>
                    <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['name'] . ' (' . $b['email'] . ')') ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="subject" placeholder="Subject" required>
            <textarea name="message" rows="4" placeholder="Write your message..." required></textarea>
            <button type="submit" class="full">Send via Gmail</button>
        </form>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>Add Borrower</h3>
        <form method="POST">
            <input type="hidden" name="action" value="add_borrower">
            <input type="text" name="name" placeholder="Full name" required>
            <input type="email" name="email" placeholder="Borrower Gmail" required>
            <button type="submit" class="full">Add Borrower</button>
        </form>
    </div>
</main>
</body>
</html>
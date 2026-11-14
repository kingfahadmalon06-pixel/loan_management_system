<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }
require_once 'db.php';

function peso($n) { return '₱' . number_format((float)$n, 2); }

$filter = $_GET['status'] ?? 'all';
$where = '';
if ($filter === 'active')       $where = "WHERE l.status = 'Active'";
elseif ($filter === 'paid')     $where = "WHERE l.status = 'Paid'";
elseif ($filter === 'overdue')  $where = "WHERE l.status = 'Active' AND l.due_date < CURDATE()";
else $filter = 'all';

$loans = $pdo->query("SELECT l.*, b.name, b.email, (l.total_due - l.amount_paid) AS balance
                      FROM loans l JOIN borrowers b ON b.id = l.borrower_id
                      $where ORDER BY l.loan_date DESC, l.id DESC")->fetchAll();

// All payments, grouped by loan
$pay = [];
foreach ($pdo->query("SELECT loan_id, amount, paid_at, note FROM payments ORDER BY paid_at DESC")->fetchAll() as $p) {
    $pay[$p['loan_id']][] = $p;
}

$sumLent = array_sum(array_column($loans, 'principal'));
$sumPaid = array_sum(array_column($loans, 'amount_paid'));
$sumBal  = array_sum(array_column($loans, 'balance'));
$today   = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Loans</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f9f9f9; color: #333; margin: 0; }
        header { background: #fff; border-bottom: 1px solid #eaeaea; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; }
        header h2 { margin: 0; font-weight: 400; color: #111; }
        header a { padding: 8px 16px; background: #fff; color: #111; border: 1px solid #111; border-radius: 4px; text-decoration: none; font-weight: 600; font-size: 13px; margin-left: 8px; }
        header a:hover { background: #111; color: #fff; }
        main { max-width: 1000px; margin: 25px auto; padding: 0 20px; }
        .card { background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #eaeaea; box-shadow: 0 4px 12px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .tabs { display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
        .tabs a { padding: 7px 14px; border: 1px solid #ddd; border-radius: 999px; text-decoration: none; color: #333; font-size: 13px; background: #fff; }
        .tabs a.on { background: #111; color: #fff; border-color: #111; }
        .sum { display: flex; gap: 24px; flex-wrap: wrap; margin-bottom: 16px; font-size: 14px; color: #666; }
        .sum b { display: block; font-size: 20px; color: #111; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { text-align: left; padding: 10px; border-bottom: 1px solid #eee; vertical-align: top; }
        th { color: #666; font-weight: 600; }
        .scroll { overflow-x: auto; }
        .badge { padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
        .Active { background: #fff3cd; color: #8a6d00; }
        .Paid { background: #eaf7ea; color: #2e7d32; }
        .overdue { color: #c62828; font-weight: 600; }
        .muted { color: #888; font-size: 13px; }
        details summary { cursor: pointer; color: #1a56db; font-size: 13px; }
        details table { margin-top: 8px; font-size: 13px; }
    </style>
</head>
<body>
<header>
    <h2>My Loans</h2>
    <div>
        <a href="dashboard.php">Dashboard</a>
        <a href="logout.php">Log Out</a>
    </div>
</header>
<main>
    <div class="tabs">
        <?php foreach (['all' => 'All', 'active' => 'Active', 'overdue' => 'Overdue', 'paid' => 'Paid'] as $k => $label): ?>
            <a href="?status=<?= $k ?>" class="<?= $filter === $k ? 'on' : '' ?>"><?= $label ?></a>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <div class="sum">
            <div>Loans<b><?= count($loans) ?></b></div>
            <div>Total lent<b><?= peso($sumLent) ?></b></div>
            <div>Collected<b><?= peso($sumPaid) ?></b></div>
            <div>Balance<b><?= peso($sumBal) ?></b></div>
        </div>
        <div class="scroll">
        <table>
            <tr><th>Borrower</th><th>Loaned</th><th>Total due</th><th>Paid</th><th>Balance</th><th>Dates</th><th>Status</th></tr>
            <?php foreach ($loans as $l): $late = $l['status'] === 'Active' && $l['due_date'] < $today; ?>
            <tr>
                <td><?= htmlspecialchars($l['name']) ?><br><span class="muted"><?= htmlspecialchars($l['email']) ?></span></td>
                <td><?= peso($l['principal']) ?><br><span class="muted"><?= (float)$l['interest_percent'] ?>% interest</span></td>
                <td><?= peso($l['total_due']) ?></td>
                <td><?= peso($l['amount_paid']) ?></td>
                <td><strong><?= peso($l['balance']) ?></strong></td>
                <td>
                    <span class="muted">Loaned:</span> <?= htmlspecialchars($l['loan_date']) ?><br>
                    <span class="<?= $late ? 'overdue' : '' ?>"><span class="muted">Due:</span> <?= htmlspecialchars($l['due_date']) ?><?= $late ? ' (overdue)' : '' ?></span>
                </td>
                <td>
                    <span class="badge <?= htmlspecialchars($l['status']) ?>"><?= htmlspecialchars($l['status']) ?></span>
                    <?php if (!empty($pay[$l['id']])): ?>
                    <details>
                        <summary><?= count($pay[$l['id']]) ?> payment(s)</summary>
                        <table>
                            <?php foreach ($pay[$l['id']] as $p): ?>
                            <tr><td><?= peso($p['amount']) ?></td><td><?= htmlspecialchars(substr($p['paid_at'], 0, 10)) ?></td><td><?= htmlspecialchars($p['note'] ?? '') ?></td></tr>
                            <?php endforeach; ?>
                        </table>
                    </details>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$loans): ?><tr><td colspan="7" class="muted">No loans found. Create one from the dashboard.</td></tr><?php endif; ?>
        </table>
        </div>
    </div>
</main>
</body>
</html>
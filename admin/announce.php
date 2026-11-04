<?php
require_once 'auth.php';
require_once '../config/config.php';
require_once '../lib/announcements.php';
$pdo = getDBConnection();

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

const ANNOUNCE_BATCH_SIZE = 10;

function jsonOut(array $data): void
{
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Endpoint AJAX: kirim satu batch ke penerima yang belum dikirimi.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        jsonOut(['ok' => false, 'error' => 'Invalid session token. Reload the page.']);
    }
    @set_time_limit(120);
    $eventId = (int) ($_POST['event'] ?? 0);
    $action  = $_POST['action'] ?? '';
    $groups  = groupOrdersByRecipient(fetchOrdersToAnnounce($pdo, $eventId, true));

    if ($action === 'test') {
        $to = trim($_POST['to'] ?? '');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) jsonOut(['ok' => false, 'error' => 'Invalid test email.']);
        if (!$groups) jsonOut(['ok' => false, 'error' => 'No recipients left to use as a sample.']);
        $error = sendAnnouncement($groups[0], $to);
        jsonOut(['ok' => $error === null, 'error' => $error]);
    }

    if ($action === 'batch') {
        $sent = 0;
        $failed = [];
        foreach (array_slice($groups, 0, ANNOUNCE_BATCH_SIZE) as $group) {
            $error = sendAnnouncement($group);
            if ($error === null) {
                markOrdersAnnounced($pdo, $group['orders']);
                $sent++;
            } else {
                // Tandai tidak dikirim agar tidak diulang tanpa henti di run ini.
                $failed[] = $group['email'] . ' (' . $error . ')';
            }
            usleep(REMINDER_PAUSE_MICROS);
        }
        $remaining = count($groups) - $sent;
        jsonOut(['ok' => true, 'sent' => $sent, 'failed' => $failed, 'remaining' => $remaining,
                 'stalled' => $sent === 0 && $remaining > 0]);
    }
    jsonOut(['ok' => false, 'error' => 'Unknown action.']);
}

$events = $pdo->query("
    SELECT e.id, e.title, COUNT(o.id) AS orders_count
    FROM events e JOIN orders o ON o.event_id = e.id AND o.status = 'confirmed'
    GROUP BY e.id, e.title ORDER BY e.event_date_start DESC
")->fetchAll();
$eventId = (int) ($_GET['event'] ?? ($events[0]['id'] ?? 0));

$groups = $eventId ? groupOrdersByRecipient(fetchOrdersToAnnounce($pdo, $eventId, false)) : [];
$unsentStmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE status='confirmed' AND event_id=? AND " . ANNOUNCE_COLUMN . " IS NULL");
$unsentStmt->execute([$eventId]);
$unsentOrders = (int) $unsentStmt->fetchColumn();
$unsentGroups = count(groupOrdersByRecipient(fetchOrdersToAnnounce($pdo, $eventId, true)));
$sample = $groups[0] ?? null;
$preview = $sample ? announceHtml($sample, false) : '';
$pendingCount = $pdo->query("SELECT COUNT(*) FROM orders WHERE status='pending'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Announcement — Noirlab Admin</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; background:#fff; color:#000; -webkit-font-smoothing:antialiased; display:flex; min-height:100vh; }
        .main { margin-left:220px; flex:1; padding:50px 48px; min-width:0; max-width:980px; }
        h1 { font-size:1.75rem; letter-spacing:-.5px; line-height:1; }
        .sub { font-size:.85rem; color:rgba(0,0,0,.45); margin:10px 0 32px; padding-bottom:32px; border-bottom:1px solid #e5e5e5; }
        .card { border:1px solid #e5e5e5; padding:20px; margin-bottom:20px; }
        .card h2 { font-size:.8rem; text-transform:uppercase; letter-spacing:.8px; color:rgba(0,0,0,.45); margin-bottom:14px; }
        select, input[type=email] { padding:9px 12px; font:inherit; font-size:.875rem; width:100%; max-width:420px; }
        .row { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
        .stat { font-size:1.4rem; font-weight:700; }
        .stat small { display:block; font-size:.75rem; font-weight:400; color:rgba(0,0,0,.45); margin-top:4px; }
        .stats { display:flex; gap:40px; margin-bottom:18px; }
        .bar { height:6px; background:#eee; margin-top:16px; display:none; }
        .bar i { display:block; height:100%; width:0; background:#000; transition:width .3s; }
        #log { font-size:.8rem; line-height:1.7; margin-top:12px; white-space:pre-wrap; }
        #log .err { color:#900; }
        iframe { width:100%; height:760px; border:1px solid #e5e5e5; }
        @media (max-width:860px) { .main { padding:24px 16px; } .stats { gap:24px; } iframe { height:640px; } #sendAll, #testBtn { width:100%; } }
    </style>
<?php require_once __DIR__ . '/_shell.php'; adminShellStyles(); ?>
</head>
<body>
<?php require_once __DIR__ . '/_shell.php'; adminShell(); ?>

<div class="main">
    <h1>Announcement</h1>
    <p class="sub">Group tickets closed: thank-you email with each buyer's ticket data. One email per address; buyers with several orders get them in a single email.</p>

    <?php if (!$events): ?>
        <div class="card">No confirmed orders yet.</div>
    <?php else: ?>
    <div class="card">
        <h2>Event</h2>
        <form method="GET" class="row">
            <select name="event" onchange="this.form.submit()">
                <?php foreach ($events as $e): ?>
                <option value="<?= (int)$e['id'] ?>" <?= (int)$e['id'] === $eventId ? 'selected' : '' ?>><?= htmlspecialchars($e['title']) ?> (<?= (int)$e['orders_count'] ?> orders)</option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <div class="card">
        <h2>Send</h2>
        <div class="stats">
            <div class="stat"><?= count($groups) ?><small>recipients in total</small></div>
            <div class="stat" id="left"><?= $unsentGroups ?><small>not sent yet (<?= $unsentOrders ?> orders)</small></div>
        </div>
        <div class="row" style="margin-bottom:14px">
            <input type="email" id="testTo" placeholder="Your email for a test send">
            <button class="btn" id="testBtn" <?= $unsentGroups ? '' : 'disabled' ?>>Send test</button>
        </div>
        <button class="btn btn-primary" id="sendAll" <?= $unsentGroups ? '' : 'disabled' ?>>Send to all (<?= $unsentGroups ?>)</button>
        <div class="bar" id="bar"><i></i></div>
        <div id="log"></div>
    </div>

    <?php if ($preview): ?>
    <div class="card">
        <h2>Preview (first recipient: <?= htmlspecialchars($sample['email']) ?>)</h2>
        <iframe srcdoc="<?= htmlspecialchars($preview, ENT_QUOTES) ?>"></iframe>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<script>
const CSRF = <?= json_encode($_SESSION['csrf']) ?>, EVENT = <?= (int)$eventId ?>;
const total = <?= (int)$unsentGroups ?>;
const log = document.getElementById('log'), bar = document.getElementById('bar');
const say = (t, cls) => { const d = document.createElement('div'); d.textContent = t; if (cls) d.className = cls; log.appendChild(d); };
const post = data => fetch('announce', { method: 'POST', body: new URLSearchParams({ csrf: CSRF, event: EVENT, ...data }) }).then(r => r.json());

document.getElementById('testBtn')?.addEventListener('click', async () => {
    const to = document.getElementById('testTo').value.trim();
    const r = await post({ action: 'test', to });
    say(r.ok ? 'Test sent to ' + to : 'Test failed: ' + r.error, r.ok ? '' : 'err');
});

document.getElementById('sendAll')?.addEventListener('click', async e => {
    if (!confirm('Send the announcement to ' + total + ' recipients now?')) return;
    e.target.disabled = true; bar.style.display = 'block';
    let done = 0;
    while (true) {
        const r = await post({ action: 'batch' }).catch(() => ({ ok: false, error: 'Network error' }));
        if (!r.ok) { say('Stopped: ' + r.error, 'err'); break; }
        done += r.sent;
        r.failed.forEach(f => say('Failed: ' + f, 'err'));
        bar.firstElementChild.style.width = Math.min(100, done / total * 100) + '%';
        document.getElementById('left').firstChild.textContent = r.remaining;
        if (r.remaining === 0) { say('Done. ' + done + ' emails sent.'); break; }
        if (r.stalled) { say('Stopped: the remaining ' + r.remaining + ' keep failing. Fix the errors above and click again.', 'err'); break; }
    }
    e.target.disabled = false;
});
</script>
</body>
</html>

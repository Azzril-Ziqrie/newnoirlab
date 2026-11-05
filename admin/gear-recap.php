<?php
require_once 'auth.php';
require_once '../config/config.php';
require_once '../lib/addons.php';
$pdo = getDBConnection();

/**
 * Isi paket dibaca dari nama tier / add-on, mis. "GROUP 3 PERSON. Include 4-person tent, 2 mats & 2 sleeping bags"
 * → tenda kap 4 ×1, matras 2, sleeping bag 2 (per unit). Tier tanpa kalimat "Include ..." tidak membawa peralatan.
 */
function gearFromName(string $name): ?array
{
    // Paket add-on: data kita sendiri, selalu 1 tenda kap 4 + 2 matras + 2 sleeping bag, tidak bergantung teks nama.
    if (preg_match('/add[\s-]*ons?|just a tent/i', $name)) {   // "just a tent" = nama lama tier add-on di produksi
        return ['size' => 4, 'mats' => 2, 'bags' => 2];
    }
    // Paket Group: juga dikunci di sini (sesuai poster), supaya rekap benar walau nama tier di DB masih versi lama.
    if (preg_match('/group\s*3\b/i', $name)) {
        return ['size' => 4, 'mats' => 2, 'bags' => 2];
    }
    if (preg_match('/group\s*5\b/i', $name)) {
        return ['size' => 6, 'mats' => 5, 'bags' => 5];
    }
    if (!preg_match('/include\s+(\d+)\s*-?\s*person\s+tent\D+(\d+)\s*mats?\D+(\d+)\s*sleeping\s*bags?/i', $name, $m)) {
        return null;
    }
    return ['size' => (int) $m[1], 'mats' => (int) $m[2], 'bags' => (int) $m[3]];
}

function shortName(string $tier): string
{
    return trim(explode('. ', $tier, 2)[0]);
}

/** Order berstatus $statuses pada satu event, beserta peralatan dari tier dan add-on-nya. */
function gearLines(PDO $pdo, int $eventId, array $statuses): array
{
    ensureAddonTables($pdo);
    $marks = implode(',', array_fill(0, count($statuses), '?'));
    $st = $pdo->prepare("
        SELECT o.id, o.order_code, o.buyer_name, o.qty, t.tier_name
        FROM orders o JOIN tickets t ON t.id = o.ticket_id
        WHERE o.event_id = ? AND o.status IN ($marks)
        ORDER BY o.buyer_name, o.id");
    $st->execute(array_merge([$eventId], $statuses));
    $orders = $st->fetchAll();

    $addons = [];
    if ($orders) {
        $ids = implode(',', array_map('intval', array_column($orders, 'id')));
        foreach ($pdo->query("SELECT order_id, name, qty FROM order_addons WHERE order_id IN ($ids)") as $a) {
            $addons[$a['order_id']][] = $a;
        }
    }

    $lines = [];   // satu baris per sumber peralatan: tier atau add-on
    foreach ($orders as $o) {
        $sources = [[$o['tier_name'], (int) $o['qty'], false]];
        foreach ($addons[$o['id']] ?? [] as $a) $sources[] = [$a['name'], (int) $a['qty'], true];
        foreach ($sources as [$name, $qty, $isAddon]) {
            $g = gearFromName($name);
            if (!$g) continue;
            $lines[] = ['order' => $o, 'source' => ($isAddon ? 'Add-on: ' : '') . shortName($name), 'qty' => $qty,
                        'size' => $g['size'], 'tents' => $qty, 'mats' => $g['mats'] * $qty, 'bags' => $g['bags'] * $qty];
        }
    }
    return $lines;
}

$events  = $pdo->query("SELECT id, title, event_date_start FROM events ORDER BY event_date_start DESC")->fetchAll();
$eventId = (int) ($_GET['event_id'] ?? 0);
if (!$eventId && $events) {
    // Event yang punya paket peralatan terbanyak; kalau tidak ada, event terbaru.
    foreach ($events as $ev) {
        if (gearLines($pdo, (int) $ev['id'], ['confirmed', 'used'])) { $eventId = (int) $ev['id']; break; }
    }
    $eventId = $eventId ?: (int) $events[0]['id'];
}
$event = null;
foreach ($events as $ev) if ((int) $ev['id'] === $eventId) $event = $ev;

$lines   = $event ? gearLines($pdo, $eventId, ['confirmed', 'used']) : [];
$pending = $event ? gearLines($pdo, $eventId, ['pending']) : [];

/**
 * Rangkum baris peralatan: total tenda/matras/sleeping bag, rincian per paket, dan per pembeli.
 * "orders" dihitung per order unik (satu order bisa punya baris tier + add-on), bukan per baris.
 */
function summarizeGear(array $lines): array
{
    $sum = ['tentsBySize' => [], 'mats' => 0, 'bags' => 0, 'bySource' => [], 'people' => [], 'orderIds' => []];
    foreach ($lines as $l) {
        $orderId = (int) $l['order']['id'];
        $sum['tentsBySize'][$l['size']] = ($sum['tentsBySize'][$l['size']] ?? 0) + $l['tents'];
        $sum['mats'] += $l['mats'];
        $sum['bags'] += $l['bags'];
        $sum['orderIds'][$orderId] = true;

        $s = &$sum['bySource'][$l['source']];
        $s ??= ['orderIds' => [], 'qty' => 0, 'tents' => [], 'mats' => 0, 'bags' => 0];
        $s['orderIds'][$orderId] = true;
        $s['qty'] += $l['qty'];
        $s['tents'][$l['size']] = ($s['tents'][$l['size']] ?? 0) + $l['tents'];
        $s['mats'] += $l['mats'];
        $s['bags'] += $l['bags'];
        unset($s);

        // Gabung per nama pembeli (huruf besar/kecil dan spasi ganda diabaikan)
        $key = mb_strtolower(preg_replace('/\s+/u', ' ', trim($l['order']['buyer_name'])), 'UTF-8');
        $p = &$sum['people'][$key];
        $p ??= ['name' => trim($l['order']['buyer_name']), 'tents' => [], 'mats' => 0, 'bags' => 0, 'codes' => []];
        $p['tents'][$l['size']] = ($p['tents'][$l['size']] ?? 0) + $l['tents'];
        $p['mats'] += $l['mats'];
        $p['bags'] += $l['bags'];
        $p['codes'][$l['order']['order_code']] = true;
        unset($p);
    }
    ksort($sum['tentsBySize']);
    uasort($sum['people'], fn ($a, $b) => strcasecmp($a['name'], $b['name']));
    return $sum;
}

['tentsBySize' => $tentsBySize, 'mats' => $mats, 'bags' => $bags, 'bySource' => $bySource,
 'people' => $people, 'orderIds' => $orderIds] = summarizeGear($lines);
$sizes = array_keys($tentsBySize);

$pendingTents = array_sum(array_column($pending, 'tents'));
$pendingMats  = array_sum(array_column($pending, 'mats'));
$pendingBags  = array_sum(array_column($pending, 'bags'));

if (($_GET['export'] ?? '') === 'xlsx' && $event) {
    require_once dirname(__DIR__) . '/lib/xlsx-writer.php';
    $X = 'XlsxWriter';
    $slug  = trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $event['title']), '-');
    $bulan = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $today = (int) date('j') . ' ' . $bulan[(int) date('n')] . ' ' . date('Y');
    $cols  = array_reverse($sizes);                       // Tent 6, Tent 4, Tent 3 seperti contoh
    $blank = fn ($n) => $n > 0 ? $n : '';                 // nol dikosongkan supaya tabel mudah dibaca

    // Sheet 1: peserta + tenda
    $d = [];
    $d[] = [['PESERTA + TENDA (data ' . $today . ')', $X::G_TITLE]];
    $d[] = [['Group 3 = tenda kap 4 · Group 5 = tenda kap 6 · add-on = tenda kap 4 · digabung per nama', $X::G_NOTE]];
    $d[] = [];
    $h = [['Nama', $X::G_TH], ['Jumlah Tenda', $X::G_TH]];
    foreach ($cols as $z) $h[] = ["Tent $z", $X::G_TH];
    $h[] = ['Matras', $X::G_TH]; $h[] = ['SB', $X::G_TH];
    $d[] = $h;
    foreach ($people as $p) {
        $row = [[$p['name'], $X::G_NAME], [array_sum($p['tents']), $X::G_RED]];
        foreach ($cols as $z) $row[] = [$blank($p['tents'][$z] ?? 0), $X::G_NUM];
        $row[] = [$blank($p['mats']), $X::G_NUM]; $row[] = [$blank($p['bags']), $X::G_NUM];
        $d[] = $row;
    }
    $t = [['TOTAL', $X::G_TOT_LABEL], [array_sum($tentsBySize), $X::G_TOT_NUM]];
    foreach ($cols as $z) $t[] = [$tentsBySize[$z], $X::G_TOT_NUM];
    $t[] = [$mats, $X::G_TOT_NUM]; $t[] = [$bags, $X::G_TOT_NUM];
    $d[] = $t;

    // Sheet 2: total peralatan
    $r = [];
    $r[] = [['Peralatan Total', $X::G_TITLE]];
    $r[] = [[$event['title'] . ' · order confirmed + sudah masuk · ' . $today, $X::G_NOTE]];
    $r[] = [];
    $r[] = [['Item', $X::G_TH], ['Jumlah', $X::G_TH]];
    foreach ($tentsBySize as $size => $n) $r[] = [["Tenda kap $size", $X::G_NAME], [$n, $X::G_NUM]];
    $r[] = [['Matras', $X::G_NAME], [$mats, $X::G_NUM]];
    $r[] = [['Sleeping bag', $X::G_NAME], [$bags, $X::G_NUM]];

    $n = count($cols);
    $xl = new XlsxWriter();
    $xl->addSheet('Peserta + Tenda', $d, [
        'widths' => array_merge([34, 16], array_fill(0, $n + 2, 14)),
        'rowHeights' => [1 => 28, 4 => 24], 'freeze' => 4, 'landscape' => true,
    ]);
    $xl->addSheet('Peralatan Total', $r, ['widths' => [28, 14], 'rowHeights' => [1 => 28, 4 => 24]]);
    if ($xl->output('Rekap-Peralatan-' . $slug . '-' . date('Ymd') . '.xlsx')) exit;
    header('Location: gear-recap?event_id=' . $eventId . '&export=csv');   // ext-zip tidak ada → CSV
    exit;
}

if (($_GET['export'] ?? '') === 'csv' && $event) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Rekap-Peralatan-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBFsep=;\r\n");
    fputcsv($out, array_merge(['Nama'], array_map(fn ($s) => "Tenda kap $s", $sizes), ['Matras', 'Sleeping bag', 'Kode tiket']), ';');
    foreach ($people as $p) {
        fputcsv($out, array_merge([$p['name']], array_map(fn ($s) => $p['tents'][$s] ?? 0, $sizes),
            [$p['mats'], $p['bags'], implode(', ', array_keys($p['codes']))]), ';');
    }
    fputcsv($out, array_merge(['TOTAL'], array_map(fn ($s) => $tentsBySize[$s], $sizes), [$mats, $bags, '']), ';');
    fclose($out);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rekap Peralatan — Noirlab Admin</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: #fff; display: flex; min-height: 100vh; }
        .main { flex: 1; padding: 50px 48px; min-width: 0; }
        .page-header { margin-bottom: 32px; padding-bottom: 28px; border-bottom: 1px solid #e5e5e5; }
        .page-header h1 { font-size: 1.75rem; font-weight: 700; }
        .page-header p { font-size: .875rem; color: rgba(0,0,0,.45); margin-top: 8px; }
        .bar { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 24px; }
        .bar select { flex: 1; min-width: 240px; max-width: 460px; padding: 0 12px; }
        .totals { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); border: 1px solid #e5e5e5; margin-bottom: 12px; }
        .total { padding: 22px 20px; border-right: 1px solid #e5e5e5; }
        .total:last-child { border-right: 0; }
        .total b { display: block; font-size: 2.4rem; line-height: 1; letter-spacing: -.03em; font-variant-numeric: tabular-nums; }
        .total span { display: block; margin-top: 10px; font-size: .68rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: rgba(0,0,0,.45); }
        .note { font-size: .8rem; color: rgba(0,0,0,.5); margin-bottom: 32px; line-height: 1.6; }
        h2 { font-size: .8rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: rgba(0,0,0,.45); margin: 0 0 12px; }
        .card { border: 1px solid #e5e5e5; margin-bottom: 32px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 11px 16px; font-size: .65rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: rgba(0,0,0,.4); border-bottom: 1px solid #e5e5e5; white-space: nowrap; }
        td { padding: 12px 16px; font-size: .875rem; border-bottom: 1px solid #f3f3f3; vertical-align: top; }
        tr:last-child td { border-bottom: 0; }
        th.n, td.n { text-align: right; font-variant-numeric: tabular-nums; }
        tfoot td { font-weight: 700; background: #000; color: #fff; border: 0; }
        .codes { font-family: monospace; font-size: .75rem; color: rgba(0,0,0,.5); }
        .empty { padding: 48px 20px; text-align: center; color: rgba(0,0,0,.35); font-size: .875rem; }
        .search { max-width: 320px; width: 100%; padding: 0 12px; margin-bottom: 12px; }
        @media (max-width: 860px) {
            .main { padding: 24px 16px; } .page-header h1 { font-size: 1.4rem; }
            .total { border-right: 0; border-bottom: 1px solid #e5e5e5; } .total:last-child { border-bottom: 0; }
            .totals { grid-template-columns: repeat(2, 1fr); } .total:nth-child(odd):not(:last-child) { border-right: 1px solid #e5e5e5; }
            .total b { font-size: 2rem; }
        }
    </style>
<?php require_once __DIR__ . '/_shell.php'; adminShellStyles(); ?>
</head>
<body>
<?php require_once __DIR__ . '/_shell.php'; adminShell(); ?>

<div class="main">
    <div class="page-header">
        <h1>Rekap Peralatan</h1>
        <p>Berapa tenda, matras, dan sleeping bag yang harus disiapkan. Dihitung otomatis dari order yang sudah dibayar (confirmed dan sudah masuk).</p>
    </div>

    <form method="GET" class="bar">
        <select name="event_id" onchange="this.form.submit()">
            <?php foreach ($events as $ev): ?>
            <option value="<?= (int) $ev['id'] ?>" <?= (int) $ev['id'] === $eventId ? 'selected' : '' ?>><?= htmlspecialchars($ev['title']) ?> — <?= date('d M Y', strtotime($ev['event_date_start'])) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($lines): ?><a class="btn btn-primary" href="gear-recap?event_id=<?= $eventId ?>&export=xlsx">Export Excel</a><a class="btn" href="gear-recap?event_id=<?= $eventId ?>&export=csv">CSV polos</a><?php endif; ?>
    </form>

    <?php if (!$lines): ?>
        <div class="card"><div class="empty">Belum ada order berisi tenda untuk event ini.</div></div>
    <?php else: ?>

    <div class="totals">
        <?php foreach ($tentsBySize as $size => $n): ?>
        <div class="total"><b><?= $n ?></b><span>Tenda kap <?= $size ?></span></div>
        <?php endforeach; ?>
        <div class="total"><b><?= $mats ?></b><span>Matras</span></div>
        <div class="total"><b><?= $bags ?></b><span>Sleeping bag</span></div>
    </div>
    <?php if ($pending): ?>
    <p class="note">Belum dihitung: order <strong>pending</strong> (belum dibayar) akan menambah <?= $pendingTents ?> tenda, <?= $pendingMats ?> matras, dan <?= $pendingBags ?> sleeping bag kalau nanti dibayar.</p>
    <?php else: ?><p class="note">Tidak ada order pending berisi tenda.</p><?php endif; ?>

    <h2>Per paket</h2>
    <div class="card">
        <table>
            <thead><tr><th>Paket</th><th class="n">Order</th><th class="n">Qty</th>
                <?php foreach ($sizes as $s): ?><th class="n">Tenda kap <?= $s ?></th><?php endforeach; ?>
                <th class="n">Matras</th><th class="n">Sleeping bag</th></tr></thead>
            <tbody>
            <?php foreach ($bySource as $name => $s): ?>
                <tr><td><?= htmlspecialchars($name) ?></td><td class="n"><?= count($s['orderIds']) ?></td><td class="n"><?= $s['qty'] ?></td>
                    <?php foreach ($sizes as $z): ?><td class="n"><?= $s['tents'][$z] ?? 0 ?></td><?php endforeach; ?>
                    <td class="n"><?= $s['mats'] ?></td><td class="n"><?= $s['bags'] ?></td></tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td>Total</td><td class="n"><?= count($orderIds) ?></td><td class="n"><?= array_sum(array_column($bySource, 'qty')) ?></td>
                <?php foreach ($sizes as $z): ?><td class="n"><?= $tentsBySize[$z] ?></td><?php endforeach; ?>
                <td class="n"><?= $mats ?></td><td class="n"><?= $bags ?></td></tr></tfoot>
        </table>
    </div>

    <h2>Per pembeli (<?= count($people) ?> orang)</h2>
    <input type="search" class="search" id="q" placeholder="Cari nama atau kode…">
    <div class="card">
        <table id="people">
            <thead><tr><th>Nama</th>
                <?php foreach ($sizes as $s): ?><th class="n">Tenda kap <?= $s ?></th><?php endforeach; ?>
                <th class="n">Matras</th><th class="n">Sleeping bag</th><th>Kode tiket</th></tr></thead>
            <tbody>
            <?php foreach ($people as $p): ?>
                <tr><td><?= htmlspecialchars($p['name']) ?></td>
                    <?php foreach ($sizes as $z): ?><td class="n"><?= $p['tents'][$z] ?? 0 ?: '–' ?></td><?php endforeach; ?>
                    <td class="n"><?= $p['mats'] ?></td><td class="n"><?= $p['bags'] ?></td>
                    <td class="codes"><?= htmlspecialchars(implode(', ', array_keys($p['codes']))) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<script>
document.getElementById('q')?.addEventListener('input', e => {
    const q = e.target.value.trim().toLowerCase();
    document.querySelectorAll('#people tbody tr').forEach(r => { r.hidden = q && !r.textContent.toLowerCase().includes(q); });
});
</script>
</body>
</html>

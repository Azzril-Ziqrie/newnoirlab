<?php
require_once 'auth.php';
require_once '../config/config.php';
require_once '../lib/tents.php';
$pdo = getDBConnection();
ensureTentTables($pdo);

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

// Event BONAFEST yang masih upcoming (sama seperti halaman Input BONAFEST)
$event = $pdo->query("SELECT id, title FROM events WHERE status='upcoming' AND title LIKE 'BONAFEST%CIPUTRI%' ORDER BY event_date_start ASC, id DESC LIMIT 1")->fetch();
if (!$event) {
    $event = $pdo->query("SELECT id, title FROM events WHERE title LIKE 'BONAFEST%' ORDER BY event_date_start DESC, id DESC LIMIT 1")->fetch();
}
$eventId = $event ? (int) $event['id'] : 0;

/** Peta aksi editor denah → fungsi di lib/tents.php. Tiap aksi mengembalikan data tambahan (atau []) untuk dikirim ke klien. */
function tentActions(PDO $pdo, int $eventId): array
{
    return [
        'load'      => function (array $in) use ($pdo, $eventId) { tentSeedIfUnconfigured($pdo, $eventId); return []; },
        'save_plot' => function (array $in) use ($pdo, $eventId) { tentSavePlot($pdo, $eventId, (int) ($in['rows'] ?? 0), (int) ($in['cols'] ?? 0), (string) ($in['front'] ?? '')); return []; },
        'seed'      => function (array $in) use ($pdo, $eventId) { tentSeedBonafest($pdo, $eventId); return []; },
        'reset'     => function (array $in) use ($pdo, $eventId) { tentResetToBonafest($pdo, $eventId); return []; },
        'fill'      => function (array $in) use ($pdo, $eventId) { tentFillEmptyCells($pdo, $eventId); return []; },
        'add'       => function (array $in) use ($pdo, $eventId) { tentAddAt($pdo, $eventId, (int) ($in['r'] ?? -1), (int) ($in['c'] ?? -1)); return []; },
        'save'      => function (array $in) use ($pdo, $eventId) { tentUpdate($pdo, $eventId, (int) ($in['id'] ?? 0), $in); return []; },
        'delete'    => function (array $in) use ($pdo, $eventId) { tentDelete($pdo, $eventId, (int) ($in['id'] ?? 0)); return []; },
        'move'      => function (array $in) use ($pdo, $eventId) { tentMove($pdo, $eventId, (int) ($in['id'] ?? 0), (int) ($in['r'] ?? -1), (int) ($in['c'] ?? -1)); return []; },
        'orders'    => fn(array $in) => ['orders' => tentSearchOrders($pdo, $eventId, (string) ($in['q'] ?? ''))],
    ];
}

function tentRespond(array $body, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
}

function tentHandleRequest(PDO $pdo, int $eventId): void
{
    header('Content-Type: application/json; charset=utf-8');
    $in = json_decode(file_get_contents('php://input'), true) ?: [];

    if (!hash_equals($_SESSION['csrf'], (string) ($in['csrf'] ?? ''))) {
        tentRespond(['ok' => false, 'error' => 'Token sesi tidak valid. Muat ulang halaman.'], 403);
        return;
    }
    if (!$eventId) {
        tentRespond(['ok' => false, 'error' => 'Event BONAFEST tidak ditemukan.'], 400);
        return;
    }

    $action = tentActions($pdo, $eventId)[$in['action'] ?? ''] ?? null;
    if (!$action) {
        tentRespond(['ok' => false, 'error' => 'Aksi tidak dikenal.'], 400);
        return;
    }

    try {
        $extra = $action($in);
        // Aksi 'orders' hanya mencari data; aksi lain mengembalikan denah terbaru.
        tentRespond(['ok' => true] + ($extra ?: tentState($pdo, $eventId)));
    } catch (TentException $e) {
        tentRespond(['ok' => false, 'error' => $e->getMessage()], 400);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('tent-plot: ' . $e->getMessage());
        tentRespond(['ok' => false, 'error' => 'Gagal menyimpan. Coba lagi.'], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tentHandleRequest($pdo, $eventId);
    exit;
}

$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Plot Tenda — Noirlab</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; background:#fff; color:#000; -webkit-font-smoothing:antialiased; display:flex; min-height:100vh; }
        .sidebar { width:220px; flex-shrink:0; border-right:1px solid #e5e5e5; display:flex; flex-direction:column; padding:40px 0; position:fixed; top:0; left:0; height:100vh; overflow-y:auto; background:#fff; }
        .sidebar-logo { padding:0 28px 32px; border-bottom:1px solid #e5e5e5; }
        .sidebar-logo img { height:103px; width:auto; display:block; }
        .sidebar-menu { padding:24px 16px; flex:1; display:flex; flex-direction:column; gap:2px; }
        .sidebar-menu a { padding:10px 14px; color:rgba(0,0,0,.4); text-decoration:none; font-size:.875rem; font-weight:500; }
        .sidebar-menu a.active { color:#000; font-weight:700; border-left:2px solid #000; padding-left:12px; }
        .sidebar-footer { padding:24px 28px 0; border-top:1px solid #e5e5e5; }
        .sidebar-footer a { font-size:.8rem; color:rgba(0,0,0,.35); text-decoration:none; font-weight:500; }
        .main { margin-left:220px; flex:1; padding:50px 48px; min-width:0; }
        .page-header { margin-bottom:28px; padding-bottom:24px; border-bottom:1px solid #e5e5e5; }
        .page-header h1 { font-size:1.75rem; font-weight:700; letter-spacing:-.5px; line-height:1; }
        .page-header p { font-size:.875rem; color:rgba(0,0,0,.4); margin-top:8px; }

        .toolbar { display:flex; flex-wrap:wrap; gap:12px 18px; align-items:flex-end; margin-bottom:20px; }
        .tf label { display:block; font-size:.65rem; font-weight:700; color:rgba(0,0,0,.4); margin-bottom:5px; text-transform:uppercase; letter-spacing:.7px; }
        .tf input, .tf select, .tf textarea { padding:9px 12px; border:1px solid #e5e5e5; font-size:.85rem; font-family:inherit; background:#fff; color:#000; }
        .tf input:focus, .tf select:focus, .tf textarea:focus { outline:none; border-color:#000; }
        .tf input.num { width:76px; }
        .tbtn { padding:10px 16px; border:1px solid #000; background:#fff; font-size:.8rem; font-weight:700; cursor:pointer; font-family:inherit; }
        .tbtn.primary { background:#000; color:#fff; }
        .tbtn:hover { opacity:.7; }
        .tbtn.danger { border-color:#b00020; color:#b00020; }
        .stats { display:flex; gap:22px; font-size:.8rem; color:rgba(0,0,0,.55); margin-bottom:18px; flex-wrap:wrap; }
        .stats b { color:#000; font-size:1rem; margin-right:4px; }

        .layout { display:grid; grid-template-columns:1fr 340px; gap:24px; align-items:start; }
        .board { border:1px solid #e5e5e5; padding:24px; overflow:auto; }
        .front { text-align:center; font-size:.7rem; font-weight:800; letter-spacing:6px; padding:10px; background:#000; color:#fff; margin:0 auto 26px; max-width:100%; clip-path:polygon(4% 0,96% 0,100% 100%,0 100%); }

        .grid { display:grid; gap:6px; width:max-content; margin:0 auto; --cell:54px; }
        .cell { width:var(--cell); height:var(--cell); border:1px dashed rgba(0,0,0,.12); background:transparent; cursor:pointer; padding:0; font-family:inherit; }
        .cell:hover { border-color:#000; }
        .tent { border:1.5px solid #000; background:#fff; color:#000; display:flex; align-items:center; justify-content:center; }   /* kotak putih polos bernomor */
        .tent .lab { font-size:1rem; font-weight:700; line-height:1; }
        .tent.taken { background:#000; color:#fff; }
        .tent.blocked { background:repeating-linear-gradient(45deg,#f1f1f1,#f1f1f1 6px,#e2e2e2 6px,#e2e2e2 12px); border-color:rgba(0,0,0,.25); color:rgba(0,0,0,.35); }
        .tent.sel { outline:3px solid #2563eb; outline-offset:2px; }
        .tent.hit { box-shadow:0 0 0 4px #facc15; }
        .tent.dim { opacity:.25; }
        .tent.over { outline:3px dashed #2563eb; }
        .cell.over { border-color:#2563eb; background:rgba(37,99,235,.08); }
        .legend { display:flex; gap:16px; font-size:.72rem; color:rgba(0,0,0,.55); margin-top:18px; justify-content:center; flex-wrap:wrap; }
        .legend i { display:inline-block; width:12px; height:12px; border:1.5px solid #000; margin-right:6px; vertical-align:-2px; }
        .legend i.t { background:#000; } .legend i.b { background:#ddd; border-color:#999; } .legend i.e { border:1px dashed #aaa; }

        .panel { border:1px solid #e5e5e5; padding:24px; position:sticky; top:20px; }
        .panel h2 { font-size:.9rem; font-weight:700; margin-bottom:18px; }
        .panel .tf { margin-bottom:14px; }
        .panel .tf input, .panel .tf select, .panel .tf textarea { width:100%; }
        .panel .row2 { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
        .panel .actions { display:flex; gap:10px; margin-top:6px; }
        .panel .actions .tbtn { flex:1; }
        .hint { font-size:.8rem; color:rgba(0,0,0,.4); line-height:1.6; }
        .sugg { border:1px solid #e5e5e5; margin-top:-1px; max-height:190px; overflow:auto; }
        .sugg button { display:block; width:100%; text-align:left; padding:9px 12px; border:0; border-bottom:1px solid #f0f0f0; background:#fff; font-size:.8rem; cursor:pointer; font-family:inherit; }
        .sugg button:hover { background:#f5f5f5; }
        .sugg small { color:rgba(0,0,0,.4); }
        .linked { font-size:.72rem; color:rgba(0,0,0,.5); margin-top:6px; }
        .toast { position:fixed; bottom:24px; left:50%; transform:translateX(-50%); background:#000; color:#fff; padding:11px 20px; font-size:.85rem; font-weight:600; display:none; z-index:50; }
        .toast.err { background:#b00020; }
        @media (max-width:1100px) { .layout { grid-template-columns:1fr; } .panel { position:static; } }
        @media (max-width:860px) {
            .sidebar { display:none; } .main { margin-left:0; padding:28px 16px; } .board { padding:14px; }
            .grid { --cell:42px; gap:4px; } .tent .lab { font-size:.85rem; }
            .tf input, .tf select, .tf textarea { font-size:16px; }   /* 16px: iOS tidak zoom saat input diketuk */
            .panel { position:fixed; left:0; right:0; bottom:0; top:auto; max-height:55vh; overflow:auto; z-index:40; background:#fff; border:0; border-top:1px solid #000; box-shadow:0 -8px 24px rgba(0,0,0,.15); padding:16px; display:none; }
            body.has-sel .panel { display:block; }
            body.moving .panel { display:none; }
            body.has-sel .main { padding-bottom:60vh; }   /* ruang supaya baris terbawah tidak tertutup panel */
            .mobile-only { display:inline-block !important; }
        }
        .mobile-only { display:none; }
        @media print {
            .toolbar, .panel, .stats, .page-header p { display:none !important; }
            .layout { display:block; } .board { border:0; padding:0; }
            .tent.sel { outline:0; } .tent.taken, .front { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
        }
    </style>
<?php require_once __DIR__ . '/_shell.php'; adminShellStyles(); ?>
</head>
<body>

<?php require_once __DIR__ . '/_shell.php'; adminShell(); ?>

<div class="main">
    <div class="page-header">
        <h1>Plot Tenda</h1>
        <p><?= $event ? $h($event['title']) . ' — atur posisi tenda seperti kursi bioskop, lalu beri nama tamu di tiap tenda.' : 'Event BONAFEST tidak ditemukan.' ?></p>
    </div>

<?php if (!$event): ?>
    <div class="hint">Buat event BONAFEST dulu agar denah tenda bisa dibuat.</div>
<?php else: ?>
    <div class="toolbar">
        <div class="tf"><label for="inRows">Baris</label><input class="num" type="number" id="inRows" min="1" max="<?= TENT_MAX_ROWS ?>"></div>
        <div class="tf"><label for="inCols">Kolom</label><input class="num" type="number" id="inCols" min="1" max="<?= TENT_MAX_COLS ?>"></div>
        <div class="tf"><label for="inFront">Label depan</label><input type="text" id="inFront" maxlength="60" placeholder="STAGE"></div>
        <button class="tbtn" id="btnPlot" type="button">Terapkan ukuran</button>
        <button class="tbtn" id="btnFill" type="button">Isi semua sel dengan tenda</button>
        <button class="tbtn primary" id="btnSeed" type="button" hidden>Muat denah awal BONAFEST (99 tenda)</button>
        <button class="tbtn danger" id="btnReset" type="button" title="Hapus semua tenda lalu muat ulang denah awal dari sketsa">Reset ke denah awal</button>
        <div class="tf" style="margin-left:auto"><label for="inFind">Cari tamu / tenda</label><input type="search" id="inFind" placeholder="Nomor atau nama…"></div>
        <button class="tbtn" type="button" onclick="window.print()">Cetak</button>
    </div>

    <div class="stats" id="stats"></div>

    <div class="layout">
        <div class="board">
            <div class="front" id="front"></div>
            <div class="grid" id="grid"></div>
            <div class="legend">
                <span><i></i>Tenda kosong</span><span><i class="t"></i>Terisi</span><span><i class="b"></i>Tidak dipakai</span><span><i class="e"></i>Lorong (klik untuk pasang tenda)</span>
            </div>
        </div>

        <aside class="panel" id="panel"></aside>
    </div>
<?php endif; ?>
</div>
<div class="toast" id="toast"></div>

<?php if ($event): ?>
<script>
(() => {
    const CSRF = <?= json_encode($_SESSION['csrf']) ?>;
    let plot = { rows: 0, cols: 0, front: '' }, tents = [], selId = null, drag = null, moveId = null;
    const $ = id => document.getElementById(id);
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    function toast(msg, err) {
        const t = $('toast'); t.textContent = msg; t.className = 'toast' + (err ? ' err' : ''); t.style.display = 'block';
        clearTimeout(toast.t); toast.t = setTimeout(() => t.style.display = 'none', 2600);
    }

    async function api(action, data = {}) {
        try {
            const res = await fetch(location.pathname, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action, csrf: CSRF, ...data }) });
            const j = await res.json();
            if (!j.ok) { toast(j.error || 'Gagal.', true); return null; }
            return j;
        } catch (e) { toast('Koneksi bermasalah.', true); return null; }
    }

    function apply(j) {
        if (!j) return false;
        if (j.plot) plot = j.plot;
        if (j.tents) tents = j.tents;
        if (selId && !tents.some(t => t.id === selId)) selId = null;
        render();
        return true;
    }

    const byCell = () => { const m = new Map(); tents.forEach(t => m.set(t.r + ',' + t.c, t)); return m; };
    const isTaken = t => t.status !== 'blocked' && t.guest.trim() !== '';

    function render() {
        $('inRows').value = plot.rows; $('inCols').value = plot.cols; if (document.activeElement !== $('inFront')) $('inFront').value = plot.front;
        $('front').textContent = plot.front || '';
        $('front').style.display = plot.front ? '' : 'none';

        const q = $('inFind').value.trim().toLowerCase();
        const cells = byCell();
        const g = $('grid');
        g.style.gridTemplateColumns = `repeat(${plot.cols}, var(--cell))`;
        let html = '';
        for (let r = 0; r < plot.rows; r++) {
            for (let c = 0; c < plot.cols; c++) {
                const t = cells.get(r + ',' + c);
                if (!t) { html += `<button type="button" class="cell" data-r="${r}" data-c="${c}" aria-label="Pasang tenda di baris ${r + 1}, kolom ${c + 1}"></button>`; continue; }
                const hay = (t.label + ' ' + t.guest + ' ' + t.note).toLowerCase();
                const cls = ['cell', 'tent', t.status === 'blocked' ? 'blocked' : isTaken(t) ? 'taken' : '', t.id === selId ? 'sel' : '',
                             q ? (hay.includes(q) ? 'hit' : 'dim') : ''].join(' ');
                html += `<button type="button" draggable="true" class="${cls}" data-id="${t.id}" data-r="${r}" data-c="${c}" title="${esc(t.label + (t.guest ? ' — ' + t.guest : ''))}">`
                      + `<span class="lab">${esc(t.label)}</span></button>`;
            }
        }
        g.innerHTML = html;

        const total = tents.length, usable = tents.filter(t => t.status !== 'blocked'), taken = tents.filter(isTaken);
        $('stats').innerHTML = `<span><b>${total}</b>tenda</span><span><b>${taken.length}</b>terisi</span><span><b>${usable.length - taken.length}</b>kosong</span>`
            + `<span><b>${tents.filter(t => t.status === 'blocked').length}</b>tidak dipakai</span><span><b>${taken.reduce((s, t) => s + (t.pax || 0), 0)}</b>orang</span>`;
        $('btnSeed').hidden = tents.length > 0;
        document.body.classList.toggle('has-sel', selId !== null);
        renderPanel();
    }

    function renderPanel() {
        const p = $('panel'), t = tents.find(x => x.id === selId);
        if (!t) { p.innerHTML = '<h2>Detail tenda</h2><p class="hint">Klik sel kosong untuk memasang tenda, atau klik tenda untuk memberi nama tamu.<br><br>Seret tenda ke sel lain untuk memindah (atau pakai tombol Pindahkan di HP); lepas di atas tenda lain untuk menukar posisinya.</p>'; return; }
        p.innerHTML = `
            <h2>Tenda ${esc(t.label)}</h2>
            <div class="tf"><label for="fGuest">Nama tamu</label><input type="text" id="fGuest" maxlength="120" value="${esc(t.guest)}" placeholder="Ketik nama, atau cari pembeli di bawah" autocomplete="off"></div>
            <div class="tf"><label for="fFind">Cari dari order (nama / kode)</label><input type="text" id="fFind" placeholder="mis. Budi atau NL-AB12CD" autocomplete="off">
                <div class="sugg" id="sugg" hidden></div>
                <div class="linked" id="linked">${t.order_code ? 'Terhubung ke order ' + esc(t.order_code) + ' · <a href="#" id="unlink">lepas</a>' : ''}</div></div>
            <div class="row2">
                <div class="tf"><label for="fLabel">Label</label><input type="text" id="fLabel" maxlength="20" value="${esc(t.label)}"></div>
                <div class="tf"><label for="fPax">Jumlah orang</label><input type="number" id="fPax" min="0" max="99" value="${t.pax || ''}"></div>
            </div>
            <div class="tf"><label for="fStatus">Status</label><select id="fStatus"><option value="free">Dipakai</option><option value="blocked"${t.status === 'blocked' ? ' selected' : ''}>Tidak dipakai</option></select></div>
            <div class="tf"><label for="fNote">Catatan</label><textarea id="fNote" rows="2" maxlength="255">${esc(t.note)}</textarea></div>
            <div class="actions"><button class="tbtn primary" id="fSave" type="button">Simpan</button><button class="tbtn danger" id="fDel" type="button">Hapus tenda</button></div>
            <div class="actions"><button class="tbtn" id="fMove" type="button">Pindahkan</button><button class="tbtn mobile-only" id="fClose" type="button">Tutup</button></div>`;

        let orderId = t.order_id, orderCode = t.order_code, timer;
        $('fSave').onclick = async () => {
            apply(await api('save', { id: t.id, label: $('fLabel').value, guest: $('fGuest').value, pax: $('fPax').value, note: $('fNote').value,
                                      status: $('fStatus').value, order_id: orderId, order_code: orderCode })) && toast('Tersimpan.');
        };
        $('fMove').onclick = () => { moveId = t.id; document.body.classList.add('moving'); toast('Ketuk sel tujuan. Ketuk tenda ini lagi untuk batal.'); };
        $('fClose').onclick = () => { selId = null; render(); };
        $('fDel').onclick = async () => { if (confirm('Hapus tenda ' + t.label + '? Tenda kembali jadi lorong.')) apply(await api('delete', { id: t.id })); };
        p.querySelectorAll('input[type=text],input[type=number]').forEach(i => i.addEventListener('keydown', e => { if (e.key === 'Enter') $('fSave').click(); }));
        const un = $('unlink'); if (un) un.onclick = e => { e.preventDefault(); orderId = orderCode = null; $('linked').textContent = 'Link order dilepas (tekan Simpan).'; };

        $('fFind').addEventListener('input', () => {
            clearTimeout(timer);
            const q = $('fFind').value.trim(), box = $('sugg');
            if (q.length < 2) { box.hidden = true; return; }
            timer = setTimeout(async () => {
                const j = await api('orders', { q }); if (!j) return;
                box.hidden = !j.orders.length;
                box.innerHTML = j.orders.map(o => `<button type="button" data-id="${o.id}" data-code="${esc(o.order_code)}" data-name="${esc(o.buyer_name)}" data-qty="${o.qty}">${esc(o.buyer_name)} <small>${esc(o.order_code)} · ${o.qty} tiket</small></button>`).join('');
            }, 250);
        });
        $('sugg').addEventListener('click', e => {
            const b = e.target.closest('button'); if (!b) return;
            $('fGuest').value = b.dataset.name; $('fPax').value = b.dataset.qty; orderId = +b.dataset.id; orderCode = b.dataset.code;
            $('linked').textContent = 'Terhubung ke order ' + orderCode + ' (tekan Simpan).'; $('sugg').hidden = true; $('fFind').value = '';
        });
    }

    // Klik: pilih tenda, pasang tenda di sel kosong, atau tujuan pindah (layar sentuh tidak bisa drag)
    $('grid').addEventListener('click', async e => {
        const b = e.target.closest('.cell'); if (!b) return;
        if (moveId !== null) {
            const id = moveId; moveId = null; document.body.classList.remove('moving');
            if (+b.dataset.id === id) { render(); return; }
            selId = id; apply(await api('move', { id, r: +b.dataset.r, c: +b.dataset.c }));
            return;
        }
        if (b.dataset.id) { selId = +b.dataset.id; render(); return; }
        const j = await api('add', { r: +b.dataset.r, c: +b.dataset.c });
        if (j) { const t = j.tents.find(x => x.r === +b.dataset.r && x.c === +b.dataset.c); selId = t ? t.id : null; apply(j); const g = $('fGuest'); if (g) g.focus(); }
    });
    $('grid').addEventListener('dragstart', e => { const b = e.target.closest('.tent'); if (!b) return; drag = +b.dataset.id; e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', String(drag)); });
    $('grid').addEventListener('dragover', e => { const b = e.target.closest('.cell'); if (!b || drag === null) return; e.preventDefault(); document.querySelectorAll('.over').forEach(x => x.classList.remove('over')); b.classList.add('over'); });
    $('grid').addEventListener('dragend', () => { drag = null; document.querySelectorAll('.over').forEach(x => x.classList.remove('over')); });
    $('grid').addEventListener('drop', async e => {
        const b = e.target.closest('.cell'); if (!b || drag === null) return; e.preventDefault();
        const id = drag; drag = null;
        if (+b.dataset.id === id) { render(); return; }
        selId = id; apply(await api('move', { id, r: +b.dataset.r, c: +b.dataset.c }));
    });

    $('inFind').addEventListener('input', render);

    $('btnPlot').onclick = async () => {
        const rows = Math.max(1, +$('inRows').value || 1), cols = Math.max(1, +$('inCols').value || 1);
        const lost = tents.filter(t => t.r >= rows || t.c >= cols);
        if (lost.length && !confirm(lost.length + ' tenda di luar ukuran baru akan terhapus' + (lost.some(isTaken) ? ' (termasuk yang sudah terisi nama)' : '') + '. Lanjut?')) return;
        apply(await api('save_plot', { rows, cols, front: $('inFront').value })) && toast('Ukuran denah diterapkan.');
    };
    $('btnFill').onclick = async () => {
        if (!confirm('Pasang tenda di semua sel kosong? Tenda yang sudah ada tidak berubah.')) return;
        apply(await api('fill')) && toast('Sel kosong sudah diisi tenda.');
    };

    $('btnSeed').onclick = async () => {
        if (!confirm('Muat denah awal dari sketsa (99 tenda, nomor 1-102 tanpa 33, 60, 61)? Posisi bisa digeser setelahnya.')) return;
        apply(await api('seed')) && toast('Denah awal dimuat.');
    };

    $('btnReset').onclick = async () => {
        const named = tents.filter(isTaken).length;
        if (!confirm('Reset denah ke denah awal BONAFEST (99 tenda bernomor)? Semua posisi diulang' + (named ? ' dan ' + named + ' nama tamu yang sudah diisi akan HILANG' : '') + '.')) return;
        selId = null;
        apply(await api('reset')) && toast('Denah dikembalikan ke denah awal.');
    };

    api('load').then(apply);
})();
</script>
<?php endif; ?>
</body>
</html>

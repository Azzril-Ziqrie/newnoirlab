<?php
require_once 'config/config.php';
require_once 'lib/addons.php';
require_once 'lib/fees.php';
$pdo = getDBConnection();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header("Location: index-event"); exit; }

$stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
$stmt->execute([$id]);
$e = $stmt->fetch();
if (!$e) { header("Location: index-event"); exit; }

$ticketStmt = $pdo->prepare("SELECT * FROM tickets WHERE event_id = ? ORDER BY price ASC");
$ticketStmt->execute([$id]);
$tickets = $ticketStmt->fetchAll();

$startTs      = strtotime($e['event_date_start']);
$dayLabel     = date('l', $startTs);
$dayNum       = date('j', $startTs);
$year         = date('Y', $startTs);
$month        = date('F', $startTs);

if (!empty($e['event_date_end']) && $e['event_date_end'] !== $e['event_date_start']) {
    $dayNum .= '–' . date('j', strtotime($e['event_date_end']));
}

$dateFormatted = "$dayLabel, $dayNum $month $year";
$isUpcoming    = $e['status'] === 'upcoming';

// Cek apakah semua tiket sold out
$allSoldOut = !empty($tickets) && array_reduce($tickets, fn($carry, $t) => $carry && ($t['sold'] >= $t['quota']), true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($e['title']) ?> — Noirlab Collective</title>
    <link rel="stylesheet" href="global.css">
    <style>
        body { background: #ffffff; }

        .wrapper { max-width: 960px; margin: 0 auto; padding: 48px 24px 80px; }

        /* --- BACK LINK --- */
        .back-link {
            display: inline-flex; align-items: center; gap: 8px;
            font-size: 13px; font-weight: 500; color: #666;
            text-decoration: none; margin-bottom: 28px; letter-spacing: 0.2px;
            transition: color .15s;
        }
        .back-link:hover { color: #000; }
        .back-link svg { width: 14px; height: 14px; }

        /* --- HERO --- */
        .event-hero {
            width: 100%;
            height: 400px;
            object-fit: cover;
            display: block;
            margin-bottom: 40px;
            border: 1px solid #000;
            /* Fallback jika gambar gagal load */
            background: #f0f0f0;
        }

        /* --- LAYOUT --- */
        .event-layout {
            display: grid;
            grid-template-columns: 1fr 300px;
            gap: 48px;
            align-items: start;
        }

        /* --- LEFT MAIN --- */
        .category-tag {
            font-size: 10px; font-weight: 700; letter-spacing: 1.8px;
            text-transform: uppercase; color: #888;
            margin-bottom: 12px; display: block;
        }
        .event-title {
            font-size: clamp(24px, 3.5vw, 38px); font-weight: 700;
            letter-spacing: -1px; line-height: 1.15;
            margin-bottom: 16px; color: #000;
        }
        .meta-row {
            display: flex; align-items: center; gap: 8px;
            font-size: 13px; color: #666; margin-bottom: 7px;
        }
        .meta-row svg { width: 13px; height: 13px; stroke: #666; flex-shrink: 0; }

        .divider { border: none; border-top: 1px solid #000; margin: 28px 0; }

        .section-heading {
            font-size: 10px; font-weight: 700; letter-spacing: 1.8px;
            text-transform: uppercase; color: #000; margin-bottom: 16px;
        }
        .event-description { font-size: 14px; line-height: 1.85; color: #444; }

        /* --- TICKET BLOCKS --- */
        .ticket-block { border: 1px solid #000; margin-bottom: 12px; }
        .ticket-tier-label {
            font-size: 10px; font-weight: 700; letter-spacing: 1.8px;
            text-transform: uppercase; color: #888;
            padding: 13px 20px; border-bottom: 1px solid #e0e0e0;
        }
        .ticket-body {
            display: flex; align-items: center;
            justify-content: space-between;
            padding: 16px 20px; gap: 16px;
        }
        .price-label {
            font-size: 10px; font-weight: 700; letter-spacing: 1.2px;
            text-transform: uppercase; color: #aaa; margin-bottom: 5px;
        }
        .price-val { font-size: 16px; font-weight: 700; color: #000; }
        .price-per { font-size: 12px; font-weight: 400; color: #888; }
        .sold-out-text {
            font-size: 10px; font-weight: 700; letter-spacing: 1.4px;
            text-transform: uppercase; color: #aaa;
        }

        /* Add-ons */
        .addon-section { display: none; border-top: 1px solid #e0e0e0; }
        .addon-section.open { display: block; }
        .addon-section .ticket-tier-label { padding-top: 13px; }
        .addon-section .ticket-body { padding-top: 4px; }
        .addon-section .ticket-body + .ticket-body { padding-top: 12px; }

        /* Pesan "no tickets" */
        .no-ticket-msg {
            font-size: 13px; color: #888;
            padding: 16px 0; font-style: italic;
        }

        .qty-select {
            appearance: none; -webkit-appearance: none;
            background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%23666' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E") no-repeat right 12px center;
            border: 1px solid #000;
            padding: 10px 36px 10px 14px;
            font-size: 13px; font-family: inherit; color: #000;
            cursor: pointer; min-width: 160px; outline: none;
            transition: border-color .15s;
        }
        .qty-select:focus { border-color: #000; }

        /* --- SIDEBAR --- */
        .event-sidebar { position: sticky; top: 90px; }

        .sidebar-card {
            border: 1px solid #000;
            padding: 24px;
            margin-bottom: 16px;
        }
        .sidebar-label {
            font-size: 10px; font-weight: 700; letter-spacing: 1.8px;
            text-transform: uppercase; color: #888;
            margin-bottom: 18px; display: block;
        }

        .status-pill {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 5px 12px;
            font-size: 10px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase;
            border: 1px solid #000; margin-bottom: 18px;
        }
        .status-dot { width: 6px; height: 6px; background: #000; flex-shrink: 0; }
        .status-pill.past { border-color: #ccc; color: #888; }
        .status-pill.past .status-dot { background: #ccc; }

        .detail-item { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 16px; }
        .detail-item svg { width: 14px; height: 14px; stroke: #888; flex-shrink: 0; margin-top: 2px; }
        .di-label {
            font-size: 10px; font-weight: 700; letter-spacing: 1.2px;
            text-transform: uppercase; color: #aaa; margin-bottom: 3px;
        }
        .di-value { font-size: 13px; font-weight: 500; color: #000; }

        /* --- SUMMARY + ORDER BUTTON --- */
        .summary-card { border: 1px solid #000; }
        .summary-top {
            display: flex; justify-content: space-between; align-items: baseline;
            padding: 20px 20px 16px; border-bottom: 1px solid #e0e0e0;
        }
        .sum-label {
            font-size: 10px; font-weight: 700; letter-spacing: 1.5px;
            text-transform: uppercase; color: #888;
        }
        .summary-fee { padding: 16px 20px 0; }
        .fee-row { display: flex; justify-content: space-between; font-size: 12px; color: #666; margin-bottom: 8px; }
        .sum-total { font-size: 20px; font-weight: 800; color: #000; letter-spacing: -0.5px; }

        .btn-order {
            display: block; width: 100%; padding: 14px;
            background: #000; color: #fff; border: none;
            font-size: 12px; font-weight: 700; letter-spacing: 1px;
            text-transform: uppercase; cursor: pointer;
            font-family: inherit; transition: opacity .2s;
        }
        .btn-order:hover:not(:disabled) { opacity: 0.75; }
        .btn-order:disabled { background: #e0e0e0; color: #aaa; cursor: not-allowed; }

        /* Notifikasi validasi */
        .order-notice {
            font-size: 11px; color: #c00;
            padding: 10px 20px 12px;
            display: none;
        }

        /* --- RESPONSIVE --- */
        @media (max-width: 860px) {
            .event-layout { grid-template-columns: 1fr; }
            .event-sidebar { position: static; }
            .event-hero { height: 260px; }
        }
        @media (max-width: 580px) {
            .wrapper { padding: 28px 16px 60px; }
            .event-hero { height: 200px; }
            .ticket-body { flex-direction: column; align-items: flex-start; }
            .qty-select { min-width: 100%; width: 100%; }
        }
    </style>
</head>
<body>

<div id="header-placeholder"></div>

<main class="wrapper">

    <a href="index-event" class="back-link">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
             stroke-linecap="round" stroke-linejoin="round">
            <path d="M19 12H5M12 5l-7 7 7 7"/>
        </svg>
        Back to Events
    </a>

    <!-- Hero image dengan onerror fallback -->
    <img class="event-hero"
         src="<?= htmlspecialchars($e['image_url']) ?>"
         alt="<?= htmlspecialchars($e['title']) ?>"
         onerror="this.style.visibility='hidden'">

    <div class="event-layout">

        <!-- LEFT: Main Content -->
        <div class="event-main">

            <?php if (!empty($e['category'])): ?>
                <span class="category-tag"><?= htmlspecialchars($e['category']) ?></span>
            <?php endif; ?>

            <h1 class="event-title"><?= htmlspecialchars($e['title']) ?></h1>

            <div class="meta-row">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0118 0z"/>
                    <circle cx="12" cy="10" r="3"/>
                </svg>
                <?= htmlspecialchars($e['location'] . ', ' . $e['city']) ?>
            </div>
            <div class="meta-row">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="4" width="18" height="18" rx="2"/>
                    <line x1="16" y1="2" x2="16" y2="6"/>
                    <line x1="8" y1="2" x2="8" y2="6"/>
                    <line x1="3" y1="10" x2="21" y2="10"/>
                </svg>
                <?= htmlspecialchars($dateFormatted) ?>
            </div>

            <hr class="divider">

            <?php if (!empty($e['description'])): ?>
                <div class="section-heading">About the Event</div>
                <p class="event-description"><?= nl2br(htmlspecialchars($e['description'])) ?></p>
                <hr class="divider">
            <?php endif; ?>

            <!-- TICKETS -->
            <?php if ($isUpcoming): ?>
                <div class="section-heading">Tickets</div>

                <?php if (empty($tickets)): ?>
                    <p class="no-ticket-msg">Ticket information not yet available.</p>

                <?php elseif ($allSoldOut): ?>
                    <p class="no-ticket-msg">All tickets are sold out.</p>

                <?php else: ?>
                    <?php foreach ($tickets as $t):
                        $isSoldOut = $t['sold'] >= $t['quota'];
                        $maxQty    = min(10, $t['quota'] - $t['sold']);
                    ?>
                    <div class="ticket-block" data-ticket-block>
                        <div class="ticket-tier-label"><?= htmlspecialchars($t['tier_name']) ?></div>
                        <div class="ticket-body">
                            <div>
                                <div class="price-label">Price</div>
                                <div class="price-val">
                                    Rp <?= number_format($t['price'], 0, ',', '.') ?>
                                    <span class="price-per">/ person</span>
                                </div>
                            </div>
                            <?php if ($isSoldOut): ?>
                                <div class="sold-out-text">Sold Out</div>
                            <?php else: ?>
                                <!-- PENAMBAHAN 'this' PADA ONCHANGE DISINI -->
                                <select class="qty-select"
                                        data-price="<?= (int)$t['price'] ?>"
                                        data-tier="<?= htmlspecialchars($t['tier_name']) ?>"
                                        data-ticket-id="<?= (int)$t['id'] ?>"
                                        onchange="updateTotal(this)">
                                    <option value="0">— Select qty —</option>
                                    <?php for ($q = 1; $q <= $maxQty; $q++): ?>
                                        <option value="<?= $q ?>">
                                            <?= $q ?> ticket<?= $q > 1 ? 's' : '' ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            <?php endif; ?>
                        </div>

                        <?php if (!$isSoldOut && !empty($addons = getTicketAddons($pdo, (int)$t['id']))): ?>
                        <div class="addon-section">
                            <div class="ticket-tier-label" style="border-bottom:none;">Add-ons</div>
                            <?php foreach ($addons as $ad): ?>
                            <div class="ticket-body">
                                <div>
                                    <div class="price-label"><?= htmlspecialchars($ad['name']) ?></div>
                                    <div class="price-val">
                                        Rp <?= number_format($ad['price'], 0, ',', '.') ?>
                                        <span class="price-per">/ unit</span>
                                    </div>
                                </div>
                                <select class="qty-select addon-select"
                                        data-price="<?= (int)$ad['price'] ?>"
                                        data-addon-id="<?= (int)$ad['id'] ?>"
                                        onchange="updateTotal()">
                                    <option value="0">— Select qty —</option>
                                    <?php for ($q = 1; $q <= 10; $q++): ?>
                                        <option value="<?= $q ?>">
                                            <?= $q ?> unit<?= $q > 1 ? 's' : '' ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>

            <?php endif; ?>

        </div>

        <!-- RIGHT: Sidebar -->
        <aside class="event-sidebar">

            <div class="sidebar-card">
                <span class="sidebar-label">Event Details</span>

                <div class="status-pill <?= $isUpcoming ? 'upcoming' : 'past' ?>">
                    <span class="status-dot"></span>
                    <?= $isUpcoming ? 'Upcoming' : 'Event Ended' ?>
                </div>

                <div class="detail-item">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="4" width="18" height="18" rx="2"/>
                        <line x1="16" y1="2" x2="16" y2="6"/>
                        <line x1="8" y1="2" x2="8" y2="6"/>
                        <line x1="3" y1="10" x2="21" y2="10"/>
                    </svg>
                    <div>
                        <div class="di-label">Date</div>
                        <div class="di-value"><?= htmlspecialchars($dateFormatted) ?></div>
                    </div>
                </div>

                <div class="detail-item">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0118 0z"/>
                        <circle cx="12" cy="10" r="3"/>
                    </svg>
                    <div>
                        <div class="di-label">Location</div>
                        <div class="di-value"><?= htmlspecialchars($e['location'] . ', ' . $e['city']) ?></div>
                    </div>
                </div>
            </div>

            <?php if ($isUpcoming && !empty($tickets) && !$allSoldOut): ?>
            <div class="summary-card">
                <div class="summary-fee" id="summary-fee-wrap" style="display:none;">
                    <div class="fee-row"><span>Subtotal</span><span id="summary-subtotal">Rp 0</span></div>
                    <div class="fee-row"><span>Admin fee (<?= rtrim(rtrim(number_format(ADMIN_FEE_RATE * 100, 2), '0'), '.') ?>%)</span><span id="summary-fee">Rp 0</span></div>
                </div>
                <div class="summary-top">
                    <span class="sum-label">Total</span>
                    <span class="sum-total" id="summary-total">Rp 0</span>
                </div>
                <p class="order-notice" id="order-notice">Pilih minimal 1 tiket untuk melanjutkan.</p>
                <button class="btn-order" id="btn-order" disabled onclick="pesanTiket()">
                    Order Ticket
                </button>
            </div>
            <?php endif; ?>

        </aside>

    </div>

</main>

<!-- Hidden checkout form -->
<form id="checkout-form" method="POST" action="payment" style="display:none;">
    <input type="hidden" name="event_id"   value="<?= (int)$e['id'] ?>">
    <input type="hidden" name="ticket_id"  id="cf-ticket-id">
    <input type="hidden" name="qty"        id="cf-qty">
    <input type="hidden" name="total"      id="cf-total">
</form>

<script src="navbar.js"></script>
<script>
function formatRupiah(num) {
    return 'Rp ' + num.toLocaleString('id-ID');
}

// Tampilan saja — server (payment.php) selalu menghitung ulang fee-nya sendiri.
const ADMIN_FEE_RATE = <?= ADMIN_FEE_RATE ?>;
function calcFee(subtotal) {
    return Math.round(subtotal * ADMIN_FEE_RATE);
}

// FUNGSI UPDATE TOTAL YANG SUDAH DIPERBAIKI LOGIKANYA
function updateTotal(changedSelect = null) {
    const selects = document.querySelectorAll('.qty-select:not(.addon-select)');
    let total = 0, hasSelected = false;

    selects.forEach(sel => {
        // Logika untuk mereset opsi tier lain jika ada pilihan baru
        if (changedSelect && sel !== changedSelect && parseInt(changedSelect.value) > 0) {
            sel.value = "0";
        }

        const qty   = parseInt(sel.value)   || 0;
        const price = parseInt(sel.dataset.price) || 0;

        // Add-on hanya aktif untuk tier yang dipilih; sisanya direset
        const block = sel.closest('[data-ticket-block]');
        const addonSection = block.querySelector('.addon-section');
        if (addonSection) addonSection.classList.toggle('open', qty > 0);
        block.querySelectorAll('.addon-select').forEach(ad => {
            if (qty < 1) ad.value = "0";
            else total += (parseInt(ad.value) || 0) * (parseInt(ad.dataset.price) || 0);
        });

        if (qty > 0) {
            total += qty * price;
            hasSelected = true;
        }
    });

    const fee = calcFee(total);
    document.getElementById('summary-subtotal').textContent = formatRupiah(total);
    document.getElementById('summary-fee').textContent      = formatRupiah(fee);
    document.getElementById('summary-fee-wrap').style.display = hasSelected ? 'block' : 'none';
    document.getElementById('summary-total').textContent = formatRupiah(total + fee);

    const btn   = document.getElementById('btn-order');
    const notice = document.getElementById('order-notice');
    btn.disabled         = !hasSelected;
    notice.style.display = hasSelected ? 'none' : 'block';
}

function pesanTiket() {
    const selects = document.querySelectorAll('.qty-select:not(.addon-select)');
    let selectedSel = null;

    selects.forEach(sel => {
        if (parseInt(sel.value) > 0 && !selectedSel) selectedSel = sel;
    });

    if (!selectedSel) {
        document.getElementById('order-notice').style.display = 'block';
        return;
    }

    const qty      = parseInt(selectedSel.value);
    const price    = parseInt(selectedSel.dataset.price);
    const ticketId = selectedSel.dataset.ticketId;
    let total      = qty * price;

    // Kirim add-on dari tier terpilih (harga tetap dihitung ulang di server)
    const form = document.getElementById('checkout-form');
    form.querySelectorAll('.cf-addon').forEach(el => el.remove());
    selectedSel.closest('[data-ticket-block]').querySelectorAll('.addon-select').forEach(ad => {
        const aq = parseInt(ad.value) || 0;
        if (aq < 1) return;
        total += aq * parseInt(ad.dataset.price);
        const inp = document.createElement('input');
        inp.type = 'hidden';
        inp.className = 'cf-addon';
        inp.name = 'addons[' + ad.dataset.addonId + ']';
        inp.value = aq;
        form.appendChild(inp);
    });

    document.getElementById('cf-ticket-id').value = ticketId;
    document.getElementById('cf-qty').value        = qty;
    document.getElementById('cf-total').value      = total + calcFee(total);
    document.getElementById('checkout-form').submit();
}

// Active nav link
document.addEventListener('DOMContentLoaded', function () {
    const currentPath = window.location.pathname.split('/').pop() || 'index.php';
    document.querySelectorAll('nav a').forEach(function (link) {
        const linkPath = link.getAttribute('href').split('/').pop();
        if (linkPath === currentPath) link.classList.add('active-page');
    });
});
</script>
</body>
</html>
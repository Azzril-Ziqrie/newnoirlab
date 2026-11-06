<?php
/**
 * Logika check-in tiket di gerbang (halaman admin/scan-tiket).
 * Query dan bentuk data dipisah dari tampilan supaya halamannya tipis dan fungsinya mudah dites.
 */
require_once __DIR__ . '/addons.php';

const CHECKIN_ACTIONS      = ['lookup', 'search', 'enter', 'undo'];
const CHECKIN_SEARCH_LIMIT = 8;
const CHECKIN_ORDER_SELECT = 'SELECT o.*, e.title AS event_title, t.tier_name
    FROM orders o
    JOIN events  e ON o.event_id  = e.id
    JOIN tickets t ON o.ticket_id = t.id';

/**
 * Event yang sedang discan. Urutan: pilihan eksplisit (?filter_event, 0 = semua event),
 * pilihan terakhir di perangkat ini (cookie), lalu event terdekat. Default ke satu event
 * supaya tiket event lain tidak lolos sebagai "valid" di gerbang.
 */
function resolveScanEventId(array $events, array $request, array $cookies): int
{
    $known = array_map('intval', array_column($events, 'id'));
    if (isset($request['filter_event'])) {
        return (int) $request['filter_event'];
    }
    if (isset($cookies['scan_event']) && in_array((int) $cookies['scan_event'], [0, ...$known], true)) {
        return (int) $cookies['scan_event'];
    }
    return $known[0] ?? 0;
}

/** Kode yang mungkin ada di hasil scan: teks utuh, atau tiap bagian bila QR berformat gabungan dengan '|'. */
function scanCandidates(string $raw): array
{
    $raw = strtoupper(trim($raw));
    if ($raw === '') {
        return [];
    }
    $parts = array_filter(array_map('trim', explode('|', $raw)), fn ($part) => $part !== '');
    return array_values(array_unique([$raw, ...$parts]));
}

function findOrderByScanned(PDO $pdo, string $raw, int $eventId): ?array
{
    $stmt = $pdo->prepare(CHECKIN_ORDER_SELECT . ' WHERE o.order_code = ?' . ($eventId ? ' AND o.event_id = ?' : ''));
    foreach (scanCandidates($raw) as $code) {
        $stmt->execute($eventId ? [$code, $eventId] : [$code]);
        if ($row = $stmt->fetch()) {
            return $row;
        }
    }
    return null;
}

function fetchOrderById(PDO $pdo, int $orderId): ?array
{
    $stmt = $pdo->prepare(CHECKIN_ORDER_SELECT . ' WHERE o.id = ?');
    $stmt->execute([$orderId]);
    return $stmt->fetch() ?: null;
}

/** Cari tamu yang tidak membawa QR: nama, email, nomor HP, atau potongan kode. */
function searchOrders(PDO $pdo, string $query, int $eventId): array
{
    $query = trim($query);
    if (mb_strlen($query) < 2) {
        return [];
    }
    $like   = '%' . addcslashes($query, '%_\\') . '%';
    $params = [$like, $like, $like, $like];
    $sql = CHECKIN_ORDER_SELECT . " WHERE o.status <> 'rejected'
        AND (o.buyer_name LIKE ? OR o.buyer_email LIKE ? OR o.buyer_phone LIKE ? OR o.order_code LIKE ?)";
    if ($eventId) {
        $sql .= ' AND o.event_id = ?';
        $params[] = $eventId;
    }
    $stmt = $pdo->prepare($sql . ' ORDER BY o.buyer_name LIMIT ' . CHECKIN_SEARCH_LIMIT);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Semua order satu event (atau semua event bila $eventId = 0) untuk daftar tamu. */
function fetchCheckinOrders(PDO $pdo, int $eventId): array
{
    $stmt = $pdo->prepare(CHECKIN_ORDER_SELECT . ($eventId ? ' WHERE o.event_id = ?' : '') . ' ORDER BY o.created_at DESC');
    $stmt->execute($eventId ? [$eventId] : []);
    return $stmt->fetchAll();
}

function shortDateTime(?string $value): string
{
    return $value ? date('d M, H:i', strtotime($value)) : '';
}

/** Bentuk data order yang dikirim ke halaman scan. */
function orderPayload(PDO $pdo, array $order): array
{
    $addons = array_map(fn ($a) => $a['name'] . ' ×' . (int) $a['qty'], getOrderAddons($pdo, (int) $order['id']));
    return [
        'id'                 => (int) $order['id'],
        'status'             => $order['status'],
        'code'               => $order['order_code'],
        'name'               => $order['buyer_name'],
        'email'              => $order['buyer_email'],
        'phone'              => $order['buyer_phone'] ?? '',
        'event'              => $order['event_title'],
        'tier'               => $order['tier_name'],
        'qty'                => (int) $order['qty'],
        'addons'             => $addons,
        'used_at'            => $order['used_at'] ? date('d M Y, H:i:s', strtotime($order['used_at'])) : null,
        'used_at_short'      => shortDateTime($order['used_at']),
        'confirmed_at_short' => shortDateTime($order['confirmed_at'] ?? $order['created_at']),
    ];
}

/** Hitungan di gerbang: yang penting jumlah orang, bukan jumlah order. */
function checkinStats(PDO $pdo, int $eventId): array
{
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(CASE WHEN status = 'used'      THEN qty END), 0) AS people_inside,
               COALESCE(SUM(CASE WHEN status = 'confirmed' THEN qty END), 0) AS people_waiting,
               COALESCE(SUM(status = 'used'), 0)    AS orders_entered,
               COALESCE(SUM(status = 'pending'), 0) AS orders_pending
        FROM orders WHERE (? = 0 OR event_id = ?)");
    $stmt->execute([$eventId, $eventId]);
    return array_map('intval', $stmt->fetch());
}

/**
 * Tandai masuk, atau batalkan masuk. UPDATE bersyarat membuat dua petugas yang scan tiket
 * yang sama bersamaan tidak sama-sama berhasil: yang kedua mendapat changed = false.
 */
function changeEntry(PDO $pdo, string $action, int $orderId, int $eventId): array
{
    $sql = $action === 'enter'
        ? "UPDATE orders SET status = 'used', used_at = NOW() WHERE id = ? AND status = 'confirmed'"
        : "UPDATE orders SET status = 'confirmed', used_at = NULL WHERE id = ? AND status = 'used'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$orderId]);

    $order = fetchOrderById($pdo, $orderId);
    return [
        'ok'      => true,
        'changed' => $stmt->rowCount() === 1,
        'order'   => $order ? orderPayload($pdo, $order) : null,
        'stats'   => checkinStats($pdo, $eventId),
    ];
}

/** Satu pintu untuk semua aksi AJAX halaman scan. */
function checkinApi(PDO $pdo, string $action, array $input, int $eventId): array
{
    switch ($action) {
        case 'lookup':
            $order = findOrderByScanned($pdo, (string) ($input['code'] ?? ''), $eventId);
            return ['ok' => true, 'order' => $order ? orderPayload($pdo, $order) : null];
        case 'search':
            $orders = searchOrders($pdo, (string) ($input['q'] ?? ''), $eventId);
            return ['ok' => true, 'orders' => array_map(fn ($o) => orderPayload($pdo, $o), $orders)];
        case 'enter':
        case 'undo':
            return changeEntry($pdo, $action, (int) ($input['order_id'] ?? 0), $eventId);
    }
    return ['ok' => false, 'error' => 'Unknown action.'];
}

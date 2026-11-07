<?php
/**
 * Tier yang dijual lewat WhatsApp saat penjualan online ditutup ($salesClosed di detail-event.php).
 * Kolom tickets.wa_open: 1 = dijual via WhatsApp, 0 = tidak (bawaan). Tier boleh sudah sold out di web.
 * Diatur per tier di dashboard (ticket-manage).
 */
const WA_MAX_QTY = 10;

function ensureWaColumn(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    if (!$pdo->query("SHOW COLUMNS FROM tickets LIKE 'wa_open'")->fetch()) {
        $pdo->exec("ALTER TABLE tickets ADD COLUMN wa_open TINYINT(1) NOT NULL DEFAULT 0");
    }
    $done = true;
}

function waSellableTickets(PDO $pdo, int $eventId): array
{
    ensureWaColumn($pdo);
    $st = $pdo->prepare("SELECT * FROM tickets WHERE event_id = ? AND wa_open = 1 ORDER BY price ASC");
    $st->execute([$eventId]);
    return $st->fetchAll();
}

function toggleWaSale(PDO $pdo, int $ticketId, int $eventId): void
{
    $pdo->prepare("UPDATE tickets SET wa_open = 1 - wa_open WHERE id = ? AND event_id = ?")->execute([$ticketId, $eventId]);
}

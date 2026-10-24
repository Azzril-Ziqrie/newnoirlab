<?php
/**
 * Email pengingat H-1 untuk pembeli tiket.
 *
 * Penerima: order berstatus 'confirmed' pada event 'upcoming' yang mulai besok (atau hari ini,
 * kalau pengingat kemarin terlewat). Satu email per alamat per event, berisi semua kode tiket
 * milik alamat itu. Order yang sudah diingatkan ditandai di orders.reminder_sent_at,
 * jadi menjalankan skrip berkali-kali tidak mengirim ganda.
 *
 * Pengiriman memakai pengaturan SMTP yang sama dengan email tiket (config/config.php).
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';

const REMINDER_DAYS_AHEAD_MAX = 1;
const REMINDER_SMTP_TIMEOUT   = 20;
const REMINDER_PAUSE_MICROS   = 300000;   // jeda antar email, supaya SMTP hosting tidak menolak
const REMINDER_FAQ_URL        = 'https://noirlabcollective.com/faq';
const REMINDER_LOGO_PATH      = __DIR__ . '/../assets/logos/logo.png';

function reminderColumnExists(PDO $pdo): bool
{
    return (bool) $pdo->query("SHOW COLUMNS FROM orders LIKE 'reminder_sent_at'")->fetch();
}

function ensureReminderColumn(PDO $pdo): void
{
    if (!reminderColumnExists($pdo)) {
        $pdo->exec('ALTER TABLE orders ADD COLUMN reminder_sent_at DATETIME NULL');
    }
}

/** Order yang masih perlu diingatkan. Kolom belum ada berarti belum ada yang pernah diingatkan. */
function fetchOrdersToRemind(PDO $pdo, ?int $eventId): array
{
    $notSentYet = reminderColumnExists($pdo) ? 'AND o.reminder_sent_at IS NULL' : '';
    $onlyEvent  = $eventId ? 'AND e.id = :event_id' : '';

    $stmt = $pdo->prepare("
        SELECT o.id, o.order_code, o.buyer_name, o.buyer_email, o.qty, t.tier_name,
               e.id AS event_id, e.title, e.event_date_start, e.location, e.city,
               DATEDIFF(e.event_date_start, CURDATE()) AS days_left
        FROM orders o
        JOIN events  e ON e.id = o.event_id
        JOIN tickets t ON t.id = o.ticket_id
        WHERE o.status = 'confirmed' AND e.status = 'upcoming'
          AND DATEDIFF(e.event_date_start, CURDATE()) BETWEEN 0 AND " . REMINDER_DAYS_AHEAD_MAX . "
          $notSentYet $onlyEvent
        ORDER BY o.buyer_email, o.id
    ");
    $stmt->execute($eventId ? [':event_id' => $eventId] : []);
    return $stmt->fetchAll();
}

/** Satu entri per alamat email per event, dengan daftar order miliknya. Alamat tak valid dibuang. */
function groupOrdersByRecipient(array $orders): array
{
    $groups = [];
    foreach ($orders as $order) {
        $email = strtolower(trim($order['buyer_email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        $key = $email . '|' . $order['event_id'];
        $groups[$key] ??= ['email' => $email, 'name' => $order['buyer_name'], 'event' => $order, 'orders' => []];
        $groups[$key]['orders'][] = $order;
    }
    return array_values($groups);
}

function reminderSubject(array $group): string
{
    $when = (int) $group['event']['days_left'] === 0 ? 'Today' : 'Tomorrow';
    return $when . ': ' . $group['event']['title'];
}

function reminderTicketRows(array $orders): string
{
    $rows = '';
    foreach ($orders as $order) {
        $rows .= '<tr>'
            . '<td style="padding:8px 0;border-top:1px solid #e0e0e0;font-family:\'Courier New\',Courier,monospace;font-size:15px;font-weight:700;letter-spacing:2px;color:#000000;">' . htmlspecialchars($order['order_code']) . '</td>'
            . '<td style="padding:8px 0;border-top:1px solid #e0e0e0;font-size:12px;color:#555555;text-align:right;">' . htmlspecialchars($order['tier_name']) . ' &times; ' . (int) $order['qty'] . '</td>'
            . '</tr>';
    }
    return $rows;
}

function reminderHtml(array $group, bool $hasLogo): string
{
    $event = $group['event'];
    $when  = (int) $event['days_left'] === 0 ? 'today' : 'tomorrow';
    $date  = date('l, F j, Y', strtotime($event['event_date_start']));
    $place = htmlspecialchars($event['location'] . ', ' . $event['city']);
    $logo  = $hasLogo ? 'cid:nllogo' : 'https://noirlabcollective.com/assets/logos/logo.png';

    return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Reminder</title></head>'
        . '<body style="margin:0;padding:0;background:#f5f5f5;font-family:\'Helvetica Neue\',Helvetica,Arial,sans-serif;">'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:48px 16px;"><tr><td align="center">'
        . '<table width="580" cellpadding="0" cellspacing="0" style="background:#ffffff;max-width:100%;border:1px solid #e0e0e0;">'
        . '<tr><td style="padding:32px 40px;border-bottom:1px solid #000000;"><img src="' . $logo . '" alt="NOIRLAB COLLECTIVE" height="56" style="display:block;height:56px;width:auto;"></td></tr>'
        . '<tr><td style="padding:36px 40px 8px;">'
        . '<p style="font-size:15px;font-weight:700;color:#000000;margin:0 0 8px;">Hello, ' . htmlspecialchars($group['name']) . '</p>'
        . '<p style="font-size:13px;line-height:1.7;color:#555555;margin:0;">See you ' . $when . ' at <strong style="color:#000000;">' . htmlspecialchars($event['title']) . '</strong>.</p>'
        . '</td></tr>'
        . '<tr><td style="padding:24px 40px;">'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #000000;"><tr><td style="padding:20px 24px;">'
        . '<div style="font-size:10px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#888888;margin-bottom:4px;">Date</div>'
        . '<div style="font-size:13px;color:#000000;margin-bottom:16px;">' . htmlspecialchars($date) . '</div>'
        . '<div style="font-size:10px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#888888;margin-bottom:4px;">Location</div>'
        . '<div style="font-size:13px;color:#000000;margin-bottom:16px;">' . $place . '</div>'
        . '<div style="font-size:10px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#888888;margin-bottom:6px;">Your ticket code</div>'
        . '<table width="100%" cellpadding="0" cellspacing="0">' . reminderTicketRows($group['orders']) . '</table>'
        . '</td></tr></table></td></tr>'
        . '<tr><td style="padding:0 40px 32px;"><p style="font-size:13px;line-height:1.8;color:#333333;margin:0;">'
        . 'Show your ticket code or the QR code from your ticket email at the entrance. Search your inbox for <strong>Your Ticket</strong> if you need it.<br>'
        . 'Directions and answers to common questions: <a href="' . REMINDER_FAQ_URL . '" style="color:#000000;font-weight:700;">noirlabcollective.com/faq</a>'
        . '</p></td></tr>'
        . '<tr><td style="padding:20px 40px;border-top:1px solid #000000;text-align:center;"><p style="font-size:11px;color:#aaaaaa;margin:0;">&copy; ' . date('Y') . ' Noirlab Collective</p></td></tr>'
        . '</table></td></tr></table></body></html>';
}

function reminderPlainText(array $group): string
{
    $event = $group['event'];
    $codes = implode(', ', array_column($group['orders'], 'order_code'));
    return "Hello, {$group['name']}\n\nSee you " . ((int) $event['days_left'] === 0 ? 'today' : 'tomorrow')
        . " at {$event['title']}.\nDate: " . date('l, F j, Y', strtotime($event['event_date_start']))
        . "\nLocation: {$event['location']}, {$event['city']}\nTicket code: $codes\n\nFAQ: " . REMINDER_FAQ_URL;
}

function buildReminderMail(array $group, string $to): PHPMailer
{
    $hasLogo = is_file(REMINDER_LOGO_PATH);
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = MAIL_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = MAIL_USERNAME;
    $mail->Password   = MAIL_PASSWORD;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = MAIL_PORT;
    $mail->CharSet    = 'UTF-8';
    $mail->Timeout    = REMINDER_SMTP_TIMEOUT;

    $mail->setFrom(MAIL_FROM, MAIL_FROMNAME);
    $mail->Sender = MAIL_FROM;
    $mail->addAddress($to, $group['name']);
    $mail->addReplyTo(MAIL_FROM, MAIL_FROMNAME);
    if ($hasLogo) {
        $mail->addEmbeddedImage(REMINDER_LOGO_PATH, 'nllogo', 'logo.png', 'base64', 'image/png');
    }

    $mail->isHTML(true);
    $mail->Subject = reminderSubject($group);
    $mail->Body    = reminderHtml($group, $hasLogo);
    $mail->AltBody = reminderPlainText($group);
    return $mail;
}

/** Kirim satu pengingat. Mengembalikan null bila berhasil, atau pesan galat. */
function sendReminder(array $group, ?string $overrideTo = null): ?string
{
    try {
        buildReminderMail($group, $overrideTo ?? $group['email'])->send();
        return null;
    } catch (Exception $error) {
        return $error->getMessage();
    }
}

function markOrdersReminded(PDO $pdo, array $orders): void
{
    $ids = array_column($orders, 'id');
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare("UPDATE orders SET reminder_sent_at = NOW() WHERE id IN ($marks)")->execute($ids);
}

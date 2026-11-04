<?php
/**
 * Email pengumuman "tiket group sudah ditutup" untuk pembeli tiket.
 *
 * Satu email per alamat per event, berisi ucapan terima kasih dan rekap semua tiket
 * (tier, jumlah, add-on seperti tenda, total) milik alamat itu. Order yang sudah dikirimi
 * ditandai di orders.group_closed_sent_at, jadi mengirim ulang tidak membuat email ganda.
 * Memakai SMTP, logo, dan pengelompokan penerima yang sama dengan lib/reminders.php.
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/reminders.php';
require_once __DIR__ . '/addons.php';

const ANNOUNCE_WA_DISPLAY = '0895386847552';
const ANNOUNCE_WA_URL     = 'https://wa.me/62895386847552';
const ANNOUNCE_COLUMN = 'group_closed_sent_at';

function ensureAnnounceColumn(PDO $pdo): void
{
    if (!$pdo->query("SHOW COLUMNS FROM orders LIKE '" . ANNOUNCE_COLUMN . "'")->fetch()) {
        $pdo->exec('ALTER TABLE orders ADD COLUMN ' . ANNOUNCE_COLUMN . ' DATETIME NULL');
    }
}

/** Order terkonfirmasi pada satu event; $onlyUnsent=true membuang yang sudah dikirimi. */
function fetchOrdersToAnnounce(PDO $pdo, int $eventId, bool $onlyUnsent): array
{
    ensureAnnounceColumn($pdo);
    $unsent = $onlyUnsent ? 'AND o.' . ANNOUNCE_COLUMN . ' IS NULL' : '';
    $stmt = $pdo->prepare("
        SELECT o.id, o.order_code, o.buyer_name, o.buyer_email, o.qty, o.total_price,
               t.tier_name, e.id AS event_id, e.title, e.event_date_start, e.location, e.city
        FROM orders o
        JOIN events  e ON e.id = o.event_id
        JOIN tickets t ON t.id = o.ticket_id
        WHERE o.status = 'confirmed' AND e.id = ? $unsent
        ORDER BY o.buyer_email, o.id
    ");
    $stmt->execute([$eventId]);
    $orders = $stmt->fetchAll();
    foreach ($orders as &$order) {
        $order['addons'] = getOrderAddons($pdo, (int) $order['id']);
    }
    return $orders;
}

function announceSubject(array $group): string
{
    return 'Thank you: group tickets are now closed (' . $group['event']['title'] . ')';
}

function announceOrderBlock(array $order): string
{
    $label = 'font-size:10px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#888888;margin-bottom:4px;';
    $addons = '';
    foreach ($order['addons'] as $addon) {
        $addons .= htmlspecialchars($addon['name']) . ' &times; ' . (int) $addon['qty'] . '<br>';
    }
    $addonCell = $addons !== '' ? $addons : '<span style="color:#888888;">None</span>';

    return '<table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #000000;margin-bottom:12px;">'
        . '<tr><td style="padding:14px 18px;border-bottom:1px solid #000000;font-family:\'Courier New\',Courier,monospace;font-size:16px;font-weight:700;letter-spacing:3px;color:#000000;">'
        . htmlspecialchars($order['order_code']) . '</td></tr>'
        . '<tr><td style="padding:16px 18px;"><table width="100%" cellpadding="0" cellspacing="0"><tr>'
        . '<td width="50%" style="vertical-align:top;padding-bottom:12px;"><div style="' . $label . '">Ticket</div><div style="font-size:13px;color:#000000;">' . htmlspecialchars($order['tier_name']) . ' &times; ' . (int) $order['qty'] . '</div></td>'
        . '<td width="50%" style="vertical-align:top;padding-bottom:12px;"><div style="' . $label . '">Add-ons</div><div style="font-size:13px;color:#000000;line-height:1.6;">' . $addonCell . '</div></td>'
        . '</tr></table></td></tr>'
        . '<tr><td style="padding:10px 18px;background:#000000;text-align:right;"><span style="font-size:11px;color:#aaaaaa;text-transform:uppercase;letter-spacing:.5px;">Total paid&nbsp;&nbsp;</span>'
        . '<span style="font-size:14px;font-weight:700;color:#ffffff;">Rp ' . number_format((int) $order['total_price'], 0, ',', '.') . '</span></td></tr>'
        . '</table>';
}

function announceHtml(array $group, bool $hasLogo): string
{
    $event = $group['event'];
    $date  = date('l, F j, Y', strtotime($event['event_date_start']));
    $logo  = $hasLogo ? 'cid:nllogo' : 'https://noirlabcollective.com/assets/logos/logo.png';
    $blocks = implode('', array_map('announceOrderBlock', $group['orders']));

    return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Group tickets closed</title></head>'
        . '<body style="margin:0;padding:0;background:#f5f5f5;font-family:\'Helvetica Neue\',Helvetica,Arial,sans-serif;">'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:32px 12px;"><tr><td align="center">'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#ffffff;max-width:580px;border:1px solid #e0e0e0;">'
        . '<tr><td style="padding:32px 28px;border-bottom:1px solid #000000;"><table width="100%" cellpadding="0" cellspacing="0"><tr>'
        . '<td><img src="' . $logo . '" alt="NOIRLAB COLLECTIVE" height="56" style="display:block;height:56px;width:auto;"></td>'
        . '<td style="text-align:right;font-size:11px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#000000;">Announcement</td>'
        . '</tr></table></td></tr>'
        . '<tr><td style="padding:36px 28px 8px;">'
        . '<p style="font-size:15px;font-weight:700;color:#000000;margin:0 0 12px;">Hello, ' . htmlspecialchars($group['name']) . '</p>'
        . '<p style="font-size:13px;line-height:1.8;color:#333333;margin:0 0 12px;">Thank you for being part of <strong style="color:#000000;">' . htmlspecialchars($event['title']) . '</strong>. '
        . 'We are grateful for your support, and <strong style="color:#000000;">group ticket sales are now closed</strong>.</p>'
        . '<p style="font-size:13px;line-height:1.8;color:#333333;margin:0;">Below is the ticket data you have booked with us. Please check that it is correct.</p>'
        . '</td></tr>'
        . '<tr><td style="padding:24px 28px 8px;">'
        . '<div style="font-size:10px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#888888;margin-bottom:12px;">Your tickets &middot; ' . count($group['orders']) . ' order(s)</div>'
        . $blocks . '</td></tr>'
        . '<tr><td style="padding:8px 28px 28px;"><table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #000000;border-top:3px solid #000000;"><tr><td style="padding:18px 22px;font-size:13px;line-height:1.9;color:#333333;">'
        . '<strong style="color:#000000;">' . htmlspecialchars($date) . '</strong><br>' . htmlspecialchars($event['location'] . ', ' . $event['city']) . '<br>'
        . 'Your ticket email with the QR code stays valid. Search your inbox for <strong>Your Ticket</strong>.<br>'
        . 'Is something wrong with your ticket data? Chat us on WhatsApp <a href="' . ANNOUNCE_WA_URL . '" style="color:#000000;font-weight:700;">' . ANNOUNCE_WA_DISPLAY . '</a> with your ticket code and we will confirm and fix it.'
        . '</td></tr></table></td></tr>'
        . '<tr><td style="padding:0 28px 32px;text-align:center;"><p style="font-size:12px;color:#888888;margin:0;">More info: <a href="' . REMINDER_FAQ_URL . '" style="color:#000000;font-weight:700;text-decoration:none;">noirlabcollective.com/faq</a></p></td></tr>'
        . '<tr><td style="padding:20px 28px;border-top:1px solid #000000;text-align:center;"><p style="font-size:11px;color:#aaaaaa;margin:0;">&copy; ' . date('Y') . ' Noirlab Collective</p></td></tr>'
        . '</table></td></tr></table></body></html>';
}

function announcePlainText(array $group): string
{
    $lines = ["Hello, {$group['name']}", '',
        "Thank you for being part of {$group['event']['title']}. Group ticket sales are now closed.",
        'Here is the ticket data you have booked:', ''];
    foreach ($group['orders'] as $order) {
        $lines[] = $order['order_code'] . ' - ' . $order['tier_name'] . ' x' . (int) $order['qty'];
        foreach ($order['addons'] as $addon) {
            $lines[] = '  Add-on: ' . $addon['name'] . ' x' . (int) $addon['qty'];
        }
        $lines[] = '  Total paid: Rp ' . number_format((int) $order['total_price'], 0, ',', '.');
        $lines[] = '';
    }
    $lines[] = 'Is something wrong with your ticket data? Chat us on WhatsApp ' . ANNOUNCE_WA_DISPLAY . ' (' . ANNOUNCE_WA_URL . ') with your ticket code.';
    return implode("\n", $lines);
}

function buildAnnounceMail(array $group, string $to): PHPMailer
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
    $mail->Subject = announceSubject($group);
    $mail->Body    = announceHtml($group, $hasLogo);
    $mail->AltBody = announcePlainText($group);
    return $mail;
}

/** Kirim satu pengumuman. Mengembalikan null bila berhasil, atau pesan galat. */
function sendAnnouncement(array $group, ?string $overrideTo = null): ?string
{
    try {
        buildAnnounceMail($group, $overrideTo ?? $group['email'])->send();
        return null;
    } catch (Exception $error) {
        return $error->getMessage();
    }
}

function markOrdersAnnounced(PDO $pdo, array $orders): void
{
    $ids = array_column($orders, 'id');
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare('UPDATE orders SET ' . ANNOUNCE_COLUMN . " = NOW() WHERE id IN ($marks)")->execute($ids);
}

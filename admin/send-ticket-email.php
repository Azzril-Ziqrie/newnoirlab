<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once dirname(__DIR__) . '/lib/PHPMailer/Exception.php';
require_once dirname(__DIR__) . '/lib/PHPMailer/PHPMailer.php';
require_once dirname(__DIR__) . '/lib/PHPMailer/SMTP.php';
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/lib/addons.php';

function sendTicketEmail($pdo, $orderId) {
    $stmt = $pdo->prepare("
        SELECT o.*,
               e.title        AS event_title,
               e.event_date_start,
               e.location,
               e.city,
               e.image_url    AS event_image,
               t.tier_name
        FROM orders o
        JOIN events  e ON o.event_id  = e.id
        JOIN tickets t ON o.ticket_id = t.id
        WHERE o.id = ?
    ");
    $stmt->execute([$orderId]);
    $o = $stmt->fetch();

    if (!$o) return ['success' => false, 'error' => 'Order not found.'];

    // Format event date in English
    $ts       = strtotime($o['event_date_start']);
    $tglEvent = date('l, F j, Y', $ts);

    // QR code URL
    $qrData = urlencode('NOIRLAB|' . $o['order_code'] . '|' . $o['event_title'] . '|' . $o['buyer_name']);
    $qrUrl  = "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=$qrData";

    $addons     = getOrderAddons($pdo, (int)$o['id']);
    $addonsText = '';
    foreach ($addons as $ad) {
        $addonsText .= "Add-on: " . $ad['name'] . " x" . (int)$ad['qty'] . "\n";
    }

    // Total format
    $total ='Rp ' . number_format($o['total_price'], 0, ',', '.');

    $html = emailTemplate([
        'order_code'  => $o['order_code'],
        'buyer_name'  => $o['buyer_name'],
        'event_title' => $o['event_title'],
        'tgl_event'   => $tglEvent,
        'location'    => $o['location'] . ', ' . $o['city'],
        'tier_name'   => $o['tier_name'],
        'qty'         => $o['qty'],
        'addons'      => $addons,
        'total'       => $total,
    ]);

    // QR + logo di-embed langsung ke email (CID). Klien email (Gmail/Outlook/iCloud)
    // sering memblokir gambar remote, dan kalau api.qrserver.com down QR hilang.
    $qrPng = fetchRemoteImage($qrUrl);
    $logoPath = dirname(__DIR__) . '/assets/logos/logo.png';

    $subject = 'Your Ticket — ' . $o['event_title'] . ' [' . $o['order_code'] . ']';
    $altBody = "Your ticket for " . $o['event_title'] . " has been confirmed.\n\nTicket Code: " . $o['order_code'] . "\nDate: $tglEvent\nLocation: " . $o['location'] . ", " . $o['city'] . "\nTier: " . $o['tier_name'] . "\nQuantity: " . $o['qty'] . " ticket(s)\n" . $addonsText . "\nPresent this code at the entrance.";

    $lastError = 'Unknown error';

    // Max 2 percobaan: SMTP shared hosting sering time-out / throttle sesaat.
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host        = MAIL_HOST;
            $mail->SMTPAuth    = true;
            $mail->Username    = MAIL_USERNAME;
            $mail->Password    = MAIL_PASSWORD;
            $mail->SMTPSecure  = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port        = MAIL_PORT;
            $mail->CharSet     = 'UTF-8';
            $mail->Timeout     = 20;

            $mail->setFrom(MAIL_FROM, MAIL_FROMNAME);
            $mail->Sender = MAIL_FROM; // envelope sender sama dengan From (SPF alignment)
            $mail->addAddress($o['buyer_email'], $o['buyer_name']);
            $mail->addReplyTo(MAIL_FROM, MAIL_FROMNAME);

            $hasQr   = $qrPng !== null;
            $hasLogo = is_file($logoPath);
            if ($hasQr)   $mail->addStringEmbeddedImage($qrPng, 'ticketqr', 'qr-' . $o['order_code'] . '.png', 'base64', 'image/png');
            if ($hasLogo) $mail->addEmbeddedImage($logoPath, 'nllogo', 'logo.png', 'base64', 'image/png');

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = str_replace(
                ['{{QR_SRC}}', '{{LOGO_SRC}}'],
                [$hasQr ? 'cid:ticketqr' : $qrUrl, $hasLogo ? 'cid:nllogo' : 'https://noirlabcollective.com/assets/logos/logo.png'],
                $html
            );
            $mail->AltBody = $altBody;

            $mail->send();
            recordEmailResult($pdo, (int)$o['id'], true, null);
            return ['success' => true];

        } catch (Exception $e) {
            $lastError = $mail->ErrorInfo ?: $e->getMessage();
            if ($attempt < 2) sleep(2);
        }
    }

    recordEmailResult($pdo, (int)$o['id'], false, $lastError);
    return ['success' => false, 'error' => $lastError];
}

function fetchRemoteImage(string $url): ?string {
    $ctx  = stream_context_create(['http' => ['timeout' => 6], 'ssl' => ['verify_peer' => true]]);
    $data = @file_get_contents($url, false, $ctx);
    return ($data !== false && strncmp($data, "\x89PNG", 4) === 0) ? $data : null;
}

// Catat hasil kirim supaya admin bisa lihat order mana yang emailnya gagal.
// Kolom dibuat otomatis kalau belum ada; kegagalan di sini tidak boleh mengganggu pengiriman.
function recordEmailResult($pdo, int $orderId, bool $ok, ?string $error): void {
    static $ready = false;
    try {
        if (!$ready) {
            $have = $pdo->query("SHOW COLUMNS FROM orders LIKE 'email_sent_at'")->fetch();
            if (!$have) {
                $pdo->exec("ALTER TABLE orders ADD COLUMN email_sent_at DATETIME NULL, ADD COLUMN email_error VARCHAR(500) NULL");
            }
            $ready = true;
        }
        if ($ok) {
            $pdo->prepare("UPDATE orders SET email_sent_at = NOW(), email_error = NULL WHERE id = ?")->execute([$orderId]);
        } else {
            $pdo->prepare("UPDATE orders SET email_error = ? WHERE id = ?")->execute([mb_substr((string)$error, 0, 500), $orderId]);
        }
    } catch (Throwable $e) {
        // diabaikan
    }
}

// ===== EMAIL TEMPLATE =====
function emailTemplate($d) {
    // Baris add-on (mis. tenda) — hanya muncul kalau order punya add-on.
    $addonsRow = '';
    if (!empty($d['addons'])) {
        $lines = '';
        foreach ($d['addons'] as $ad) {
            $lines .= htmlspecialchars($ad['name']) . ' &times; ' . (int)$ad['qty'] . '<br>';
        }
        $addonsRow = '
                <tr>
                  <td colspan="2" style="padding-top:14px;vertical-align:top;">
                    <div style="font-size:10px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#888888;margin-bottom:4px;">
                      Add-ons
                    </div>
                    <div style="font-size:13px;font-weight:500;color:#000000;line-height:1.6;">
                      ' . $lines . '
                    </div>
                  </td>
                </tr>';
    }


    return '
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Noirlab Ticket</title>
</head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:\'Helvetica Neue\',Helvetica,Arial,sans-serif;-webkit-font-smoothing:antialiased;">

<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:48px 16px;">
<tr><td align="center">

  <!-- OUTER CARD -->
  <table width="580" cellpadding="0" cellspacing="0" style="background:#ffffff;max-width:100%;border:1px solid #e0e0e0;">

    <!-- HEADER -->
    <tr>
      <td style="padding:36px 48px;border-bottom:1px solid #000000;">
        <table width="100%" cellpadding="0" cellspacing="0">
          <tr>
            <td>
              <img src="{{LOGO_SRC}}" alt="NOIRLAB COLLECTIVE" height="72"
                   style="display:block;height:72px;width:auto;font-size:18px;font-weight:700;color:#000000;">
            </td>
            <td style="text-align:right;">
              <div style="font-size:11px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#000000;">
                Official Ticket
              </div>
            </td>
          </tr>
        </table>
      </td>
    </tr>

    <!-- GREETING -->
    <tr>
      <td style="padding:40px 48px 0;">
        <p style="font-size:15px;color:#000000;margin:0 0 6px;font-weight:700;">
          Hello, ' . htmlspecialchars($d['buyer_name']) . '
        </p>
        <p style="font-size:13px;color:#555555;margin:0;line-height:1.6;">
          Your payment has been confirmed. Here is your ticket for the event below.
        </p>
      </td>
    </tr>

    <!-- TICKET BLOCK -->
    <tr>
      <td style="padding:32px 48px;">

        <!-- EVENT SECTION -->
        <table width="100%" cellpadding="0" cellspacing="0"
               style="border:1px solid #000000;margin-bottom:0;">

          <!-- Event Title Row -->
          <tr>
            <td style="padding:24px 28px;border-bottom:1px solid #000000;">
              <div style="font-size:10px;font-weight:700;letter-spacing:1.8px;text-transform:uppercase;color:#888888;margin-bottom:10px;">
                Event
              </div>
              <div style="font-size:22px;font-weight:700;color:#000000;line-height:1.2;margin-bottom:20px;">
                ' . htmlspecialchars($d['event_title']) . '
              </div>

              <!-- 4-column details grid -->
              <table width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td style="width:50%;padding-bottom:14px;vertical-align:top;">
                    <div style="font-size:10px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#888888;margin-bottom:4px;">
                      Date
                    </div>
                    <div style="font-size:13px;font-weight:500;color:#000000;">
                      ' . htmlspecialchars($d['tgl_event']) . '
                    </div>
                  </td>
                  <td style="width:50%;padding-bottom:14px;vertical-align:top;">
                    <div style="font-size:10px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#888888;margin-bottom:4px;">
                      Location
                    </div>
                    <div style="font-size:13px;font-weight:500;color:#000000;">
                      ' . htmlspecialchars($d['location']) . '
                    </div>
                  </td>
                </tr>
                <tr>
                  <td style="vertical-align:top;">
                    <div style="font-size:10px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#888888;margin-bottom:4px;">
                      Tier
                    </div>
                    <div style="font-size:13px;font-weight:500;color:#000000;">
                      ' . htmlspecialchars($d['tier_name']) . '
                    </div>
                  </td>
                  <td style="vertical-align:top;">
                    <div style="font-size:10px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#888888;margin-bottom:4px;">
                      Quantity
                    </div>
                    <div style="font-size:13px;font-weight:500;color:#000000;">
                      ' . $d['qty'] . ' ticket(s)
                    </div>
                  </td>
                </tr>
                ' . $addonsRow . '
              </table>
            </td>
          </tr>

          <!-- DASHED SEPARATOR (ticket tear line) -->
          <tr>
            <td style="padding:0;position:relative;">
              <table width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td width="24" style="background:#f5f5f5;">&nbsp;</td>
                  <td style="border-top:2px dashed #000000;">&nbsp;</td>
                  <td width="24" style="background:#f5f5f5;">&nbsp;</td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- QR + CODE -->
          <tr>
            <td style="padding:28px;text-align:center;background:#ffffff;">
              <div style="font-size:10px;font-weight:700;letter-spacing:1.8px;text-transform:uppercase;color:#888888;margin-bottom:18px;">
                Present at Entrance
              </div>
              <img src="{{QR_SRC}}" alt="QR Code" width="150" height="150"
                   style="display:block;margin:0 auto 20px;border:1px solid #000000;">
              <div style="font-family:\'Courier New\',Courier,monospace;font-size:26px;font-weight:700;letter-spacing:5px;color:#000000;background:#f5f5f5;display:inline-block;padding:12px 28px;border:1px solid #000000;">
                ' . htmlspecialchars($d['order_code']) . '
              </div>
            </td>
          </tr>

          <!-- TOTAL ROW -->
          <tr>
            <td style="padding:16px 28px;background:#000000;text-align:right;">
              <span style="font-size:12px;color:#aaaaaa;letter-spacing:0.5px;text-transform:uppercase;">
                Total Paid&nbsp;&nbsp;
              </span>
              <span style="font-size:17px;font-weight:700;color:#ffffff;">
                ' . $d['total'] . '
              </span>
            </td>
          </tr>

        </table>

      </td>
    </tr>

    <!-- IMPORTANT NOTES -->
    <tr>
      <td style="padding:0 48px 40px;">
        <table width="100%" cellpadding="0" cellspacing="0"
               style="border:1px solid #000000;border-top:3px solid #000000;">
          <tr>
            <td style="padding:20px 24px;">
              <div style="font-size:10px;font-weight:700;letter-spacing:1.8px;text-transform:uppercase;color:#000000;margin-bottom:12px;">
                Important Notes
              </div>
              <div style="font-size:13px;color:#333333;line-height:1.9;">
                Keep this email as proof of purchase.<br>
                Show your QR code or ticket code <strong>' . htmlspecialchars($d['order_code']) . '</strong> at the entrance.<br>
                This ticket is valid for <strong>' . $d['qty'] . ' person(s)</strong>.<br>
                This ticket is non-transferable.
              </div>
            </td>
          </tr>
        </table>
      </td>
    </tr>

    <!-- CONTACT -->
    <tr>
      <td style="padding:0 48px 40px;text-align:center;">
        <p style="font-size:12px;color:#888888;margin:0;line-height:1.8;">
          Questions? Reach us at
          <a href="mailto:' . MAIL_FROM . '" style="color:#000000;font-weight:700;text-decoration:none;">' . MAIL_FROM . '</a>
        </p>
      </td>
    </tr>

    <!-- FOOTER -->
    <tr>
      <td style="padding:20px 48px;border-top:1px solid #000000;text-align:center;">
        <p style="font-size:11px;color:#aaaaaa;margin:0;letter-spacing:0.3px;">
          &copy; ' . date('Y') . ' Noirlab Collective &nbsp;&middot;&nbsp; This is an automated email, please do not reply directly.
        </p>
      </td>
    </tr>

  </table>

</td></tr>
</table>
</body>
</html>';
}
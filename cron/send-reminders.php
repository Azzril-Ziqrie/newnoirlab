<?php
/**
 * Kirim email pengingat H-1 ke pembeli tiket. Hanya bisa dijalankan dari command line.
 *
 *   php cron/send-reminders.php                  daftar penerima saja (tidak mengirim apa pun)
 *   php cron/send-reminders.php --send           kirim sungguhan
 *   php cron/send-reminders.php --send --event=18 --limit=100
 *   php cron/send-reminders.php --test-to=saya@contoh.com   kirim satu contoh ke alamat itu, tanpa menandai order
 *
 * Jadwalkan sekali sehari lewat cron (cPanel) atau Task Scheduler (Windows), misalnya pukul 09.00:
 *   0 9 * * *  php /home/USER/public_html/cron/send-reminders.php --send
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/reminders.php';

const DEFAULT_BATCH_LIMIT = 200;

function readOptions(array $argv): array
{
    $options = ['send' => false, 'event' => null, 'limit' => DEFAULT_BATCH_LIMIT, 'test-to' => null];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--send') {
            $options['send'] = true;
        } elseif (preg_match('/^--(event|limit)=(\d+)$/', $arg, $match)) {
            $options[$match[1]] = (int) $match[2];
        } elseif (preg_match('/^--test-to=(.+)$/', $arg, $match)) {
            $options['test-to'] = $match[1];
        }
    }
    return $options;
}

function describe(array $group): string
{
    $codes = implode(', ', array_column($group['orders'], 'order_code'));
    return sprintf('%s | %s | %s', $group['email'], $group['event']['title'], $codes);
}

function runTest(array $groups, string $to): int
{
    if (!$groups) {
        echo "Tidak ada penerima hari ini, jadi tidak ada contoh yang bisa dikirim.\n";
        return 0;
    }
    $error = sendReminder($groups[0], $to);
    echo $error === null ? "Contoh terkirim ke $to.\n" : "Gagal: $error\n";
    return $error === null ? 0 : 1;
}

function runBatch(PDO $pdo, array $groups): int
{
    ensureReminderColumn($pdo);
    $sent = 0;
    $failed = 0;
    foreach ($groups as $group) {
        $error = sendReminder($group);
        if ($error === null) {
            markOrdersReminded($pdo, $group['orders']);
            $sent++;
            echo 'OK    ' . describe($group) . "\n";
        } else {
            $failed++;
            echo 'GAGAL ' . describe($group) . " ($error)\n";
        }
        usleep(REMINDER_PAUSE_MICROS);
    }
    echo "Selesai: $sent terkirim, $failed gagal. Yang gagal dicoba lagi di jalan berikutnya.\n";
    return $failed ? 1 : 0;
}

$options = readOptions($argv);
$pdo     = getDBConnection();
$groups  = groupOrdersByRecipient(fetchOrdersToRemind($pdo, $options['event']));
$total   = count($groups);
$groups  = array_slice($groups, 0, $options['limit']);

echo "Penerima pengingat: $total alamat" . ($total > count($groups) ? ', diproses ' . count($groups) . " (batas {$options['limit']})" : '') . "\n";

if ($options['test-to'] !== null) {
    exit(runTest($groups, $options['test-to']));
}

if (!$options['send']) {
    foreach ($groups as $group) {
        echo '  ' . describe($group) . "\n";
    }
    echo "Mode lihat saja. Tambahkan --send untuk mengirim.\n";
    exit(0);
}

exit(runBatch($pdo, $groups));

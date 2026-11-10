<?php
/**
 * Siapkan database otomatis saat sistem dibuka, jadi tidak perlu impor lewat phpMyAdmin.
 * - Membuat database kalau belum ada.
 * - Kalau tabel dasar (events) belum ada, membuat semua tabel dari noih6912_noirlab.sql.
 *   Hanya struktur tabel dan akun admin yang dimuat; data pesanan/event tidak ikut.
 * Tabel tambahan (merch, promo, tenda, addon) dibuat sendiri oleh lib masing-masing.
 * Aman dipanggil berulang: kalau semuanya sudah ada, hanya satu query ringan per proses.
 */
function ensureDatabase(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $server = new PDO(
            'mysql:host=' . DB_HOST . ';charset=' . DB_CHARSET,
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $name = str_replace('`', '', DB_NAME);
        $server->exec('CREATE DATABASE IF NOT EXISTS `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        $server->exec('USE `' . $name . '`');

        $exists = $server->query("SHOW TABLES LIKE 'events'")->fetchColumn();
        if ($exists) {
            return;
        }
        importBaseSchema($server);
    } catch (Throwable $error) {
        // Tanpa izin CREATE (misal hosting): biarkan, halaman akan memakai database yang sudah ada.
        error_log('ensureDatabase: ' . $error->getMessage());
    }
}

function importBaseSchema(PDO $pdo): void
{
    $file = __DIR__ . '/../noih6912_noirlab.sql';
    if (!is_readable($file)) {
        return;
    }
    $sql = file_get_contents($file);
    // Buang komentar baris, lalu pisah per statement di titik koma akhir baris.
    $sql = preg_replace('/^--.*$/m', '', $sql);
    $statements = preg_split('/;\s*\n/', $sql);

    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if ($statement === '') {
            continue;
        }
        $isStructure = preg_match('/^(CREATE TABLE|ALTER TABLE|CREATE INDEX)/i', $statement);
        $isAdmins    = preg_match('/^INSERT INTO `admins`/i', $statement);
        if ($isStructure || $isAdmins) {
            $pdo->exec($statement);
        }
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}

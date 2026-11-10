<?php
/**
 * Role admin. Kolom admins.role: 'admin' = akses penuh (bawaan), 'scanner' = hanya halaman scan tiket.
 * Role disimpan di sesi saat login ($_SESSION['admin_role']); sesi lama tanpa role dianggap 'admin'.
 */

const ADMIN_ROLE_FULL    = 'admin';
const ADMIN_ROLE_SCANNER = 'scanner';

function ensureAdminRoleColumn(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    if (!$pdo->query("SHOW COLUMNS FROM admins LIKE 'role'")->fetch()) {
        $pdo->exec("ALTER TABLE admins ADD COLUMN role VARCHAR(10) NOT NULL DEFAULT 'admin'");
    }
    $done = true;
}

function adminRole(): string
{
    return ($_SESSION['admin_role'] ?? ADMIN_ROLE_FULL) === ADMIN_ROLE_SCANNER ? ADMIN_ROLE_SCANNER : ADMIN_ROLE_FULL;
}

function adminIsScannerOnly(): bool
{
    return adminRole() === ADMIN_ROLE_SCANNER;
}

<?php
/**
 * Mulai session dengan cookie yang aman.
 * SameSite=Strict membuat aksi admin (termasuk hapus lewat link) tidak bisa dipicu dari situs lain.
 */
function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Strict',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

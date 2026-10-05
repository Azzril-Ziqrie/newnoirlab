<?php
/**
 * Biaya admin: persen dari subtotal (tiket + add-on), dibulatkan ke rupiah.
 * Dipakai detail-event.php (tampilan) dan payment.php (angka yang ditagih).
 */
const ADMIN_FEE_RATE = 0.02;

function calcAdminFee(int $subtotal): int
{
    return (int) round($subtotal * ADMIN_FEE_RATE);
}

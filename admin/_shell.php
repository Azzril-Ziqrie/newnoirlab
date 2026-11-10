<?php
/**
 * Kerangka admin bersama: sidebar, topbar mobile, dan overlay.
 * Dipakai semua halaman admin supaya menu, urutan, dan bahasanya seragam.
 * Pakai: taruh adminShellStyles() di <head> (paling akhir) dan adminShell() tepat setelah <body>.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/admin-role.php';

const ADMIN_NAV_ALIASES = ['order-edit' => 'orders', 'ticket-manage' => 'dashboard', 'event-edit' => 'dashboard', 'event-delete' => 'dashboard'];

function adminNavItems(): array
{
    $items = [
        'Overview' => [['./', 'Dashboard', 'dashboard']],
        'Events'   => [['event-add', 'Add Event', 'event-add']],
        'Sales'    => [['orders', 'Orders', 'orders'], ['ots-buy', 'On-the-spot', 'ots-buy'],
                       ['resend-email', 'Resend Email', 'resend-email'], ['announce', 'Announcement', 'announce']],
        'Store'    => [['merch', 'Merch', 'merch'], ['merch-orders', 'Merch Orders', 'merch-orders']],
        'Door'     => [['scan-tiket', 'Scan Ticket', 'scan-tiket'], ['tent-plot', 'Tent Plot', 'tent-plot']],
        'Reports'  => [['report-event', 'Event Report', 'report-event'], ['gear-recap', 'Equipment Recap', 'gear-recap']],
        'Settings' => [['manage-admin', 'Manage Admins', 'manage-admin']],
    ];
    if (adminIsScannerOnly()) {
        return ['Door' => [['scan-tiket', 'Scan Ticket', 'scan-tiket']]];
    }
    // Halaman yang hanya ada di komputer lokal tidak dimunculkan di server.
    if (is_file(__DIR__ . '/bonafest-input.php')) {
        $items['Reports'][] = ['bonafest-input', 'Input BONAFEST', 'bonafest-input'];
    }
    return $items;
}

function adminPendingOrders(): int
{
    try {
        return (int) getDBConnection()->query("SELECT COUNT(*) FROM orders WHERE status='pending'")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function adminShellStyles(): void
{
    echo '<link rel="stylesheet" href="../assets/css/admin-shell.css?v=' . @filemtime(__DIR__ . '/../assets/css/admin-shell.css') . '">' . "\n";
}

function adminShell(): void
{
    $page    = basename($_SERVER['PHP_SELF'], '.php');
    $page    = $page === 'index' ? 'dashboard' : (ADMIN_NAV_ALIASES[$page] ?? $page);
    $pending = adminPendingOrders();
    $name    = htmlspecialchars($_SESSION['admin_name'] ?? 'Admin');

    echo '<header class="mobile-header">'
        . '<img src="../assets/logos/logo.png" alt="Noirlab" class="mobile-logo">'
        . '<button class="menu-toggle" type="button" aria-label="Open menu" aria-expanded="false" aria-controls="sidebar"><span></span><span></span></button>'
        . '</header><div class="sidebar-overlay"></div>'
        . '<nav class="sidebar" id="sidebar" aria-label="Admin"><div class="sidebar-logo"><img src="../assets/logos/logo.png" alt="Noirlab"></div><div class="sidebar-menu">';

    foreach (adminNavItems() as $group => $links) {
        echo '<div class="nav-group">' . htmlspecialchars($group) . '</div>';
        foreach ($links as [$href, $label, $key]) {
            $current = $key === $page;
            echo '<a href="' . $href . '"' . ($current ? ' class="active" aria-current="page"' : '') . '>' . htmlspecialchars($label);
            if ($key === 'orders' && $pending > 0) echo '<span class="notif-badge">' . $pending . '</span>';
            echo '</a>';
        }
    }

    echo '</div><div class="sidebar-footer"><a href="../index-event" target="_blank" rel="noopener">View site ↗</a>'
        . '<a href="./?logout=1">Log out · ' . $name . '</a></div></nav>';
    echo '<script src="../assets/js/admin-shell.js?v=' . @filemtime(__DIR__ . '/../assets/js/admin-shell.js') . '" defer></script>';
}

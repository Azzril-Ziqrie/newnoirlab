<?php
/**
 * Data ringkasan penjualan untuk dashboard admin.
 *
 * Penjualan dihitung dari order yang sudah dibayar: 'confirmed' (dikonfirmasi) dan 'used'
 * (tiketnya sudah dipindai di pintu). 'pending' dan 'rejected' tidak dihitung.
 * Hanya event yang masih berjalan (status 'upcoming'); event 'past' tidak ikut.
 * Tanggal penjualan = tanggal order dibuat, mengikuti zona waktu database.
 */

const PAID_STATUSES_SQL   = "'confirmed','used'";
const ACTIVE_SALES_FROM   = 'orders o JOIN events e ON e.id = o.event_id';
const ACTIVE_SALES_WHERE  = "o.status IN ('confirmed','used') AND e.status = 'upcoming'";
const SALES_RANGE_DEFAULT = 30;
const SALES_RANGE_ALL     = 0;
const SALES_RANGE_OPTIONS = [7 => '7 hari', 30 => '30 hari', 90 => '90 hari', SALES_RANGE_ALL => 'Semua'];
const SALES_TOP_EVENTS    = 6;

function parseSalesRange(?string $value): int
{
    $days = $value === null ? SALES_RANGE_DEFAULT : (int) $value;
    return array_key_exists($days, SALES_RANGE_OPTIONS) ? $days : SALES_RANGE_DEFAULT;
}

function databaseToday(PDO $pdo): DateTimeImmutable
{
    return new DateTimeImmutable((string) $pdo->query('SELECT CURDATE()')->fetchColumn());
}

function firstPaidSaleDay(PDO $pdo): ?DateTimeImmutable
{
    $first = $pdo->query('SELECT DATE(MIN(o.created_at)) FROM ' . ACTIVE_SALES_FROM . ' WHERE ' . ACTIVE_SALES_WHERE)->fetchColumn();
    return $first ? new DateTimeImmutable($first) : null;
}

/** Hari pertama periode. Untuk "Semua": hari penjualan pertama. */
function salesPeriodStart(PDO $pdo, DateTimeImmutable $today, int $range): DateTimeImmutable
{
    if ($range === SALES_RANGE_ALL) {
        return firstPaidSaleDay($pdo) ?? $today;
    }
    return $today->modify('-' . ($range - 1) . ' days');
}

function fetchDailySales(PDO $pdo, DateTimeImmutable $start, DateTimeImmutable $today): array
{
    $stmt = $pdo->prepare('
        SELECT DATE(o.created_at) AS day, SUM(o.total_price) AS revenue, SUM(o.qty) AS tickets, COUNT(*) AS orders
        FROM ' . ACTIVE_SALES_FROM . '
        WHERE ' . ACTIVE_SALES_WHERE . ' AND DATE(o.created_at) BETWEEN ? AND ?
        GROUP BY day
    ');
    $stmt->execute([$start->format('Y-m-d'), $today->format('Y-m-d')]);
    return array_column($stmt->fetchAll(), null, 'day');
}

/** Satu entri per hari di periode, hari tanpa penjualan bernilai 0. */
function fillEmptyDays(array $salesByDay, DateTimeImmutable $start, DateTimeImmutable $today): array
{
    $days = [];
    for ($day = $start; $day <= $today; $day = $day->modify('+1 day')) {
        $key  = $day->format('Y-m-d');
        $sale = $salesByDay[$key] ?? null;
        $days[] = [
            'day'     => $key,
            'revenue' => (int) ($sale['revenue'] ?? 0),
            'tickets' => (int) ($sale['tickets'] ?? 0),
            'orders'  => (int) ($sale['orders'] ?? 0),
        ];
    }
    return $days;
}

function sumSales(array $days): array
{
    return [
        'revenue' => array_sum(array_column($days, 'revenue')),
        'tickets' => array_sum(array_column($days, 'tickets')),
        'orders'  => array_sum(array_column($days, 'orders')),
    ];
}

/** Pendapatan periode sebelumnya yang panjangnya sama, untuk pembanding. Null bila tidak berlaku. */
function previousPeriodRevenue(PDO $pdo, DateTimeImmutable $start, int $length): ?int
{
    $previousStart = $start->modify("-{$length} days");
    $previousEnd   = $start->modify('-1 day');
    $stmt = $pdo->prepare('
        SELECT COALESCE(SUM(o.total_price), 0) FROM ' . ACTIVE_SALES_FROM . '
        WHERE ' . ACTIVE_SALES_WHERE . ' AND DATE(o.created_at) BETWEEN ? AND ?
    ');
    $stmt->execute([$previousStart->format('Y-m-d'), $previousEnd->format('Y-m-d')]);
    return (int) $stmt->fetchColumn();
}

function fetchSalesByEvent(PDO $pdo, DateTimeImmutable $start, DateTimeImmutable $today): array
{
    $stmt = $pdo->prepare('
        SELECT e.title, SUM(o.total_price) AS revenue, SUM(o.qty) AS tickets
        FROM ' . ACTIVE_SALES_FROM . '
        WHERE ' . ACTIVE_SALES_WHERE . ' AND DATE(o.created_at) BETWEEN ? AND ?
        GROUP BY e.id, e.title
        ORDER BY revenue DESC
        LIMIT ' . SALES_TOP_EVENTS . '
    ');
    $stmt->execute([$start->format('Y-m-d'), $today->format('Y-m-d')]);
    return array_map(fn ($row) => [
        'title'   => $row['title'],
        'revenue' => (int) $row['revenue'],
        'tickets' => (int) $row['tickets'],
    ], $stmt->fetchAll());
}

/** Persen perubahan terhadap periode sebelumnya, null bila pembandingnya nol atau tidak ada. */
function percentChange(int $current, ?int $previous): ?float
{
    return $previous ? round(($current - $previous) / $previous * 100, 1) : null;
}

function buildSalesOverview(PDO $pdo, int $range): array
{
    $today  = databaseToday($pdo);
    $start  = salesPeriodStart($pdo, $today, $range);
    $days   = fillEmptyDays(fetchDailySales($pdo, $start, $today), $start, $today);
    $totals = sumSales($days);

    $previous = $range === SALES_RANGE_ALL ? null : previousPeriodRevenue($pdo, $start, count($days));

    return [
        'range'      => $range,
        'days'       => $days,
        'totals'     => $totals,
        'average'    => $totals['orders'] ? intdiv($totals['revenue'], $totals['orders']) : 0,
        'change'     => percentChange($totals['revenue'], $previous),
        'byEvent'    => fetchSalesByEvent($pdo, $start, $today),
    ];
}

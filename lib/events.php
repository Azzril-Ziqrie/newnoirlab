<?php
require_once __DIR__ . '/../config/config.php';

/**
 * Ambil event dari database. Mengembalikan array kosong kalau database tidak bisa dihubungi,
 * supaya halaman publik tetap tampil.
 *
 * @param string $status 'upcoming', 'past', atau 'all'
 */
function fetchEvents(string $status = 'all', int $limit = 100): array
{
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $where = $status === 'all' ? '' : 'WHERE status = :status';
        $order = $status === 'past' ? 'DESC' : 'ASC';
        $stmt  = $pdo->prepare("SELECT * FROM events $where ORDER BY event_date_start $order LIMIT :limit");
        if ($status !== 'all') {
            $stmt->bindValue(':status', $status);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (Throwable $error) {
        return [];
    }
}

function connectDatabase(): PDO
{
    return new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

/** Satu event beserta tiketnya. Null kalau tidak ada atau database tidak bisa dihubungi. */
function fetchEventWithTickets(int $id): ?array
{
    try {
        $pdo = connectDatabase();
        $stmt = $pdo->prepare('SELECT * FROM events WHERE id = ?');
        $stmt->execute([$id]);
        $event = $stmt->fetch();
        if (!$event) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT * FROM tickets WHERE event_id = ? ORDER BY price ASC');
        $stmt->execute([$id]);
        return ['event' => $event, 'tickets' => $stmt->fetchAll()];
    } catch (Throwable $error) {
        return null;
    }
}

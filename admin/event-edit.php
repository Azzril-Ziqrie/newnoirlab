<?php
require_once 'auth.php';
require_once '../config/config.php';
$pdo = getDBConnection();

$id = (int) ($_GET['id'] ?? 0);
if (!$id) { header("Location: ./"); exit; }

$stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
$stmt->execute([$id]);
$ev = $stmt->fetch();
if (!$ev) { header("Location: ./"); exit; }

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title     = trim($_POST['title']);
    $desc      = trim($_POST['description']);
    $location  = trim($_POST['location']);
    $city      = trim($_POST['city']);
    $category  = trim($_POST['category']);
    $status    = $_POST['status'];
    $dateStart = $_POST['event_date_start'];
    $dateEnd   = $_POST['event_date_end'] ?: null;
    $ticketUrl = trim($_POST['ticket_url']);
    $imageUrl  = trim($_POST['image_url']);

    if (!$title || !$location || !$city || !$dateStart) {
        $error = 'Field bertanda * wajib diisi.';
    } else {
        $pdo->prepare("UPDATE events SET title=?, description=?, location=?, city=?, category=?, status=?, event_date_start=?, event_date_end=?, ticket_url=?, image_url=? WHERE id=?")
            ->execute([$title, $desc, $location, $city, $category, $status, $dateStart, $dateEnd, $ticketUrl, $imageUrl, $id]);
        header("Location: ./?updated=1");
        exit;
    }
    // Pakai POST value kalau ada error
    $ev = array_merge($ev, $_POST);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Event - Admin Noirlab</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', sans-serif; background: #f3f4f6; color: #111827; display: flex; min-height: 100vh; }
        .sidebar { width: 220px; flex-shrink: 0; background: #1f2937; color: #fff; display: flex; flex-direction: column; padding: 28px 0; position: fixed; top: 0; left: 0; height: 100vh; }
        .sidebar-logo { font-size: 17px; font-weight: 700; padding: 0 24px 28px; border-bottom: 1px solid rgba(255,255,255,0.08); }
        .sidebar-menu { padding: 20px 12px; flex: 1; }
        .sidebar-menu a { display: flex; align-items: center; gap: 10px; padding: 10px 14px; border-radius: 10px; color: #9ca3af; text-decoration: none; font-size: 14px; font-weight: 500; margin-bottom: 4px; transition: background .2s, color .2s; }
        .sidebar-menu a:hover { background: rgba(255,255,255,0.08); color: #fff; }
        .sidebar-footer { padding: 16px 24px; border-top: 1px solid rgba(255,255,255,0.08); }
        .sidebar-footer a { font-size: 13px; color: #6b7280; text-decoration: none; }

        .main { margin-left: 220px; flex: 1; padding: 36px 32px; }
        .page-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 28px; }
        .page-header h1 { font-size: 20px; font-weight: 600; }

        .alert-error { padding: 12px 18px; border-radius: 10px; font-size: 14px; margin-bottom: 20px; background: #fef2f2; color: #dc2626; }

        .form-card { background: #fff; border-radius: 16px; padding: 28px; box-shadow: 0 2px 12px rgba(0,0,0,0.05); }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 24px; }
        .form-group { margin-bottom: 18px; }
        .form-group.full { grid-column: 1 / -1; }
        label { font-size: 13px; font-weight: 600; color: #374151; display: block; margin-bottom: 6px; }
        input, select, textarea { width: 100%; padding: 10px 13px; border: 1px solid #e5e7eb; border-radius: 10px; font-size: 14px; font-family: inherit; transition: border-color .2s; }
        input:focus, select:focus, textarea:focus { outline: none; border-color: #9ca3af; }
        small { font-size: 12px; color: #9ca3af; display: block; margin-top: 4px; }
        .form-actions { display: flex; justify-content: flex-end; gap: 12px; margin-top: 24px; padding-top: 20px; border-top: 1px solid #f3f4f6; }
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 10px 20px; border-radius: 8px; font-size: 14px; font-weight: 600; text-decoration: none; border: none; cursor: pointer; font-family: inherit; transition: opacity .2s; }
        .btn:hover { opacity: 0.85; }
        .btn-primary { background: #1f2937; color: #fff; }
        .btn-ghost { background: #f3f4f6; color: #374151; }

        @media (max-width: 860px) { .form-grid { grid-template-columns: 1fr; } .sidebar { display: none; } .main { margin-left: 0; } }
    </style>
</head>
<body>

<nav class="sidebar">
    <div class="sidebar-logo">⬛ Noirlab Admin</div>
    <div class="sidebar-menu">
        <a href="./">🗂 Dashboard</a>
        <a href="event-add">➕ Tambah Event</a>
        <a href="../index-event" target="_blank">🔗 Lihat Halaman</a>
    </div>
    <div class="sidebar-footer">
        <a href="./?logout=1">⏏ Logout</a>
    </div>
</nav>

<div class="main">
    <div class="page-header">
        <h1>Edit Event</h1>
        <div style="display:flex;gap:10px">
            <a href="ticket-manage?event_id=<?= $id ?>" class="btn btn-ghost">🎫 Kelola Tiket</a>
            <a href="./" class="btn btn-ghost">← Kembali</a>
        </div>
    </div>

    <?php if ($error): ?><div class="alert-error"><?= $error ?></div><?php endif; ?>

    <div class="form-card">
        <form method="POST">
            <div class="form-grid">
                <div class="form-group full">
                    <label>Judul Event *</label>
                    <input type="text" name="title" required value="<?= htmlspecialchars($ev['title']) ?>">
                </div>
                <div class="form-group full">
                    <label>Deskripsi</label>
                    <textarea name="description" rows="5"><?= htmlspecialchars($ev['description'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label>Venue / Lokasi *</label>
                    <input type="text" name="location" required value="<?= htmlspecialchars($ev['location']) ?>">
                </div>
                <div class="form-group">
                    <label>Kota *</label>
                    <input type="text" name="city" required value="<?= htmlspecialchars($ev['city']) ?>">
                </div>
                <div class="form-group">
                    <label>Tanggal Mulai *</label>
                    <input type="date" name="event_date_start" required value="<?= $ev['event_date_start'] ?>">
                </div>
                <div class="form-group">
                    <label>Tanggal Selesai</label>
                    <input type="date" name="event_date_end" value="<?= $ev['event_date_end'] ?? '' ?>">
                </div>
                <div class="form-group">
                    <label>Kategori</label>
                    <input type="text" name="category" value="<?= htmlspecialchars($ev['category'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="upcoming" <?= $ev['status'] === 'upcoming' ? 'selected' : '' ?>>Upcoming</option>
                        <option value="past"     <?= $ev['status'] === 'past'     ? 'selected' : '' ?>>Past</option>
                    </select>
                </div>
                <div class="form-group full">
                    <label>URL Gambar</label>
                    <input type="text" name="image_url" value="<?= htmlspecialchars($ev['image_url'] ?? '') ?>">
                </div>
                <div class="form-group full">
                    <label>Link Tiket Eksternal</label>
                    <input type="url" name="ticket_url" value="<?= htmlspecialchars($ev['ticket_url'] ?? '') ?>">
                </div>
            </div>

            <div class="form-actions">
                <a href="./" class="btn btn-ghost">Batal</a>
                <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>
</body>
</html>
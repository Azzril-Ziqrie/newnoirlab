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
        body { background: #fff; display: flex; min-height: 100vh; }
        .main { flex: 1; padding: 50px 48px; max-width: 980px; }
        .page-header { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 32px; padding-bottom: 28px; border-bottom: 1px solid #e5e5e5; }
        .page-header h1 { font-size: 1.75rem; font-weight: 700; }
        .alert-error { padding: 13px 18px; border: 1px solid #e5e5e5; border-left: 3px solid #b00020; color: #900; font-size: .875rem; margin-bottom: 24px; }
        .form-card { border: 1px solid #e5e5e5; padding: 28px; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 24px; }
        .form-group { margin-bottom: 20px; }
        .form-group.full { grid-column: 1 / -1; }
        label { display: block; margin-bottom: 7px; font-size: .68rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: rgba(0,0,0,.45); }
        small { display: block; margin-top: 5px; font-size: .75rem; color: rgba(0,0,0,.4); }
        .form-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 24px; padding-top: 22px; border-top: 1px solid #e5e5e5; }
        @media (max-width: 860px) { .main { padding: 24px 16px; } .form-grid { grid-template-columns: 1fr; } .form-card { padding: 20px 16px; } .page-header h1 { font-size: 1.4rem; } }
    </style>
<?php require_once __DIR__ . '/_shell.php'; adminShellStyles(); ?>
</head>
<body>

<?php require_once __DIR__ . '/_shell.php'; adminShell(); ?>

<div class="main">
    <div class="page-header">
        <h1>Edit Event</h1>
        <div style="display:flex;gap:10px">
            <a href="ticket-manage?event_id=<?= $id ?>" class="btn btn-ghost">Manage Tickets</a>
            <a href="./" class="btn btn-ghost">Back</a>
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
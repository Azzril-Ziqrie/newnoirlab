<?php
/**
 * Merch: produk, foto, dan varian. Diatur di admin/merch, tampil di merch dan merch-detail.
 *
 * merch_products : satu baris = satu produk. compare_price = harga coret (opsional, harus lebih besar dari price).
 *                  status 'active' tampil di situs, 'hidden' disembunyikan. stock dipakai bila produk tanpa varian.
 * merch_images   : foto produk. Foto dengan sort_order terkecil jadi sampul.
 * merch_variants : pilihan (ukuran/warna). Bila ada varian, stok dihitung dari varian; price kosong = ikut harga produk.
 *
 * Pesanan diteruskan lewat WhatsApp (pola yang sama dengan tiket saat web ditutup); stok dikurangi manual oleh admin.
 */
require_once __DIR__ . '/../config/config.php';

const MERCH_WA_NUMBER       = '6281380259349';
const MERCH_UPLOAD_DIR      = __DIR__ . '/../assets/merch/';
const MERCH_UPLOAD_URL      = 'assets/merch/';
const MERCH_MAX_IMAGE_BYTES = 5 * 1024 * 1024;
const MERCH_MAX_IMAGES      = 12;
const MERCH_MAX_VARIANTS    = 30;
const MERCH_PAY_BANK        = 'BCA';
const MERCH_PAY_ACCOUNT     = '4062092738';
const MERCH_PAY_NAME        = 'Muchlas yulianto';
const MERCH_MAX_QTY         = 10;
const MERCH_MAX_PRICE       = 100000000;
const MERCH_IMAGE_TYPES     = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];

/** Kesalahan yang aman ditampilkan ke admin (bukan bug server). */
class MerchException extends RuntimeException {}

function ensureMerchTables(PDO $pdo): void
{
    static $done = false;
    if ($done) return;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS merch_products (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            name          VARCHAR(120) NOT NULL,
            description   TEXT NULL,
            price         INT NOT NULL DEFAULT 0,
            compare_price INT NULL,
            stock         INT NOT NULL DEFAULT 0,
            status        VARCHAR(10) NOT NULL DEFAULT 'active',
            sort_order    INT NOT NULL DEFAULT 0,
            created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS merch_images (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            path       VARCHAR(255) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            KEY idx_product (product_id)
        ) DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS merch_variants (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            name       VARCHAR(60) NOT NULL,
            stock      INT NOT NULL DEFAULT 0,
            price      INT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            KEY idx_product (product_id)
        ) DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS merch_orders (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            code         VARCHAR(16) NOT NULL,
            product_id   INT NOT NULL,
            product_name VARCHAR(120) NOT NULL,
            variant_name VARCHAR(60) NULL,
            qty          INT NOT NULL,
            unit_price   INT NOT NULL,
            total        INT NOT NULL,
            buyer_name   VARCHAR(120) NOT NULL,
            address      TEXT NOT NULL,
            phone        VARCHAR(30) NOT NULL,
            contact      VARCHAR(160) NOT NULL,
            status       VARCHAR(12) NOT NULL DEFAULT 'pending',
            created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_code (code),
            KEY idx_status (status)
        ) DEFAULT CHARSET=utf8mb4
    ");
    // Periode pre order (opsional): kolom ditambahkan otomatis pada database lama.
    foreach (['po_start', 'po_end'] as $col) {
        if (!$pdo->query("SHOW COLUMNS FROM merch_products LIKE '$col'")->fetch()) {
            $pdo->exec("ALTER TABLE merch_products ADD COLUMN $col DATE NULL");
        }
    }
    merchSeedIfEmpty($pdo);
    $done = true;
}

/** Produk awal (Rasamala dan Tie-Dye), diisi otomatis kalau tabel produk masih kosong. */
function merchSeedIfEmpty(PDO $pdo): void
{
    if ((int)$pdo->query('SELECT COUNT(*) FROM merch_products')->fetchColumn() > 0) {
        return;
    }
    $about = "Artwork by @mahdialbart";
    $products = [
        ['Rasamala T-Shirt', 190000, 1,
         "Rooted in the spirit of Rasamala.\n\nThe Rasamala T-Shirt carries an exclusive artwork by Mahdi Albart, inspired by the tree that becomes the central idea of Bona Fest 2026. A piece to remember the stories, encounters, and moments shared in the forest.\n\nAvailable in black, sizes S, M, L, and XL.\n\n$about",
         ['merch_rasamala_depan', 'merch_rasamala_belakang', 'merch_bona_foto_3', 'merch_bona_foto_1']],
        ['Bona Tie-Dye T-Shirt', 220000, 2,
         "A piece of Bona Fest 2026 to take home.\n\nBona Tie-Dye T-Shirt features the official Bona Fest 2026 artwork by Mahdi Albart, finished with a tie-dye treatment that makes each piece part of the festival's visual identity.\n\nAvailable in sizes S, M, L, and XL.\n\n$about",
         ['merch_tiedye_depan', 'merch_tiedye_belakang', 'merch_bona_foto_2', 'merch_bona_foto_1']],
    ];
    $insProduct = $pdo->prepare("INSERT INTO merch_products (name, description, price, stock, status, sort_order) VALUES (?, ?, ?, 0, 'active', ?)");
    $insImage   = $pdo->prepare('INSERT INTO merch_images (product_id, path, sort_order) VALUES (?, ?, ?)');
    $insVariant = $pdo->prepare('INSERT INTO merch_variants (product_id, name, stock, sort_order) VALUES (?, ?, 100, ?)');
    foreach ($products as [$name, $price, $order, $description, $images]) {
        $insProduct->execute([$name, $description, $price, $order]);
        $id = (int)$pdo->lastInsertId();
        foreach ($images as $i => $file) {
            $insImage->execute([$id, MERCH_UPLOAD_URL . $file . '.webp', $i]);
        }
        foreach (['S', 'M', 'L', 'XL'] as $i => $size) {
            $insVariant->execute([$id, $size, $i]);
        }
    }
}

/** Status periode PO: 'none' (tanpa PO), 'soon', 'open', atau 'closed'. */
function merchPoState(array $product, ?string $today = null): string
{
    $start = $product['po_start'] ?? null;
    $end   = $product['po_end'] ?? null;
    if (!$start && !$end) return 'none';
    $today ??= date('Y-m-d');
    if ($start && $today < $start) return 'soon';
    if ($end && $today > $end) return 'closed';
    return 'open';
}

/** "9 November 2026" */
function merchDateId(string $ymd): string
{
    $m = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    [$y, $mo, $d] = array_map('intval', explode('-', $ymd));
    return "$d {$m[$mo]} $y";
}

function merchRupiah(int $amount): string
{
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

/** Persen diskon dari harga coret, atau 0 bila tidak ada. */
function merchDiscountPercent(int $price, ?int $compare): int
{
    return ($compare && $compare > $price && $price >= 0) ? (int) round(($compare - $price) / $compare * 100) : 0;
}

/** Stok total: dari varian bila ada, kalau tidak dari kolom stock produk. */
function merchStock(array $product): int
{
    if (!empty($product['variants'])) {
        return array_sum(array_map(fn($v) => max(0, (int) $v['stock']), $product['variants']));
    }
    return max(0, (int) $product['stock']);
}

/** [harga terendah, harga tertinggi] dengan memperhitungkan harga khusus tiap varian. */
function merchPriceRange(array $product): array
{
    $variants = $product['variants'] ?? [];
    if (!$variants) return [(int) $product['price'], (int) $product['price']];

    // Varian tanpa harga khusus ikut harga produk. Varian yang habis tidak ikut dihitung, kecuali semuanya habis.
    $inStock = array_filter($variants, fn($v) => $v['stock'] > 0);
    $prices  = array_map(fn($v) => $v['price'] ?? (int) $product['price'], $inStock ?: $variants);
    return [min($prices), max($prices)];
}

/** Lampirkan foto dan varian ke daftar produk dengan dua query (tanpa N+1). */
function merchAttach(PDO $pdo, array $products): array
{
    if (!$products) return [];
    $ids = array_map(fn($p) => (int) $p['id'], $products);
    $in  = implode(',', $ids);

    $images = $variants = [];
    foreach ($pdo->query("SELECT id, product_id, path, sort_order FROM merch_images WHERE product_id IN ($in) ORDER BY sort_order, id") as $row) {
        $images[(int) $row['product_id']][] = ['id' => (int) $row['id'], 'path' => $row['path']];
    }
    foreach ($pdo->query("SELECT id, product_id, name, stock, price FROM merch_variants WHERE product_id IN ($in) ORDER BY sort_order, id") as $row) {
        $variants[(int) $row['product_id']][] = [
            'id' => (int) $row['id'], 'name' => $row['name'], 'stock' => (int) $row['stock'],
            'price' => $row['price'] === null ? null : (int) $row['price'],
        ];
    }

    return array_map(function ($p) use ($images, $variants) {
        $id = (int) $p['id'];
        $p['id']            = $id;
        $p['price']         = (int) $p['price'];
        $p['compare_price'] = $p['compare_price'] === null ? null : (int) $p['compare_price'];
        $p['stock']         = (int) $p['stock'];
        $p['images']        = $images[$id] ?? [];
        $p['variants']      = $variants[$id] ?? [];
        return $p;
    }, $products);
}

function merchList(PDO $pdo, bool $onlyVisible): array
{
    ensureMerchTables($pdo);
    $where = $onlyVisible ? "WHERE status = 'active'" : '';
    return merchAttach($pdo, $pdo->query("SELECT * FROM merch_products $where ORDER BY sort_order ASC, id DESC")->fetchAll());
}

function merchGet(PDO $pdo, int $id, bool $onlyVisible = false): ?array
{
    ensureMerchTables($pdo);
    $st = $pdo->prepare('SELECT * FROM merch_products WHERE id = ?' . ($onlyVisible ? " AND status = 'active'" : ''));
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ? merchAttach($pdo, [$row])[0] : null;
}

/** Angka dari form: kosong = null, selain itu bilangan bulat tak negatif. */
function merchInt($value, int $max = MERCH_MAX_PRICE): ?int
{
    $value = trim((string) $value);
    if ($value === '') return null;
    $digits = preg_replace('/[^\d]/', '', $value);   // "Rp 54.370" -> 54370
    return $digits === '' ? null : min((int) $digits, $max);
}

/** Baris varian dari form (name[], stock[], price[]) yang namanya terisi, dengan batas jumlah. */
function merchParseVariants(array $in): array
{
    $names  = (array) ($in['variant_name']  ?? []);
    $stocks = (array) ($in['variant_stock'] ?? []);
    $prices = (array) ($in['variant_price'] ?? []);
    $rows = [];
    foreach ($names as $i => $name) {
        $name = mb_substr(trim((string) $name), 0, 60);
        if ($name === '') continue;
        $rows[] = ['name' => $name, 'stock' => merchInt($stocks[$i] ?? 0, 100000) ?? 0, 'price' => merchInt($prices[$i] ?? '')];
    }
    if (count($rows) > MERCH_MAX_VARIANTS) {
        throw new MerchException('Varian maksimal ' . MERCH_MAX_VARIANTS . '.');
    }
    return $rows;
}

/** Simpan produk baru (id null) atau perbarui. Mengembalikan id produk. */
function merchSave(PDO $pdo, ?int $id, array $in): int
{
    ensureMerchTables($pdo);

    $name  = mb_substr(trim((string) ($in['name'] ?? '')), 0, 120);
    $price = merchInt($in['price'] ?? '');
    if ($name === '')   throw new MerchException('Nama produk wajib diisi.');
    if ($price === null) throw new MerchException('Harga wajib diisi.');

    $compare = merchInt($in['compare_price'] ?? '');
    if ($compare !== null && $compare <= $price) $compare = null;   // harga coret hanya masuk akal bila lebih tinggi

    $po = [];
    foreach (['po_start', 'po_end'] as $k) {
        $v = trim((string) ($in[$k] ?? ''));
        $po[$k] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false ? $v : null;
    }
    if ($po['po_start'] && $po['po_end'] && $po['po_end'] < $po['po_start']) {
        throw new MerchException('Tanggal akhir PO tidak boleh sebelum tanggal mulai.');
    }

    $fields = [
        $name,
        trim((string) ($in['description'] ?? '')),
        $price,
        $compare,
        merchInt($in['stock'] ?? 0, 100000) ?? 0,
        ($in['status'] ?? '') === 'hidden' ? 'hidden' : 'active',
        merchInt($in['sort_order'] ?? 0, 9999) ?? 0,
        $po['po_start'],
        $po['po_end'],
    ];
    $variants = merchParseVariants($in);

    $pdo->beginTransaction();
    try {
        if ($id) {
            if (!merchGet($pdo, $id)) throw new MerchException('Produk tidak ditemukan.');
            $pdo->prepare('UPDATE merch_products SET name=?, description=?, price=?, compare_price=?, stock=?, status=?, sort_order=?, po_start=?, po_end=? WHERE id=?')
                ->execute([...$fields, $id]);
            $pdo->prepare('DELETE FROM merch_variants WHERE product_id = ?')->execute([$id]);
        } else {
            $pdo->prepare('INSERT INTO merch_products (name, description, price, compare_price, stock, status, sort_order, po_start, po_end) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute($fields);
            $id = (int) $pdo->lastInsertId();
        }
        $ins = $pdo->prepare('INSERT INTO merch_variants (product_id, name, stock, price, sort_order) VALUES (?,?,?,?,?)');
        foreach ($variants as $i => $v) {
            $ins->execute([$id, $v['name'], $v['stock'], $v['price'], $i]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $id;
}

/** $_FILES['images'] (multi-upload) jadi daftar berkas yang benar-benar diunggah. */
function merchUploadedFiles(array $files): array
{
    $out = [];
    foreach ((array) ($files['name'] ?? []) as $i => $name) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        $out[] = ['name' => (string) $name, 'tmp' => $files['tmp_name'][$i], 'size' => (int) $files['size'][$i], 'error' => (int) $files['error'][$i]];
    }
    return $out;
}

/** Simpan foto unggahan. Mengembalikan daftar pesan untuk berkas yang ditolak (yang lain tetap tersimpan). */
function merchAddImages(PDO $pdo, int $productId, array $files): array
{
    $problems = [];
    $count = $pdo->prepare('SELECT COUNT(*), COALESCE(MAX(sort_order), -1) FROM merch_images WHERE product_id = ?');
    $count->execute([$productId]);
    [$have, $maxOrder] = array_map('intval', $count->fetch(PDO::FETCH_NUM));

    if (!is_dir(MERCH_UPLOAD_DIR)) @mkdir(MERCH_UPLOAD_DIR, 0755, true);
    $ins = $pdo->prepare('INSERT INTO merch_images (product_id, path, sort_order) VALUES (?,?,?)');

    foreach (merchUploadedFiles($files) as $f) {
        $label = $f['name'];
        $ext   = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if ($have >= MERCH_MAX_IMAGES)                  { $problems[] = "$label: maksimal " . MERCH_MAX_IMAGES . ' foto per produk.'; continue; }
        if ($f['error'] !== UPLOAD_ERR_OK)              { $problems[] = "$label: gagal diunggah."; continue; }
        if ($f['size'] > MERCH_MAX_IMAGE_BYTES)         { $problems[] = "$label: ukuran maksimal 5MB."; continue; }
        if (!isset(MERCH_IMAGE_TYPES[$ext]))            { $problems[] = "$label: gunakan JPG, PNG, WEBP, atau GIF."; continue; }
        $info = @getimagesize($f['tmp']);
        if (!$info || !in_array($info['mime'], MERCH_IMAGE_TYPES, true)) { $problems[] = "$label: bukan gambar yang valid."; continue; }

        $filename = 'merch_' . bin2hex(random_bytes(7)) . '.' . $ext;
        if (!move_uploaded_file($f['tmp'], MERCH_UPLOAD_DIR . $filename)) { $problems[] = "$label: gagal disimpan (cek izin folder assets/merch/)."; continue; }
        $ins->execute([$productId, MERCH_UPLOAD_URL . $filename, ++$maxOrder]);
        $have++;
    }
    return $problems;
}

/** Hapus berkas foto hanya bila memang di folder merch (jangan pernah menghapus path sembarangan dari database). */
function merchUnlinkImage(string $path): void
{
    if (strpos($path, MERCH_UPLOAD_URL) !== 0) return;
    $file = MERCH_UPLOAD_DIR . basename($path);
    if (is_file($file)) @unlink($file);
}

function merchDeleteImage(PDO $pdo, int $productId, int $imageId): void
{
    $st = $pdo->prepare('SELECT path FROM merch_images WHERE id = ? AND product_id = ?');
    $st->execute([$imageId, $productId]);
    $path = $st->fetchColumn();
    if ($path === false) return;
    $pdo->prepare('DELETE FROM merch_images WHERE id = ?')->execute([$imageId]);
    merchUnlinkImage((string) $path);
}

/** Jadikan foto ini sampul: urutannya dipindah ke paling depan. */
function merchSetCover(PDO $pdo, int $productId, int $imageId): void
{
    $pdo->prepare('UPDATE merch_images SET sort_order = (SELECT m FROM (SELECT COALESCE(MIN(sort_order), 0) - 1 AS m FROM merch_images WHERE product_id = ?) t)
                   WHERE id = ? AND product_id = ?')->execute([$productId, $imageId, $productId]);
}

function merchSetStatus(PDO $pdo, int $id, string $status): void
{
    $pdo->prepare('UPDATE merch_products SET status = ? WHERE id = ?')->execute([$status === 'hidden' ? 'hidden' : 'active', $id]);
}

function merchDelete(PDO $pdo, int $id): void
{
    $product = merchGet($pdo, $id);
    if (!$product) return;
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM merch_images WHERE product_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM merch_variants WHERE product_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM merch_products WHERE id = ?')->execute([$id]);
    $pdo->commit();
    foreach ($product['images'] as $img) merchUnlinkImage($img['path']);
}

/** Tautan WhatsApp berisi pesanan yang sudah tersusun. */
function merchWaUrl(string $text): string
{
    return 'https://wa.me/' . MERCH_WA_NUMBER . '?text=' . rawurlencode($text);
}


const MERCH_ORDER_STATUSES = ['pending' => 'Menunggu bayar', 'paid' => 'Sudah bayar', 'shipped' => 'Dikirim', 'cancelled' => 'Batal'];

/**
 * Simpan pesanan dari form web. Harga, stok, dan periode PO selalu dihitung ulang dari database
 * (data dari browser hanya dipakai untuk memilih produk, varian, dan jumlah).
 */
function merchCreateOrder(PDO $pdo, array $in): array
{
    ensureMerchTables($pdo);

    $product = merchGet($pdo, (int) ($in['product_id'] ?? 0), true);
    if (!$product) throw new MerchException('Produk tidak ditemukan.');
    if (!in_array(merchPoState($product), ['none', 'open'], true)) throw new MerchException('Periode pre order tidak sedang dibuka.');

    $variant = null;
    if ($product['variants']) {
        foreach ($product['variants'] as $v) if ($v['id'] === (int) ($in['variant_id'] ?? 0)) $variant = $v;
        if (!$variant) throw new MerchException('Pilih varian dulu.');
    }
    $stock = $variant ? $variant['stock'] : $product['stock'];
    $qty   = (int) ($in['qty'] ?? 0);
    if ($qty < 1 || $qty > MERCH_MAX_QTY) throw new MerchException('Jumlah tidak valid.');
    if ($qty > $stock) throw new MerchException($stock > 0 ? "Stok tersisa $stock." : 'Stok habis.');

    $clean = fn($k, $max) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($in[$k] ?? ''))), 0, $max);
    $name    = $clean('name', 120);
    $phone   = $clean('phone', 30);
    $contact = $clean('contact', 160);
    $address = mb_substr(trim((string) ($in['address'] ?? '')), 0, 500);
    if ($name === '' || $address === '' || $phone === '' || $contact === '') throw new MerchException('Lengkapi semua data pemesan.');
    if (strlen(preg_replace('/\D/', '', $phone)) < 8) throw new MerchException('Nomor telepon tidak valid.');

    $unit  = $variant && $variant['price'] !== null ? $variant['price'] : $product['price'];
    $total = $unit * $qty;

    for ($try = 0; $try < 5; $try++) {
        $code = 'MR-' . strtoupper(bin2hex(random_bytes(3)));
        try {
            $pdo->prepare('INSERT INTO merch_orders (code, product_id, product_name, variant_name, qty, unit_price, total, buyer_name, address, phone, contact) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$code, $product['id'], $product['name'], $variant['name'] ?? null, $qty, $unit, $total, $name, $address, $phone, $contact]);
            return ['code' => $code, 'total' => $total, 'item' => $product['name'], 'variant' => $variant['name'] ?? null, 'qty' => $qty,
                    'name' => $name, 'address' => $address, 'phone' => $phone, 'contact' => $contact];
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') throw $e;   // kode bentrok: coba lagi
        }
    }
    throw new RuntimeException('Gagal membuat kode pesanan.');
}

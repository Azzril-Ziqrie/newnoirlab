<?php
require_once 'auth.php';
require_once '../config/config.php';
$pdo = getDBConnection();

$error    = '';
$uploadDir = '../assets/events/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

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
    $imageUrl  = '';

    if (!empty($_FILES['image_file']['name'])) {
        $file    = $_FILES['image_file'];
        $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','gif','webp'];
        $maxSize = 5 * 1024 * 1024;

        if (!in_array($ext, $allowed)) {
            $error = 'Format gambar tidak didukung. Gunakan JPG, PNG, GIF, atau WEBP.';
        } elseif ($file['size'] > $maxSize) {
            $error = 'Ukuran gambar maksimal 5MB.';
        } else {
            $filename = uniqid('event_') . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
                $imageUrl = 'assets/events/' . $filename;
            } else {
                $error = 'Gagal upload. Cek permission folder assets/events/';
            }
        }
    }

    if (!$error && (!$title || !$location || !$city || !$dateStart)) {
        $error = 'Field bertanda * wajib diisi.';
    }

    if (!$error) {
        $pdo->prepare("INSERT INTO events (title, description, location, city, category, status, event_date_start, event_date_end, ticket_url, image_url) VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([$title, $desc, $location, $city, $category, $status, $dateStart, $dateEnd, $ticketUrl, $imageUrl]);
        $newId = $pdo->lastInsertId();
        header("Location: ticket-manage?event_id=$newId&new=1");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tambah Event — Admin Noirlab</title>
    <style>
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            background-color: #ffffff;
            color: #000000;
            -webkit-font-smoothing: antialiased;
            display: flex;
            min-height: 100vh;
        }

        /* --- SIDEBAR --- */
        .sidebar {
            width: 220px;
            flex-shrink: 0;
            background-color: #ffffff;
            border-right: 1px solid #e5e5e5;
            display: flex;
            flex-direction: column;
            padding: 40px 0;
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            z-index: 10;
            overflow-y: auto;
        }

        .sidebar-logo {
            padding: 0 28px 32px;
            border-bottom: 1px solid #e5e5e5;
        }

        .sidebar-logo img {
            height: 103px;
            width: auto;
            display: block;
        }

        .sidebar-menu {
            padding: 24px 16px;
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .sidebar-menu a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            color: rgba(0,0,0,0.4);
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
            letter-spacing: 0.2px;
            transition: color 0.2s ease;
        }

        .sidebar-menu a:hover { color: #000000; }

        .sidebar-menu a.active {
            color: #000000;
            font-weight: 700;
            border-left: 2px solid #000000;
            padding-left: 12px;
        }

        .sidebar-footer {
            padding: 24px 28px 0;
            border-top: 1px solid #e5e5e5;
        }

        .sidebar-footer a {
            font-size: 0.8rem;
            color: rgba(0,0,0,0.35);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s ease;
        }

        .sidebar-footer a:hover { color: #000000; }

        /* --- MAIN --- */
        .main {
            margin-left: 220px;
            flex: 1;
            padding: 50px 48px;
            min-width: 0;
            background-color: #ffffff;
        }

        /* --- PAGE HEADER --- */
        .page-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 48px;
            padding-bottom: 32px;
            border-bottom: 1px solid #e5e5e5;
        }

        .page-header h1 {
            font-size: 1.75rem;
            font-weight: 700;
            letter-spacing: -0.5px;
            line-height: 1;
            color: #000000;
        }

        /* --- ALERT --- */
        .alert-error {
            border: 1px solid #000000;
            color: #000000;
            padding: 13px 18px;
            font-size: 0.875rem;
            margin-bottom: 24px;
            background: #ffffff;
        }

        /* --- FORM CARD --- */
        .form-card {
            border: 1px solid #e5e5e5;
            padding: 36px 40px;
            background: #ffffff;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0 28px;
        }

        .form-group { margin-bottom: 24px; }
        .form-group.full { grid-column: 1 / -1; }

        .form-divider {
            grid-column: 1 / -1;
            border: none;
            border-top: 1px solid #e5e5e5;
            margin: 4px 0 24px;
        }

        .form-section-title {
            grid-column: 1 / -1;
            font-size: 0.65rem;
            font-weight: 700;
            color: rgba(0,0,0,0.35);
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin-bottom: 8px;
        }

        label {
            font-size: 0.8rem;
            font-weight: 700;
            color: #000000;
            display: block;
            margin-bottom: 8px;
            letter-spacing: 0.1px;
        }

        input[type="text"],
        input[type="date"],
        input[type="url"],
        input[type="number"],
        select,
        textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #e5e5e5;
            border-radius: 0;
            font-size: 0.875rem;
            font-family: inherit;
            color: #000000;
            background: #ffffff;
            transition: border-color 0.2s;
            appearance: none;
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #000000;
        }

        input::placeholder, textarea::placeholder { color: rgba(0,0,0,0.25); }

        textarea {
            resize: vertical;
            min-height: 110px;
        }

        small {
            font-size: 0.75rem;
            color: rgba(0,0,0,0.35);
            display: block;
            margin-top: 6px;
        }

        select {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23000000' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
            padding-right: 38px;
            cursor: pointer;
        }

        /* --- LOCATION WRAPPER --- */
        .location-wrapper { position: relative; }

        .location-icon {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            pointer-events: none;
            opacity: 0.3;
            z-index: 1;
        }

        /* Styling PlaceAutocompleteElement (New API) agar sesuai desain */
        gmp-place-autocomplete {
            width: 100%;
            display: block;
        }

        gmp-place-autocomplete::part(input) {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #e5e5e5;
            border-radius: 0 !important;
            font-size: 0.875rem;
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            color: #000000;
            background: #ffffff;
            transition: border-color 0.2s;
            box-shadow: none !important;
            outline: none;
        }

        gmp-place-autocomplete::part(input):focus {
            border-color: #000000 !important;
            outline: none !important;
        }

        /* Dropdown suggestion */
        .pac-container {
            border-radius: 0 !important;
            border: 1px solid #000000 !important;
            box-shadow: 0 4px 16px rgba(0,0,0,0.08) !important;
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif !important;
            margin-top: 2px !important;
        }

        .pac-item {
            padding: 10px 14px !important;
            font-size: 0.8rem !important;
            color: #000000 !important;
            border-top: 1px solid #f0f0f0 !important;
            cursor: pointer !important;
        }

        .pac-item:hover, .pac-item-selected { background-color: #f5f5f5 !important; }
        .pac-item-query { font-size: 0.8rem !important; color: #000000 !important; font-weight: 600 !important; }
        .pac-icon { display: none !important; }
        .pac-matched { font-weight: 700 !important; }

        /* Badge "Terisi otomatis" */
        .auto-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 0.68rem;
            font-weight: 600;
            color: #2d7a4f;
            background: #eaf5ee;
            border: 1px solid #b8dfc8;
            padding: 2px 8px;
            margin-left: 8px;
            letter-spacing: 0.2px;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .auto-badge.show { opacity: 1; }

        /* --- UPLOAD AREA --- */
        .upload-area {
            border: 1px dashed rgba(0,0,0,0.25);
            padding: 32px 20px;
            text-align: center;
            cursor: pointer;
            transition: border-color 0.2s, background 0.2s;
            position: relative;
        }

        .upload-area:hover { border-color: #000000; background: #fafafa; }
        .upload-area.drag-over { border-color: #000000; background: #f5f5f5; }

        .upload-area input[type="file"] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
            width: 100%;
            height: 100%;
        }

        .upload-icon { font-size: 28px; margin-bottom: 10px; opacity: 0.4; }
        .upload-text { font-size: 0.875rem; font-weight: 600; color: #000000; margin-bottom: 4px; }
        .upload-sub { font-size: 0.75rem; color: rgba(0,0,0,0.35); }

        .img-preview-wrap { margin-top: 14px; display: none; }

        .img-preview-wrap img {
            width: 100%;
            max-height: 220px;
            object-fit: cover;
            display: block;
            border: 1px solid #e5e5e5;
        }

        .img-preview-name {
            font-size: 0.75rem;
            color: rgba(0,0,0,0.45);
            margin-top: 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .img-remove {
            font-size: 0.75rem;
            color: #000000;
            cursor: pointer;
            font-weight: 700;
            border: none;
            background: none;
            font-family: inherit;
            text-decoration: underline;
        }

        /* --- FORM ACTIONS --- */
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 32px;
            padding-top: 24px;
            border-top: 1px solid #e5e5e5;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 22px;
            font-size: 0.8rem;
            font-weight: 600;
            text-decoration: none;
            border: 1px solid #000000;
            cursor: pointer;
            font-family: inherit;
            transition: background-color 0.2s ease, color 0.2s ease;
            white-space: nowrap;
            letter-spacing: 0.2px;
            background: #ffffff;
            color: #000000;
        }

        .btn:hover { background-color: #000000; color: #ffffff; }

        .btn-primary { background: #000000; color: #ffffff; }
        .btn-primary:hover { opacity: 0.7; background: #000000; color: #ffffff; }

        .btn-ghost { border-color: #e5e5e5; color: rgba(0,0,0,0.5); }
        .btn-ghost:hover { border-color: #000000; background: #000000; color: #ffffff; }

        /* --- RESPONSIVE --- */
        @media (max-width: 860px) {
            .form-grid { grid-template-columns: 1fr; }
            .sidebar { display: none; }
            .main { margin-left: 0; padding: 28px 20px; }
            .page-header h1 { font-size: 1.3rem; }
            .form-card { padding: 24px 20px; }
        }
    </style>

    <!-- ✅ Google Maps Places API (New) — ganti YOUR_API_KEY_HERE dengan API key kamu -->
    <script>
    (g=>{var h,a,k,p="The Google Maps JavaScript API",c="google",l="importLibrary",q="__ib__",m=document,b=window;b=b[c]||(b[c]={});var d=b.maps||(b.maps={}),r=new Set,e=new URLSearchParams,u=()=>h||(h=new Promise(async(f,n)=>{await (a=m.createElement("script"));e.set("libraries",[...r]+"");for(k in g)e.set(k.replace(/[A-Z]/g,t=>"_"+t[0].toLowerCase()),g[k]);e.set("callback",c+".maps."+q);a.src=`https://maps.${c}apis.com/maps/api/js?`+e;d[q]=f;a.onerror=()=>h=n(Error(p+" could not load."));a.nonce=m.querySelector("script[nonce]")?.nonce||"";m.head.append(a)}));d[l]?console.warn(p+" only loads once. Ignoring:",g):d[l]=(f,...n)=>r.add(f)&&u().then(()=>d[l](f,...n))})
    ({key: "AIzaSyBLLizYS6AHLoNZbTMLn7zXDonG64mSuUY", v: "weekly"});
    </script>
</head>
<body>

<!-- SIDEBAR -->
<nav class="sidebar">
    <div class="sidebar-logo">
        <img src="../assets/logos/logo.png" alt="Noirlab">
    </div>
    <div class="sidebar-menu">
        <a href="./">Dashboard</a>
        <a href="event-add" class="active">Tambah Event</a>
        <a href="manage-admin">Kelola Admin</a>
        <a href="../index-event" target="_blank">Lihat Halaman ↗</a>
    </div>
    <div class="sidebar-footer">
        <a href="./?logout=1">Logout — <?= htmlspecialchars($_SESSION['admin_name'] ?? 'Admin') ?></a>
    </div>
</nav>

<!-- MAIN -->
<div class="main">
    <div class="page-header">
        <div>
            <h1>Tambah Event Baru</h1>
        </div>
        <a href="./" class="btn btn-ghost">← Kembali</a>
    </div>

    <?php if ($error): ?>
    <div class="alert-error">⚠ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="form-card">
        <form method="POST" enctype="multipart/form-data">
            <div class="form-grid">

                <div class="form-section-title">Informasi Event</div>

                <div class="form-group full">
                    <label>Judul Event *</label>
                    <input type="text" name="title" placeholder="Contoh: LOUD KRAP: SERINGAI" required
                           value="<?= htmlspecialchars($_POST['title'] ?? '') ?>">
                </div>

                <div class="form-group full">
                    <label>Deskripsi</label>
                    <textarea name="description" placeholder="Ceritakan tentang event ini..."><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                </div>

                <hr class="form-divider">
                <div class="form-section-title">Tempat & Waktu</div>

                <div class="form-group">
                    <label>
                        Venue / Lokasi *
                        <span class="auto-badge" id="locationBadge">✓ Dipilih dari Maps</span>
                    </label>
                    <div class="location-wrapper">
                        <!-- Elemen ini akan diganti oleh PlaceAutocompleteElement via JS -->
                        <input type="text" name="location" id="locationInput"
                               placeholder="Ketik nama venue..."
                               required
                               value="<?= htmlspecialchars($_POST['location'] ?? '') ?>">
                        <svg class="location-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                            <circle cx="12" cy="10" r="3"/>
                        </svg>
                    </div>
                    <small>Ketik nama venue — pilih dari suggestion Google Maps</small>
                </div>

                <div class="form-group">
                    <label>
                        Kota *
                        <span class="auto-badge" id="cityBadge">✓ Terisi otomatis</span>
                    </label>
                    <input type="text" name="city" id="cityInput"
                           placeholder="Otomatis terisi saat pilih venue"
                           required
                           value="<?= htmlspecialchars($_POST['city'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label>Tanggal Mulai *</label>
                    <input type="date" name="event_date_start" required
                           value="<?= htmlspecialchars($_POST['event_date_start'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label>Tanggal Selesai <span style="font-weight:400;color:rgba(0,0,0,0.35)">(opsional)</span></label>
                    <input type="date" name="event_date_end"
                           value="<?= htmlspecialchars($_POST['event_date_end'] ?? '') ?>">
                </div>

                <hr class="form-divider">
                <div class="form-section-title">Detail Lainnya</div>

                <div class="form-group">
                    <label>Kategori</label>
                    <input type="text" name="category" placeholder="Musik, Pameran, Workshop..."
                           value="<?= htmlspecialchars($_POST['category'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label>Status *</label>
                    <select name="status">
                        <option value="upcoming" <?= ($_POST['status'] ?? 'upcoming') === 'upcoming' ? 'selected' : '' ?>>Upcoming</option>
                        <option value="past"     <?= ($_POST['status'] ?? '') === 'past' ? 'selected' : '' ?>>Past</option>
                    </select>
                </div>

                <div class="form-group full">
                    <label>Gambar Event</label>
                    <div class="upload-area" id="uploadArea">
                        <input type="file" name="image_file" id="imageFile"
                               accept=".jpg,.jpeg,.png,.gif,.webp"
                               onchange="previewImage(this)">
                        <div id="uploadPlaceholder">
                            <div class="upload-icon">↑</div>
                            <div class="upload-text">Klik atau drag & drop gambar ke sini</div>
                            <div class="upload-sub">JPG, PNG, WEBP, GIF — Maks. 5MB</div>
                        </div>
                    </div>
                    <div class="img-preview-wrap" id="previewWrap">
                        <img id="previewImg" src="" alt="Preview">
                        <div class="img-preview-name">
                            <span id="previewName"></span>
                            <button type="button" class="img-remove" onclick="removeImage()">Hapus ✕</button>
                        </div>
                    </div>
                    <small>Gambar akan disimpan di folder <code>assets/events/</code></small>
                </div>

                <div class="form-group full">
                    <label>Link Tiket Eksternal <span style="font-weight:400;color:rgba(0,0,0,0.35)">(opsional)</span></label>
                    <input type="url" name="ticket_url" placeholder="https://loket.com/..."
                           value="<?= htmlspecialchars($_POST['ticket_url'] ?? '') ?>">
                </div>

            </div>

            <div class="form-actions">
                <a href="./" class="btn btn-ghost">Batal</a>
                <button type="submit" class="btn btn-primary">Simpan & Atur Tiket →</button>
            </div>
        </form>
    </div>
</div>

<script>
/* =============================================
   IMAGE UPLOAD PREVIEW
   ============================================= */
function previewImage(input) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    const reader = new FileReader();
    reader.onload = function(e) {
        document.getElementById('previewImg').src = e.target.result;
        document.getElementById('previewName').textContent = file.name + ' (' + (file.size / 1024).toFixed(0) + ' KB)';
        document.getElementById('previewWrap').style.display = 'block';
        document.getElementById('uploadPlaceholder').style.display = 'none';
    };
    reader.readAsDataURL(file);
}

function removeImage() {
    document.getElementById('imageFile').value = '';
    document.getElementById('previewWrap').style.display = 'none';
    document.getElementById('uploadPlaceholder').style.display = 'block';
}

const area = document.getElementById('uploadArea');
area.addEventListener('dragover', e => { e.preventDefault(); area.classList.add('drag-over'); });
area.addEventListener('dragleave', () => area.classList.remove('drag-over'));
area.addEventListener('drop', e => { e.preventDefault(); area.classList.remove('drag-over'); });


/* =============================================
   GOOGLE MAPS PLACES AUTOCOMPLETE (NEW API)
   Menggunakan PlaceAutocompleteElement — API resmi per 2025
   ============================================= */
async function initMapsAutocomplete() {
    try {
        const cityInput     = document.getElementById('cityInput');
        const locationBadge = document.getElementById('locationBadge');
        const cityBadge     = document.getElementById('cityBadge');
        const locationInput = document.getElementById('locationInput');
        const oldValue      = locationInput.value;

        // Load Places library (New)
        const { PlaceAutocompleteElement } = await google.maps.importLibrary("places");

        // Buat elemen autocomplete baru
        const placeAutocomplete = new PlaceAutocompleteElement({
            componentRestrictions: { country: 'id' },
            types: ['establishment', 'geocode']
        });

        // Set nama agar ikut ter-submit di form PHP
        placeAutocomplete.setAttribute('name', 'location');
        placeAutocomplete.id = 'locationInput';

        // Isi ulang nilai lama jika ada (setelah reload akibat validasi error)
        if (oldValue) placeAutocomplete.value = oldValue;

        // Ganti <input> asli dengan elemen baru
        locationInput.removeAttribute('name'); // lepas name dari input lama
        locationInput.parentNode.replaceChild(placeAutocomplete, locationInput);

        // Cegah Enter menyebabkan form submit
        placeAutocomplete.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') e.preventDefault();
        });

        // Event saat user memilih tempat dari suggestion
        placeAutocomplete.addEventListener('gmp-select', async ({ placePrediction }) => {
            const place = placePrediction.toPlace();
            await place.fetchFields({ fields: ['addressComponents', 'displayName', 'formattedAddress'] });

            // Badge konfirmasi lokasi
            locationBadge.classList.add('show');
            setTimeout(() => locationBadge.classList.remove('show'), 4000);

            // Ekstrak nama kota dari address_components
            const components = place.addressComponents || [];
            let city = '';

            // Prioritas: locality → administrative_area_level_2 → administrative_area_level_1
            for (const comp of components) {
                if (comp.types.includes('locality')) { city = comp.longText; break; }
            }
            if (!city) {
                for (const comp of components) {
                    if (comp.types.includes('administrative_area_level_2')) {
                        city = comp.longText.replace(/^(Kota |Kabupaten )/i, '');
                        break;
                    }
                }
            }
            if (!city) {
                for (const comp of components) {
                    if (comp.types.includes('administrative_area_level_1')) {
                        city = comp.longText;
                        break;
                    }
                }
            }

            if (city) {
                cityInput.value = city;
                cityBadge.classList.add('show');
                setTimeout(() => cityBadge.classList.remove('show'), 4000);
            }
        });

    } catch (err) {
        console.warn('Google Maps gagal dimuat, field lokasi tetap bisa diisi manual.', err);
    }
}

// Panggil setelah DOM siap
document.addEventListener('DOMContentLoaded', initMapsAutocomplete);
</script>

</body>
</html>

/**
 * Halaman admin/scan-tiket: scan QR dengan kamera, cari manual (kode/nama/email/HP),
 * konfirmasi masuk, dan batalkan masuk. Konfigurasi dari window.SCAN_CONFIG = { csrf, eventId }.
 * Semua data lewat AJAX, jadi kamera tetap menyala untuk tamu berikutnya.
 * Kalau sinyal hilang, scan-offline.js menjawab dari salinan daftar tamu di HP dan menyinkronkannya nanti.
 */
(() => {
    'use strict';

    const { csrf: CSRF, eventId: EVENT_ID } = window.SCAN_CONFIG;
    const REPEAT_SCAN_MS = 4000;       // abaikan baca ganda QR yang sama
    const NEXT_GUEST_DELAY_MS = 1200;  // jeda sebelum kamera lanjut setelah konfirmasi
    const CODE_PATTERN = /^NL-[A-Z0-9]{6}$/i;
    const ENTRY_ACTIONS = ['enter', 'undo'];
    const READ_TIMEOUT_MS = 6000;      // lewat ini dianggap tidak ada sinyal → pakai salinan di HP
    const WRITE_TIMEOUT_MS = 12000;    // menyimpan masuk lebih lama ditunggu, supaya tidak tercatat ganda

    const state = {
        scanner: null, scanning: false, busy: false, resumeOnVisible: false,
        cameras: [], cameraIndex: -1, torchOn: false, lastCode: '', lastAt: 0, wakeLock: null,
    };

    const el = id => document.getElementById(id);
    const esc = value => String(value ?? '').replace(/[&<>"']/g,
        c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const pluralPeople = n => `${n} ${n === 1 ? 'person' : 'people'}`;

    /* ---------- server ---------- */

    function browserStorage() {
        try { return window.localStorage; } catch (e) { return null; }   // diblokir di sebagian mode privat
    }

    /** Satu kali panggilan ke server. Melempar bila tidak terjangkau, atau jawabannya bukan JSON (sesi habis / error server). */
    async function post(action, data = {}) {
        const controller = new AbortController();
        const timeout = ENTRY_ACTIONS.includes(action) ? WRITE_TIMEOUT_MS : READ_TIMEOUT_MS;
        const timer = setTimeout(() => controller.abort(), timeout);
        try {
            const response = await fetch('scan-tiket', {
                method: 'POST',
                body: new URLSearchParams({ csrf: CSRF, filter_event: EVENT_ID, action, ...data }),
                signal: controller.signal,
            });
            if (!(response.headers.get('content-type') || '').includes('json')) {
                throw new Error('Login expired or server error');
            }
            return await response.json();
        } finally {
            clearTimeout(timer);
        }
    }

    const offline = ScanOffline.create({ post, eventId: EVENT_ID, storage: browserStorage() });
    const api = (action, data) => offline.request(action, data);

    /* ---------- umpan balik: suara, getar, layar ---------- */

    function tone(frequency, startOffset = 0, duration = 0.2) {
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            const osc = ctx.createOscillator(), gain = ctx.createGain();
            osc.connect(gain); gain.connect(ctx.destination);
            osc.frequency.value = frequency; osc.type = 'sine';
            const t0 = ctx.currentTime + startOffset;
            gain.gain.setValueAtTime(0.3, t0);
            gain.gain.exponentialRampToValueAtTime(0.001, t0 + duration);
            osc.start(t0); osc.stop(t0 + duration);
        } catch (e) { /* audio tidak tersedia */ }
    }

    function vibrate(pattern) { if (navigator.vibrate) navigator.vibrate(pattern); }

    /** Valid = satu nada tinggi; ditolak = dua nada rendah dan getar panjang, tanpa perlu melihat layar. */
    function feedback(accepted) {
        if (accepted) { tone(1200); vibrate(60); return; }
        tone(320); tone(320, 0.25); vibrate([140, 60, 140]);
    }

    async function holdScreenAwake() {
        try { if ('wakeLock' in navigator) state.wakeLock = await navigator.wakeLock.request('screen'); }
        catch (e) { /* tidak didukung */ }
    }

    function releaseScreen() {
        if (state.wakeLock) state.wakeLock.release().catch(() => {});
        state.wakeLock = null;
    }

    function setStatus(kind, text) {
        const box = el('scanStatus');
        box.className = 'scan-status ' + kind;
        box.textContent = text;
        box.style.display = text ? 'block' : 'none';
    }

    /* ---------- kamera ---------- */

    function getScanner() {
        state.scanner = state.scanner || new Html5Qrcode('qr-reader', {
            formatsToSupport: [Html5QrcodeSupportedFormats.QR_CODE],        // QR saja → decode lebih cepat
            experimentalFeatures: { useBarCodeDetectorIfSupported: true },  // detektor bawaan browser bila ada
            verbose: false,
        });
        return state.scanner;
    }

    async function startScanner(deviceId) {
        if (state.scanning) return;
        if (!window.isSecureContext) {
            setStatus('error', 'Camera needs HTTPS. Open the site with https:// or use "Scan from photo".');
            return;
        }
        const config = {
            fps: 15,
            qrbox: (w, h) => { const side = Math.floor(Math.min(w, h) * 0.75); return { width: side, height: side }; },
        };
        try {
            await getScanner().start(deviceId || { facingMode: 'environment' }, config, onDecode, () => {});
            state.scanning = true;
            el('btnScan').textContent = 'Stop Camera';
            el('btnScan').classList.add('active');
            setStatus('scanning', 'Camera active. Point at the QR code...');
            holdScreenAwake();
            setupCameraControls();
        } catch (err) {
            setStatus('error', 'Camera cannot be accessed. Allow camera permission, or use "Scan from photo".');
        }
    }

    async function stopScanner() {
        if (!state.scanner || !state.scanning) return;
        state.scanning = false;
        state.torchOn = false;
        try { await state.scanner.stop(); state.scanner.clear(); } catch (e) { /* sudah berhenti */ }
        releaseScreen();
        el('btnScan').textContent = 'Open QR Scanner Camera';
        el('btnScan').classList.remove('active');
        el('btnTorch').hidden = true;
        el('btnTorch').classList.remove('active');
        el('btnSwitch').hidden = true;
        setStatus('', '');
    }

    /** Flash dan ganti kamera hanya muncul kalau perangkat mendukung. */
    async function setupCameraControls() {
        try { el('btnTorch').hidden = !state.scanner.getRunningTrackCameraCapabilities().torchFeature().isSupported(); }
        catch (e) { el('btnTorch').hidden = true; }
        try {
            state.cameras = await Html5Qrcode.getCameras();
            el('btnSwitch').hidden = state.cameras.length < 2;
        } catch (e) { el('btnSwitch').hidden = true; }
    }

    async function toggleTorch() {
        try {
            state.torchOn = !state.torchOn;
            await state.scanner.getRunningTrackCameraCapabilities().torchFeature().apply(state.torchOn);
            el('btnTorch').classList.toggle('active', state.torchOn);
        } catch (e) {
            state.torchOn = false;
            setStatus('error', 'Flash is not supported on this device.');
        }
    }

    async function switchCamera() {
        if (state.cameras.length < 2) return;
        state.cameraIndex = (state.cameraIndex + 1) % state.cameras.length;
        await stopScanner();
        await startScanner(state.cameras[state.cameraIndex].id);
    }

    function pauseScanner() { try { state.scanner.pause(true); } catch (e) { /* tidak sedang scan */ } }

    function resumeScan() {
        el('scanResult').innerHTML = '';
        state.busy = false;
        if (!state.scanning) { setStatus('', ''); return; }
        try { state.scanner.resume(); } catch (e) { /* abaikan */ }
        setStatus('scanning', 'Camera active. Point at the QR code...');
    }

    async function onDecode(text) {
        const code = text.trim().toUpperCase();
        const now = Date.now();
        if (state.busy || (code === state.lastCode && now - state.lastAt < REPEAT_SCAN_MS)) return;
        state.busy = true; state.lastCode = code; state.lastAt = now;
        vibrate(40);
        pauseScanner();
        await lookupCode(code);
    }

    /* ---------- hasil: satu panel untuk kamera, pencarian manual, dan klik daftar ---------- */

    async function lookupCode(code) {
        setStatus('found', code + ' — checking...');
        try {
            const { order } = await api('lookup', { code });
            setStatus('', '');
            presentOrder(order, code);
        } catch (e) {
            setStatus('error', 'Check failed: ' + e.message);
            resumeScan();
        }
    }

    function describeOrder(order, code) {
        if (!order) return { kind: 'invalid', title: 'NOT FOUND', sub: 'Code ' + code + ' is not in the system' };
        switch (order.status) {
            case 'test':      return { kind: 'test', title: 'TEST TICKET', sub: 'Scan works. Sample ticket, nothing is recorded.', accepted: true };
            case 'confirmed': return { kind: 'valid', title: 'VALID TICKET', sub: 'Allow entry', canEnter: true, accepted: true };
            case 'used':      return { kind: 'used', title: 'ALREADY USED', sub: 'Scanned ' + (order.used_at || 'before'), canUndo: true };
            case 'pending':   return { kind: 'pending', title: 'NOT CONFIRMED', sub: 'Payment pending, deny entry' };
            default:          return { kind: 'invalid', title: 'INVALID TICKET', sub: 'Order rejected' };
        }
    }

    function orderDetailsHtml(order) {
        if (!order) return '';
        const addons = order.addons.length ? '<br>Add-on: ' + order.addons.map(esc).join(', ') : '';
        return '<div class="ar-name">' + esc(order.name) + '</div>'
            + '<div class="ar-meta">' + esc(order.code) + '<br>' + esc(order.event) + '<br>' + esc(order.tier) + addons + '</div>'
            + '<div class="ar-qty">' + pluralPeople(order.qty) + '</div>';
    }

    function presentOrder(order, code = '') {
        const view = describeOrder(order, code);
        feedback(!!view.accepted);
        const box = el('scanResult');
        const buttons = (view.canEnter ? '<button type="button" class="primary" id="btnEnter">Confirm Entry — ' + pluralPeople(order.qty) + '</button>' : '')
            + (view.canUndo ? '<button type="button" class="secondary" id="btnUndo">Undo entry (marked by mistake)</button>' : '')
            + '<button type="button" class="secondary" id="btnNext">' + (state.scanning ? 'Scan Next' : 'Close') + '</button>';
        box.innerHTML = '<div class="ajax-result ' + view.kind + '">'
            + '<div class="ar-head"><div class="ar-status">' + view.title + '</div><div class="ar-sub">' + esc(view.sub) + '</div></div>'
            + '<div class="ar-body">' + orderDetailsHtml(order) + '<div class="ar-buttons">' + buttons + '</div></div></div>';
        box.scrollIntoView({ block: 'nearest', behavior: 'smooth' });

        state.busy = true;   // kamera tidak boleh membaca tiket lain selagi hasil ini terbuka
        pauseScanner();
        el('btnNext').onclick = resumeScan;
        if (view.canEnter) el('btnEnter').onclick = () => submitEntry('enter', order);
        if (view.canUndo) el('btnUndo').onclick = () => {
            if (confirm('Undo entry for ' + order.name + '?')) submitEntry('undo', order);
        };
    }

    async function submitEntry(action, order) {
        const button = el(action === 'enter' ? 'btnEnter' : 'btnUndo');
        button.disabled = true;
        try {
            const res = await api(action, { order_id: order.id });
            if (res.stats) renderStats(res.stats);
            if (!res.changed) {
                setStatus('error', action === 'enter' ? 'This ticket was just used. Deny entry.' : 'Nothing to undo: the ticket is not marked as used.');
                if (res.order) presentOrder(res.order);
                return;
            }
            moveGuestRow(res.order, action === 'enter');
            const suffix = res.offline ? ' (saved on this phone, will sync)' : '';
            if (action === 'enter') {
                setStatus('found', order.name + ' — entry confirmed' + suffix);
                setTimeout(resumeScan, NEXT_GUEST_DELAY_MS);
            } else {
                setStatus('found', order.name + ' — entry undone' + suffix);
                presentOrder(res.order);
            }
        } catch (e) {
            button.disabled = false;
            setStatus('error', 'Could not save: ' + e.message + '. Try again.');
        }
    }

    function renderStats(stats) {
        for (const [key, value] of Object.entries(stats)) {
            const target = el('stat-' + key);
            if (target) target.textContent = value;
        }
    }

    /* ---------- daftar tamu (sisi kanan) ---------- */

    function rowByCode(listId, code) {
        return el(listId).querySelector('[data-code="' + CSS.escape(code) + '"]');
    }

    function syncList(listId) {
        const list = el(listId);
        const items = list.querySelectorAll('.daftar-item').length;
        const empty = list.querySelector('.daftar-empty');
        if (empty) empty.hidden = items > 0;
        const counter = document.querySelector('[data-count-for="' + listId + '"]');
        if (counter) counter.textContent = items;
    }

    /** Pindahkan baris tamu antara "Already Entered" dan "Not Scanned" tanpa memuat ulang halaman. */
    function moveGuestRow(order, entered) {
        const from = entered ? 'list-unscanned' : 'list-entered';
        const to = entered ? 'list-entered' : 'list-unscanned';
        const source = rowByCode(from, order.code);
        if (source) {
            const row = source.cloneNode(true);
            row.querySelector('.daftar-time').textContent = entered ? order.used_at_short : order.confirmed_at_short;
            source.remove();
            el(to).prepend(row);
        }
        const badge = rowByCode('list-all', order.code)?.querySelector('.badge-sm');
        if (badge) {
            badge.className = 'badge-sm ' + (entered ? 'used' : 'confirmed');
            badge.textContent = entered ? 'Used' : 'Confirmed';
        }
        ['list-entered', 'list-unscanned'].forEach(syncList);
    }

    function switchDaftar(tab, button) {
        document.querySelectorAll('.daftar-tab').forEach(t => t.classList.remove('active'));
        button.classList.add('active');
        document.querySelectorAll('.daftar-content').forEach(c => c.classList.remove('active'));
        el('tab-' + tab).classList.add('active');
        el('daftarSearch').value = '';
        filterDaftar();
    }

    function filterDaftar() {
        const query = el('daftarSearch').value.toLowerCase();
        document.querySelectorAll('.daftar-content.active .daftar-item').forEach(item => {
            item.style.display = item.dataset.search.includes(query) ? '' : 'none';
        });
    }

    /* ---------- pencarian manual ---------- */

    function showPickList(orders) {
        el('scanResult').innerHTML = '<div class="pick-list">' + orders.map((o, i) =>
            '<button type="button" class="pick-item" data-index="' + i + '">'
            + '<span class="pick-main"><b>' + esc(o.name) + '</b><span>' + esc(o.code) + ' · ' + esc(o.tier) + ' · ' + pluralPeople(o.qty) + '</span></span>'
            + '<span class="badge-sm ' + esc(o.status) + '">' + esc(o.status) + '</span></button>').join('') + '</div>';
        el('scanResult').querySelectorAll('.pick-item').forEach(button => {
            button.onclick = () => presentOrder(orders[Number(button.dataset.index)]);
        });
    }

    async function submitManual(event) {
        event.preventDefault();
        const query = el('codeInput').value.trim();
        if (!query) return;
        try {
            if (query.includes('|') || CODE_PATTERN.test(query)) { await lookupCode(query.toUpperCase()); return; }
            const { orders } = await api('search', { q: query });
            if (orders.length === 0) presentOrder(null, query.toUpperCase());
            else if (orders.length === 1) presentOrder(orders[0]);
            else showPickList(orders);
        } catch (e) {
            setStatus('error', 'Search failed: ' + e.message);
        }
    }

    /** Foto QR dari kamera bawaan HP, cadangan kalau kamera live tidak bisa dipakai. */
    async function scanPhoto(event) {
        const file = event.target.files[0];
        event.target.value = '';
        if (!file) return;
        await stopScanner();
        try {
            const text = await getScanner().scanFile(file, false);
            await lookupCode(text.trim().toUpperCase());
        } catch (err) {
            setStatus('error', 'QR not readable in the photo. Move closer, add light, and try again.');
        }
    }

    /* ---------- status koneksi dan sinkron ---------- */

    function formatClock(ms) {
        const d = new Date(ms);
        return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
    }

    function connectionView(st) {
        const waiting = st.queued ? ` · ${st.queued} scan${st.queued === 1 ? '' : 's'} waiting to sync` : '';
        const updated = st.updatedAt ? `${st.count} guests saved, updated ${formatClock(st.updatedAt)}` : 'offline list not downloaded yet';
        if (st.offline && st.ready) return { state: 'offline', title: 'OFFLINE', detail: `Scans are saved on this phone${waiting}. ${st.lastError}` };
        if (st.offline) return { state: 'bad', title: 'OFFLINE', detail: `No offline list on this phone: scanning needs a connection. ${st.lastError}` };
        if (st.syncing) return { state: 'busy', title: 'Syncing…', detail: `Sending${waiting}` };
        if (st.queued) return { state: 'busy', title: 'Online', detail: `Sending${waiting}` };
        if (!st.ready) return { state: 'busy', title: 'Online', detail: 'Downloading the offline list…' };
        return { state: 'ok', title: 'Online', detail: updated + (st.lastError ? ' · ' + st.lastError : '') };
    }

    const CONFLICT_TEXT = {
        enter: c => `${c.name} (${c.code}) was already ${c.status === 'used' ? 'entered on another device' : 'not valid (' + c.status + ')'}: possible duplicate entry.`,
        undo:  c => `Undo for ${c.name} (${c.code}) did not apply: the ticket was not marked as used.`,
    };

    function renderConnection() {
        const st = offline.status();
        const view = connectionView(st);
        el('offlineBar').dataset.state = view.state;
        el('obState').textContent = view.title;
        el('obDetail').textContent = view.detail;
        el('obSync').hidden = st.queued === 0;
        const box = el('obConflicts');
        const list = offline.conflicts();
        box.hidden = list.length === 0;
        box.innerHTML = list.length
            ? list.map(c => '<div>⚠ ' + esc((CONFLICT_TEXT[c.action] || CONFLICT_TEXT.enter)(c)) + '</div>').join('')
              + '<button type="button" class="btn-cam" id="obDismiss">Dismiss</button>'
            : '';
        if (list.length) el('obDismiss').onclick = () => offline.clearConflicts();
    }

    /** Daftar tamu di halaman ikut disesuaikan dengan salinan terbaru, termasuk scan dari HP lain. */
    function reconcileLists() {
        const inEntered = new Set([...el('list-entered').querySelectorAll('.daftar-item')].map(row => row.dataset.code));
        const inWaiting = new Set([...el('list-unscanned').querySelectorAll('.daftar-item')].map(row => row.dataset.code));
        for (const order of offline.orders()) {
            if (order.status === 'used' && inWaiting.has(order.code)) moveGuestRow(order, true);
            else if (order.status === 'confirmed' && inEntered.has(order.code)) moveGuestRow(order, false);
        }
        renderStats(offline.stats());
    }

    function setupOffline() {
        offline.onChange(kind => { renderConnection(); if (kind === 'snapshot') reconcileLists(); });
        el('obRefresh').onclick = () => offline.refresh();
        el('obSync').onclick = () => offline.sync();
        window.addEventListener('online', () => offline.tick());
        renderConnection();
        offline.start();
        registerServiceWorker();
    }

    /** Service worker menyimpan halaman dan skripnya supaya halaman scan bisa dibuka tanpa sinyal. */
    async function registerServiceWorker() {
        if (!('serviceWorker' in navigator) || !window.isSecureContext) return;
        try {
            const registration = await navigator.serviceWorker.register('scan-sw.js');
            await navigator.serviceWorker.ready;
            if (!navigator.serviceWorker.controller && registration.active) {   // kunjungan pertama belum lewat service worker
                registration.active.postMessage({ type: 'cache-page', url: location.href });
            }
        } catch (e) { /* tanpa service worker, halaman tetap jalan selama online */ }
    }

    /* ---------- tab dan pemasangan ---------- */

    function switchTab(tab) {
        document.querySelectorAll('.scanner-tab').forEach(t => t.classList.remove('active'));
        el('tab-' + tab).classList.add('active');
        el('section-manual').style.display = tab === 'manual' ? 'block' : 'none';
        el('section-camera').style.display = tab === 'camera' ? 'block' : 'none';
        if (tab !== 'camera') stopScanner();
    }

    function bindEvents() {
        el('tab-manual').onclick = () => switchTab('manual');
        el('tab-camera').onclick = () => switchTab('camera');
        el('btnScan').onclick = () => (state.scanning ? stopScanner() : startScanner());
        el('btnTorch').onclick = toggleTorch;
        el('btnSwitch').onclick = switchCamera;
        el('qrFile').addEventListener('change', scanPhoto);
        el('searchForm').addEventListener('submit', submitManual);
        el('daftarSearch').addEventListener('input', filterDaftar);
        document.querySelectorAll('.daftar-tab').forEach(button => {
            button.onclick = () => switchDaftar(button.dataset.tab, button);
        });
        // Klik tamu di daftar → buka di panel hasil (untuk tamu yang lupa membawa QR)
        document.querySelector('.daftar-card').addEventListener('click', event => {
            const row = event.target.closest('.daftar-item');
            if (row) { switchTab('manual'); lookupCode(row.dataset.code); }
        });
        // Layar mati → kamera mati (hemat baterai); layar menyala lagi → lanjut otomatis.
        document.addEventListener('visibilitychange', async () => {
            if (document.hidden) { state.resumeOnVisible = state.scanning; await stopScanner(); }
            else if (state.resumeOnVisible) { state.resumeOnVisible = false; startScanner(); }
        });
        window.addEventListener('beforeunload', stopScanner);
    }

    bindEvents();
    ['list-entered', 'list-unscanned'].forEach(syncList);
    setupOffline();

    // Di HP langsung buka kamera.
    if (window.matchMedia('(max-width: 860px)').matches) {
        switchTab('camera');
        startScanner();
    }
})();

/**
 * Lapisan offline untuk halaman scan tiket.
 *
 * HP petugas menyimpan salinan daftar tamu. Selama ada sinyal, semua permintaan lewat server
 * (dan salinan ikut diperbarui). Begitu server tidak terjangkau, lookup/cari/masuk/batal dijawab
 * dari salinan dan setiap perubahan masuk antrean; antrean dikirim ke server saat sinyal kembali.
 *
 * Batas yang tidak bisa dihindari: selama offline, HP ini tidak tahu apa yang di-scan HP lain.
 * Kalau tiket yang sama masuk di dua HP, server menolak yang kedua saat sinkron dan
 * menandainya sebagai bentrok (conflict) supaya petugas tahu.
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) module.exports = factory();
    else root.ScanOffline = factory();
})(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    const TEST_CODE = 'NL-TEST00';          // sama dengan TEST_TICKET_CODE di lib/checkin.php
    const SEARCH_LIMIT = 8;
    const REFRESH_MS = 120000;              // perbarui salinan tiap 2 menit selama online
    const TICK_MS = 10000;                  // cek koneksi/antrean tiap 10 detik
    const KEY_QUEUE = 'scan:queue';
    const KEY_CONFLICTS = 'scan:conflicts';
    const KEY_CLOCK = 'scan:clock';
    const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    const pad = n => String(n).padStart(2, '0');
    const formatShort = ms => { const d = new Date(ms); return `${pad(d.getDate())} ${MONTHS[d.getMonth()]}, ${pad(d.getHours())}:${pad(d.getMinutes())}`; };
    const formatLong = ms => { const d = new Date(ms); return `${pad(d.getDate())} ${MONTHS[d.getMonth()]} ${d.getFullYear()}, ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`; };

    /** Kode yang mungkin ada di hasil scan: teks utuh, atau tiap bagian bila QR berformat gabungan dengan '|'. */
    function scanCandidates(raw) {
        const text = String(raw ?? '').trim().toUpperCase();
        if (!text) return [];
        const parts = text.split('|').map(part => part.trim()).filter(Boolean);
        return [...new Set([text, ...parts])];
    }

    function computeStats(orders) {
        const stats = { people_inside: 0, people_waiting: 0, orders_entered: 0, orders_pending: 0 };
        for (const order of orders) {
            if (order.status === 'used') { stats.people_inside += order.qty; stats.orders_entered++; }
            else if (order.status === 'confirmed') stats.people_waiting += order.qty;
            else if (order.status === 'pending') stats.orders_pending++;
        }
        return stats;
    }

    function testOrder() {
        return { id: 0, status: 'test', code: TEST_CODE, name: 'Test Guest', email: '', phone: '', event: 'TEST TICKET',
                 tier: 'Sample ticket', qty: 1, addons: [], used_at: null, used_at_short: '', confirmed_at_short: '' };
    }

    /** Ubah status satu order di salinan lokal, dipakai saat scan offline dan saat memutar ulang antrean. */
    function applyEntry(order, action, atMs) {
        const entering = action === 'enter';
        order.status = entering ? 'used' : 'confirmed';
        order.used_at = entering ? formatLong(atMs) : null;
        order.used_at_short = entering ? formatShort(atMs) : '';
    }

    function applicationError(message) {
        const error = new Error(message || 'Request failed');
        error.application = true;   // server menjawab dan menolak; bukan masalah koneksi
        return error;
    }

    /** localStorage yang tidak pernah melempar error (mode privat / penuh), dengan JSON otomatis. */
    function safeStorage(storage) {
        return {
            get(key, fallback) {
                try { const raw = storage && storage.getItem(key); return raw === null || raw === undefined ? fallback : JSON.parse(raw); }
                catch (e) { return fallback; }
            },
            set(key, value) {
                try { if (storage) storage.setItem(key, JSON.stringify(value)); return true; }
                catch (e) { return false; }
            },
        };
    }

    /**
     * @param {object}   options
     * @param {Function} options.post     (action, data) => Promise<json>; melempar bila server tidak terjangkau
     * @param {number}   options.eventId  event yang discan (0 = semua)
     * @param {Storage}  options.storage  localStorage
     * @param {Function} [options.now]    jam, untuk tes
     */
    function create({ post, eventId, storage, now = Date.now }) {
        const store = safeStorage(storage);
        const snapshotKey = `scan:snapshot:${eventId}`;
        const listeners = [];
        const byId = new Map();
        const byCode = new Map();

        let snapshot = store.get(snapshotKey, null);   // { fetchedAt, orders }
        let queue = store.get(KEY_QUEUE, []);
        let conflicts = store.get(KEY_CONFLICTS, []);
        let clockOffset = store.get(KEY_CLOCK, 0);
        let offline = false, syncing = false, refreshing = false, lastError = '', timer = null;

        /* ---------- salinan lokal ---------- */

        const ready = () => !!snapshot;
        const correctedNow = () => now() + clockOffset;
        const emit = kind => listeners.forEach(listener => listener(kind));

        function index() {
            byId.clear(); byCode.clear();
            for (const order of snapshot ? snapshot.orders : []) {
                byId.set(order.id, order);
                byCode.set(String(order.code).toUpperCase(), order);
            }
        }

        function persist() {
            if (snapshot) store.set(snapshotKey, snapshot);
            store.set(KEY_QUEUE, queue);
            store.set(KEY_CONFLICTS, conflicts);
        }

        /** Terima data order terbaru dari server (hasil lookup/cari/masuk) tanpa menunggu unduhan penuh. */
        function upsert(order) {
            if (!ready() || !order || order.status === 'test') return;
            const known = byId.get(order.id);
            if (known) { Object.assign(known, order); return; }
            snapshot.orders.push(order);
            byId.set(order.id, order);
            byCode.set(String(order.code).toUpperCase(), order);
        }

        /** Entri antrean yang belum terkirim harus tetap terlihat setelah salinan diganti dari server. */
        function replayQueue() {
            for (const entry of queue) {
                const order = byId.get(entry.order_id);
                if (order) applyEntry(order, entry.action, entry.at * 1000);
            }
        }

        function adoptServerTime(serverSeconds) {
            if (!serverSeconds) return;
            clockOffset = serverSeconds * 1000 - now();   // jam HP bisa salah; jam server jadi acuan
            store.set(KEY_CLOCK, clockOffset);
        }

        function goOnline() { if (offline || lastError) { offline = false; lastError = ''; emit('status'); } }
        function goOffline(error) { offline = true; lastError = (error && error.message) || ''; emit('status'); }

        /* ---------- jawaban lokal (saat server tidak terjangkau) ---------- */

        function localLookup(data) {
            const codes = scanCandidates(data.code);
            if (codes.includes(TEST_CODE)) return { ok: true, order: testOrder() };
            const found = codes.map(code => byCode.get(code)).find(Boolean);
            return { ok: true, order: found ? { ...found } : null };
        }

        function localSearch(data) {
            const query = String(data.q ?? '').trim().toLowerCase();
            if (query.length < 2) return { ok: true, orders: [] };
            const matches = snapshot.orders
                .filter(o => o.status !== 'rejected' && [o.name, o.email, o.phone, o.code].some(v => String(v ?? '').toLowerCase().includes(query)))
                .sort((a, b) => String(a.name).localeCompare(String(b.name)))
                .slice(0, SEARCH_LIMIT);
            return { ok: true, orders: matches.map(o => ({ ...o })) };
        }

        function localChange(action, data) {
            const order = byId.get(Number(data.order_id));
            const expected = action === 'enter' ? 'confirmed' : 'used';
            const changed = !!order && order.status === expected;
            if (changed) {
                const at = correctedNow();
                applyEntry(order, action, at);
                queue.push({ id: `${at}-${Math.random().toString(36).slice(2, 8)}`, action, order_id: order.id,
                             code: order.code, name: order.name, at: Math.floor(at / 1000) });
                persist();
                emit('status');
            }
            return { ok: true, changed, order: order ? { ...order } : null, stats: computeStats(snapshot.orders), offline: true };
        }

        function local(action, data) {
            switch (action) {
                case 'lookup': return { ...localLookup(data), offline: true };
                case 'search': return { ...localSearch(data), offline: true };
                case 'enter':
                case 'undo':   return localChange(action, data);
            }
            throw new Error('Unknown action.');
        }

        /* ---------- permintaan: server dulu, salinan lokal sebagai cadangan ---------- */

        function mirror(action, json) {
            if (action === 'search') (json.orders || []).forEach(upsert);
            else upsert(json.order);
            persist();
        }

        async function request(action, data = {}) {
            if (queue.length && !offline) await sync();   // jangan baca status server yang tertinggal dari antrean lokal
            if (queue.length || (offline && ready())) return local(action, data);
            try {
                const json = await post(action, data);
                if (!json.ok) throw applicationError(json.error);
                goOnline();
                mirror(action, json);
                return json;
            } catch (error) {
                if (error.application) throw error;
                goOffline(error);
                if (!ready()) throw new Error('No connection, and the offline guest list has not been downloaded yet.');
                return local(action, data);
            }
        }

        /* ---------- sinkron dan unduh ---------- */

        function recordConflicts(batch, results) {
            for (const result of results) {
                if (result.result !== 'conflict') continue;
                const entry = batch.find(item => item.id === result.id) || {};
                conflicts.push({ name: entry.name || '', code: entry.code || '', action: result.action,
                                 status: result.order ? result.order.status : 'unknown', at: now() });
            }
        }

        async function pushQueue() {
            const batch = queue.slice();
            const payload = batch.map(({ id, action, order_id, at }) => ({ id, action, order_id, at }));
            const json = await post('sync', { entries: JSON.stringify(payload) });
            if (!json.ok) throw applicationError(json.error);
            adoptServerTime(json.server_time);
            const sent = new Set(batch.map(entry => entry.id));
            queue = queue.filter(entry => !sent.has(entry.id));
            recordConflicts(batch, json.results || []);
            (json.results || []).forEach(result => upsert(result.order));
            persist();
            return json.results || [];
        }

        async function sync() {
            if (syncing || !queue.length) return null;
            syncing = true;
            emit('status');
            let results = null;
            try {
                results = await pushQueue();
                goOnline();
            } catch (error) {
                if (error.application) { lastError = error.message; emit('status'); } else goOffline(error);
            } finally {
                syncing = false;
                emit('status');
            }
            if (results) await refresh();
            return results;
        }

        async function refresh() {
            if (refreshing) return false;
            refreshing = true;
            try {
                const json = await post('snapshot');
                if (!json.ok) throw applicationError(json.error);
                adoptServerTime(json.server_time);
                snapshot = { fetchedAt: now(), orders: json.orders };
                index();
                replayQueue();
                persist();
                goOnline();
                emit('snapshot');
                return true;
            } catch (error) {
                if (error.application) { lastError = error.message; emit('status'); } else goOffline(error);
                return false;
            } finally {
                refreshing = false;
            }
        }

        async function tick() {
            if (queue.length) { await sync(); return; }
            if (offline || !ready() || now() - snapshot.fetchedAt >= REFRESH_MS) await refresh();
        }

        function start() {
            if (snapshot) { index(); replayQueue(); }
            refresh();
            timer = setInterval(tick, TICK_MS);
            return api;
        }

        function stop() { clearInterval(timer); }

        const api = {
            request, refresh, sync, tick, start, stop,
            onChange: listener => listeners.push(listener),
            orders: () => (snapshot ? snapshot.orders : []),
            stats: () => computeStats(snapshot ? snapshot.orders : []),
            conflicts: () => conflicts.slice(),
            clearConflicts: () => { conflicts = []; persist(); emit('status'); },
            status: () => ({
                ready: ready(), offline, syncing, queued: queue.length, conflicts: conflicts.length, lastError,
                count: snapshot ? snapshot.orders.length : 0, updatedAt: snapshot ? snapshot.fetchedAt : null,
            }),
        };
        return api;
    }

    return { create, scanCandidates, computeStats, TEST_CODE };
});

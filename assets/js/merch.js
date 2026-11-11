// Detail merch: galeri (geser / thumbnail / panah), pilihan varian, jumlah, total, dan tombol pesan via WhatsApp.
// Data produk (MERCH) datang dari merch-detail.php.
(function () {
    const $ = (id) => document.getElementById(id);
    const rupiah = (n) => 'Rp ' + Math.round(n).toLocaleString('id-ID');

    /* ---------- galeri ---------- */
    const track = $('track');
    if (track) {
        const slides = track.children.length;
        const thumbs = [...document.querySelectorAll('.m-thumb')];
        const count = $('count');
        const index = () => Math.round(track.scrollLeft / track.clientWidth);
        const go = (i) => track.scrollTo({ left: Math.max(0, Math.min(slides - 1, i)) * track.clientWidth, behavior: 'smooth' });

        track.addEventListener('scroll', () => {
            const i = index();
            if (count) count.textContent = (i + 1) + '/' + slides;
            thumbs.forEach((t, n) => t.classList.toggle('on', n === i));
            const on = thumbs[i];
            if (on) on.scrollIntoView({ block: 'nearest', inline: 'nearest' });
        }, { passive: true });
        thumbs.forEach((t) => t.addEventListener('click', () => go(+t.dataset.i)));
        if ($('prev')) $('prev').addEventListener('click', () => go(index() - 1));
        if ($('next')) $('next').addEventListener('click', () => go(index() + 1));
    }

    /* ---------- varian, jumlah, total ---------- */
    const hasVariants = MERCH.variants.length > 0;
    let variant = null;   // varian terpilih
    let qty = 1;

    const unit = () => (variant && variant.price !== null ? variant.price : MERCH.price);
    const stock = () => (hasVariants ? (variant ? variant.stock : 0) : MERCH.stock);
    const maxQty = () => Math.max(0, Math.min(MERCH.maxQty, stock()));
    const poOpen = MERCH.po === 'none' || MERCH.po === 'open';
    const ready = () => poOpen && (!hasVariants || variant) && stock() > 0;

    function render() {
        qty = Math.max(1, Math.min(qty, Math.max(1, maxQty())));
        $('qty').textContent = qty;
        $('minus').disabled = !ready() || qty <= 1;
        $('plus').disabled = !ready() || qty >= maxQty();

        const total = rupiah(unit() * qty);
        $('total').textContent = total;
        $('total2').textContent = total;

        // harga terpilih + harga coret bila memang lebih tinggi
        if (variant) $('price').textContent = rupiah(unit());
        const showWas = MERCH.compare && MERCH.compare > unit();
        $('was').hidden = $('off').hidden = !showWas;
        if (showWas) {
            $('was').textContent = rupiah(MERCH.compare);
            $('off').textContent = '-' + Math.round((MERCH.compare - unit()) / MERCH.compare * 100) + '%';
        }

        // jumlah stok tidak ditampilkan; hanya penanda bila habis
        const soldOut = hasVariants && !variant ? !MERCH.variants.some((v) => v.stock > 0) : stock() < 1;
        $('stock').textContent = soldOut ? 'Stok habis' : '';

        const anyStock = hasVariants ? MERCH.variants.some((v) => v.stock > 0) : MERCH.stock > 0;
        const label = !poOpen ? (MERCH.po === 'soon' ? 'PO belum dibuka' : 'PO ditutup')
            : !anyStock ? 'Stok habis'
            : hasVariants && !variant ? 'Pilih varian dulu'
            : stock() < 1 ? 'Stok habis' : 'Pesan sekarang';
        document.querySelectorAll('[data-buy]').forEach((b) => { b.disabled = !ready(); b.textContent = label; });
    }

    const chips = $('chips');
    if (chips) chips.addEventListener('change', (e) => {
        variant = MERCH.variants.find((v) => String(v.id) === e.target.value) || null;
        render();
    });
    $('minus').addEventListener('click', () => { qty -= 1; render(); });
    $('plus').addEventListener('click', () => { qty += 1; render(); });

    /* ---------- pesan: form di web -> transfer -> konfirmasi WhatsApp ---------- */
    const dlg = $('order-dialog'), form = $('od-form'), pay = $('od-pay'), err = $('od-err');
    const summary = () => MERCH.name + (variant ? ' · ' + variant.name : '') + ' · ' + qty + ' pcs · ' + rupiah(unit() * qty);

    document.querySelectorAll('[data-buy]').forEach((btn) => btn.addEventListener('click', () => {
        if (btn.disabled) return;
        form.hidden = false; pay.hidden = true; err.hidden = true;
        $('od-sum').textContent = summary();
        dlg.showModal();
    }));
    $('od-close').addEventListener('click', () => dlg.close());
    dlg.addEventListener('click', (e) => { if (e.target === dlg) dlg.close(); });

    function waLink(o) {
        const lines = [
            'Haii, saya sudah transfer untuk pesanan ' + o.code + '.', '',
            'Nama: ' + o.name,
            'Alamat lengkap: ' + o.address,
            'Item: ' + o.item,
            'Ukuran & warna: ' + (o.variant || '-'),
            'Jumlah: ' + o.qty,
            'Total: ' + rupiah(o.total),
            'No telp: ' + o.phone,
            'Email & akun Ig: ' + o.contact, '',
            'Pembayaran melalui:', 'Rekening', MERCH.bank, MERCH.account, MERCH.holder, ''
        ];
        if (MERCH.po !== 'none') lines.push(MERCH.poLabel + ', Barang akan dikirim jika Anda sudah melakukan pembayaran lunas melalui transfer perbankan.');
        lines.push('Terima kasih atas supportnya kepada Bona Festival.', '', '(Bukti transfer saya lampirkan di chat ini)');
        return 'https://wa.me/' + MERCH.wa + '?text=' + encodeURIComponent(lines.join('\n'));
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const f = new FormData(form), submit = $('od-submit');
        const body = { product_id: MERCH.id, variant_id: variant ? variant.id : 0, qty };
        f.forEach((v, k) => { body[k] = v; });
        if (!body.name.trim() || !body.address.trim() || !body.phone.trim() || !body.contact.trim()) {
            err.textContent = 'Lengkapi semua data pemesan.'; err.hidden = false; return;
        }
        submit.disabled = true; submit.textContent = 'Memproses...'; err.hidden = true;
        try {
            const res = await fetch('merch-order', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.ok) throw new Error(data.error || 'Gagal mengirim pesanan. Coba lagi.');
            $('od-pay-sum').textContent = summary();
            $('od-total').textContent = rupiah(data.total);
            $('od-code').textContent = data.code;
            $('od-wa').href = waLink(data);
            form.hidden = true; pay.hidden = false;
            form.reset();
        } catch (ex) {
            err.textContent = ex.message; err.hidden = false;
        } finally {
            submit.disabled = false; submit.textContent = 'Lanjut ke pembayaran';
        }
    });

    $('od-copy').addEventListener('click', async (e) => {
        try { await navigator.clipboard.writeText(MERCH.account); e.target.textContent = 'Tersalin'; }
        catch (_) { e.target.textContent = 'Salin manual: ' + MERCH.account; }
    });

    /* ---------- panduan ukuran: dialog di desktop, bottom sheet yang bisa ditarik di HP ---------- */
    const szOpen = $('size-open'), sz = $('size-dialog');
    if (szOpen && sz) {
        const reduce = matchMedia('(prefers-reduced-motion: reduce)');
        const isSheet = () => matchMedia('(max-width: 900px)').matches;
        const SPRING = 'cubic-bezier(.32,.72,0,1)';
        let anim = null, closing = false;

        const stop = () => { if (anim) { anim.cancel(); anim = null; } };
        const run = (frames, ms) => {
            stop();
            if (reduce.matches) return { finished: Promise.resolve() };
            anim = sz.animate(frames, { duration: ms, easing: SPRING, fill: 'forwards' });
            return anim;
        };
        const finishClose = () => { closing = false; stop(); sz.style.transform = ''; sz.close(); };
        const closeSheet = () => {
            if (closing || !sz.open) return;
            closing = true;
            const out = isSheet() ? { transform: 'translateY(100%)' } : { opacity: 0, transform: 'scale(.96)' };
            run([{ transform: getComputedStyle(sz).transform === 'none' ? 'none' : getComputedStyle(sz).transform }, out], 260).finished.then(finishClose, finishClose);
        };

        szOpen.addEventListener('click', () => {
            sz.showModal();
            run(isSheet() ? [{ transform: 'translateY(100%)' }, { transform: 'none' }] : [{ opacity: 0, transform: 'scale(.96)' }, { opacity: 1, transform: 'none' }], 420)
                .finished.then(() => { if (anim) { anim.commitStyles(); stop(); } }, () => {});
        });
        $('sz-close').addEventListener('click', closeSheet);
        sz.addEventListener('cancel', (e) => { e.preventDefault(); closeSheet(); });
        sz.addEventListener('click', (e) => { if (e.target === sz) closeSheet(); }); // klik area gelap

        // tarik ke bawah (hanya HP): ikuti jari 1:1, lepas dengan momentum
        const grab = [$('sz-grab'), sz.querySelector('.m-sheet-head')];
        grab.forEach((h) => {
            let y0 = null, dy = 0, hist = [];
            h.addEventListener('pointerdown', (e) => {
                if (!isSheet() || closing) return;
                stop(); y0 = e.clientY; dy = 0; hist = [[e.timeStamp, e.clientY]];
                h.setPointerCapture(e.pointerId);
            });
            h.addEventListener('pointermove', (e) => {
                if (y0 === null) return;
                const raw = e.clientY - y0;
                dy = raw < 0 ? -Math.sqrt(-raw) * 2 : raw; // ke atas: perlawanan seperti karet
                sz.style.transform = `translateY(${dy}px)`;
                hist.push([e.timeStamp, e.clientY]); if (hist.length > 5) hist.shift();
            });
            const end = (e) => {
                if (y0 === null) return;
                y0 = null;
                const a = hist[0], b = hist[hist.length - 1];
                const v = b[0] > a[0] ? (b[1] - a[1]) / (b[0] - a[0]) * 1000 : 0; // px/s
                const projected = dy + (v / 1000) * 0.998 / (1 - 0.998) * 0.25;
                if (e.type !== 'pointercancel' && projected > sz.offsetHeight * 0.4) {
                    closing = true;
                    sz.animate([{ transform: `translateY(${dy}px)` }, { transform: 'translateY(100%)' }], { duration: 240, easing: SPRING, fill: 'forwards' })
                        .finished.then(finishClose, finishClose);
                } else {
                    const back = sz.animate([{ transform: `translateY(${dy}px)` }, { transform: 'none' }], { duration: 380, easing: SPRING });
                    sz.style.transform = '';
                }
            };
            h.addEventListener('pointerup', end);
            h.addEventListener('pointercancel', end);
        });
    }

    render();
})();

// Halaman Access: switch rute dengan thumb berpegas, checklist langkah, dan salin alamat.
// Pegas bersifat kontinu: target baru selalu dimulai dari posisi dan kecepatan saat ini (bisa diinterupsi).
(() => {
    document.documentElement.classList.add('js');
    const reduce = matchMedia('(prefers-reduced-motion: reduce)');

    // Pegas kritis (damping 1): response = detik menuju target. Empat substep per frame supaya stabil.
    class Spring {
        constructor(value, apply, response = 0.35) { this.v = value; this.vel = 0; this.t = value; this.apply = apply; this.w = 2 * Math.PI / response; this.raf = 0; this.last = null; apply(value); }
        to(target) {
            this.t = target;
            if (reduce.matches) { this.v = target; this.vel = 0; this.apply(target); return; }
            if (!this.raf) { this.last = null; this.raf = requestAnimationFrame(t => this.frame(t)); }
        }
        frame(now) {
            if (this.last === null) this.last = now;
            const dt = Math.max(0, Math.min((now - this.last) / 1000, 1 / 30)); this.last = now;
            for (let i = 0; i < 4; i++) {
                const h = dt / 4;
                this.vel += (-this.w * this.w * (this.v - this.t) - 2 * this.w * this.vel) * h;
                this.v += this.vel * h;
            }
            this.apply(this.v);
            if (Math.abs(this.v - this.t) < 0.05 && Math.abs(this.vel) < 0.5) { this.v = this.t; this.vel = 0; this.apply(this.t); this.raf = 0; return; }
            this.raf = requestAnimationFrame(t => this.frame(t));
        }
    }

    const seg    = document.getElementById('ac-seg');
    const thumb  = seg.querySelector('.ac-thumb');
    const tabs   = [...seg.querySelectorAll('.ac-seg-btn')];
    const panels = [...document.querySelectorAll('.ac-panel')];

    const x = new Spring(0, v => { thumb.style.transform = `translateX(${v}px)`; }, 0.38);
    const w = new Spring(0, v => { thumb.style.width = v + 'px'; }, 0.38);
    let placed = false;

    function placeThumb() {
        const t = tabs.find(b => b.getAttribute('aria-selected') === 'true');
        const left = t.offsetLeft, width = t.offsetWidth;
        if (!placed) { x.v = x.t = left; w.v = w.t = width; x.apply(left); w.apply(width); placed = true; return; }   // posisi awal tanpa animasi
        x.to(left); w.to(width);
    }

    function select(route, { focus = false, push = true } = {}) {
        tabs.forEach(b => {
            const on = b.dataset.route === route;
            b.setAttribute('aria-selected', on);
            b.tabIndex = on ? 0 : -1;
            if (on && focus) b.focus();
        });
        panels.forEach(p => { p.hidden = p.dataset.route !== route; });
        placeThumb();
        if (push) { try { history.replaceState(null, '', '#' + route); } catch (e) {} }
    }

    tabs.forEach(b => b.addEventListener('click', () => select(b.dataset.route)));
    seg.addEventListener('keydown', e => {
        const i = tabs.findIndex(b => b.getAttribute('aria-selected') === 'true');
        const next = { ArrowRight: i + 1, ArrowLeft: i - 1, Home: 0, End: tabs.length - 1 }[e.key];
        if (next === undefined) return;
        e.preventDefault();
        select(tabs[(next + tabs.length) % tabs.length].dataset.route, { focus: true });
    });
    addEventListener('resize', () => { placed = false; placeThumb(); });

    const fromHash = location.hash.slice(1);
    select(tabs.some(b => b.dataset.route === fromHash) ? fromHash : tabs[0].dataset.route, { push: false });
    document.fonts && document.fonts.ready.then(() => { placed = false; placeThumb(); });   // lebar tombol berubah setelah font dimuat

    // Checklist per rute, disimpan di browser (opsional: tetap jalan tanpa localStorage)
    const store = {
        get(k) { try { return JSON.parse(localStorage.getItem(k) || '[]'); } catch (e) { return []; } },
        set(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} },
    };

    panels.forEach(panel => {
        const key   = 'ac-done-' + panel.dataset.route;
        const btns  = [...panel.querySelectorAll('.ac-step-btn')];
        const bar   = panel.querySelector('.ac-progress-bar i');
        const text  = panel.querySelector('.ac-progress-text');
        const reset = panel.querySelector('.ac-reset');
        const prog  = new Spring(0, v => { bar.style.transform = `scaleX(${v})`; }, 0.45);

        const done = new Set(store.get(key));
        function render() {
            btns.forEach(b => b.setAttribute('aria-pressed', done.has(+b.dataset.step)));
            text.textContent = `${done.size} of ${btns.length} done`;
            reset.hidden = done.size === 0;
            prog.to(done.size / btns.length);
        }
        btns.forEach(b => b.addEventListener('click', () => {
            const n = +b.dataset.step;
            done.has(n) ? done.delete(n) : done.add(n);
            store.set(key, [...done]);
            render();
        }));
        reset.addEventListener('click', () => { done.clear(); store.set(key, []); render(); });
        render();
    });

    // Masuk bertahap saat langkah terlihat
    const io = 'IntersectionObserver' in window ? new IntersectionObserver(entries => {
        entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } });
    }, { threshold: 0.15 }) : null;
    document.querySelectorAll('.ac-step').forEach(s => io ? io.observe(s) : s.classList.add('in'));

    // Salin alamat
    const copy = document.getElementById('ac-copy');
    copy.addEventListener('click', async () => {
        const text = document.getElementById('ac-address').textContent.trim();
        try { await navigator.clipboard.writeText(text); }
        catch (e) {
            const t = Object.assign(document.createElement('textarea'), { value: text });
            document.body.appendChild(t); t.select(); document.execCommand('copy'); t.remove();
        }
        copy.textContent = 'Copied';
        clearTimeout(copy._t);
        copy._t = setTimeout(() => { copy.textContent = copy.dataset.label; }, 1600);
    });
})();

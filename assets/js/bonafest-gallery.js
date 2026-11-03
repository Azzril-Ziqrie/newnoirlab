document.documentElement.classList.add('js');
// Data galeri disediakan halaman lewat window.GALLERY = [{name, poster, media, tag}]
const ARTISTS = window.GALLERY || [];
const reduce = matchMedia('(prefers-reduced-motion: reduce)');

// Masuk bertahap saat kartu terlihat
const io = new IntersectionObserver(es => es.forEach(e => { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } }), { rootMargin: '0px 0px -5% 0px' });
document.querySelectorAll('.ar-card').forEach(c => io.observe(c));

// Sheet: posisi y (px dari posisi terbuka) digerakkan spring yang bisa diinterupsi & mewarisi kecepatan
const sheet = document.getElementById('ar-sheet'), scrim = document.getElementById('ar-scrim'),
      bar = document.getElementById('ar-bar'), body = document.getElementById('ar-body');
let y = 0, raf = 0, current = -1, trigger = null;
const H = () => sheet.offsetHeight || innerHeight;

function setY(v) {
    y = v;
    sheet.style.transform = `translate3d(0, ${v}px, 0)`;
    scrim.style.opacity = Math.max(0, Math.min(1, 1 - v / H()));
}
function springTo(target, v0 = 0, damping = 1, response = .4, done) {
    cancelAnimationFrame(raf);
    if (reduce.matches) { setY(target); done && done(); return; }
    let pos = y, vel = v0, last = null;
    const w = 2 * Math.PI / response;
    const frame = t => {
        if (last === null) last = t;
        const dt = Math.max(0, Math.min((t - last) / 1000, 1 / 30)); last = t;
        for (let i = 0; i < 4; i++) {
            const h = dt / 4;
            vel += (-w * w * (pos - target) - 2 * damping * w * vel) * h;
            pos += vel * h;
        }
        setY(pos);
        if (Math.abs(pos - target) < .4 && Math.abs(vel) < 4) { setY(target); done && done(); return; }
        raf = requestAnimationFrame(frame);
    };
    raf = requestAnimationFrame(frame);
}

function render(i, animate) {
    const a = ARTISTS[current = (i + ARTISTS.length) % ARTISTS.length];
    const fill = () => {
        document.getElementById('ar-title').textContent = a.name;
        body.replaceChildren(); body.scrollTop = 0;
        (a.media.length ? a.media : [['img', a.poster]]).forEach(([type, src, poster]) => {
            const el = document.createElement(type === 'video' ? 'video' : 'img');
            el.src = src;
            if (type === 'video') { el.controls = true; el.playsInline = true; el.preload = 'none'; if (poster) el.poster = poster; }
            else el.alt = a.name;
            body.appendChild(el);
        });
        body.classList.remove('swap');
    };
    if (!animate || reduce.matches) return fill();
    body.classList.add('swap'); setTimeout(fill, 180);
}

function openArtist(i, from) {
    trigger = from || null;
    render(i, false);
    sheet.classList.add('open'); scrim.classList.add('open');
    document.body.style.overflow = 'hidden';
    if (!sheet.classList.contains('was')) setY(innerHeight);
    sheet.classList.add('was');
    springTo(0, 0, 1, .45);
    document.getElementById('ar-close').focus({ preventScroll: true });
}
function closeArtist(v0 = 0) {
    springTo(H(), v0, 1, .35, () => {
        body.querySelectorAll('video').forEach(v => v.pause());
        body.replaceChildren();
        sheet.classList.remove('open', 'was'); scrim.classList.remove('open');
        document.body.style.overflow = '';
        if (trigger) trigger.focus({ preventScroll: true });
    });
}

// Tarik turun dari bar: ikuti jari 1:1, rubber-band ke atas, lempar dengan proyeksi momentum
let drag = null;
const rubber = (o, d, c = .55) => (o * d * c) / (d + c * Math.abs(o));
bar.addEventListener('pointerdown', e => {
    if (e.target.closest('button')) return;
    cancelAnimationFrame(raf);
    bar.setPointerCapture(e.pointerId);
    drag = { startY: e.clientY, startPos: y, hist: [{ t: e.timeStamp, y: e.clientY }] };
});
bar.addEventListener('pointermove', e => {
    if (!drag) return;
    drag.hist.push({ t: e.timeStamp, y: e.clientY });
    if (drag.hist.length > 6) drag.hist.shift();
    const raw = drag.startPos + (e.clientY - drag.startY);
    setY(raw < 0 ? rubber(raw, H()) : raw);
});
const release = e => {
    if (!drag) return;
    const h = drag.hist, a = h[0], b = h[h.length - 1];
    const v = b.t > a.t ? ((b.y - a.y) / (b.t - a.t)) * 1000 : 0; // px/s
    drag = null;
    const projected = y + (v / 1000) * .998 / (1 - .998);
    if (projected > H() * .5) closeArtist(v); else springTo(0, v, .8, .35);
};
bar.addEventListener('pointerup', release);
bar.addEventListener('pointercancel', release);

document.querySelectorAll('.ar-card').forEach(b => b.addEventListener('click', () => openArtist(+b.dataset.i, b)));
document.getElementById('ar-close').addEventListener('click', () => closeArtist());
document.getElementById('ar-prev').addEventListener('click', () => render(current - 1, true));
document.getElementById('ar-next').addEventListener('click', () => render(current + 1, true));
scrim.addEventListener('click', () => closeArtist());
document.addEventListener('keydown', e => {
    if (!sheet.classList.contains('open')) return;
    if (e.key === 'Escape') closeArtist();
    else if (e.key === 'ArrowLeft') render(current - 1, true);
    else if (e.key === 'ArrowRight') render(current + 1, true);
});

// Drawer admin di layar kecil: buka/tutup, tutup dengan Esc atau tap overlay,
// dan geser ke kiri untuk menutup. Drawer mengikuti jari 1:1, lalu mendarat ke posisi yang
// diproyeksikan dari kecepatan lepas (bukan sekadar posisi lepas).
(() => {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    const toggle  = document.querySelector('.menu-toggle');
    if (!sidebar || !overlay || !toggle) return;

    const phone = matchMedia('(max-width: 860px)');
    const project = v => (v / 1000) * 0.998 / (1 - 0.998);   // px/s → jarak luncur, fungsi Apple

    const setOpen = open => {
        sidebar.classList.toggle('open', open);
        overlay.classList.toggle('open', open);
        toggle.setAttribute('aria-expanded', open);
        toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
        document.documentElement.style.overflow = open && phone.matches ? 'hidden' : '';
    };

    toggle.addEventListener('click', () => setOpen(!sidebar.classList.contains('open')));
    overlay.addEventListener('click', () => setOpen(false));
    addEventListener('keydown', e => { if (e.key === 'Escape') setOpen(false); });
    sidebar.addEventListener('click', e => { if (e.target.closest('a') && phone.matches) setOpen(false); });
    phone.addEventListener('change', () => setOpen(false));

    let startX = 0, startY = 0, dx = 0, track = [], dragging = false, decided = false;

    sidebar.addEventListener('pointerdown', e => {
        if (!phone.matches || !sidebar.classList.contains('open') || e.pointerType === 'mouse') return;
        startX = e.clientX; startY = e.clientY; dx = 0; track = [[e.timeStamp, e.clientX]]; dragging = true; decided = false;
    });
    sidebar.addEventListener('pointermove', e => {
        if (!dragging) return;
        const mx = e.clientX - startX, my = e.clientY - startY;
        if (!decided) {                                   // ambang ±10px sebelum memilih arah
            if (Math.hypot(mx, my) < 10) return;
            decided = true;
            if (Math.abs(my) > Math.abs(mx)) { dragging = false; return; }   // geser vertikal = scroll menu
            sidebar.setPointerCapture(e.pointerId);
            sidebar.classList.add('dragging');
        }
        dx = Math.min(0, mx);                             // tak bisa ditarik melewati tepi kanan
        sidebar.style.setProperty('--drag', dx + 'px');
        overlay.style.opacity = Math.max(0, 1 + dx / sidebar.offsetWidth);
        track.push([e.timeStamp, e.clientX]);
        if (track.length > 6) track.shift();
    });
    const end = e => {
        if (!dragging) return;
        dragging = false;
        if (!sidebar.classList.contains('dragging')) return;
        sidebar.classList.remove('dragging');
        const [t0, x0] = track[0], [t1, x1] = track[track.length - 1];
        const v = t1 > t0 ? (x1 - x0) / (t1 - t0) * 1000 : 0;
        const landing = dx + project(v);                  // titik berhenti yang diproyeksikan
        sidebar.style.removeProperty('--drag');
        overlay.style.removeProperty('opacity');
        setOpen(landing > -sidebar.offsetWidth * 0.4);
    };
    sidebar.addEventListener('pointerup', end);
    sidebar.addEventListener('pointercancel', end);
})();

// Perilaku bersama halaman publik: muncul halus saat scroll dan garis progres.
(() => {
    function revealOnScroll() {
        const observer = new IntersectionObserver((entries) => {
            entries.filter((entry) => entry.isIntersecting).forEach((entry) => {
                entry.target.classList.add('in');
                observer.unobserve(entry.target);
            });
        }, { rootMargin: '0px 0px -8% 0px', threshold: 0.05 });
        document.querySelectorAll('.rv').forEach((el) => observer.observe(el));
    }

    // Garis progres di atas halaman, satu rAF per frame scroll.
    function showScrollProgress() {
        const bar = document.getElementById('scroll-progress');
        if (!bar) return;
        let framePending = false;
        const update = () => {
            framePending = false;
            const scrollable = document.documentElement.scrollHeight - innerHeight;
            bar.style.transform = `scaleX(${scrollable > 0 ? Math.min(scrollY / scrollable, 1) : 0})`;
        };
        addEventListener('scroll', () => {
            if (framePending) return;
            framePending = true;
            requestAnimationFrame(update);
        }, { passive: true });
        update();
    }


    // Judul hero hidup terus tanpa bergeser: ketebalan huruf berubah. Satu kilap tebal berjalan pelan
    // dari kiri ke kanan, dan huruf dekat kursor (atau sentuhan) ikut menebal. Font mono, jadi lebar
    // huruf tetap dan baris tidak bergeser.
    function animateHeroTitle() {
        const title = document.querySelector('.hero-title');
        if (!title || matchMedia('(prefers-reduced-motion: reduce)').matches) return;

        const SWEEP_SPEED = 0.003;        // huruf per ms
        const SWEEP_PAUSE_LETTERS = 9;     // jeda sebelum kilap berikutnya
        const SWEEP_WIDTH = 2.2;           // lebar kilap, dalam huruf
        const GLINT_GRAY = 135;            // seberapa terang kilap perak (0 hitam pekat)
        const GLINT_STEP = 5;              // tingkat abu dibulatkan, supaya style jarang ditulis
        const BREATH_PERIOD_MS = 3200;     // satu napas penuh
        const BREATH_PHASE_STEP = 0.55;    // selisih fase antar huruf, membuat ombak ketebalan
        const WEIGHT_BREATH = 90;
        const WEIGHT_BASE = 540;
        const WEIGHT_SWEEP_BOOST = 120;
        const WEIGHT_CURSOR_BOOST = 220;
        const WEIGHT_MAX = 800;
        const CATCH_UP_MS = 90;            // makin kecil makin cepat mengejar target

        title.setAttribute('aria-label', title.textContent.trim().replace(/\s+/g, ' '));
        const letters = [];
        title.querySelectorAll('.line > span').forEach((word) => {
            const chars = Array.from(word.textContent);
            word.setAttribute('aria-hidden', 'true');
            word.innerHTML = chars.map((c) => `<i class="ch">${c}</i>`).join('');
            word.querySelectorAll('.ch').forEach((el, i) => letters.push({ el, i, weight: WEIGHT_BASE }));
        });
        const longestWord = Math.max(...letters.map((letter) => letter.i)) + 1;

        // Posisi layout (tidak terpengaruh transform) relatif terhadap judul.
        const offsetWithin = (el, ancestor) => {
            let x = 0;
            let y = 0;
            for (let node = el; node && node !== ancestor; node = node.offsetParent) {
                x += node.offsetLeft;
                y += node.offsetTop;
            }
            return { x, y };
        };
        let fontSize = 0;
        const centers = [];
        const measure = () => {
            fontSize = parseFloat(getComputedStyle(title).fontSize);
            letters.forEach((letter, k) => {
                const { x, y } = offsetWithin(letter.el, title);
                centers[k] = { x: x + letter.el.offsetWidth / 2, y: y + letter.el.offsetHeight / 2 };
            });
        };

        const pointer = { x: -1e4, y: -1e4 };
        addEventListener('pointermove', (event) => { pointer.x = event.clientX; pointer.y = event.clientY; }, { passive: true });
        addEventListener('pointerup', () => { pointer.x = pointer.y = -1e4; });
        document.documentElement.addEventListener('pointerleave', () => { pointer.x = pointer.y = -1e4; });

        // Posisi judul hanya dibaca ulang setelah scroll atau resize, bukan di setiap frame.
        let box = title.getBoundingClientRect();
        let boxStale = false;
        const markBoxStale = () => { boxStale = true; };
        addEventListener('scroll', markBoxStale, { passive: true });
        addEventListener('resize', markBoxStale);

        let running = false;
        let last = performance.now();
        const frame = (now) => {
            if (!running) return;
            const dt = Math.min(now - last, 64);
            last = now;
            if (boxStale) { box = title.getBoundingClientRect(); boxStale = false; }
            const localX = pointer.x - box.left;
            const localY = pointer.y - box.top;
            const sigma = Math.max(110, fontSize * 1.1);
            const follow = 1 - Math.exp(-dt / CATCH_UP_MS);
            const sweepAt = (now * SWEEP_SPEED) % (longestWord + SWEEP_PAUSE_LETTERS) - SWEEP_WIDTH;

            letters.forEach((letter, k) => {
                const dx = localX - centers[k].x;
                const dy = localY - centers[k].y;
                const near = Math.exp(-(dx * dx + dy * dy) / (2 * sigma * sigma));
                const sweep = Math.exp(-Math.pow(letter.i - sweepAt, 2) / (2 * SWEEP_WIDTH * SWEEP_WIDTH));

                const breath = Math.sin(now / BREATH_PERIOD_MS * 2 * Math.PI - letter.i * BREATH_PHASE_STEP);
                const target = WEIGHT_BASE + WEIGHT_BREATH * breath + WEIGHT_SWEEP_BOOST * sweep + WEIGHT_CURSOR_BOOST * near;
                letter.weight += (target - letter.weight) * follow;
                const rounded = Math.round(Math.min(letter.weight, WEIGHT_MAX));
                if (rounded !== letter.applied) {   // tidak ada perubahan, tidak ada kerja browser
                    letter.applied = rounded;
                    letter.el.style.fontWeight = rounded;
                }
                const gray = Math.round(GLINT_GRAY * sweep / GLINT_STEP) * GLINT_STEP;
                if (gray !== letter.gray) {
                    letter.gray = gray;
                    letter.el.style.color = `rgb(${gray},${gray},${gray})`;
                }
            });
            requestAnimationFrame(frame);
        };

        const setRunning = (value) => {
            if (value === running) return;
            running = value;
            if (running) { last = performance.now(); requestAnimationFrame(frame); }
        };

        let visible = true;
        let measured = false;
        const sync = () => setRunning(measured && visible && !document.hidden);
        new IntersectionObserver(([entry]) => { visible = entry.isIntersecting; sync(); }).observe(title);
        document.addEventListener('visibilitychange', sync);
        addEventListener('resize', measure);

        (document.fonts ? document.fonts.ready : Promise.resolve()).then(() => { measure(); measured = true; sync(); });
    }

    revealOnScroll();
    showScrollProgress();
    animateHeroTitle();
})();

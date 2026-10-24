// Grafik penjualan harian di dashboard admin. Data dibaca dari <script id="sales-data"> yang dibuat PHP.
(function () {
    const SVG_NS = 'http://www.w3.org/2000/svg';
    const INK = '#000000';
    const GRID = '#ececec';
    const MUTED = 'rgba(0,0,0,0.45)';
    const PLOT = { top: 12, right: 14, bottom: 26, left: 46 };
    const HEIGHT_DESKTOP = 240;
    const HEIGHT_MOBILE = 190;
    const MOBILE_WIDTH = 520;
    const Y_TICKS = 4;
    const X_LABELS_DESKTOP = 6;
    const X_LABELS_MOBILE = 4;
    const DRAW_MS = 900;

    const rupiah = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 });
    const compact = new Intl.NumberFormat('id-ID', { notation: 'compact', maximumFractionDigits: 1 });
    const shortDate = new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short' });
    const longDate = new Intl.DateTimeFormat('id-ID', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });

    const parseDay = (iso) => new Date(iso + 'T00:00:00');

    function svgEl(name, attrs = {}) {
        const el = document.createElementNS(SVG_NS, name);
        Object.entries(attrs).forEach(([key, value]) => el.setAttribute(key, value));
        return el;
    }

    // Batas atas sumbu Y yang tiap garisnya bulat: langkah 1, 2, 5 atau 10 dikali pangkat sepuluh.
    function niceCeiling(value) {
        if (value <= 0) return Y_TICKS;
        const rawStep = value / Y_TICKS;
        const magnitude = 10 ** Math.floor(Math.log10(rawStep));
        const step = [1, 2, 5, 10].find((candidate) => candidate * magnitude >= rawStep);
        return step * magnitude * Y_TICKS;
    }

    function buildScales(days, width, height) {
        const innerWidth = width - PLOT.left - PLOT.right;
        const innerHeight = height - PLOT.top - PLOT.bottom;
        const yMax = niceCeiling(Math.max(...days.map((day) => day.revenue)));
        const lastIndex = Math.max(days.length - 1, 1);
        return {
            yMax,
            x: (index) => PLOT.left + (index / lastIndex) * innerWidth,
            y: (value) => PLOT.top + innerHeight - (value / yMax) * innerHeight,
            baseline: PLOT.top + innerHeight,
            indexAt: (px) => Math.round(Math.min(Math.max((px - PLOT.left) / innerWidth, 0), 1) * lastIndex),
        };
    }

    function drawGrid(svg, scales, width) {
        for (let i = 0; i <= Y_TICKS; i++) {
            const value = (scales.yMax / Y_TICKS) * i;
            const y = scales.y(value);
            svg.append(svgEl('line', { x1: PLOT.left, x2: width - PLOT.right, y1: y, y2: y, stroke: GRID, 'stroke-width': 1 }));
            const label = svgEl('text', { x: PLOT.left - 8, y: y + 4, 'text-anchor': 'end', 'font-size': 11, fill: MUTED });
            label.textContent = value === 0 ? '0' : compact.format(value);
            svg.append(label);
        }
    }

    function drawDateLabels(svg, days, scales, width) {
        const count = Math.min(width < MOBILE_WIDTH ? X_LABELS_MOBILE : X_LABELS_DESKTOP, days.length);
        for (let i = 0; i < count; i++) {
            const index = count === 1 ? 0 : Math.round((i / (count - 1)) * (days.length - 1));
            const anchor = i === 0 ? 'start' : i === count - 1 ? 'end' : 'middle';
            const label = svgEl('text', { x: scales.x(index), y: scales.baseline + 18, 'text-anchor': anchor, 'font-size': 11, fill: MUTED });
            label.textContent = shortDate.format(parseDay(days[index].day));
            svg.append(label);
        }
    }

    function drawSeries(svg, days, scales) {
        const points = days.map((day, index) => `${scales.x(index).toFixed(1)},${scales.y(day.revenue).toFixed(1)}`);
        const first = scales.x(0).toFixed(1);
        const last = scales.x(days.length - 1).toFixed(1);

        const area = svgEl('path', {
            d: `M${first},${scales.baseline} L${points.join(' L')} L${last},${scales.baseline} Z`,
            fill: INK, 'fill-opacity': 0.07,
        });
        const line = svgEl('path', {
            d: `M${points.join(' L')}`, fill: 'none', stroke: INK, 'stroke-width': 2,
            'stroke-linejoin': 'round', 'stroke-linecap': 'round', pathLength: 1,
        });
        svg.append(area, line);
        animateDraw(area, line);
    }

    function animateDraw(area, line) {
        if (matchMedia('(prefers-reduced-motion: reduce)').matches || !line.animate) return;
        const options = { duration: DRAW_MS, easing: 'cubic-bezier(0.22, 1, 0.36, 1)' };
        line.style.strokeDasharray = 1;
        line.animate([{ strokeDashoffset: 1 }, { strokeDashoffset: 0 }], options);
        area.animate([{ opacity: 0 }, { opacity: 1 }], options);
    }

    // Titik penanda: cincin 2px berwarna permukaan supaya tetap jelas di atas garis.
    function createMarker() {
        return svgEl('circle', { r: 4.5, fill: INK, stroke: '#ffffff', 'stroke-width': 2, visibility: 'hidden', 'pointer-events': 'none' });
    }

    function tooltipHtml(day) {
        return `<strong>${longDate.format(parseDay(day.day))}</strong>`
            + `<span>${rupiah.format(day.revenue)}</span>`
            + `<small>${day.tickets} tiket, ${day.orders} order</small>`;
    }

    function attachHover(host, svg, days, scales, width) {
        const tooltip = host.querySelector('.chart-tooltip');
        const crosshair = svgEl('line', { y1: PLOT.top, y2: scales.baseline, stroke: INK, 'stroke-opacity': 0.25, 'stroke-width': 1, visibility: 'hidden', 'pointer-events': 'none' });
        const marker = createMarker();
        svg.append(crosshair, marker);

        const show = (event) => {
            const box = svg.getBoundingClientRect();
            const index = scales.indexAt(((event.clientX - box.left) / box.width) * width);
            const x = scales.x(index);
            const y = scales.y(days[index].revenue);
            crosshair.setAttribute('x1', x);
            crosshair.setAttribute('x2', x);
            marker.setAttribute('cx', x);
            marker.setAttribute('cy', y);
            [crosshair, marker].forEach((el) => el.setAttribute('visibility', 'visible'));

            tooltip.innerHTML = tooltipHtml(days[index]);
            tooltip.hidden = false;
            const left = (x / width) * box.width;
            const flip = left > box.width - tooltip.offsetWidth - 12;
            tooltip.style.left = `${flip ? left - tooltip.offsetWidth - 12 : left + 12}px`;
            tooltip.style.top = `${Math.max((y / svg.viewBox.baseVal.height) * box.height - tooltip.offsetHeight - 10, 0)}px`;
        };
        const hide = () => {
            tooltip.hidden = true;
            [crosshair, marker].forEach((el) => el.setAttribute('visibility', 'hidden'));
        };

        svg.addEventListener('pointermove', show);
        svg.addEventListener('pointerdown', show);
        svg.addEventListener('pointerleave', hide);
        svg.addEventListener('pointercancel', hide);
    }

    function renderTrend(host, days) {
        const width = Math.max(host.clientWidth, 280);
        const height = width < MOBILE_WIDTH ? HEIGHT_MOBILE : HEIGHT_DESKTOP;
        const scales = buildScales(days, width, height);

        const svg = svgEl('svg', { viewBox: `0 0 ${width} ${height}`, width: '100%', height, role: 'img', 'aria-label': trendSummary(days) });
        drawGrid(svg, scales, width);
        drawDateLabels(svg, days, scales, width);
        drawSeries(svg, days, scales);
        attachHover(host, svg, days, scales, width);

        host.querySelector('svg')?.remove();
        host.prepend(svg);
    }

    function trendSummary(days) {
        const total = days.reduce((sum, day) => sum + day.revenue, 0);
        return `Pendapatan harian ${shortDate.format(parseDay(days[0].day))} sampai ${shortDate.format(parseDay(days[days.length - 1].day))}, total ${rupiah.format(total)}`;
    }

    function renderTable(host, days) {
        const rows = days.filter((day) => day.orders > 0).reverse().map((day) =>
            `<tr><td>${longDate.format(parseDay(day.day))}</td><td>${day.orders}</td><td>${day.tickets}</td><td>${rupiah.format(day.revenue)}</td></tr>`
        );
        host.innerHTML = rows.length
            ? `<table><thead><tr><th>Tanggal</th><th>Order</th><th>Tiket</th><th>Pendapatan</th></tr></thead><tbody>${rows.join('')}</tbody></table>`
            : '<p class="chart-empty-note">Belum ada penjualan di periode ini.</p>';
    }

    function init() {
        const dataNode = document.getElementById('sales-data');
        const trend = document.getElementById('sales-trend');
        if (!dataNode || !trend) return;

        const { days } = JSON.parse(dataNode.textContent);
        if (!days.length) return;

        const draw = () => renderTrend(trend, days);
        draw();
        renderTable(document.getElementById('sales-table'), days);

        let lastWidth = trend.clientWidth;
        new ResizeObserver(() => {
            if (trend.clientWidth === lastWidth) return;
            lastWidth = trend.clientWidth;
            draw();
        }).observe(trend);
    }

    init();
})();

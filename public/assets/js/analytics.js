/**
 * Renders the daily-visits line chart on the admin Analytics page: two
 * series (total visits, unique visitors) with a shared crosshair, a
 * per-point tooltip, and keyboard-focusable hit targets so the same detail
 * is reachable without a pointer.
 */
(function () {
    const NS = 'http://www.w3.org/2000/svg';

    function fmt(n) {
        return n.toLocaleString();
    }

    function el(tag, attrs) {
        const e = document.createElementNS(NS, tag);
        for (const [k, v] of Object.entries(attrs)) e.setAttribute(k, v);
        return e;
    }

    /** Rounds up to a "clean" axis max (1/2/5/10 x a power of ten). */
    function niceCeiling(value) {
        if (value <= 10) return 10;
        const magnitude = Math.pow(10, Math.floor(Math.log10(value)));
        const normalized = value / magnitude;
        let niceNormalized = 10;
        if (normalized <= 1) niceNormalized = 1;
        else if (normalized <= 2) niceNormalized = 2;
        else if (normalized <= 5) niceNormalized = 5;
        return niceNormalized * magnitude;
    }

    function buildChart(container) {
        let points;
        try {
            points = JSON.parse(container.dataset.points || '[]');
        } catch (err) {
            points = [];
        }

        container.innerHTML = '';

        if (!points.length) {
            container.innerHTML = '<p class="field-hint">No visit data yet.</p>';
            return;
        }

        const rect = container.getBoundingClientRect();
        const width = Math.max(rect.width, 100);
        const height = rect.height || 220;
        const padding = { top: 16, right: 16, bottom: 24, left: 40 };
        const plotWidth = width - padding.left - padding.right;
        const plotHeight = height - padding.top - padding.bottom;

        const maxRaw = Math.max(1, ...points.map((p) => Math.max(p.total, p.unique_sessions)));
        const niceMax = niceCeiling(maxRaw);

        const stepX = points.length > 1 ? plotWidth / (points.length - 1) : 0;
        const xAt = (i) => padding.left + stepX * i;
        const yAt = (v) => padding.top + plotHeight * (1 - v / niceMax);

        const svg = el('svg', {
            viewBox: `0 0 ${width} ${height}`,
            role: 'img',
            'aria-label': 'Daily visits: total visits and unique visitors over time',
        });

        // Gridlines + axis labels: 0, 1/3, 2/3, max.
        [0, niceMax / 3, (niceMax / 3) * 2, niceMax].forEach((t) => {
            const y = yAt(t);
            svg.appendChild(el('line', { x1: padding.left, x2: width - padding.right, y1: y, y2: y, class: 'chart-gridline' }));
            const label = el('text', { x: padding.left - 8, y: y + 4, class: 'chart-axis-label', 'text-anchor': 'end' });
            label.textContent = fmt(Math.round(t));
            svg.appendChild(label);
        });

        const buildPath = (key) => points.map((p, i) => `${i === 0 ? 'M' : 'L'} ${xAt(i)} ${yAt(p[key])}`).join(' ');
        svg.appendChild(el('path', { d: buildPath('total'), class: 'chart-line chart-line-total' }));
        svg.appendChild(el('path', { d: buildPath('unique_sessions'), class: 'chart-line chart-line-unique' }));

        points.forEach((p, i) => {
            svg.appendChild(el('circle', { cx: xAt(i), cy: yAt(p.total), r: 4, class: 'chart-dot chart-dot-total' }));
            svg.appendChild(el('circle', { cx: xAt(i), cy: yAt(p.unique_sessions), r: 4, class: 'chart-dot chart-dot-unique' }));
        });

        const crosshair = el('line', { x1: 0, x2: 0, y1: padding.top, y2: height - padding.bottom, class: 'chart-crosshair' });
        svg.appendChild(crosshair);

        const firstLabel = el('text', { x: xAt(0), y: height - 6, class: 'chart-axis-label', 'text-anchor': 'start' });
        firstLabel.textContent = points[0].day;
        svg.appendChild(firstLabel);

        const lastLabel = el('text', { x: xAt(points.length - 1), y: height - 6, class: 'chart-axis-label', 'text-anchor': 'end' });
        lastLabel.textContent = points[points.length - 1].day;
        svg.appendChild(lastLabel);

        const tooltip = document.createElement('div');
        tooltip.className = 'chart-tooltip';

        function showTooltip(i) {
            const p = points[i];
            crosshair.setAttribute('x1', xAt(i));
            crosshair.setAttribute('x2', xAt(i));
            crosshair.style.opacity = '1';

            tooltip.textContent = '';

            const date = document.createElement('div');
            date.className = 'chart-tooltip-date';
            date.textContent = p.day;
            tooltip.appendChild(date);

            [
                ['Total visits', p.total, 'var(--chart-teal)'],
                ['Unique visitors', p.unique_sessions, 'var(--chart-violet)'],
            ].forEach(([name, value, color]) => {
                const row = document.createElement('div');
                row.className = 'chart-tooltip-row';

                const key = document.createElement('span');
                key.className = 'chart-tooltip-key';
                key.style.background = color;

                const val = document.createElement('span');
                val.className = 'chart-tooltip-value';
                val.textContent = fmt(value);

                const label = document.createElement('span');
                label.className = 'chart-tooltip-name';
                label.textContent = name;

                row.append(key, val, label);
                tooltip.appendChild(row);
            });

            tooltip.style.left = Math.min(Math.max(xAt(i) - 60, 0), width - 160) + 'px';
            tooltip.style.top = '0px';
            tooltip.classList.add('is-visible');
        }

        function hideTooltip() {
            tooltip.classList.remove('is-visible');
            crosshair.style.opacity = '0';
        }

        points.forEach((p, i) => {
            const hitWidth = points.length > 1 ? stepX : plotWidth;
            const hitX = i === 0 ? padding.left : xAt(i) - stepX / 2;
            const hit = el('rect', {
                x: hitX,
                y: padding.top,
                width: hitWidth,
                height: plotHeight,
                class: 'chart-hit',
                tabindex: '0',
                'aria-label': `${p.day}: ${fmt(p.total)} total visits, ${fmt(p.unique_sessions)} unique visitors`,
            });
            hit.addEventListener('pointerenter', () => showTooltip(i));
            hit.addEventListener('pointermove', () => showTooltip(i));
            hit.addEventListener('pointerleave', hideTooltip);
            hit.addEventListener('focus', () => showTooltip(i));
            hit.addEventListener('blur', hideTooltip);
            svg.appendChild(hit);
        });

        container.appendChild(svg);
        container.appendChild(tooltip);
    }

    function init() {
        document.querySelectorAll('#visits-chart').forEach(buildChart);
    }

    init();

    let resizeTimer;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(init, 150);
    });
})();

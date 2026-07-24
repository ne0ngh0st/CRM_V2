<script setup>
import { onMounted, onUnmounted, ref } from 'vue';

const svg = ref(null);

const COLS = 14;
const ROWS = 18;
const CELL = 56;
const WIDTH = COLS * CELL;
const HEIGHT = ROWS * CELL;
const HOVER_RADIUS = 165;

// Paleta oficial da marca (Tema.json): navy, teal, cyan + tints e um creme raro.
const PALETTE = [
    [15, 58, 105],   // navy  #0F3A69
    [0, 90, 111],    // teal  #005A6F
    [16, 84, 122],   // navy-teal mix
    [0, 138, 170],   // cyan escuro
    [0, 169, 206],   // cyan  #00A9CE
    [130, 205, 228], // cyan claro
    [243, 226, 192], // creme (raro)
];
const HOVER_COLOR = [243, 226, 192]; // creme
const CYAN = [0, 169, 206];

function lerpColor(a, b, t) {
    return [
        Math.round(a[0] + (b[0] - a[0]) * t),
        Math.round(a[1] + (b[1] - a[1]) * t),
        Math.round(a[2] + (b[2] - a[2]) * t),
    ];
}

function pickColor(t) {
    // t = 0 no canto âncora (superior direito), 1 no extremo oposto.
    const r = Math.random();
    if (t < 0.35) {
        if (r < 0.06) return PALETTE[6];
        if (r < 0.45) return PALETTE[5];
        if (r < 0.75) return PALETTE[4];
        if (r < 0.9) return PALETTE[3];
        return PALETTE[2];
    }
    if (t < 0.7) {
        if (r < 0.03) return PALETTE[6];
        if (r < 0.25) return PALETTE[4];
        if (r < 0.5) return PALETTE[3];
        if (r < 0.78) return PALETTE[2];
        return PALETTE[1];
    }
    if (r < 0.2) return PALETTE[2];
    if (r < 0.55) return PALETTE[1];
    return PALETTE[0];
}

let tris = [];
let rafId = null;
let mouse = { x: -9999, y: -9999, active: false };

function buildMosaic() {
    const ns = 'http://www.w3.org/2000/svg';
    const anchorX = WIDTH;
    const anchorY = 0;
    const maxDist = Math.sqrt(WIDTH * WIDTH + HEIGHT * HEIGHT);
    const animate = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    for (let r = 0; r < ROWS; r++) {
        for (let c = 0; c < COLS; c++) {
            const x0 = c * CELL, y0 = r * CELL, x1 = x0 + CELL, y1 = y0 + CELL;
            const TL = [x0, y0], TR = [x1, y0], BL = [x0, y1], BR = [x1, y1];

            // Orientação alternada da diagonal — o que dá a intricância.
            const flip = Math.random() < 0.5;
            const cells = flip
                ? [[TL, TR, BR], [TL, BR, BL]]
                : [[TL, TR, BL], [TR, BR, BL]];

            cells.forEach((pts) => {
                const cx = (pts[0][0] + pts[1][0] + pts[2][0]) / 3;
                const cy = (pts[0][1] + pts[1][1] + pts[2][1]) / 3;
                const dist = Math.sqrt((cx - anchorX) ** 2 + (cy - anchorY) ** 2);
                const t = Math.min(1, dist / maxDist);

                // Esmaece em direção ao texto (canto oposto à âncora).
                const fade = Math.max(0, 1 - t * 1.25);
                const baseOpacity = Math.min(1, fade * (0.9 + (Math.random() - 0.5) * 0.3));
                if (baseOpacity < 0.03) return;

                const base = pickColor(t);
                const baseFill = `rgb(${base[0]},${base[1]},${base[2]})`;

                const poly = document.createElementNS(ns, 'polygon');
                poly.setAttribute('points', pts.map((p) => p.join(',')).join(' '));
                poly.setAttribute('fill', baseFill);
                poly.setAttribute('opacity', baseOpacity.toFixed(3));
                poly.style.setProperty('--tri-opacity', baseOpacity.toFixed(3));
                poly.style.transformBox = 'fill-box';
                poly.style.transformOrigin = 'center';

                if (animate) {
                    // Brilho ambiente: CSS puro (roda no compositor, custo mínimo).
                    const dur = 7 + Math.random() * 4;
                    const delay = t * 4 + Math.random();
                    poly.style.animation = `mosaic-shimmer ${dur.toFixed(2)}s ease-in-out ${delay.toFixed(2)}s infinite`;
                }

                svg.value.appendChild(poly);
                tris.push({ el: poly, cx, cy, base, baseFill, influence: 0 });
            });
        }
    }
}

function onMouseMove(e) {
    if (!svg.value) return;
    // getScreenCTM converte pixel-da-tela -> coord do viewBox respeitando o
    // preserveAspectRatio="slice" (escala + corte). Conversão linear não serve aqui.
    const ctm = svg.value.getScreenCTM();
    if (!ctm) return;
    const pt = svg.value.createSVGPoint();
    pt.x = e.clientX;
    pt.y = e.clientY;
    const p = pt.matrixTransform(ctm.inverse());
    if (p.x < 0 || p.y < 0 || p.x > WIDTH || p.y > HEIGHT) {
        mouse.active = false;
    } else {
        mouse.x = p.x;
        mouse.y = p.y;
        mouse.active = true;
    }
    // Liga o loop de hover só quando há interação (poupa CPU no idle).
    if (rafId === null) rafId = requestAnimationFrame(tick);
}

function tick() {
    let stillActive = false;

    for (let i = 0; i < tris.length; i++) {
        const tr = tris[i];

        let target = 0;
        if (mouse.active) {
            const dist = Math.sqrt((tr.cx - mouse.x) ** 2 + (tr.cy - mouse.y) ** 2);
            const raw = Math.max(0, 1 - dist / HOVER_RADIUS);
            target = raw * raw * (3 - 2 * raw);
        }
        // Subida mais rápida que a descida = rastro suave que desacende devagar.
        const ease = target > tr.influence ? 0.14 : 0.055;
        tr.influence += (target - tr.influence) * ease;

        if (tr.influence > 0.002) {
            stillActive = true;
            const warm = lerpColor(CYAN, HOVER_COLOR, tr.influence);
            const col = lerpColor(tr.base, warm, tr.influence * 0.85);
            tr.el.setAttribute('fill', `rgb(${col[0]},${col[1]},${col[2]})`);
            tr.el.style.transform = `scale(${(1 + tr.influence * 0.32).toFixed(3)})`;
        } else if (tr.influence !== 0) {
            tr.influence = 0;
            tr.el.setAttribute('fill', tr.baseFill);
            tr.el.style.transform = '';
        }
    }

    if (stillActive || mouse.active) {
        rafId = requestAnimationFrame(tick);
    } else {
        rafId = null;
    }
}

onMounted(() => {
    buildMosaic();
    window.addEventListener('mousemove', onMouseMove);
});

onUnmounted(() => {
    if (rafId) cancelAnimationFrame(rafId);
    window.removeEventListener('mousemove', onMouseMove);
});
</script>

<template>
    <div class="pointer-events-none absolute inset-0 overflow-hidden">
        <svg
            ref="svg"
            :viewBox="`0 0 ${WIDTH} ${HEIGHT}`"
            preserveAspectRatio="xMaxYMin slice"
            class="absolute inset-0 h-full w-full"
            role="img"
            aria-hidden="true"
        >
            <title>Mosaico decorativo de triângulos</title>
        </svg>
    </div>
</template>

<style>
@keyframes mosaic-shimmer {
    0%, 100% { opacity: var(--tri-opacity); }
    50% { opacity: calc(var(--tri-opacity) * 1.28 + 0.05); }
}
</style>

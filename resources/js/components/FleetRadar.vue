<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ServiceController from '@/actions/App/Http/Controllers/ServiceController';
import { statusMeta, statusOrder } from '@/lib/status';
import type { MonitoredService, Status } from '@/types';

/**
 * An ASCII radar of the fleet, after ../ascii-sharks: a canvas cell grid, glyph ramps for light,
 * pointer play, paused off-screen and a still frame for reduced motion. Each service is a blip,
 * closer to the centre the worse its status; the sweep lights blips up, and red ones kick the
 * heartbeat line, the same one as in the logo.
 */
const props = defineProps<{ services: MonitoredService[] }>();

const canvas = ref<HTMLCanvasElement>();
const hovered = ref<number | null>(null);

const RAMP = ' .:-=+*#%@';
const FONT_SIZE = 11;
const CELL_H = 12;
const TURN = 4; // seconds per sweep
const BAND: Record<Status, number> = {
    red: 0.28,
    yellow: 0.52,
    green: 0.74,
    unknown: 0.92,
};
const GLYPH: Record<Status, string> = {
    red: '@',
    yellow: '*',
    green: 'o',
    unknown: '?',
};
const CSS_VAR: Record<Status, string> = {
    red: '--status-critical',
    yellow: '--status-warning',
    green: '--status-ok',
    unknown: '--status-unknown',
};

function hash(a: number, b = 0, c = 0): number {
    let h = (a * 374761393 + b * 668265263 + c * 2147483647) | 0;
    h = Math.imul(h ^ (h >>> 13), 1274126177);

    return ((h ^ (h >>> 16)) >>> 0) / 4294967295;
}

const blips = computed(() =>
    props.services.map((service) => ({
        id: service.id,
        name: service.name,
        status: service.status,
        angle: hash(service.id, 7) * Math.PI * 2,
        radius: BAND[service.status] + (hash(service.id, 3) - 0.5) * 0.12,
        advisories: service.dependencies.reduce(
            (sum, dependency) =>
                sum +
                dependency.advisories.filter((advisory) => !advisory.accepted)
                    .length,
            0,
        ),
    })),
);

const counts = computed(
    () =>
        Object.fromEntries(
            statusOrder.map((status) => [
                status,
                props.services.filter((service) => service.status === status)
                    .length,
            ]),
        ) as Record<Status, number>,
);

const summary = computed(
    () =>
        `Fleet radar: ${statusOrder
            .map(
                (status) =>
                    `${counts.value[status]} ${statusMeta[status].label.toLowerCase()}`,
            )
            .join(
                ', ',
            )}. Closer to the centre means worse. Click a blip to open that service.`,
);

let ctx: CanvasRenderingContext2D | null = null;
let width = 0;
let height = 0;
let cellW = 7;
let raf = 0;
let last = 0;
let time = 0;
let visible = true;
let colors = {
    ink: '#2b2b2b',
    muted: '#706d68',
    brand: '#3b71ca',
    status: {} as Record<Status, string>,
};
const pointer = { x: -1, y: -1, active: false };
const lit = new Map<number, number>(); // service id -> time it was last lit
const pings: { x: number; y: number; at: number }[] = [];
let ecg: number[] = [];
let beat = 0;
let ecgClock = 0;
let samples = 0;
let contact: number | null = null;
let passes = 0;

const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

function readColors() {
    const style = getComputedStyle(document.documentElement);
    const read = (name: string) => style.getPropertyValue(name).trim();
    colors = {
        ink: read('--foreground'),
        muted: read('--muted-foreground'),
        brand: read('--brand'),
        status: Object.fromEntries(
            statusOrder.map((status) => [status, read(CSS_VAR[status])]),
        ) as Record<Status, string>,
    };
}

function geometry() {
    const r = Math.max(24, height / 2 - 10);

    return {
        cx: r + 12,
        cy: height / 2,
        r,
        readout: Math.ceil((2 * r + 18) / cellW),
    };
}

function blipPosition(blip: (typeof blips.value)[number]) {
    const { cx, cy, r } = geometry();

    return {
        x: cx + Math.cos(blip.angle) * blip.radius * r,
        y: cy + Math.sin(blip.angle) * blip.radius * r,
    };
}

function resize() {
    const el = canvas.value;

    if (!el || !ctx) {
        return;
    }

    const rect = el.getBoundingClientRect();
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    width = rect.width;
    height = rect.height;
    el.width = Math.round(width * dpr);
    el.height = Math.round(height * dpr);
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.font = `${FONT_SIZE}px ${getComputedStyle(el).fontFamily}`;
    ctx.textBaseline = 'middle';
    cellW = ctx.measureText('M').width || 7;
    draw();
}

function glyph(
    text: string,
    col: number,
    row: number,
    color: string,
    alpha: number,
) {
    ctx!.globalAlpha = Math.min(1, alpha);
    ctx!.fillStyle = color;
    ctx!.fillText(text, col * cellW, row * CELL_H + CELL_H / 2);
}

/** Cut text to what fits right of a column. */
function fit(text: string, col: number): string {
    return text.slice(0, Math.max(0, Math.floor(width / cellW) - col));
}

function step(dt: number) {
    const before = (time / TURN) * Math.PI * 2;
    time += dt;
    const sweep = (time / TURN) * Math.PI * 2;
    passes = Math.floor(time / TURN);

    for (const blip of blips.value) {
        // Did the beam cross this blip during the step?
        const crossed =
            Math.floor((sweep - blip.angle) / (Math.PI * 2)) !==
            Math.floor((before - blip.angle) / (Math.PI * 2));

        if (crossed) {
            lit.set(blip.id, time);

            if (blip.status !== 'green' && blip.status !== 'unknown') {
                contact = blip.id;
            }

            if (blip.status === 'red') {
                beat = 1;
            }
        }
    }

    // Heartbeat, 20 samples a second: a beat every second, a tall one after the sweep hits a red service.
    const cols = Math.max(0, Math.floor(width / cellW) - geometry().readout);
    ecgClock += dt;

    while (ecgClock >= 0.05) {
        ecgClock -= 0.05;
        const k = samples++ % 20;
        ecg.push([0, 1, beat > 0 ? 3 : 2, 0, -1][samples++ % 20] ?? 0);
    }

    beat = Math.max(0, beat - dt);

    if (ecg.length > cols) {
        ecg = ecg.slice(ecg.length - cols);
    }

    for (let i = pings.length - 1; i >= 0; i--) {
        if (time - pings[i].at > 1.2) {
            pings.splice(i, 1);
        }
    }
}

function draw() {
    if (!ctx || width < 1) {
        return;
    }

    ctx.clearRect(0, 0, width, height);
    const { cx, cy, r, readout } = geometry();
    const sweep = (time / TURN) * Math.PI * 2;
    const rows = Math.floor(height / CELL_H);
    const firstCol = Math.floor((cx - r) / cellW);
    const lastCol = Math.ceil((cx + r) / cellW);
    const tick = Math.floor(time * 8);

    // 1. The scope: rings, crosshair, phosphor trail behind the sweep, and the pointer's lens.
    for (let row = 0; row < rows; row++) {
        for (let col = firstCol; col <= lastCol; col++) {
            const x = (col + 0.5) * cellW;
            const y = (row + 0.5) * CELL_H;
            const dx = x - cx;
            const dy = y - cy;
            const d = Math.hypot(dx, dy) / r;

            if (d > 1.04) {
                continue;
            }

            const behind =
                (((sweep - Math.atan2(dy, dx)) % (Math.PI * 2)) + Math.PI * 2) %
                (Math.PI * 2);
            let light =
                d <= 1
                    ? Math.exp(-behind * 2.2) *
                      (0.7 + 0.3 * hash(col, row, tick))
                    : 0;
            const lens = pointer.active
                ? Math.max(0, 1 - Math.hypot(x - pointer.x, y - pointer.y) / 26)
                : 0;
            light = Math.max(light, lens * 0.6);

            for (const ping of pings) {
                const wave = Math.abs(
                    Math.hypot(x - ping.x, y - ping.y) - (time - ping.at) * 140,
                );
                light = Math.max(
                    light,
                    wave < 6
                        ? (1 - wave / 6) * (1 - (time - ping.at) / 1.2)
                        : 0,
                );
            }

            if (light > 0.06) {
                glyph(
                    RAMP[
                        Math.min(
                            RAMP.length - 1,
                            Math.floor(light * RAMP.length),
                        )
                    ],
                    col,
                    row,
                    colors.brand,
                    0.25 + light,
                );
            } else if (
                Math.abs(d - 1) < CELL_H / (2 * r) ||
                [0.25, 0.5, 0.75].some((ring) => Math.abs(d - ring) < 0.035)
            ) {
                glyph(d > 0.98 ? ':' : '.', col, row, colors.muted, 0.55);
            } else if (Math.abs(dx) < cellW / 2 || Math.abs(dy) < CELL_H / 2) {
                glyph(
                    Math.abs(dx) < cellW / 2
                        ? Math.abs(dy) < CELL_H / 2
                            ? '+'
                            : '|'
                        : '-',
                    col,
                    row,
                    colors.muted,
                    0.3,
                );
            }
        }
    }

    // 2. Blips: lit by the sweep and fading over a turn; red ones ripple.
    for (const blip of blips.value) {
        const { x, y } = blipPosition(blip);
        const col = Math.floor(x / cellW);
        const row = Math.floor(y / CELL_H);
        const glow = reducedMotion.matches
            ? 1
            : Math.exp(-(time - (lit.get(blip.id) ?? -99)) / (TURN * 0.6));
        const isHovered = hovered.value === blip.id;
        const color = colors.status[blip.status];

        if (blip.status === 'red' && !reducedMotion.matches) {
            const ripple = ((time * 1.5 + hash(blip.id)) % 1) * 3 + 1;

            for (let a = 0; a < 12; a++) {
                const angle = (a / 12) * Math.PI * 2;
                glyph(
                    '.',
                    Math.floor((x + Math.cos(angle) * ripple * cellW) / cellW),
                    Math.floor(
                        (y + Math.sin(angle) * ripple * CELL_H * 0.8) / CELL_H,
                    ),
                    color,
                    0.9 - ripple / 4,
                );
            }
        }

        glyph(GLYPH[blip.status], col, row, color, 0.45 + glow);

        if (isHovered) {
            glyph('[', col - 1, row, colors.ink, 1);
            glyph(']', col + 1, row, colors.ink, 1);
        }

        if (isHovered || glow > 0.35) {
            const label = blip.name.slice(
                0,
                Math.max(0, Math.min(14, readout - col - 3)),
            );
            // Knock the scope out behind the name so it stays readable.
            ctx.clearRect(
                (col + 2) * cellW,
                row * CELL_H,
                label.length * cellW,
                CELL_H,
            );
            glyph(label, col + 2, row, colors.ink, isHovered ? 1 : glow);
        }
    }

    // 3. Readout: what the scope shows, in words.
    const left = readout;
    const barCells = Math.max(
        4,
        Math.min(12, Math.floor(width / cellW) - left - 14),
    );
    const total = Math.max(1, props.services.length);
    const degrees = String(
        Math.floor(((sweep * 180) / Math.PI) % 360),
    ).padStart(3, '0');
    glyph(
        fit(`fleet scan · ${props.services.length} svc`, left),
        left,
        0,
        colors.ink,
        1,
    );
    glyph(
        `sweep ${degrees}° · pass ${String(passes).padStart(3, '0')}`,
        left,
        1,
        colors.muted,
        1,
    );

    statusOrder.forEach((status, index) => {
        const filled = Math.round((counts.value[status] / total) * barCells);
        const row = 3 + index;
        glyph(GLYPH[status], left, row, colors.status[status], 1);
        glyph(status.padEnd(8), left + 2, row, colors.ink, 1);
        glyph('#'.repeat(filled), left + 10, row, colors.status[status], 1);
        glyph(
            '.'.repeat(barCells - filled),
            left + 10 + filled,
            row,
            colors.muted,
            0.6,
        );
        glyph(
            String(counts.value[status]).padStart(3),
            left + 10 + barCells,
            row,
            colors.ink,
            1,
        );
    });

    const focus = blips.value.find(
        (blip) => blip.id === (hovered.value ?? contact),
    );

    if (focus) {
        glyph('>', left, 8, colors.status[focus.status], 1);
        glyph(
            fit(
                `${focus.name.slice(0, 12)} ${focus.status}${focus.advisories ? ` · ${focus.advisories} adv` : ''}`,
                left + 2,
            ),
            left + 2,
            8,
            colors.ink,
            1,
        );
    } else {
        glyph(fit('> no contacts, all clear', left), left, 8, colors.muted, 1);
    }

    glyph(
        fit(
            hovered.value !== null
                ? '  click to open'
                : '  click the scope to ping',
            left,
        ),
        left,
        9,
        colors.muted,
        0.8,
    );

    // 4. Heartbeat along the bottom.
    const base = rows - 2;
    ecg.forEach((value, index) => {
        const previous = ecg[index - 1] ?? 0;
        const color =
            Math.max(value, previous) > 2 ? colors.status.red : colors.brand;

        if (value === previous) {
            glyph('-', left + index, base - value, color, 0.9);

            return;
        }

        // One slash per row climbed, so the beat reads as one connected line.
        const up = value > previous;

        for (
            let v = up ? previous + 1 : previous - 1;
            up ? v <= value : v >= value;
            v += up ? 1 : -1
        ) {
            glyph(
                up ? '/' : '\\',
                left + index,
                base - (up ? v : v + 1),
                color,
                0.9,
            );
        }
    });

    ctx.globalAlpha = 1;
}

function frame(now: number) {
    const dt = Math.min(0.1, (now - last) / 1000);
    last = now;
    step(dt);
    draw();
    raf = requestAnimationFrame(frame);
}

function schedule() {
    cancelAnimationFrame(raf);

    if (!visible || document.hidden || reducedMotion.matches) {
        if (reducedMotion.matches) {
            // Still frame: sweep parked, every blip lit, a flat heartbeat.
            time = TURN * 0.3;
            ecg = Array(80).fill(0);
            draw();
        }

        return;
    }

    last = performance.now();
    raf = requestAnimationFrame(frame);
}

function local(event: PointerEvent) {
    const rect = canvas.value!.getBoundingClientRect();

    return { x: event.clientX - rect.left, y: event.clientY - rect.top };
}

function onMove(event: PointerEvent) {
    const { x, y } = local(event);
    pointer.x = x;
    pointer.y = y;
    pointer.active = event.pointerType !== 'touch';
    const near = blips.value
        .map((blip) => ({
            blip,
            distance: Math.hypot(
                blipPosition(blip).x - x,
                blipPosition(blip).y - y,
            ),
        }))
        .sort((a, b) => a.distance - b.distance)[0];
    hovered.value = near && near.distance < 14 ? near.blip.id : null;

    if (reducedMotion.matches) {
        draw();
    }
}

function onLeave() {
    pointer.active = false;
    hovered.value = null;
}

function onClick(event: PointerEvent) {
    if (hovered.value !== null) {
        router.visit(ServiceController.show(hovered.value));

        return;
    }

    const { x, y } = local(event);
    const { cx, cy, r } = geometry();

    if (Math.hypot(x - cx, y - cy) <= r && !reducedMotion.matches) {
        pings.push({ x, y, at: time });

        // The ping lights up whatever it reaches, like a sonar ping.
        for (const blip of blips.value) {
            const position = blipPosition(blip);
            lit.set(
                blip.id,
                Math.max(
                    lit.get(blip.id) ?? -99,
                    time + Math.hypot(position.x - x, position.y - y) / 140,
                ),
            );
        }
    }
}

let resizeObserver: ResizeObserver;
let intersectionObserver: IntersectionObserver;
let themeObserver: MutationObserver;

function onVisibility() {
    schedule();
}

onMounted(() => {
    ctx = canvas.value!.getContext('2d');
    readColors();
    resizeObserver = new ResizeObserver(resize);
    resizeObserver.observe(canvas.value!);
    intersectionObserver = new IntersectionObserver(([entry]) => {
        visible = entry.isIntersecting;
        schedule();
    });
    intersectionObserver.observe(canvas.value!);
    // Light and dark mode swap the tokens on <html>.
    themeObserver = new MutationObserver(() => {
        readColors();
        draw();
    });
    themeObserver.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['class', 'data-theme'],
    });
    reducedMotion.addEventListener('change', schedule);
    document.addEventListener('visibilitychange', onVisibility);
    // Canvas text can't trigger a webfont load, so redraw once Courier Prime is in.
    document.fonts?.ready.then(resize);
    schedule();
});

onBeforeUnmount(() => {
    cancelAnimationFrame(raf);
    resizeObserver.disconnect();
    intersectionObserver.disconnect();
    themeObserver.disconnect();
    reducedMotion.removeEventListener('change', schedule);
    document.removeEventListener('visibilitychange', onVisibility);
});

watch(blips, () => draw());
</script>

<template>
    <div role="img" :aria-label="summary" class="relative">
        <canvas
            ref="canvas"
            class="block size-full font-mono select-none"
            :class="hovered !== null ? 'cursor-pointer' : 'cursor-crosshair'"
            @pointermove="onMove"
            @pointerleave="onLeave"
            @pointerup="onClick"
        />
    </div>
</template>

<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import {
    CELL_H,
    GLYPH,
    hash,
    useAsciiCanvas,
} from '@/composables/useAsciiCanvas';
import { statusMeta, statusOrder } from '@/lib/status';
import type { Status } from '@/types';

export type RadarBlip = {
    id: number;
    name: string;
    status: Status;
    /** Extra words for the readout, e.g. "3 adv". */
    detail?: string;
    /** Where a click goes; http(s) links open in a new tab. */
    href?: string;
};

/**
 * An ASCII radar, after ../ascii-sharks: a canvas cell grid, glyph ramps for light, pointer play,
 * paused off-screen and a still frame for reduced motion. Each item is a blip, closer to the
 * centre the worse its status; the sweep lights blips up, and red ones kick the heartbeat line,
 * the same one as in the logo.
 */
const props = defineProps<{ blips: RadarBlip[] }>();

const canvas = ref<HTMLCanvasElement>();
const hovered = ref<number | null>(null);

const RAMP = ' .:-=+*#%@';
const PAD = 2; // empty columns at the left and right edges
const GAP = 4; // columns between the scope and the readout
const TURN = 4; // seconds per sweep
const BAND: Record<Status, number> = {
    red: 0.28,
    yellow: 0.52,
    green: 0.74,
    unknown: 0.92,
};

const blips = computed(() =>
    props.blips.map((blip) => ({
        ...blip,
        angle: hash(blip.id, 7) * Math.PI * 2,
        radius: BAND[blip.status] + (hash(blip.id, 3) - 0.5) * 0.12,
    })),
);

const target = computed(
    () => blips.value.find((blip) => blip.id === hovered.value)?.href,
);

const counts = computed(
    () =>
        Object.fromEntries(
            statusOrder.map((status) => [
                status,
                props.blips.filter((blip) => blip.status === status).length,
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
            .join(', ')}. Closer to the centre means worse.${
            props.blips.some((blip) => blip.href)
                ? ' Click a blip to open it.'
                : ''
        }`,
);

const pointer = { x: -1, y: -1, active: false };
const lit = new Map<number, number>(); // service id -> time it was last lit
const pings: { x: number; y: number; at: number }[] = [];
let ecg: number[] = [];
let beat = 0;
let ecgClock = 0;
let samples = 0;
let contact: number | null = null;
let passes = 0;

let time = 0;

/** The scope is centred on a cell, so the rings and crosshair come out symmetric on the grid. */
function geometry() {
    const rows = Math.floor(g.height / CELL_H);
    const centreRow = Math.floor(rows / 2);
    const r = Math.max(24, Math.min((centreRow - 1) * CELL_H, g.width * 0.28));
    const centreCol = PAD + Math.ceil(r / g.cellW);

    return {
        cx: (centreCol + 0.5) * g.cellW,
        cy: (centreRow + 0.5) * CELL_H,
        r,
        rows,
        readout: centreCol + Math.ceil(r / g.cellW) + GAP,
    };
}

/** Columns that can hold text, leaving the right-hand padding. */
function usableCols(): number {
    return Math.floor(g.width / g.cellW) - PAD;
}

function blipPosition(blip: (typeof blips.value)[number]) {
    const { cx, cy, r } = geometry();

    return {
        x: cx + Math.cos(blip.angle) * blip.radius * r,
        y: cy + Math.sin(blip.angle) * blip.radius * r,
    };
}

/** Cut text to what fits right of a column. */
function fit(text: string, col: number): string {
    return text.slice(0, Math.max(0, usableCols() - col));
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
    const cols = Math.max(0, usableCols() - geometry().readout);
    ecgClock += dt;

    while (ecgClock >= 0.05) {
        ecgClock -= 0.05;
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

function render() {
    const { cx, cy, r, rows, readout } = geometry();
    const sweep = (time / TURN) * Math.PI * 2;
    const firstCol = Math.floor((cx - r) / g.cellW);
    const lastCol = Math.ceil((cx + r) / g.cellW);
    const tick = Math.floor(time * 8);

    // 1. The scope: rings, crosshair, phosphor trail behind the sweep, and the pointer's lens.
    for (let row = 0; row < rows; row++) {
        for (let col = firstCol; col <= lastCol; col++) {
            const x = (col + 0.5) * g.cellW;
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
                g.glyph(
                    RAMP[
                        Math.min(
                            RAMP.length - 1,
                            Math.floor(light * RAMP.length),
                        )
                    ],
                    col,
                    row,
                    g.colors.brand,
                    0.25 + light,
                );
            } else if (
                Math.abs(d - 1) < CELL_H / (2 * r) ||
                [0.25, 0.5, 0.75].some((ring) => Math.abs(d - ring) < 0.035)
            ) {
                g.glyph(d > 0.98 ? ':' : '.', col, row, g.colors.muted, 0.55);
            } else if (
                Math.abs(dx) < g.cellW / 2 ||
                Math.abs(dy) < CELL_H / 2
            ) {
                g.glyph(
                    Math.abs(dx) < g.cellW / 2
                        ? Math.abs(dy) < CELL_H / 2
                            ? '+'
                            : '|'
                        : '-',
                    col,
                    row,
                    g.colors.muted,
                    0.3,
                );
            }
        }
    }

    // 2. Blips: lit by the sweep and fading over a turn; red ones ripple.
    for (const blip of blips.value) {
        const { x, y } = blipPosition(blip);
        const col = Math.floor(x / g.cellW);
        const row = Math.floor(y / CELL_H);
        const glow = reducedMotion.matches
            ? 1
            : Math.exp(-(time - (lit.get(blip.id) ?? -99)) / (TURN * 0.6));
        const isHovered = hovered.value === blip.id;
        const color = g.colors.status[blip.status];

        if (blip.status === 'red' && !reducedMotion.matches) {
            const ripple = ((time * 1.5 + hash(blip.id)) % 1) * 3 + 1;

            for (let a = 0; a < 12; a++) {
                const angle = (a / 12) * Math.PI * 2;
                g.glyph(
                    '.',
                    Math.floor(
                        (x + Math.cos(angle) * ripple * g.cellW) / g.cellW,
                    ),
                    Math.floor(
                        (y + Math.sin(angle) * ripple * CELL_H * 0.8) / CELL_H,
                    ),
                    color,
                    0.9 - ripple / 4,
                );
            }
        }

        g.glyph(GLYPH[blip.status], col, row, color, 0.45 + glow);

        if (isHovered) {
            g.glyph('[', col - 1, row, g.colors.ink, 1);
            g.glyph(']', col + 1, row, g.colors.ink, 1);
        }

        if (isHovered || glow > 0.35) {
            const label = blip.name.slice(
                0,
                Math.max(0, Math.min(14, readout - col - 3)),
            );
            // Knock the scope out behind the name so it stays readable.
            g.clear(col + 2, row, label.length);
            g.glyph(label, col + 2, row, g.colors.ink, isHovered ? 1 : glow);
        }
    }

    // 3. Readout: what the scope shows, in words.
    const left = readout;
    const barCells = Math.max(4, Math.min(12, usableCols() - left - 14));
    const total = Math.max(1, props.blips.length);
    const degrees = String(
        Math.floor(((sweep * 180) / Math.PI) % 360),
    ).padStart(3, '0');
    g.glyph(
        fit(`fleet scan · ${props.blips.length} svc`, left),
        left,
        1,
        g.colors.ink,
        1,
    );
    g.glyph(
        `sweep ${degrees}° · pass ${String(passes).padStart(3, '0')}`,
        left,
        2,
        g.colors.muted,
        1,
    );

    statusOrder.forEach((status, index) => {
        const filled = Math.round((counts.value[status] / total) * barCells);
        const row = 4 + index;
        g.glyph(GLYPH[status], left, row, g.colors.status[status], 1);
        g.glyph(status.padEnd(8), left + 2, row, g.colors.ink, 1);
        g.glyph('#'.repeat(filled), left + 10, row, g.colors.status[status], 1);
        g.glyph(
            '.'.repeat(barCells - filled),
            left + 10 + filled,
            row,
            g.colors.muted,
            0.6,
        );
        g.glyph(
            String(counts.value[status]).padStart(3),
            left + 10 + barCells,
            row,
            g.colors.ink,
            1,
        );
    });

    const focus = blips.value.find(
        (blip) => blip.id === (hovered.value ?? contact),
    );

    if (focus) {
        g.glyph('>', left, 9, g.colors.status[focus.status], 1);
        g.glyph(
            fit(
                `${focus.name.slice(0, 12)} ${focus.status}${focus.detail ? ` · ${focus.detail}` : ''}`,
                left + 2,
            ),
            left + 2,
            9,
            g.colors.ink,
            1,
        );
    } else {
        g.glyph(
            fit('> no contacts, all clear', left),
            left,
            9,
            g.colors.muted,
            1,
        );
    }

    g.glyph(
        fit(
            target.value ? '  click to open' : '  click the scope to ping',
            left,
        ),
        left,
        10,
        g.colors.muted,
        0.8,
    );

    // 4. Heartbeat along the bottom.
    const base = rows - 3;
    ecg.forEach((value, index) => {
        const previous = ecg[index - 1] ?? 0;
        const color =
            Math.max(value, previous) > 2
                ? g.colors.status.red
                : g.colors.brand;

        if (value === previous) {
            g.glyph('-', left + index, base - value, color, 0.9);

            return;
        }

        // One slash per row climbed, so the beat reads as one connected line.
        const up = value > previous;

        for (
            let v = up ? previous + 1 : previous - 1;
            up ? v <= value : v >= value;
            v += up ? 1 : -1
        ) {
            g.glyph(
                up ? '/' : '\\',
                left + index,
                base - (up ? v : v + 1),
                color,
                0.9,
            );
        }
    });
}

/** Still frame: sweep parked, every blip lit, a flat heartbeat. */
function still() {
    time = TURN * 0.3;
    ecg = Array(80).fill(0);
}

const {
    grid: g,
    draw,
    local,
    reducedMotion,
} = useAsciiCanvas(canvas, {
    step,
    draw: render,
    still,
});

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
    if (target.value) {
        if (/^https?:/.test(target.value)) {
            window.open(target.value, '_blank', 'noopener');
        } else {
            router.visit(target.value);
        }

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

watch(blips, () => draw());
</script>

<template>
    <div
        role="img"
        :aria-label="summary"
        class="relative h-60 w-full overflow-hidden rounded-lg border bg-card shadow-card"
    >
        <canvas
            ref="canvas"
            class="block size-full font-mono select-none"
            :class="target ? 'cursor-pointer' : 'cursor-crosshair'"
            @pointermove="onMove"
            @pointerleave="onLeave"
            @pointerup="onClick"
        />
    </div>
</template>

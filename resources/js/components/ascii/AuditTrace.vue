<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useAsciiCanvas } from '@/composables/useAsciiCanvas';
import type { AsciiGrid } from '@/composables/useAsciiCanvas';
import type { AuditEvent, Status } from '@/types';

/**
 * A seismograph of the events on this page: time runs left to right, each column stacks the
 * events in that slice by how much attention they need, and a pen redraws the trace so the
 * busy moments stand out. Hover the trace to read a slice.
 */
const props = defineProps<{
    events: AuditEvent[];
    labels: Record<string, string>;
}>();

const canvas = ref<HTMLCanvasElement>();
const hovered = ref<number | null>(null);

// Red is worth a look (denied sign-ins, accepted risk), yellow changed access, green is routine.
const ATTENTION: Record<string, Status> = {
    'user.login_denied': 'red',
    'risk.accepted': 'red',
    'token.issued': 'yellow',
    'token.revoked': 'yellow',
    'risk.revoked': 'yellow',
    'service.allowed_ips_updated': 'yellow',
    'service.badge_rotated': 'yellow',
    'settings.updated': 'yellow',
};
const LEVELS: { status: Status; glyph: string; label: string }[] = [
    { status: 'green', glyph: '.', label: 'routine' },
    { status: 'yellow', glyph: '+', label: 'access change' },
    { status: 'red', glyph: '!', label: 'needs a look' },
];
const PEN = 6; // seconds per pass of the pen

const level = (event: AuditEvent) => ATTENTION[event.action] ?? 'green';
const format = new Intl.DateTimeFormat(undefined, {
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
});

const counts = computed(() =>
    LEVELS.map(
        ({ status }) => props.events.filter((e) => level(e) === status).length,
    ),
);

const summary = computed(
    () =>
        `Activity trace of ${props.events.length} events: ${LEVELS.map(
            (l, i) => `${counts.value[i]} ${l.label}`,
        ).join(', ')}.`,
);

let time = 0;
let buckets: AuditEvent[][] = [];
let left = 2;
let top = 3;
let axis = 9;

function render(g: AsciiGrid) {
    left = 2;
    top = 3;
    axis = g.rows - 5;
    const width = Math.max(1, g.cols - 4);
    const stamps = props.events.map((e) => Date.parse(e.created_at));
    const from = Math.min(...stamps);
    const to = Math.max(...stamps);
    const span = Math.max(1, to - from);

    buckets = Array.from({ length: width }, () => []);
    props.events.forEach((event, i) =>
        buckets[Math.round(((stamps[i] - from) / span) * (width - 1))].push(
            event,
        ),
    );

    const amplitude = axis - top;
    const max = Math.max(1, ...buckets.map((b) => b.length));
    const pen = reducedMotion.matches
        ? width
        : Math.min(width, Math.floor(((time % (PEN + 1)) / PEN) * width));

    g.glyph(
        `activity trace · ${props.events.length} events on this page · taller = busier`.slice(
            0,
            g.cols - 4,
        ),
        left,
        1,
        g.colors.ink,
    );

    g.glyph('-'.repeat(width), left, axis, g.colors.muted, 0.4);

    buckets.forEach((events, x) => {
        if (events.length === 0) {
            return;
        }

        const cells = Math.max(
            1,
            Math.round((events.length / max) * amplitude),
        );
        const stack = [...events].sort(
            (a, b) =>
                LEVELS.findIndex((l) => l.status === level(a)) -
                LEVELS.findIndex((l) => l.status === level(b)),
        );
        const drawn = x <= pen || x === hovered.value;

        for (let k = 0; k < cells; k++) {
            const event = stack[Math.floor((k / cells) * stack.length)];
            const meta = LEVELS.find((l) => l.status === level(event))!;
            g.glyph(
                meta.glyph,
                left + x,
                axis - 1 - k,
                g.colors.status[meta.status],
                drawn ? 1 : 0.15,
            );
        }

        g.glyph('+', left + x, axis, g.colors.muted, drawn ? 0.8 : 0.3);
    });

    if (pen < width) {
        g.glyph('v', left + pen, top - 1, g.colors.brand);
    }

    if (Number.isFinite(from)) {
        const start = format.format(from);
        const end = format.format(to);
        g.glyph(start, left, axis + 1, g.colors.muted);

        if (start.length + end.length + 2 <= width) {
            g.glyph(end, left + width - end.length, axis + 1, g.colors.muted);
        }
    }

    // Readout: the hovered slice, or the latest one the pen has drawn.
    let focus = hovered.value;

    if (focus === null) {
        for (let x = Math.min(pen, width - 1); x >= 0; x--) {
            if (buckets[x]?.length) {
                focus = x;

                break;
            }
        }
    }

    const slice = focus !== null ? buckets[focus] : [];

    if (slice?.length) {
        const names = [
            ...new Set(slice.map((e) => props.labels[e.action] ?? e.action)),
        ];
        const who = slice.find((e) => e.user)?.user;
        g.glyph(
            `> ${format.format(Date.parse(slice[0].created_at))} · ${slice.length} event${slice.length === 1 ? '' : 's'} · ${names.join(', ').toLowerCase()}${who ? ` · ${who}` : ''}`.slice(
                0,
                g.cols - 4,
            ),
            left,
            axis + 2,
            g.colors.ink,
        );
    } else {
        g.glyph('> no events on this page', left, axis + 2, g.colors.muted);
    }

    let col = left;

    LEVELS.forEach((meta, i) => {
        const label = `${counts.value[i]} ${meta.label}`;
        g.glyph(meta.glyph, col, axis + 3, g.colors.status[meta.status]);
        g.glyph(label, col + 2, axis + 3, g.colors.muted);
        col += label.length + 5;
    });

    g.glyph('· hover the trace', col, axis + 3, g.colors.muted, 0.8);
}

const { draw, local, reducedMotion } = useAsciiCanvas(canvas, {
    step: (dt) => (time += dt),
    draw: render,
});

function onMove(event: PointerEvent) {
    const { col, row } = local(event);
    const x = col - left;
    hovered.value =
        row >= top - 1 && row <= axis + 1 && x >= 0 && x < buckets.length
            ? // Snap to the nearest slice with events, so the sparse trace is easy to hit.
              ([0, -1, 1, -2, 2]
                  .map((d) => x + d)
                  .find((i) => buckets[i]?.length) ?? null)
            : null;
    draw();
}

watch(() => props.events, draw);
</script>

<template>
    <div
        role="img"
        :aria-label="summary"
        class="relative h-52 w-full overflow-hidden rounded-lg border bg-card shadow-card"
    >
        <canvas
            ref="canvas"
            class="block size-full cursor-default font-mono select-none"
            @pointermove="onMove"
            @pointerleave="hovered = null"
        />
    </div>
</template>

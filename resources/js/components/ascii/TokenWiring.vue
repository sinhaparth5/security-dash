<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import TokenController from '@/actions/App/Http/Controllers/TokenController';
import { GLYPH, hash, useAsciiCanvas } from '@/composables/useAsciiCanvas';
import type { AsciiGrid } from '@/composables/useAsciiCanvas';
import type { ServiceSummary } from '@/types';

/**
 * How telemetry gets in: each service sends packets down its own wire, one key per token, into
 * the API, which checks the pw_ token and allowlist and fans them out to the three ingest
 * endpoints. A service without a token has a dead wire. Click a row to manage its tokens.
 */
const props = defineProps<{
    services: ServiceSummary[];
    selected: number | null;
}>();

const canvas = ref<HTMLCanvasElement>();
const hovered = ref<number | null>(null);

const ENDPOINTS = ['heartbeat', 'image-scan', 'sbom'];
const HUB = ['  /api/v1', ' sanctum', ' pw_ token', ' ip allow'];
const HUB_W = 12;
const TRIP = 2.6; // seconds for a packet to reach the API

const totalTokens = computed(() =>
    props.services.reduce((sum, service) => sum + service.tokens_count, 0),
);
const silent = computed(
    () => props.services.filter((service) => service.tokens_count === 0).length,
);

const summary = computed(
    () =>
        `Telemetry wiring: ${props.services.length} services holding ${totalTokens.value} tokens${
            silent.value
                ? `, ${silent.value} without a token cannot report`
                : ''
        }. Click a service to manage its tokens.`,
);

let time = 0;
let flash = 0;
let rows: { service: ServiceSummary; row: number }[] = [];
const phases = new Map<string, number>();
const outbound: { lane: number; at: number }[] = [];

function step(dt: number) {
    time += dt;
    flash = Math.max(0, flash - dt * 2);

    for (const { service } of rows) {
        for (let k = 0; k < Math.min(3, service.tokens_count); k++) {
            const key = `${service.id}:${k}`;
            const phase = (time / TRIP + hash(service.id, k)) % 1;

            // A wrap means the packet just reached the API.
            if (phase < (phases.get(key) ?? 0)) {
                flash = 1;
                outbound.push({
                    lane: Math.floor(hash(service.id, k, Math.floor(time)) * 3),
                    at: time,
                });
            }

            phases.set(key, phase);
        }
    }

    for (let i = outbound.length - 1; i >= 0; i--) {
        if (time - outbound[i].at > 1) {
            outbound.splice(i, 1);
        }
    }
}

function render(g: AsciiGrid) {
    const left = 2;
    const nameCol = left + 2;
    const keyCol = nameCol + 15;
    const wireStart = keyCol + 5;
    const wide = g.cols >= 76;
    // Too narrow for the API box: the wires just run to the edge.
    const hasHub = g.cols >= wireStart + 8 + HUB_W + 2;
    const hubCol = wide
        ? Math.max(wireStart + 8, Math.floor(g.cols * 0.56))
        : hasHub
          ? g.cols - HUB_W - 2
          : g.cols - 2;
    const top = 3;
    const capacity = Math.max(1, g.rows - 6);
    const more = props.services.length > capacity;
    const shown = props.services.slice(0, more ? capacity - 1 : capacity);
    const hubBottom = Math.max(
        top + HUB.length - 1,
        top + shown.length - (more ? 0 : 1),
    );

    g.glyph(
        `telemetry wiring · ${props.services.length} svc · ${totalTokens.value} tokens${
            silent.value ? ` · ${silent.value} can't report` : ''
        }`.slice(0, g.cols - 4),
        left,
        1,
        g.colors.ink,
    );

    rows = shown.map((service, index) => ({ service, row: top + index }));

    for (const { service, row } of rows) {
        const live = service.tokens_count > 0;
        const isActive =
            service.id === hovered.value || service.id === props.selected;

        if (isActive) {
            g.glyph('>', left - 1, row, g.colors.ink);
        }

        g.glyph(
            GLYPH[service.status],
            left,
            row,
            g.colors.status[service.status],
        );
        g.glyph(
            `${service.name}`.slice(0, 14),
            nameCol,
            row,
            g.colors.ink,
            live || isActive ? 1 : 0.6,
        );
        g.glyph(
            live
                ? 'k'.repeat(Math.min(3, service.tokens_count)) +
                      (service.tokens_count > 3 ? '+' : '')
                : '--',
            keyCol,
            row,
            live ? g.colors.brand : g.colors.muted,
        );

        if (!live) {
            g.glyph(
                "no token, can't report".slice(0, hubCol - wireStart - 1),
                wireStart,
                row,
                g.colors.muted,
                0.7,
            );

            continue;
        }

        g.glyph(
            '-'.repeat(Math.max(0, hubCol - wireStart)),
            wireStart,
            row,
            g.colors.muted,
            isActive ? 0.8 : 0.4,
        );

        for (let k = 0; k < Math.min(3, service.tokens_count); k++) {
            const phase = reducedMotion.matches
                ? hash(service.id, k)
                : (time / TRIP + hash(service.id, k)) % 1;
            g.glyph(
                '>',
                wireStart + Math.floor(phase * (hubCol - wireStart)),
                row,
                g.colors.brand,
            );
        }
    }

    if (more) {
        g.glyph(
            `+${props.services.length - shown.length} more services`,
            nameCol,
            top + shown.length,
            g.colors.muted,
        );
    }

    if (!hasHub) {
        drawFooter(g, left);

        return;
    }

    // The API box every wire runs into.
    const border = flash > 0 ? g.colors.brand : g.colors.muted;
    const edge = '+' + '-'.repeat(HUB_W - 2) + '+';
    g.glyph(edge, hubCol, top - 1, border, 0.5 + flash / 2);
    g.glyph(edge, hubCol, hubBottom + 1, border, 0.5 + flash / 2);

    for (let r = top; r <= hubBottom; r++) {
        g.glyph('|', hubCol, r, border, 0.5 + flash / 2);
        g.glyph('|', hubCol + HUB_W - 1, r, border, 0.5 + flash / 2);
        const label = HUB[r - top];

        if (label) {
            g.glyph(
                label,
                hubCol + 1,
                r,
                r === top ? g.colors.ink : g.colors.muted,
            );
        }
    }

    // Out to the ingest endpoints, then grading.
    if (wide) {
        const outStart = hubCol + HUB_W;
        const outEnd = g.cols - 2 - 12;

        ENDPOINTS.forEach((endpoint, lane) => {
            const row = top + lane;
            g.glyph(
                '-'.repeat(Math.max(0, outEnd - outStart)) + '>',
                outStart,
                row,
                g.colors.muted,
                0.4,
            );
            g.glyph(endpoint, outEnd + 2, row, g.colors.ink);
        });

        for (const packet of outbound.filter((p) => !reducedMotion.matches)) {
            g.glyph(
                '>',
                outStart + Math.floor((time - packet.at) * (outEnd - outStart)),
                top + packet.lane,
                g.colors.brand,
            );
        }

        g.glyph('then graded', outEnd + 2, top + 4, g.colors.muted);
        g.glyph(GLYPH.red, outEnd + 2, top + 5, g.colors.status.red);
        g.glyph(GLYPH.yellow, outEnd + 4, top + 5, g.colors.status.yellow);
        g.glyph(GLYPH.green, outEnd + 6, top + 5, g.colors.status.green);
    }

    drawFooter(g, left);
}

function drawFooter(g: AsciiGrid, left: number) {
    const focus = rows.find(
        ({ service }) => service.id === hovered.value,
    )?.service;
    g.glyph(
        (focus
            ? `> ${focus.name}: ${focus.tokens_count} token${focus.tokens_count === 1 ? '' : 's'} · click to manage them`
            : 'each service posts with its own pw_ token · click a row to manage it'
        ).slice(0, g.cols - 4),
        left,
        g.rows - 2,
        focus ? g.colors.ink : g.colors.muted,
    );
}

const { draw, local, reducedMotion } = useAsciiCanvas(canvas, {
    step,
    draw: render,
});

function onMove(event: PointerEvent) {
    const { row } = local(event);
    hovered.value = rows.find((r) => r.row === row)?.service.id ?? null;
    draw();
}

function onClick() {
    if (hovered.value !== null) {
        router.visit(
            TokenController.index.url({ query: { service: hovered.value } }),
            { preserveScroll: true },
        );
    }
}

watch(() => [props.services, props.selected], draw);
</script>

<template>
    <div
        role="img"
        :aria-label="summary"
        class="relative h-52 w-full overflow-hidden rounded-lg border bg-card shadow-card"
    >
        <canvas
            ref="canvas"
            class="block size-full font-mono select-none"
            :class="hovered !== null ? 'cursor-pointer' : 'cursor-default'"
            @pointermove="onMove"
            @pointerleave="hovered = null"
            @pointerup="onClick"
        />
    </div>
</template>

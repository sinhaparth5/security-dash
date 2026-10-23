<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { GLYPH, hash, useAsciiCanvas } from '@/composables/useAsciiCanvas';
import type { AsciiGrid } from '@/composables/useAsciiCanvas';
import { statusMeta, statusOrder } from '@/lib/status';
import { index as packagesIndex } from '@/routes/packages';
import type { FleetPackage, Status } from '@/types';

/**
 * Blast radius: one bar per package, as tall as the number of services running it, stacked by
 * version and textured by that version's status. Advisories rain onto the red bars; click a bar
 * to search for that package.
 */
const props = defineProps<{ packages: FleetPackage[] }>();

const canvas = ref<HTMLCanvasElement>();
const hovered = ref<number | null>(null);

// Texture as well as colour, so the stack reads without colour.
const TEXTURE: Record<Status, string> = {
    red: '#',
    yellow: '=',
    green: ':',
    unknown: '.',
};
const LEGEND: Record<Status, string> = {
    red: 'patch',
    yellow: 'update',
    unknown: 'untracked',
    green: 'current',
};

const sorted = computed(() =>
    [...props.packages].sort(
        (a, b) =>
            statusOrder.indexOf(a.status) - statusOrder.indexOf(b.status) ||
            b.services_count - a.services_count,
    ),
);

const summary = computed(() => {
    const widest = [...props.packages].sort(
        (a, b) => b.services_count - a.services_count,
    )[0];

    return `Blast radius: ${props.packages.length} packages, ${
        props.packages.filter((pkg) => pkg.status === 'red').length
    } need a patch.${widest ? ` ${widest.name} runs in the most services (${widest.services_count}).` : ''}`;
});

let time = 0;
let bars: { pkg: FleetPackage; col: number }[] = [];
let top = 3;
let base = 9;

function render(g: AsciiGrid) {
    const left = 2;
    const axis = left + 4;
    const start = axis + 2;
    const capacity = Math.max(1, Math.floor((g.cols - 2 - start) / 2));
    const shown = sorted.value.slice(0, capacity);
    const max = Math.max(1, ...shown.map((pkg) => pkg.services_count));
    top = 3;
    base = g.rows - 5;
    const rows = base - top;
    const focus =
        hovered.value ?? Math.floor(time / 0.4) % Math.max(1, shown.length);

    g.glyph(
        `blast radius · ${props.packages.length} pkgs · bar height = services running it${
            shown.length < props.packages.length
                ? ` · worst ${shown.length}`
                : ''
        }`.slice(0, g.cols - 4),
        left,
        1,
        g.colors.ink,
    );

    let col = start;

    for (const status of statusOrder) {
        g.glyph(TEXTURE[status], col, 2, g.colors.status[status]);
        g.glyph(LEGEND[status], col + 2, 2, g.colors.muted);
        col += LEGEND[status].length + 4;
    }

    // Axis: the scale on the left, a baseline under the bars.
    g.glyph(String(max).padStart(3), left, top, g.colors.muted);
    g.glyph('  0', left, base, g.colors.muted);

    for (let r = top; r < base; r++) {
        g.glyph('|', axis, r, g.colors.muted, 0.5);
    }

    g.glyph(
        '+' + '-'.repeat(Math.max(0, shown.length * 2)),
        axis,
        base,
        g.colors.muted,
        0.5,
    );

    bars = shown.map((pkg, index) => ({ pkg, col: start + index * 2 }));
    bars.forEach(({ pkg, col: x }, index) => {
        // Bars grow in from the baseline, 30ms apart.
        const t = reducedMotion.matches
            ? 1
            : Math.min(1, Math.max(0, (time - index * 0.03) / 0.6));
        const grow = 1 - (1 - t) ** 3;
        const cells = Math.max(
            t > 0 ? 1 : 0,
            Math.round((pkg.services_count / max) * rows * grow),
        );
        const versions = [...pkg.versions].sort(
            (a, b) =>
                statusOrder.indexOf(a.status) - statusOrder.indexOf(b.status),
        );
        const isFocus = index === focus;

        for (let k = 0; k < cells; k++) {
            // Which version this cell of the stack belongs to, worst at the bottom.
            let at = ((k + 0.5) / cells) * pkg.services_count;
            const version =
                versions.find((v) => (at -= v.services.length) < 0) ??
                versions[versions.length - 1];
            const status = version?.status ?? pkg.status;
            g.glyph(
                TEXTURE[status],
                x,
                base - 1 - k,
                g.colors.status[status],
                isFocus ? 1 : 0.7,
            );
        }

        if (
            pkg.status === 'red' &&
            !reducedMotion.matches &&
            rows - cells > 1
        ) {
            const phase = (time * 0.8 + hash(index, 5)) % 1;
            g.glyph(
                "'",
                x,
                top + Math.floor(phase * (rows - cells)),
                g.colors.status.red,
                0.9,
            );
        }

        if (isFocus) {
            g.glyph('^', x, base + 1, g.colors.ink);
        }
    });

    const pkg = shown[focus];

    if (!pkg) {
        g.glyph('> no packages reported yet', left, base + 2, g.colors.muted);

        return;
    }

    g.glyph(
        `> ${pkg.name} · ${pkg.ecosystem} · ${pkg.services_count} svc on ${pkg.versions.length} version${pkg.versions.length === 1 ? '' : 's'} · ${statusMeta[pkg.status].label.toLowerCase()}`.slice(
            0,
            g.cols - 4,
        ),
        left,
        base + 2,
        g.colors.ink,
    );

    col = left + 2;

    for (const version of pkg.versions) {
        const label = `${version.version} ×${version.services.length}`;

        if (col + label.length + 2 > g.cols - 2) {
            break;
        }

        g.glyph(
            GLYPH[version.status],
            col,
            base + 3,
            g.colors.status[version.status],
        );
        g.glyph(label, col + 2, base + 3, g.colors.muted);
        col += label.length + 5;
    }
}

const { draw, local, reducedMotion } = useAsciiCanvas(canvas, {
    step: (dt) => (time += dt),
    draw: render,
    still: () => (time = 0),
});

function onMove(event: PointerEvent) {
    const { col, row } = local(event);
    const index = bars.findIndex(
        (bar) => col === bar.col || col === bar.col + 1,
    );
    hovered.value =
        index >= 0 && row >= top - 1 && row <= base + 1 ? index : null;
    draw();
}

function onClick() {
    const bar = hovered.value !== null ? bars[hovered.value] : undefined;

    if (bar) {
        router.visit(packagesIndex.url({ query: { q: bar.pkg.name } }));
    }
}

watch(() => props.packages, draw);
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

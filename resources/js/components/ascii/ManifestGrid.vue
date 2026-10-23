<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { GLYPH, useAsciiCanvas } from '@/composables/useAsciiCanvas';
import type { AsciiGrid } from '@/composables/useAsciiCanvas';
import { statusMeta, statusOrder } from '@/lib/status';
import type { Dependency, Status } from '@/types';

/**
 * The service's manifest as a tile grid: one tile per dependency, a row per ecosystem, and an
 * inspector stepping through them worst first, spelling out why each one got its grade.
 */
const props = defineProps<{ dependencies: Dependency[] }>();

const canvas = ref<HTMLCanvasElement>();
const hovered = ref<number | null>(null);

const STEP = 1.1; // seconds per inspected dependency
const ECOSYSTEMS: Dependency['ecosystem'][] = ['runtime', 'npm', 'go', 'image'];
const LEGEND: Record<Status, string> = {
    red: 'patch',
    yellow: 'update',
    unknown: 'untracked',
    green: 'current',
};

const order = computed(() =>
    [...props.dependencies].sort(
        (a, b) =>
            statusOrder.indexOf(a.status) - statusOrder.indexOf(b.status) ||
            a.name.localeCompare(b.name),
    ),
);

const counts = computed(
    () =>
        Object.fromEntries(
            statusOrder.map((status) => [
                status,
                props.dependencies.filter((d) => d.status === status).length,
            ]),
        ) as Record<Status, number>,
);

// Same roll-up as ServiceStatus::worst(): unknown only counts when nothing else was graded.
const grade = computed<Status>(
    () =>
        (['red', 'yellow', 'green'] as Status[]).find(
            (status) => counts.value[status] > 0,
        ) ?? 'unknown',
);

const summary = computed(
    () =>
        `Manifest: ${props.dependencies.length} dependencies, ${statusOrder
            .map(
                (status) =>
                    `${counts.value[status]} ${statusMeta[status].label.toLowerCase()}`,
            )
            .join(', ')}. The service takes the grade of its worst dependency.`,
);

let time = 0;
let tiles: { dependency: Dependency; col: number; row: number }[] = [];

function lines(dependency: Dependency, g: AsciiGrid): [string, string][] {
    const open = dependency.advisories.filter((advisory) => !advisory.accepted);
    const overdue = open.some((advisory) => advisory.overdue);
    const due = open.map((advisory) => advisory.due_at).sort()[0];
    const newer =
        dependency.latest_version &&
        dependency.latest_version !== dependency.version
            ? ` -> ${dependency.latest_version}`
            : '';
    const out: [string, string][] = [
        [`> ${dependency.name}`, g.colors.ink],
        [
            `  ${dependency.ecosystem} ${dependency.version}${newer}`,
            g.colors.muted,
        ],
        [
            `  ${GLYPH[dependency.status]} ${statusMeta[dependency.status].label.toLowerCase()}`,
            g.colors.status[dependency.status],
        ],
    ];

    if (open.length > 0) {
        out.push([
            `  ${open[0].osv_id}${open.length > 1 ? ` +${open.length - 1} more` : ''}`,
            g.colors.ink,
        ]);
        out.push([
            `  fix by ${due.slice(0, 10)}${overdue ? ' · overdue' : ''}`,
            overdue ? g.colors.status.red : g.colors.muted,
        ]);
    }

    if (dependency.malicious) {
        out.push(['  ! osv reports this as malware', g.colors.status.red]);
    } else if (open.some((advisory) => advisory.known_exploited)) {
        out.push(['  ! exploited in the wild (kev)', g.colors.status.red]);
    } else if (dependency.lookalike) {
        out.push([`  ~ one typo from ${dependency.lookalike}`, g.colors.ink]);
    } else if (dependency.eol_at) {
        out.push([
            `  end of life ${dependency.eol_at.slice(0, 10)}`,
            g.colors.muted,
        ]);
    }

    return out;
}

function render(g: AsciiGrid) {
    const left = 2;
    const wide = g.cols >= 66;
    const readout = wide ? g.cols - 34 : g.cols;
    const tileStart = left + 9;
    const perRow = Math.max(1, Math.floor((readout - 3 - tileStart) / 2));
    const lastRow = g.rows - 4;
    const current =
        order.value.find((d) => d.id === hovered.value) ??
        order.value[Math.floor(time / STEP) % Math.max(1, order.value.length)];

    g.glyph(
        `manifest · ${props.dependencies.length} deps · graded ${grade.value}, its worst dependency`.slice(
            0,
            g.cols - 4,
        ),
        left,
        1,
        g.colors.ink,
    );

    // One row (or more, when it wraps) per ecosystem, worst tiles first.
    tiles = [];
    let row = 3;

    for (const ecosystem of ECOSYSTEMS) {
        const group = order.value.filter((d) => d.ecosystem === ecosystem);

        if (group.length === 0 || row > lastRow) {
            continue;
        }

        g.glyph(ecosystem, left, row, g.colors.muted);
        group.forEach((dependency, index) => {
            const at = row + Math.floor(index / perRow);

            if (at <= lastRow) {
                tiles.push({
                    dependency,
                    col: tileStart + (index % perRow) * 2,
                    row: at,
                });
            }
        });
        row += Math.ceil(group.length / perRow);
    }

    for (const tile of tiles) {
        const { status, id } = tile.dependency;
        const isCurrent = id === current?.id;
        const pulse =
            status === 'red' && !reducedMotion.matches
                ? 0.7 + 0.3 * Math.sin(time * 5 + id)
                : 1;
        g.glyph(
            GLYPH[status],
            tile.col,
            tile.row,
            g.colors.status[status],
            isCurrent ? 1 : 0.8 * pulse,
        );

        if (isCurrent) {
            g.glyph('[', tile.col - 1, tile.row, g.colors.ink);
            g.glyph(']', tile.col + 1, tile.row, g.colors.ink);
        }
    }

    const hidden = props.dependencies.length - tiles.length;

    if (hidden > 0) {
        g.glyph(
            `+${hidden} more below`,
            tileStart,
            lastRow + 1,
            g.colors.muted,
        );
    }

    // The inspector: a column on the right, or one line above the legend when narrow.
    if (current) {
        const text = lines(current, g);

        if (wide) {
            for (let r = 3; r <= lastRow; r++) {
                g.glyph('|', readout - 2, r, g.colors.muted, 0.35);
            }

            text.forEach(([line, color], index) =>
                g.glyph(line.slice(0, 33), readout, 3 + index, color),
            );
        } else {
            g.glyph(
                `${text[0][0]} ·${text[2][0].slice(1)}`.slice(0, g.cols - 4),
                left,
                lastRow + 1,
                g.colors.ink,
            );
        }
    }

    let col = left;

    for (const status of statusOrder) {
        const label = `${counts.value[status]} ${LEGEND[status]}`;
        g.glyph(GLYPH[status], col, g.rows - 2, g.colors.status[status]);
        g.glyph(label, col + 2, g.rows - 2, g.colors.muted);
        col += label.length + 5;
    }

    if (wide) {
        g.glyph(
            'hover a tile to inspect it',
            readout,
            g.rows - 2,
            g.colors.muted,
            0.8,
        );
    }
}

const { draw, local, reducedMotion } = useAsciiCanvas(canvas, {
    step: (dt) => (time += dt),
    draw: render,
    still: () => (time = 0),
});

function onMove(event: PointerEvent) {
    const { col, row } = local(event);
    hovered.value =
        tiles.find(
            (tile) =>
                tile.row === row && (col === tile.col || col === tile.col + 1),
        )?.dependency.id ?? null;
    draw();
}

watch(() => props.dependencies, draw);
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

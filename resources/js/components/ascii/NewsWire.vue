<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useAsciiCanvas } from '@/composables/useAsciiCanvas';
import type { AsciiGrid } from '@/composables/useAsciiCanvas';
import type { NewsArticle, Status } from '@/types';

/**
 * A news wire: one ticker lane per Jev importance, the more urgent lanes moving faster, and a
 * teleprinter underneath typing out the most important stories. Hover a lane to hold it, click a
 * headline to open the article.
 */
const props = defineProps<{ articles: NewsArticle[] }>();

const canvas = ref<HTMLCanvasElement>();
const hoveredLane = ref<number | null>(null);
const hoveredArticle = ref<NewsArticle | null>(null);

const LANES: { status: Status; label: string; speed: number }[] = [
    { status: 'red', label: 'act now', speed: 9 },
    { status: 'yellow', label: 'important', speed: 6 },
    { status: 'green', label: 'routine', speed: 4 },
    { status: 'unknown', label: 'unranked', speed: 3 },
];
const SEPARATOR = '   ·   ';
const TYPE_RATE = 45; // characters a second
const HOLD = 4; // seconds a typed story stays up

// The same cut-offs as the act now / important badges on the page.
function level(article: NewsArticle): Status {
    if (article.importance === null) {
        return 'unknown';
    }

    return article.importance >= 2.5
        ? 'red'
        : article.importance >= 1.5
          ? 'yellow'
          : 'green';
}

function tag(source: string): string {
    return source.match(/[A-Z]/g)?.join('') || source.slice(0, 3).toUpperCase();
}

/** Each lane's tape, with which article and which part (tag or headline) every character is. */
const lanes = computed(() =>
    LANES.map((lane) => {
        const items = props.articles.filter((a) => level(a) === lane.status);
        let text = '';
        const owner: number[] = [];
        const isTag: boolean[] = [];

        for (const article of items) {
            const label = `[${tag(article.source)}]`;
            const piece = `${label} ${article.title}${SEPARATOR}`;
            text += piece;

            for (let i = 0; i < piece.length; i++) {
                owner.push(
                    i < piece.length - SEPARATOR.length ? article.id : -1,
                );
                isTag.push(i < label.length);
            }
        }

        return { ...lane, items, text, owner, isTag };
    }).filter((lane) => lane.items.length > 0),
);

const reel = computed(() =>
    [...props.articles].sort(
        (a, b) =>
            (b.importance ?? -1) - (a.importance ?? -1) ||
            Date.parse(b.published_at) - Date.parse(a.published_at),
    ),
);

const summary = computed(
    () =>
        `News wire: ${props.articles.length} stories, ${lanes.value
            .map((lane) => `${lane.items.length} ${lane.label}`)
            .join(', ')}. Faster lanes are more urgent.`,
);

let time = 0;
let typed = 0;
let story = 0;
const offsets = LANES.map(() => 0);
let tickerStart = 0;
let laneTop = 3;

function wrap(text: string, width: number, lines: number): string[] {
    const out: string[] = [];
    let line = '';

    for (const word of text.split(/\s+/)) {
        if ((line + ' ' + word).trim().length > width) {
            out.push(line);
            line = word;

            if (out.length === lines) {
                break;
            }
        } else {
            line = (line + ' ' + word).trim();
        }
    }

    if (out.length < lines && line) {
        out.push(line);
    }

    return out;
}

function step(dt: number) {
    time += dt;
    lanes.value.forEach((lane, index) => {
        if (hoveredLane.value !== index) {
            offsets[index] += dt * lane.speed;
        }
    });

    const current = reel.value[story % Math.max(1, reel.value.length)];
    const length = current
        ? current.title.length + (current.summary?.length ?? 0)
        : 0;
    typed += dt * TYPE_RATE;

    if (typed > length + HOLD * TYPE_RATE) {
        typed = 0;
        story++;
    }
}

function render(g: AsciiGrid) {
    const left = 2;
    const right = g.cols - 2;
    tickerStart = left + 17;
    laneTop = 3;

    g.glyph(
        `wire · ${props.articles.length} stories · lanes ranked by jev, faster = more urgent`.slice(
            0,
            g.cols - 4,
        ),
        left,
        1,
        g.colors.ink,
    );

    lanes.value.forEach((lane, index) => {
        const row = laneTop + index;
        const color = g.colors.status[lane.status];
        const paused = hoveredLane.value === index;
        g.glyph(paused ? '=' : '>', left, row, color);
        g.glyph(lane.label, left + 2, row, g.colors.ink);
        g.glyph(
            String(lane.items.length).padStart(2),
            left + 12,
            row,
            g.colors.muted,
        );
        g.glyph('|', left + 15, row, g.colors.muted, 0.4);

        const shift = Math.floor(offsets[index]);
        const width = right - tickerStart;

        for (let x = 0; x < width; x++) {
            const i = (shift + x) % lane.text.length;
            const owner = lane.owner[i];
            const edge = Math.min(x, width - 1 - x);
            const fade = edge < 3 ? 0.35 + edge * 0.2 : 1;
            const lit = owner !== -1 && owner === hoveredArticle.value?.id;
            g.glyph(
                lane.text[i],
                tickerStart + x,
                row,
                lane.isTag[i] ? color : g.colors.ink,
                (lit ? 1 : owner === -1 ? 0.4 : 0.75) * fade,
            );
        }
    });

    // Teleprinter: the hovered story, or the reel typed out most important first.
    const row = laneTop + lanes.value.length + 1;
    const article =
        hoveredArticle.value ??
        reel.value[story % Math.max(1, reel.value.length)];

    if (!article) {
        g.glyph(
            '> the wire is quiet, no stories fetched yet',
            left,
            row,
            g.colors.muted,
        );

        return;
    }

    const full = hoveredArticle.value !== null || reducedMotion.matches;
    const shown = full ? Infinity : Math.floor(typed);
    const title = `> ${article.title}`.slice(0, g.cols - 4);
    const status = level(article);
    g.glyph(title.slice(0, shown + 2), left, row, g.colors.ink);
    const body = wrap(
        `${article.source} · ${article.summary ?? ''}`,
        g.cols - 8,
        Math.max(0, g.rows - row - 3),
    );
    let budget = shown - title.length;

    body.forEach((line, index) => {
        if (budget > 0) {
            g.glyph(
                line.slice(0, budget),
                left + 2,
                row + 1 + index,
                g.colors.muted,
            );
        }

        budget -= line.length;
    });

    if (!full && Math.floor(time * 2) % 2 === 0) {
        g.glyph(
            '_',
            left + Math.min(title.length, shown + 2),
            row,
            g.colors.status[status],
        );
    }

    g.glyph(
        'hover a lane to hold it · click a headline to open it'.slice(
            0,
            g.cols - 4,
        ),
        left,
        g.rows - 2,
        g.colors.muted,
        0.8,
    );
}

const { draw, local, reducedMotion } = useAsciiCanvas(canvas, {
    step,
    draw: render,
    still: () => offsets.fill(0),
});

function onMove(event: PointerEvent) {
    const { col, row } = local(event);
    const index = row - laneTop;
    const lane = lanes.value[index];

    hoveredLane.value = lane ? index : null;
    hoveredArticle.value = null;

    if (lane && col >= tickerStart) {
        const owner =
            lane.owner[
                (Math.floor(offsets[index]) + col - tickerStart) %
                    lane.text.length
            ];
        hoveredArticle.value = lane.items.find((a) => a.id === owner) ?? null;
    }

    draw();
}

function onLeave() {
    hoveredLane.value = null;
    hoveredArticle.value = null;
}

function onClick() {
    if (hoveredArticle.value) {
        window.open(hoveredArticle.value.url, '_blank', 'noopener');
    }
}

watch(() => props.articles, draw);
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
            :class="hoveredArticle ? 'cursor-pointer' : 'cursor-default'"
            @pointermove="onMove"
            @pointerleave="onLeave"
            @pointerup="onClick"
        />
    </div>
</template>

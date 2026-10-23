import { onBeforeUnmount, onMounted } from 'vue';
import type { Ref } from 'vue';
import { statusOrder } from '@/lib/status';
import type { Status } from '@/types';

export const CELL_H = 14;
const FONT_SIZE = 12;
const TRACK = 2; // extra px between columns

/** Status glyphs shared by every ASCII scene, so a status never depends on colour alone. */
export const GLYPH: Record<Status, string> = {
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

export type AsciiGrid = {
    ctx: CanvasRenderingContext2D | null;
    width: number;
    height: number;
    cellW: number;
    cols: number;
    rows: number;
    colors: {
        ink: string;
        muted: string;
        brand: string;
        status: Record<Status, string>;
    };
    /** Draw text one character per cell, so every scene sits on the same grid. */
    glyph: (
        text: string,
        col: number,
        row: number,
        color: string,
        alpha?: number,
    ) => void;
    /** Blank cells, so text stays readable over a busy background. */
    clear: (col: number, row: number, length: number) => void;
};

export function hash(a: number, b = 0, c = 0): number {
    let h = (a * 374761393 + b * 668265263 + c * 2147483647) | 0;
    h = Math.imul(h ^ (h >>> 13), 1274126177);

    return ((h ^ (h >>> 16)) >>> 0) / 4294967295;
}

/**
 * The canvas plumbing behind the ASCII scenes (after ../ascii-sharks): a monospace cell grid
 * sized for the device pixel ratio, colours read from the CSS tokens and refreshed on theme
 * change, a frame loop that pauses off-screen or in a background tab, and a single still frame
 * for reduced motion.
 */
export function useAsciiCanvas(
    canvas: Ref<HTMLCanvasElement | undefined>,
    scene: {
        step?: (dt: number) => void;
        draw: (grid: AsciiGrid) => void;
        /** Called before the reduced-motion still frame, to park the animation somewhere useful. */
        still?: () => void;
    },
) {
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    let raf = 0;
    let last = 0;
    let visible = true;

    const grid: AsciiGrid = {
        ctx: null,
        width: 0,
        height: 0,
        cellW: 9,
        cols: 0,
        rows: 0,
        colors: {
            ink: '#2b2b2b',
            muted: '#706d68',
            brand: '#3b71ca',
            status: {
                red: '#c0392b',
                yellow: '#d4a017',
                green: '#2e8b57',
                unknown: '#999',
            },
        },
        glyph(text, col, row, color, alpha = 1) {
            const ctx = grid.ctx!;
            ctx.globalAlpha = Math.max(0, Math.min(1, alpha));
            ctx.fillStyle = color;

            for (let i = 0; i < text.length; i++) {
                if (text[i] !== ' ') {
                    ctx.fillText(
                        text[i],
                        (col + i + 0.5) * grid.cellW,
                        (row + 0.5) * CELL_H,
                    );
                }
            }
        },
        clear(col, row, length) {
            grid.ctx!.clearRect(
                col * grid.cellW,
                row * CELL_H,
                length * grid.cellW,
                CELL_H,
            );
        },
    };

    function readColors() {
        const style = getComputedStyle(document.documentElement);
        const read = (name: string) => style.getPropertyValue(name).trim();
        grid.colors = {
            ink: read('--foreground'),
            muted: read('--muted-foreground'),
            brand: read('--brand'),
            status: Object.fromEntries(
                statusOrder.map((status) => [status, read(CSS_VAR[status])]),
            ) as Record<Status, string>,
        };
    }

    function draw() {
        const ctx = grid.ctx;

        if (!ctx || grid.width < 1) {
            return;
        }

        ctx.clearRect(0, 0, grid.width, grid.height);
        scene.draw(grid);
        ctx.globalAlpha = 1;
    }

    function resize() {
        const el = canvas.value;
        const ctx = grid.ctx;

        if (!el || !ctx) {
            return;
        }

        const rect = el.getBoundingClientRect();
        const dpr = Math.min(window.devicePixelRatio || 1, 2);
        grid.width = rect.width;
        grid.height = rect.height;
        el.width = Math.round(grid.width * dpr);
        el.height = Math.round(grid.height * dpr);
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.font = `${FONT_SIZE}px ${getComputedStyle(el).fontFamily}`;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        grid.cellW = (ctx.measureText('M').width || 7) + TRACK;
        grid.cols = Math.floor(grid.width / grid.cellW);
        grid.rows = Math.floor(grid.height / CELL_H);
        draw();
    }

    function frame(now: number) {
        // The first rAF timestamp can predate performance.now() from schedule().
        const dt = Math.min(0.1, Math.max(0, (now - last) / 1000));
        last = now;
        scene.step?.(dt);
        draw();
        raf = requestAnimationFrame(frame);
    }

    function schedule() {
        cancelAnimationFrame(raf);

        if (reducedMotion.matches) {
            scene.still?.();
            draw();

            return;
        }

        if (!visible || document.hidden) {
            return;
        }

        last = performance.now();
        raf = requestAnimationFrame(frame);
    }

    /** Pointer position in px and in cells. */
    function local(event: PointerEvent) {
        const rect = canvas.value!.getBoundingClientRect();
        const x = event.clientX - rect.left;
        const y = event.clientY - rect.top;

        return {
            x,
            y,
            col: Math.floor(x / grid.cellW),
            row: Math.floor(y / CELL_H),
        };
    }

    let resizeObserver: ResizeObserver;
    let intersectionObserver: IntersectionObserver;
    let themeObserver: MutationObserver;

    onMounted(() => {
        grid.ctx = canvas.value!.getContext('2d');
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
        document.addEventListener('visibilitychange', schedule);
        // Canvas text can't trigger a webfont load, so redraw once Courier Prime is in.
        void document.fonts?.ready.then(resize);
        schedule();
    });

    onBeforeUnmount(() => {
        cancelAnimationFrame(raf);
        resizeObserver.disconnect();
        intersectionObserver.disconnect();
        themeObserver.disconnect();
        reducedMotion.removeEventListener('change', schedule);
        document.removeEventListener('visibilitychange', schedule);
    });

    return { grid, draw, local, reducedMotion };
}

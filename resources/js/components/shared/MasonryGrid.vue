<script setup lang="ts">
/**
 * Pinterest-style packing for a set of variable-height cards.
 *
 * CSS multi-column, which this replaces, fills column-by-column in document
 * order and can only choose where to break: the tallest unbreakable card sets
 * the height of every column and the remainder shows up as dead space at the
 * bottom of the last one.
 *
 * Native `grid-template-rows: masonry` has not shipped in stable Chromium, and
 * `grid-auto-flow: dense` only ever reaches a first-fit result — measurably
 * worse than multicol when a few short cards trail a tall one. So the cards are
 * measured here, balanced across the columns, and placed explicitly on a grid
 * whose rows are 1px, which makes every position exact.
 *
 * Column count stays with the caller as ordinary `grid-cols-*` utilities and is
 * read back from the computed track list, so the usual responsive classes work.
 */
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        /** Gutter between cards, both axes, in px. */
        gap?: number;
    }>(),
    { gap: 16 },
);

/**
 * Above this many cards the exhaustive balance gives way to the greedy
 * longest-first one. The panel renders eight at most; the cap guards whatever
 * reuses this next.
 */
const EXHAUSTIVE_LIMIT = 12;

const root = ref<HTMLElement | null>(null);

/**
 * Until the first measurement lands the grid stays on `auto` rows, so a failed
 * or still-pending script degrades to a plain equal-row grid rather than to a
 * stack of 1px slivers.
 */
const measured = ref(false);

let cardObserver: ResizeObserver | null = null;
let rootObserver: ResizeObserver | null = null;
let mutationObserver: MutationObserver | null = null;
let frame = 0;

function cards(): HTMLElement[] {
    return root.value ? (Array.from(root.value.children) as HTMLElement[]) : [];
}

function tallest(values: number[]): number {
    return values.reduce((max, value) => (value > max ? value : max), 0);
}

function columnHeights(heights: number[], assignment: number[], columns: number, gap: number): number[] {
    const filled = new Array(columns).fill(0);

    heights.forEach((height, index) => {
        filled[assignment[index]] += height + gap;
    });

    return filled.map((total) => (total > 0 ? total - gap : 0));
}

/** Sort tallest first, then drop each card into whichever column is shortest. */
function longestFirst(heights: number[], columns: number, gap: number): number[] {
    const filled = new Array(columns).fill(0);
    const assignment = new Array(heights.length).fill(0);
    const order = heights.map((height, index) => index).sort((a, b) => heights[b] - heights[a]);

    for (const index of order) {
        let shortest = 0;

        for (let column = 1; column < columns; column++) {
            if (filled[column] < filled[shortest]) {
                shortest = column;
            }
        }

        assignment[index] = shortest;
        filled[shortest] += heights[index] + gap;
    }

    return assignment;
}

/**
 * Assign each card to a column so the tallest column ends up as short as it
 * can, which is the same as saying the grid wastes as little space as possible.
 *
 * The search enumerates restricted growth strings — a card may only open the
 * next unused column — which drops every relabelling of the same partition and
 * so also pins the first card to the first column. That is wanted here anyway:
 * the status timeline reads as the panel's lead card.
 *
 * @param heights Card heights in px, in document order.
 * @return Column index per card, in document order.
 */
function balance(heights: number[], columns: number, gap: number): number[] {
    const count = heights.length;

    if (columns <= 1 || count === 0) {
        return new Array(count).fill(0);
    }

    if (count > EXHAUSTIVE_LIMIT) {
        return longestFirst(heights, columns, gap);
    }

    const assignment = new Array(count).fill(0);
    const filled = new Array(columns).fill(0);

    let best = longestFirst(heights, columns, gap);
    let bestTallest = tallest(columnHeights(heights, best, columns, gap));
    let bestSpread = Infinity;

    /** Height still to be placed once card `i - 1` and everything before it is down. */
    const remaining = new Array(count + 1).fill(0);
    for (let i = count - 1; i >= 0; i--) {
        remaining[i] = remaining[i + 1] + heights[i] + gap;
    }

    const search = (index: number, opened: number): void => {
        if (index === count) {
            const perColumn = filled.map((total) => (total > 0 ? total - gap : 0));
            const top = tallest(perColumn);
            const spread = top - Math.min(...perColumn);

            if (top < bestTallest || (top === bestTallest && spread < bestSpread)) {
                bestTallest = top;
                bestSpread = spread;
                best = assignment.slice();
            }

            return;
        }

        for (let column = 0; column < Math.min(opened + 1, columns); column++) {
            filled[column] += heights[index] + gap;

            // Nothing below this branch can beat either the column it just grew
            // or an even spread of everything still to come.
            const placed = filled.reduce((sum, total) => sum + total, 0);
            const bound = Math.max(tallest(filled) - gap, Math.ceil((placed + remaining[index + 1]) / columns) - gap);

            if (bound <= bestTallest) {
                assignment[index] = column;
                search(index + 1, Math.max(opened, column + 1));
            }

            filled[column] -= heights[index] + gap;
        }
    };

    search(0, 0);

    return best;
}

function layout(): void {
    const element = root.value;

    if (!element) {
        return;
    }

    const items = cards();

    // A grid with no layout box reports its tracks unresolved, so there is
    // nothing to measure against.
    if (items.length === 0 || element.getClientRects().length === 0) {
        return;
    }

    // Placing a card in a column the caller's `grid-cols-*` never declared adds
    // an implicit track, and the computed track list reports it. Left in place,
    // last pass's columns would be read back as this pass's column count and
    // the grid could never shed a column on the way down a breakpoint.
    for (const card of items) {
        card.style.gridColumn = '';
        card.style.gridRow = '';
    }

    const columns = getComputedStyle(element).gridTemplateColumns.split(' ').filter(Boolean).length || 1;
    const heights = items.map((card) => Math.max(1, Math.ceil(card.getBoundingClientRect().height)));
    const assignment = balance(heights, columns, props.gap);
    const offsets = new Array(columns).fill(0);

    items.forEach((card, index) => {
        const column = assignment[index];
        const row = offsets[column];

        card.style.gridColumn = String(column + 1);
        card.style.gridRow = `${row + 1} / span ${heights[index]}`;

        // Only the gaps *between* cards are laid down, so no column carries a
        // trailing gutter the container would then have to cancel.
        offsets[column] = row + heights[index] + props.gap;
    });

    measured.value = true;
}

function schedule(): void {
    if (frame) {
        return;
    }

    frame = requestAnimationFrame(() => {
        frame = 0;
        layout();
    });
}

/** Re-observe from scratch: cards come and go with the order state. */
function watchCards(): void {
    if (!cardObserver) {
        return;
    }

    cardObserver.disconnect();

    for (const card of cards()) {
        cardObserver.observe(card);
    }

    schedule();
}

onMounted(() => {
    if (!root.value || typeof ResizeObserver === 'undefined') {
        return;
    }

    cardObserver = new ResizeObserver(schedule);

    // The track count changes at whatever breakpoints the caller set, which no
    // card resize necessarily reports.
    rootObserver = new ResizeObserver(schedule);
    rootObserver.observe(root.value);

    mutationObserver = new MutationObserver(watchCards);
    mutationObserver.observe(root.value, { childList: true });

    watchCards();
    layout();
});

onBeforeUnmount(() => {
    cancelAnimationFrame(frame);
    cardObserver?.disconnect();
    rootObserver?.disconnect();
    mutationObserver?.disconnect();
    cardObserver = rootObserver = mutationObserver = null;
});

watch(() => props.gap, schedule);
</script>

<template>
    <!--
        `items-start` is load-bearing: a stretched card would grow to fill the
        row span just written to it, which the ResizeObserver would then measure
        and write back, and so on.
    -->
    <div ref="root" class="grid items-start" :style="measured ? { gridAutoRows: '1px', rowGap: '0px', columnGap: `${gap}px` } : { gap: `${gap}px` }">
        <slot />
    </div>
</template>

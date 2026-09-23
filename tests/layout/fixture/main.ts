import MasonryGrid from '@/components/shared/MasonryGrid.vue';
import { createApp, h, ref, type VNode } from 'vue';
import './app.css';

/**
 * Fixture page for the masonry layout specs.
 *
 * Cards are driven from the query string so a spec can describe a card set as
 * data — `?h=[760,150,880]` — rather than needing a page per scenario. Two
 * cards mirror shapes from VehicleExpandedPanel that the plain height cards
 * cannot stand in for:
 *
 * - `wide`  a card holding the repair table's `min-w-[420px]`, so a spec can
 *           check the table scrolls inside its card instead of widening it.
 * - `tiles` the repair card's three amount tiles behind a container query, so
 *           a spec can prove the query keys off the card and not the viewport.
 */
const params = new URLSearchParams(location.search);
const heights = ref<number[]>(JSON.parse(params.get('h') ?? '[]'));
const wide = params.get('wide') === '1';
const tiles = params.get('tiles') === '1';

function heightCard(height: number, index: number): VNode {
    return h('div', {
        key: `h${index}`,
        class: 'card rounded-3xl border border-[#ececec] bg-white',
        'data-h': String(height),
        style: `height:${height}px`,
    });
}

/** Stands in for the Reparaturangebot card's horizontally scrolling table. */
function wideCard(): VNode {
    return h('div', { key: 'wide', class: 'card rounded-3xl border border-[#ececec] bg-white p-6', 'data-role': 'wide' }, [
        h('div', { class: 'overflow-x-auto', 'data-role': 'scroller' }, [
            h('table', { class: 'w-full min-w-[420px] border-collapse text-left', 'data-role': 'table' }, [
                h('tbody', [h('tr', [h('td', 'Position'), h('td', 'Gutachten'), h('td', 'Reparatur'), h('td', 'Ersparnis')])]),
            ]),
        ]),
    ]);
}

/** Stands in for the amount tiles, container query and all. */
function tilesCard(): VNode {
    return h('div', { key: 'tiles', class: 'card @container rounded-3xl border border-[#ececec] bg-white p-6', 'data-role': 'tiles' }, [
        h(
            'div',
            { class: '@max-[420px]:grid-cols-1 grid grid-cols-3 gap-3', 'data-role': 'tile-grid' },
            [1, 2, 3].map((n) => h('div', { key: n, class: 'tile rounded-[13px] bg-[#f6f9f8] px-4 py-3' }, `Tile ${n}`)),
        ),
    ]);
}

createApp({
    setup() {
        return () =>
            h('div', { id: 'wrap', class: 'bg-[#EFEFEF] p-4' }, [
                h(MasonryGrid, { class: 'grid-cols-1 md:grid-cols-2 2xl:grid-cols-3' }, () => [
                    ...heights.value.map(heightCard),
                    ...(wide ? [wideCard()] : []),
                    ...(tiles ? [tilesCard()] : []),
                ]),
            ]);
    },
}).mount('#app');

declare global {
    interface Window {
        setHeights: (next: number[]) => void;
    }
}

window.setHeights = (next) => {
    heights.value = next;
};

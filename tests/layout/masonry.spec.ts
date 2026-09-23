import { expect, test, type Page } from '@playwright/test';

/**
 * MasonryGrid replaced the CSS multi-column layout the vehicle panel used to
 * pack its cards with. Multicol fills column-by-column in document order, so
 * the tallest unbreakable card set the height of every column and the rest
 * showed up as dead space at the bottom of the last one. These specs hold the
 * replacement to the two things that has to be true of it: the packing is
 * sound (no overlap, exact gutters, nothing clipped, nothing dragged sideways)
 * and it never reserves more space than the multicol it replaced.
 */

const GAP = 16;

/** Card heights in px, taken from what the panel actually renders. */
const PROFILES = {
    /** No order yet: three of the five cards are one-line empty states. */
    noOrder: [140, 150, 200, 120, 370],
    /** Order placed: timeline and repair card both run long. */
    orderPlaced: [760, 150, 880, 520, 300, 370, 260, 190],
    /** A repair card with a long line-item table dwarfing everything else. */
    bigRepair: [620, 200, 1200, 480, 300, 370],
    /** B2B, where collection and notes cards render too. */
    orderWithNotes: [900, 420, 300, 640, 260, 370, 180, 220],
};

const BREAKPOINTS = [
    { width: 1600, columns: 3 },
    { width: 1200, columns: 2 },
    { width: 600, columns: 1 },
];

interface Placement {
    trackCount: number;
    columnBottoms: number[];
    gaps: number[];
    overlaps: boolean;
    clipped: boolean;
    firstCardLeadsFirstColumn: boolean;
    contentBottom: number;
    gridHeight: number;
    overflowsSideways: boolean;
}

function fixtureUrl(heights: number[], extra: Record<string, string> = {}): string {
    const params = new URLSearchParams({ h: JSON.stringify(heights), ...extra });

    return `/?${params.toString()}`;
}

async function open(page: Page, heights: number[], extra?: Record<string, string>): Promise<void> {
    await page.goto(fixtureUrl(heights, extra));
    await expect(page.locator('.card').first()).toBeVisible();
    // The first pack lands in the mount tick; a frame settles any observer work.
    await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))));
}

async function readPlacement(page: Page): Promise<Placement> {
    return page.evaluate(() => {
        const grid = document.querySelector('.grid') as HTMLElement;
        const gridBox = grid.getBoundingClientRect();
        const cards = [...grid.children].map((element) => {
            const box = element.getBoundingClientRect();

            return {
                left: Math.round(box.left),
                right: Math.round(box.right),
                top: Math.round(box.top - gridBox.top),
                bottom: Math.round(box.bottom - gridBox.top),
                height: Math.round(box.height),
                declared: Number((element as HTMLElement).dataset.h ?? NaN),
            };
        });

        const columns = new Map<number, typeof cards>();
        for (const card of cards) {
            columns.set(card.left, [...(columns.get(card.left) ?? []), card]);
        }

        const gaps: number[] = [];
        let overlaps = false;
        for (const column of columns.values()) {
            column.sort((a, b) => a.top - b.top);
            column.forEach((card, index) => {
                if (index === 0) {
                    return;
                }
                const delta = card.top - column[index - 1].bottom;
                gaps.push(delta);
                if (delta < 0) {
                    overlaps = true;
                }
            });
        }

        const ordered = [...columns.entries()].sort((a, b) => a[0] - b[0]);
        const leftmost = ordered[0][0];

        return {
            trackCount: getComputedStyle(grid).gridTemplateColumns.split(' ').filter(Boolean).length,
            columnBottoms: ordered.map(([, column]) => Math.max(...column.map((card) => card.bottom))),
            gaps,
            overlaps,
            // Cards without a declared height (the wide and tiles cards) are sized by content.
            clipped: cards.some((card) => !Number.isNaN(card.declared) && card.height !== card.declared),
            firstCardLeadsFirstColumn: cards[0].left === leftmost && cards[0].top === 0,
            contentBottom: Math.max(...cards.map((card) => card.bottom)),
            gridHeight: Math.round(gridBox.height),
            overflowsSideways: grid.scrollWidth > grid.clientWidth || cards.some((card) => card.right > Math.round(gridBox.right)),
        };
    });
}

/**
 * The space the layout reserves and no card fills. Independent of how the
 * columns are balanced, so it compares the old layout and the new one fairly
 * even when one leaves a column empty and the other does not.
 */
function deadSpace(columns: number, contentBottom: number, heights: number[]): number {
    return columns * contentBottom - heights.reduce((total, height) => total + height, 0);
}

/** What CSS multi-column produced for the same cards, measured in the same page. */
async function multicolContentBottom(page: Page, heights: number[], columns: number): Promise<number> {
    return page.evaluate(
        ({ heights, columns, gap }) => {
            const width = document.querySelector('.grid')!.getBoundingClientRect().width;
            const probe = document.createElement('div');
            probe.style.cssText = `columns:${columns};column-gap:${gap}px;width:${width}px`;

            for (const height of heights) {
                const card = document.createElement('div');
                card.style.cssText = `height:${height}px;break-inside:avoid;margin-bottom:${gap}px;background:#fff`;
                probe.appendChild(card);
            }

            document.body.appendChild(probe);
            const top = probe.getBoundingClientRect().top;
            const bottom = Math.max(...[...probe.children].map((card) => Math.round(card.getBoundingClientRect().bottom - top)));
            probe.remove();

            return bottom;
        },
        { heights, columns, gap: GAP },
    );
}

for (const { width, columns } of BREAKPOINTS) {
    test.describe(`at ${width}px (${columns} column${columns > 1 ? 's' : ''})`, () => {
        test.use({ viewport: { width, height: 900 } });

        for (const [name, heights] of Object.entries(PROFILES)) {
            test(`packs ${name} soundly`, async ({ page }) => {
                await open(page, heights);
                const placement = await readPlacement(page);

                expect(placement.trackCount).toBe(columns);
                expect(placement.overlaps).toBe(false);
                expect(placement.clipped).toBe(false);
                expect(placement.gaps.every((gap) => gap === GAP)).toBe(true);
                expect(placement.overflowsSideways).toBe(false);
                // Every column the caller asked for is used, and the lead card
                // stays where a reader looks first.
                expect(placement.columnBottoms).toHaveLength(Math.min(columns, heights.length));
                expect(placement.firstCardLeadsFirstColumn).toBe(true);
                // No trailing gutter: the grid ends where the last card ends.
                expect(placement.gridHeight).toBe(placement.contentBottom);
            });

            test(`wastes no more space than multicol on ${name}`, async ({ page }) => {
                await open(page, heights);
                const placement = await readPlacement(page);
                const before = await multicolContentBottom(page, heights, columns);

                expect(deadSpace(columns, placement.contentBottom, heights)).toBeLessThanOrEqual(deadSpace(columns, before, heights));
            });
        }
    });
}

test.describe('responding to change', () => {
    test.use({ viewport: { width: 1600, height: 900 } });

    test('repacks when cards come and go', async ({ page }) => {
        await open(page, PROFILES.orderPlaced);

        for (const heights of [[760, 150, 880, 520, 300], [760, 150, 880, 520, 300, 400, 250, 180, 320], [300]]) {
            await page.evaluate((next) => window.setHeights(next), heights);
            await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))));

            const placement = await readPlacement(page);
            expect(placement.overlaps, `with ${heights.length} cards`).toBe(false);
            expect(placement.gaps.every((gap) => gap === GAP)).toBe(true);
            expect(placement.columnBottoms).toHaveLength(Math.min(3, heights.length));
        }
    });

    test('sheds and regains columns across breakpoints', async ({ page }) => {
        await open(page, PROFILES.orderPlaced);

        // Explicit placement creates implicit tracks; if those are ever read
        // back as the column count, the grid can never shed a column.
        for (const { width, columns } of [...BREAKPOINTS, { width: 1600, columns: 3 }]) {
            await page.setViewportSize({ width, height: 900 });
            await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))));

            const placement = await readPlacement(page);
            expect(placement.trackCount, `at ${width}px`).toBe(columns);
            expect(placement.columnBottoms, `at ${width}px`).toHaveLength(columns);
            expect(placement.overlaps).toBe(false);
        }
    });

    test('reports no ResizeObserver loop errors while repacking', async ({ page }) => {
        const loops: string[] = [];
        page.on('console', (message) => message.text().includes('ResizeObserver loop') && loops.push(message.text()));
        page.on('pageerror', (error) => error.message.includes('ResizeObserver loop') && loops.push(error.message));

        await open(page, PROFILES.orderPlaced);
        for (const width of [1200, 600, 1600, 900]) {
            await page.setViewportSize({ width, height: 900 });
            await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))));
        }

        expect(loops).toEqual([]);
    });
});

test.describe('a card whose content is wider than its track', () => {
    // 900px puts two columns on screen at ~426px each, so the repair table's
    // 420px floor genuinely outgrows the card it sits in. Wider windows give
    // the table room and prove nothing.
    test.use({ viewport: { width: 900, height: 900 } });

    test('keeps its track and scrolls inside itself', async ({ page }) => {
        await open(page, [300, 200], { wide: '1' });

        const placement = await readPlacement(page);
        // Tailwind's `grid-cols-*` floors each track at `minmax(0, 1fr)`, so the
        // table's 420px cannot widen one. This pins that down: the card holds
        // its track and the table scrolls inside it rather than pushing the
        // panel sideways, which is what the card was built to do.
        expect(placement.overflowsSideways).toBe(false);

        const { cardWidth, trackWidth, scrolls } = await page.evaluate(() => {
            const grid = document.querySelector('.grid') as HTMLElement;
            const card = document.querySelector('[data-role="wide"]') as HTMLElement;
            const scroller = document.querySelector('[data-role="scroller"]') as HTMLElement;

            return {
                cardWidth: Math.round(card.getBoundingClientRect().width),
                trackWidth: Math.round(parseFloat(getComputedStyle(grid).gridTemplateColumns.split(' ')[0])),
                scrolls: scroller.scrollWidth > scroller.clientWidth,
            };
        });

        expect(cardWidth).toBeLessThanOrEqual(trackWidth);
        expect(scrolls).toBe(true);
    });
});

/**
 * The amount tiles used a `max-[560px]` viewport query while sitting in a card
 * one masonry track wide, so the query fired on the window and never on the
 * space the tiles actually had. Keyed to the card instead, the same tiles stack
 * exactly when their card is too narrow for three of them.
 */
test.describe('the amount tiles', () => {
    async function tileColumns(page: Page): Promise<{ tiles: number; cardWidth: number }> {
        return page.evaluate(() => {
            const grid = document.querySelector('[data-role="tile-grid"]') as HTMLElement;
            const card = document.querySelector('[data-role="tiles"]') as HTMLElement;

            return {
                tiles: getComputedStyle(grid).gridTemplateColumns.split(' ').filter(Boolean).length,
                cardWidth: Math.round(card.getBoundingClientRect().width),
            };
        });
    }

    test('sit three across when the card can hold them', async ({ page }) => {
        await page.setViewportSize({ width: 1600, height: 900 });
        await open(page, [300, 200], { tiles: '1' });

        const { tiles, cardWidth } = await tileColumns(page);
        expect(cardWidth).toBeGreaterThanOrEqual(420);
        expect(tiles).toBe(3);
    });

    test('stack once the card is too narrow, even on a wide window', async ({ page }) => {
        // 800px is the case the old viewport query missed: well past its 560px
        // threshold, but two columns leave each card under 420px.
        await page.setViewportSize({ width: 800, height: 900 });
        await open(page, [300, 200], { tiles: '1' });

        const { tiles, cardWidth } = await tileColumns(page);
        expect(cardWidth).toBeLessThan(420);
        expect(tiles).toBe(1);
    });
});

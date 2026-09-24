import DamageGallery from '@/components/shared/DamageGallery.vue';
import type { DamageGalleryImage } from '@/types/order';
import { createApp, h } from 'vue';
import './app.css';

const params = new URLSearchParams(location.search);
const count = Number.parseInt(params.get('count') ?? '3', 10);
const broken = Number.parseInt(params.get('broken') ?? '0', 10);
const COLORS = ['#c0392b', '#2f9e77', '#1a4a9c', '#9a5b00', '#10393b', '#6f8585'];

/**
 * Thumbnails and full images differ in intrinsic size on purpose: a test can
 * then read naturalWidth to prove which variant the browser actually fetched,
 * rather than trusting that the right URL was passed.
 */
function imageUrl(width: number, height: number, color: string): string {
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}"><rect width="${width}" height="${height}" fill="${color}"/></svg>`;

    return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
}

const images: DamageGalleryImage[] = Array.from({ length: count }, (_, index) => {
    const color = COLORS[index % COLORS.length];
    const isBroken = index < broken;

    return {
        id: `image-${index + 1}`,
        url: isBroken ? '/missing-image.png' : imageUrl(1600, 900, color),
        thumbnail_url: isBroken ? '/missing-thumbnail.png' : imageUrl(400, 225, color),
        caption: index === 0 ? 'Stoßfänger vorne links' : null,
    };
});

createApp({
    setup() {
        return () =>
            h('div', { id: 'wrap', class: 'bg-[#f6f9f8] p-4' }, [
                h('button', { type: 'button', id: 'before' }, 'Davor'),
                h('div', { class: 'rounded-3xl border border-[#ececec] bg-white p-6' }, [
                    h(DamageGallery, { images, label: 'Schadenbilder Position 1' }),
                ]),
            ]);
    },
}).mount('#app');

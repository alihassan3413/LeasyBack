import DamageGallery from '@/components/shared/DamageGallery.vue';
import type { DamageGalleryImage } from '@/types/order';
import { createApp, h } from 'vue';
import './app.css';

const params = new URLSearchParams(location.search);
const count = Number.parseInt(params.get('count') ?? '3', 10);
const COLORS = ['#c0392b', '#2f9e77', '#1a4a9c', '#9a5b00', '#10393b', '#6f8585'];

function imageUrl(index: number): string {
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="1600" height="900"><rect width="1600" height="900" fill="${COLORS[index % COLORS.length]}"/></svg>`;

    return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
}

const images: DamageGalleryImage[] = Array.from({ length: count }, (_, index) => ({
    id: `image-${index + 1}`,
    url: imageUrl(index),
    caption: index === 0 ? 'Stoßfänger vorne links' : null,
}));

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

import AdditionalDamageCard from '@/components/workshop/AdditionalDamageCard.vue';
import type { AdditionalDamageDraft } from '@/types/order';
import { createApp, h, ref } from 'vue';
import './app.css';

const params = new URLSearchParams(location.search);
const disabled = params.get('disabled') === '1';
const maxImages = Number.parseInt(params.get('maxImages') ?? '5', 10);
const errors = params.get('errors') === '1';
const maxImagesTotal = Number.parseInt(params.get('maxImagesTotal') ?? '999', 10);

/** Mirrors the page's imageAllowanceFor(): per-card cap, minus what other cards hold. */
function allowanceFor(index: number): number {
    const used = positions.value.reduce((sum, position, other) => (other === index ? sum : sum + position.images.length), 0);

    return Math.max(0, Math.min(maxImages, maxImagesTotal - used));
}

function draft(): AdditionalDamageDraft {
    return { component: '', damage_description: '', repair_method: '', amount_net: '', images: [] };
}

const positions = ref<AdditionalDamageDraft[]>(
    Number.parseInt(params.get('positions') ?? '0', 10) > 0 ? Array.from({ length: Number.parseInt(params.get('positions') ?? '0', 10) }, draft) : [],
);

/** Mirrors the page's own lookup of `additional_positions.<index>.<field>`. */
function error(field: string): string | undefined {
    if (!errors) {
        return undefined;
    }

    return (
        {
            component: 'Bauteil muss ausgefüllt werden.',
            damage_description: 'Schadenbeschreibung muss ausgefüllt werden.',
            amount_net: 'Nettopreis muss ausgefüllt werden.',
        }[field] ?? undefined
    );
}

createApp({
    setup() {
        return () =>
            h('div', { id: 'wrap', class: 'bg-[#f6f9f8] p-4' }, [
                h('div', { class: 'mx-auto flex max-w-3xl flex-col gap-3 rounded-3xl border border-[#ececec] bg-white p-6' }, [
                    ...positions.value.map((position, index) =>
                        h(AdditionalDamageCard, {
                            key: index,
                            position,
                            'onUpdate:position': (value: AdditionalDamageDraft) => (positions.value[index] = value),
                            index,
                            maxImages: allowanceFor(index),
                            disabled,
                            error,
                            // Mirrors the page: it owns revocation, so removing a
                            // card revokes exactly that card's previews.
                            onRemove: () => {
                                for (const image of positions.value[index]?.images ?? []) {
                                    URL.revokeObjectURL(image.preview);
                                }
                                positions.value.splice(index, 1);
                            },
                        }),
                    ),
                    h(
                        'button',
                        {
                            type: 'button',
                            disabled,
                            'data-testid': 'additional-damage-add',
                            class: 'w-full cursor-pointer rounded-[13px] border border-dashed border-[#c9d6d5] bg-white px-4 py-3 text-[13px] font-bold text-[#10393b] disabled:opacity-50',
                            onClick: () => positions.value.push(draft()),
                        },
                        'Zusätzlichen Schaden melden',
                    ),
                ]),
            ]);
    },
}).mount('#app');

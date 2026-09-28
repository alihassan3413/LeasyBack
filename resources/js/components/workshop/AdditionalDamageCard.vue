<script setup lang="ts">
/**
 * One damage the workshop found that the Gutachten does not list.
 *
 * Images stay Files until the form is sent — the preview is an object URL, so
 * adding and removing costs no request and nothing is stored for a submission
 * that never happens.
 *
 * Removing an image here revokes its own URL, but this card deliberately does
 * not revoke on unmount: the cards are keyed by index, so removing one unmounts
 * the *last* component while the survivor's data slides down into a kept one —
 * an unmount-time revoke kills previews that are still on screen. The page owns
 * revocation for whole cards, and the document unload clears whatever is left.
 */
import InputError from '@/components/InputError.vue';
import RequiredMark from '@/components/form/RequiredMark.vue';
import { Input } from '@/components/ui/input';
import type { AdditionalDamageDraft } from '@/types/order';
import MdiClose from '~icons/mdi/close';
import MdiImagePlusOutline from '~icons/mdi/image-plus-outline';

const position = defineModel<AdditionalDamageDraft>('position', { required: true });

const props = defineProps<{
    index: number;
    maxImages: number;
    disabled?: boolean;
    error: (field: string) => string | undefined;
}>();

const emit = defineEmits<{ (e: 'remove'): void }>();

function addImages(event: Event) {
    const input = event.target as HTMLInputElement;
    const free = props.maxImages - position.value.images.length;

    for (const file of Array.from(input.files ?? []).slice(0, Math.max(free, 0))) {
        position.value.images.push({ file, preview: URL.createObjectURL(file) });
    }

    // Lets the same file be chosen again after it was removed.
    input.value = '';
}

function removeImage(imageIndex: number) {
    const [removed] = position.value.images.splice(imageIndex, 1);

    if (removed) {
        URL.revokeObjectURL(removed.preview);
    }
}
</script>

<template>
    <div class="rounded-[13px] border border-dashed border-[#d9a441] bg-[#fffaf0] p-3" data-testid="additional-damage-card">
        <div class="mb-3 flex items-start justify-between gap-3">
            <p class="text-[12px] font-bold tracking-wide text-[#a9741b] uppercase">Zusätzlicher Schaden {{ index + 1 }}</p>
            <button
                type="button"
                class="cursor-pointer rounded-[9px] px-2 py-1 text-[12px] font-bold text-[#c0392b] transition-colors hover:bg-[#c0392b]/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#c0392b] motion-reduce:transition-none"
                :disabled="disabled"
                data-testid="additional-damage-remove"
                @click="emit('remove')"
            >
                Entfernen
            </button>
        </div>

        <div class="grid grid-cols-2 gap-3 max-[560px]:grid-cols-1">
            <label class="flex flex-col gap-1">
                <span class="text-[12.5px] font-bold text-[#10393b]">Bauteil <RequiredMark /></span>
                <Input v-model="position.component" :disabled="disabled" placeholder="z. B. Tür vorne links" />
                <InputError :message="error('component')" />
            </label>

            <label class="flex flex-col gap-1">
                <span class="text-[12.5px] font-bold text-[#10393b]">Reparaturweg</span>
                <Input v-model="position.repair_method" :disabled="disabled" placeholder="z. B. Lackieren" />
                <InputError :message="error('repair_method')" />
            </label>

            <label class="col-span-2 flex flex-col gap-1 max-[560px]:col-span-1">
                <span class="text-[12.5px] font-bold text-[#10393b]">Schadenbeschreibung <RequiredMark /></span>
                <textarea
                    v-model="position.damage_description"
                    rows="2"
                    :disabled="disabled"
                    placeholder="Was ist beschädigt?"
                    class="w-full rounded-[9px] border border-[#e9efee] bg-white px-3 py-2 text-[13px] text-[#10393b] outline-none focus-visible:border-[#01b990] disabled:opacity-60"
                />
                <InputError :message="error('damage_description')" />
            </label>

            <label class="flex flex-col gap-1">
                <span class="text-[12.5px] font-bold text-[#10393b]">Preis netto <RequiredMark /></span>
                <Input v-model="position.amount_net" type="number" min="0.01" step="0.01" :disabled="disabled" placeholder="0,00" />
                <InputError :message="error('amount_net')" />
            </label>
        </div>

        <div class="mt-3">
            <p class="mb-1.5 text-[12.5px] font-bold text-[#10393b]">
                Schadenbilder
                <span class="font-normal text-[#9bb0af]">({{ position.images.length }} von {{ maxImages }})</span>
            </p>

            <ul class="flex flex-wrap gap-2">
                <li v-for="(image, imageIndex) in position.images" :key="image.preview" class="relative">
                    <img
                        :src="image.preview"
                        :alt="`Bild ${imageIndex + 1} zu ${position.component || 'zusätzlichem Schaden'}`"
                        class="size-[72px] rounded-[9px] border border-[#e9efee] object-cover"
                        data-testid="additional-damage-preview"
                    />
                    <button
                        type="button"
                        :disabled="disabled"
                        :aria-label="`Bild ${imageIndex + 1} entfernen`"
                        class="absolute -top-1.5 -right-1.5 flex size-6 cursor-pointer items-center justify-center rounded-full bg-[#10393b] text-white transition-colors hover:bg-[#c0392b] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#c0392b] motion-reduce:transition-none"
                        data-testid="additional-damage-image-remove"
                        @click="removeImage(imageIndex)"
                    >
                        <MdiClose class="size-4" aria-hidden="true" />
                    </button>
                </li>

                <li v-if="position.images.length < maxImages">
                    <label
                        class="flex size-[72px] cursor-pointer flex-col items-center justify-center gap-1 rounded-[9px] border border-dashed border-[#c9d6d5] bg-white text-[#6f8585] transition-colors focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-[#01b990] hover:border-[#01b990] motion-reduce:transition-none"
                    >
                        <MdiImagePlusOutline class="size-5" aria-hidden="true" />
                        <span class="text-[10.5px] font-bold">Bild</span>
                        <input
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            multiple
                            class="sr-only"
                            :disabled="disabled"
                            data-testid="additional-damage-image-input"
                            @change="addImages"
                        />
                        <span class="sr-only">Bild hinzufügen</span>
                    </label>
                </li>
            </ul>

            <InputError :message="error('images')" />
        </div>
    </div>
</template>

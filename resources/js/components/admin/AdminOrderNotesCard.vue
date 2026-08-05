<script setup lang="ts">
/**
 * Order notes with an explicit audience (b2b.txt §16).
 *
 * Two types, one list: internal notes are Leasyback-only, customer-visible
 * notes are shown to the company's authorised users. The audience is a
 * required choice — the form cannot be submitted without one, and the server
 * rejects a payload that omits it, so "clearly marked as customer-visible
 * before saving" is enforced on both sides rather than only here.
 *
 * Notes are not the message thread. Replies, read state and unread counts
 * belong to OrderMessages and are deliberately absent here.
 */
import InputError from '@/components/InputError.vue';
import type { AdminOrderNote } from '@/types/admin';
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import MdiNoteEditOutline from '~icons/mdi/note-edit-outline';

const props = defineProps<{
    orderId: string;
    notes: AdminOrderNote[];
}>();

const form = useForm({
    body: '',
    visibility: '' as '' | 'internal' | 'customer',
});

const canSubmit = computed(() => form.body.trim() !== '' && form.visibility !== '');

function submit() {
    if (!canSubmit.value) {
        return;
    }

    form.post(route('admin.orders.notes.store', props.orderId), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

function remove(note: AdminOrderNote) {
    if (!window.confirm('Diese Notiz wirklich löschen?')) {
        return;
    }

    form.delete(route('admin.orders.notes.destroy', [props.orderId, note.id]), { preserveScroll: true });
}

function formatDateTime(value: string | null): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString('de-DE', { dateStyle: 'short', timeStyle: 'short' });
}
</script>

<template>
    <div class="content-card">
        <div class="mb-4 flex items-center gap-2.5">
            <span class="flex h-9 w-9 items-center justify-center rounded-[11px] bg-[#4FA3A6]/15 text-[#2c7a7d]">
                <MdiNoteEditOutline class="size-[17px]" />
            </span>
            <div class="min-w-0 flex-1">
                <h2 class="text-[15px] font-semibold text-[#10393b]">Notizen</h2>
                <p class="text-xs text-[#6f8585]">Interne Notizen sieht nur Leasyback. Kundennotizen sieht der Kunde.</p>
            </div>
        </div>

        <form class="space-y-3" @submit.prevent="submit">
            <div>
                <textarea
                    v-model="form.body"
                    rows="3"
                    maxlength="5000"
                    placeholder="Notiz eingeben..."
                    class="w-full rounded-[10px] border border-gray-200 px-3 py-2 text-sm text-[#10393b] placeholder:text-[#9bb0b0] focus:border-emerald-400 focus:outline-none"
                />
                <InputError :message="form.errors.body" class="mt-1" />
            </div>

            <fieldset>
                <legend class="mb-2 text-xs font-medium text-[#6f8585]">Wer darf diese Notiz sehen?</legend>
                <div class="flex flex-col gap-2 sm:flex-row">
                    <label
                        class="flex flex-1 cursor-pointer items-start gap-2 rounded-[10px] border px-3 py-2 transition-colors"
                        :class="form.visibility === 'internal' ? 'border-[#2c7a7d] bg-[#4FA3A6]/10' : 'border-gray-200 hover:border-gray-300'"
                    >
                        <input v-model="form.visibility" type="radio" value="internal" class="mt-1" />
                        <span>
                            <span class="block text-sm font-medium text-[#10393b]">Nur intern</span>
                            <span class="block text-xs text-[#6f8585]">Für den Kunden nicht sichtbar.</span>
                        </span>
                    </label>

                    <label
                        class="flex flex-1 cursor-pointer items-start gap-2 rounded-[10px] border px-3 py-2 transition-colors"
                        :class="form.visibility === 'customer' ? 'border-[#ef8450] bg-[#ef8450]/10' : 'border-gray-200 hover:border-gray-300'"
                    >
                        <input v-model="form.visibility" type="radio" value="customer" class="mt-1" />
                        <span>
                            <span class="block text-sm font-medium text-[#10393b]">Für den Kunden sichtbar</span>
                            <span class="block text-xs text-[#6f8585]">Erscheint im Kundenportal.</span>
                        </span>
                    </label>
                </div>
                <InputError :message="form.errors.visibility" class="mt-1" />
            </fieldset>

            <div class="flex justify-end">
                <button
                    type="submit"
                    :disabled="!canSubmit || form.processing"
                    class="rounded-full px-5 py-2 text-sm font-semibold text-white transition-opacity disabled:cursor-not-allowed disabled:opacity-50"
                    style="background-color: #ef8450"
                >
                    {{ form.processing ? 'Wird gespeichert...' : 'Notiz speichern' }}
                </button>
            </div>
        </form>

        <div v-if="notes.length" class="mt-5 space-y-3 border-t border-gray-100 pt-4">
            <div
                v-for="note in notes"
                :key="note.id"
                class="rounded-[10px] border px-3 py-2.5"
                :class="note.visibility === 'customer' ? 'border-[#ef8450]/40 bg-[#ef8450]/5' : 'border-gray-200 bg-gray-50'"
            >
                <div class="mb-1 flex flex-wrap items-center gap-2">
                    <span
                        class="rounded-full px-2 py-0.5 text-[11px] font-semibold"
                        :class="note.visibility === 'customer' ? 'bg-[#ef8450] text-white' : 'bg-[#4FA3A6] text-white'"
                    >
                        {{ note.visibility === 'customer' ? 'Kunde sichtbar' : 'Intern' }}
                    </span>
                    <span class="text-xs text-[#6f8585]">{{ note.author_name }} · {{ formatDateTime(note.created_at) }}</span>
                    <button
                        type="button"
                        class="ml-auto text-xs text-red-600 hover:underline"
                        @click="remove(note)"
                    >
                        Löschen
                    </button>
                </div>
                <p class="text-sm whitespace-pre-line text-[#10393b]">{{ note.body }}</p>
            </div>
        </div>

        <p v-else class="mt-5 border-t border-gray-100 pt-4 text-sm text-[#6f8585]">Noch keine Notizen.</p>
    </div>
</template>

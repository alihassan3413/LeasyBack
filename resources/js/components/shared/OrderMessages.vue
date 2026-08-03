<script setup lang="ts">
/**
 * The order's message thread, rendered identically for Admin and customer —
 * only the alignment of the bubbles differs, since "mine" is decided by the
 * signed-in user, not by which page is hosting the section.
 */
import { useOrderMessages } from '@/composables/useOrderMessages';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import MdiMessageTextOutline from '~icons/mdi/message-text-outline';
import MdiSend from '~icons/mdi/send';

const props = withDefaults(
    defineProps<{
        orderId: string;
        auftragsnummer?: string | null;
        containerClass?: string;
    }>(),
    {
        auftragsnummer: null,
        containerClass: 'overflow-hidden rounded-[16px] border border-[#e6eded] bg-white',
    },
);

const page = usePage<SharedData>();
const currentUserId = computed(() => page.props.auth?.user?.id ?? null);

const { messages, unreadCount, loading, loaded, sending, hasOlder, load, loadOlder, send, markRead, listen } = useOrderMessages(
    props.orderId,
    currentUserId,
);

const MAX_LENGTH = 2000;

const draft = ref('');
const scroller = ref<HTMLElement | null>(null);
const composer = ref<HTMLTextAreaElement | null>(null);

const canSend = computed(() => draft.value.trim().length > 0 && !sending.value);
const remaining = computed(() => MAX_LENGTH - draft.value.length);

/** The composer starts one line tall and grows with the draft up to the
 *  max-height the class sets, after which it scrolls — a long message must
 *  not push the thread off the card. */
function resizeComposer(): void {
    nextTick(() => {
        const element = composer.value;

        if (!element) {
            return;
        }

        element.style.height = 'auto';
        element.style.height = `${element.scrollHeight}px`;
    });
}

watch(draft, resizeComposer);

function isMine(senderId: number | null): boolean {
    return senderId !== null && senderId === currentUserId.value;
}

function scrollToBottom(): void {
    nextTick(() => {
        if (scroller.value) {
            scroller.value.scrollTop = scroller.value.scrollHeight;
        }
    });
}

async function submit(): Promise<void> {
    if (!canSend.value) {
        return;
    }

    const body = draft.value;
    draft.value = '';

    try {
        await send(body);
        scrollToBottom();
    } catch (error) {
        draft.value = body;
        throw error;
    }
}

/** Enter sends, Shift+Enter breaks the line — the composer is a textarea so
 *  a multi-line question stays one message. */
function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        void submit();
    }
}

/** Older messages are prepended, so the thread must stay where the reader
 *  left it — hence the flag: without it the length watcher below would read
 *  the growth as a new arrival and yank them to the bottom. */
const prepending = ref(false);

async function loadOlderAndKeepPosition(): Promise<void> {
    const previousHeight = scroller.value?.scrollHeight ?? 0;

    prepending.value = true;

    try {
        await loadOlder();
    } finally {
        nextTick(() => {
            if (scroller.value) {
                scroller.value.scrollTop = scroller.value.scrollHeight - previousHeight;
            }

            prepending.value = false;
        });
    }
}

/** A message that lands while this tab is in the background stays unread, so
 *  the badge still means something the next time the user comes back. */
function catchUpIfVisible(): void {
    if (document.visibilityState === 'visible') {
        void markRead();
    }
}

watch(
    () => messages.value.length,
    (length, previous) => {
        if (length <= previous || prepending.value) {
            return;
        }

        catchUpIfVisible();
        scrollToBottom();
    },
);

onMounted(async () => {
    await load();
    scrollToBottom();
    void markRead();
    listen();

    window.addEventListener('focus', catchUpIfVisible);
});

onBeforeUnmount(() => {
    window.removeEventListener('focus', catchUpIfVisible);
});

function formatTime(value: string | null): string {
    if (!value) {
        return '';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return `${date.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' })} · ${date.toLocaleTimeString('de-DE', {
        hour: '2-digit',
        minute: '2-digit',
    })}`;
}
</script>

<template>
    <section :class="containerClass">
        <header class="flex items-center justify-between gap-3 border-b border-[#f1f5f5] px-5 py-4">
            <div class="flex min-w-0 items-center gap-2.5">
                <span class="flex size-9 shrink-0 items-center justify-center rounded-[11px] bg-[#01B990]/10 text-[#00856a]">
                    <MdiMessageTextOutline class="text-[18px]" />
                </span>
                <div class="min-w-0">
                    <h2 class="text-[15px] font-bold text-[#10393b]">Nachrichten</h2>
                    <p class="mt-0.5 truncate text-[12.5px] text-[#00000080]">
                        {{ auftragsnummer ? `Zum Auftrag ${auftragsnummer}` : 'Zu diesem Auftrag' }}
                    </p>
                </div>
            </div>

            <span
                v-if="unreadCount > 0"
                class="shrink-0 rounded-full bg-[#EF8450] px-2.5 py-1 text-[11px] font-bold text-white"
                :aria-label="`${unreadCount} ungelesen`"
            >
                {{ unreadCount > 99 ? '99+' : unreadCount }}
            </span>
        </header>

        <div ref="scroller" class="flex max-h-[420px] min-h-[220px] flex-col gap-3 overflow-y-auto px-5 py-4">
            <button
                v-if="hasOlder"
                type="button"
                :disabled="loading"
                class="mx-auto shrink-0 rounded-full border border-[#d8e4e3] bg-white px-4 py-1.5 text-[12px] font-semibold text-[#10393b] transition hover:border-[#01B990] disabled:opacity-40"
                @click="loadOlderAndKeepPosition"
            >
                Ältere Nachrichten laden
            </button>

            <p v-if="!loaded && loading" class="py-10 text-center text-[13px] text-[#9aacac]">Nachrichten werden geladen …</p>

            <p v-else-if="!messages.length" class="py-10 text-center text-[13px] text-[#9aacac]">Noch keine Nachrichten. Schreiben Sie die erste.</p>

            <div
                v-for="message in messages"
                :key="message.id"
                class="flex max-w-[85%] flex-col gap-1"
                :class="isMine(message.sender_id) ? 'items-end self-end' : 'items-start self-start'"
            >
                <div
                    class="rounded-[14px] px-3.5 py-2.5 text-[13.5px] leading-relaxed whitespace-pre-wrap"
                    :class="isMine(message.sender_id) ? 'bg-[#01B990] text-white' : 'bg-[#f1f5f5] text-[#10393b]'"
                >
                    {{ message.body }}
                </div>

                <p class="px-1 text-[11px] text-[#9aacac]">
                    {{ isMine(message.sender_id) ? 'Sie' : message.sender_name }}
                    <span v-if="!isMine(message.sender_id) && message.sender_is_admin"> · LeasyBack</span>
                    · {{ formatTime(message.created_at) }}
                </p>
            </div>
        </div>

        <form class="flex flex-col gap-2 border-t border-[#f1f5f5] px-5 py-4" @submit.prevent="submit">
            <div
                class="flex items-end gap-2 rounded-[16px] border border-[#d8e4e3] bg-white px-3 py-2.5 transition-all focus-within:border-[#01B990] focus-within:ring-4 focus-within:ring-[#01B990]/12"
            >
                <textarea
                    ref="composer"
                    v-model="draft"
                    rows="1"
                    :maxlength="MAX_LENGTH"
                    placeholder="Nachricht schreiben …"
                    class="max-h-[132px] min-h-[24px] flex-1 resize-none border-0 bg-transparent p-0 text-[13.5px] leading-[1.55] text-[#10393b] outline-none placeholder:text-[#9aacac]"
                    @keydown="onKeydown"
                ></textarea>

                <button
                    type="submit"
                    :disabled="!canSend"
                    aria-label="Nachricht senden"
                    class="flex size-9 shrink-0 items-center justify-center rounded-full bg-[#01B990] text-white transition-all hover:opacity-90 active:scale-95 disabled:bg-[#e6eded] disabled:text-[#9aacac] disabled:active:scale-100"
                >
                    <MdiSend class="ml-px text-[16px]" />
                </button>
            </div>

            <div class="flex items-center justify-between gap-3 px-1">
                <p class="text-[11px] text-[#9aacac]">
                    <span class="font-semibold text-[#6f8585]">Enter</span> zum Senden ·
                    <span class="font-semibold text-[#6f8585]">Shift + Enter</span> für neue Zeile
                </p>

                <p
                    v-if="remaining <= 200"
                    class="shrink-0 text-[11px] font-semibold tabular-nums"
                    :class="remaining === 0 ? 'text-[#E5533D]' : 'text-[#9aacac]'"
                >
                    {{ remaining }}
                </p>
            </div>
        </form>
    </section>
</template>

<style scoped>
button:not(:disabled) {
    cursor: pointer;
}
</style>

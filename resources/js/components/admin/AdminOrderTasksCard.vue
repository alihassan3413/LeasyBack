<script setup lang="ts">
/**
 * The Admin work queue for one order — B2B or B2C — rendered from
 * OrderTaskResolver::forOrderDetail(). Nothing is decided here — the card
 * only emphasises the single `next` step the backend derived and lists the
 * satisfied ones as compact history, so what an admin sees can never drift
 * from the order data, and neither channel needs its own card.
 *
 * The badge reads from `actor`, so a step that is genuinely someone else's move
 * says whose rather than presenting as an Admin to-do. A waiting step may still
 * carry an action — recording an outcome when it arrives is Admin work — so the
 * button follows `action`, not the badge.
 *
 * This is Admin-only internal information and lives outside the shared
 * customer timeline; no part of it reaches a customer payload.
 */
import { formatPortalDate } from '@/lib/portalDate';
import type { AdminOrderDetachedTask, AdminOrderTask, AdminOrderTaskAction, AdminOrderTaskPriority, AdminOrderTasks } from '@/types/admin';
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import MdiCheckCircleOutline from '~icons/mdi/check-circle-outline';
import MdiClipboardCheckOutline from '~icons/mdi/clipboard-check-outline';
import MdiClockOutline from '~icons/mdi/clock-outline';
import MdiPhoneOutline from '~icons/mdi/phone-outline';

const props = defineProps<{ tasks: AdminOrderTasks }>();

/**
 * `modal` and `inline` actions are handed up rather than handled here: the card
 * knows what a task wants done, the page knows which component does it. Adding
 * a task therefore never means editing this component.
 */
const emit = defineEmits<{ (e: 'action', action: AdminOrderTaskAction): void }>();

const busy = ref(false);
const historyOpen = ref(false);

function anchorId(section: string): string {
    return `order-section-${section}`;
}

function focusSection(section: string) {
    const target = document.getElementById(anchorId(section));

    if (!target) {
        return;
    }

    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    target.classList.add('task-target');
    window.setTimeout(() => target.classList.remove('task-target'), 1600);
}

function focusNextSection() {
    if (props.tasks.next) {
        focusSection(props.tasks.next.section);
    }
}

function runNextAction() {
    const action: AdminOrderTaskAction | null = props.tasks.next?.action ?? null;

    if (!action) {
        return;
    }

    if (action.type !== 'request') {
        emit('action', action);

        return;
    }

    if (!action.url) {
        return;
    }

    busy.value = true;

    // preserveScroll keeps the admin where they were; the reload the response
    // triggers re-resolves the tasks, so the finished step disappears and the
    // next one takes its place without a manual refresh.
    const options = { preserveScroll: true, onFinish: () => (busy.value = false) };

    if (action.method === 'post') {
        router.post(action.url, action.payload, options);

        return;
    }

    router.patch(action.url, action.payload, options);
}

/**
 * An `inline` action already scrolls to the section, so a second button doing
 * the same thing is noise. Everything else keeps it as secondary navigation.
 */
const showSectionLink = computed(() => props.tasks.next?.action?.type !== 'inline');

function formatDate(value: string | null): string {
    return formatPortalDate(value) || '—';
}

interface PriorityTreatment {
    label: string;
    badge: string;
    dot: string;
}

const PRIORITY: Record<Exclude<AdminOrderTaskPriority, 'neutral'>, PriorityTreatment> = {
    green: { label: 'Im Zeitrahmen', badge: 'bg-[#01B990]/10 text-[#00856a]', dot: 'bg-[#00856a]' },
    yellow: { label: 'Bald fällig', badge: 'bg-[#ef8450]/15 text-[#c0562a]', dot: 'bg-[#ef8450]' },
    red: { label: 'Überfällig', badge: 'bg-[#E5533D]/10 text-[#c0392b]', dot: 'bg-[#c0392b]' },
    immediate_red: { label: 'Sofort', badge: 'bg-[#E5533D]/10 text-[#c0392b]', dot: 'bg-[#c0392b]' },
};

function priorityTreatment(priority: AdminOrderTaskPriority): PriorityTreatment | null {
    return priority === 'neutral' ? null : PRIORITY[priority];
}

const nextPriority = computed(() => priorityTreatment(props.tasks.priority));

const detached = computed<AdminOrderDetachedTask[]>(() => props.tasks.detached ?? []);

const WAITING_LABEL: Record<string, string> = {
    customer: 'Wartet auf Kunde',
    workshop: 'Wartet auf Werkstatt',
};

function stateLabel(task: AdminOrderTask | null): string {
    if (task?.state !== 'waiting') {
        return 'Offen';
    }

    return WAITING_LABEL[task.actor] ?? 'Wartet';
}
</script>

<template>
    <div class="content-card">
        <div class="mb-4 flex items-center gap-2.5">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-[11px] bg-[#ef8450]/10 text-[#ef8450]">
                <MdiClipboardCheckOutline class="size-[17px]" />
            </span>
            <div class="min-w-0">
                <h2 class="text-[15px] font-extrabold tracking-[-0.3px] text-[#10393b]">Nächste Aufgabe</h2>
                <p class="mt-0.5 text-[12px] font-medium text-[#9bb0af]">{{ props.tasks.history.length }} Schritte erledigt</p>
            </div>
        </div>

        <div
            v-if="props.tasks.next"
            class="rounded-[16px] border p-4 transition-colors"
            :class="props.tasks.next.state === 'waiting' ? 'border-[#e9efee] bg-[#f8faf9]' : 'border-[#ef8450]/30 bg-[#ef8450]/5'"
        >
            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3">
                <button type="button" class="min-w-0 flex-1 text-left" :title="`Zum Abschnitt springen`" @click="focusNextSection">
                    <p class="text-[14px] font-extrabold tracking-[-0.2px] text-[#10393b] hover:opacity-70">
                        {{ props.tasks.next.title }}
                    </p>
                </button>

                <div class="flex flex-wrap items-center gap-1.5 sm:shrink-0 sm:justify-end">
                    <span
                        v-if="nextPriority"
                        class="flex items-center gap-1 rounded-full px-2 py-0.5 text-[10.5px] font-bold"
                        :class="nextPriority.badge"
                    >
                        <span class="size-1.5 rounded-full" :class="nextPriority.dot" aria-hidden="true" />
                        {{ nextPriority.label }}
                    </span>

                    <span
                        class="flex items-center gap-1 rounded-full px-2 py-0.5 text-[10.5px] font-bold"
                        :class="props.tasks.next.state === 'waiting' ? 'bg-[#f4f7f6] text-[#6f8585]' : 'bg-[#ef8450]/15 text-[#c0562a]'"
                    >
                        <MdiClockOutline class="size-3" />
                        {{ stateLabel(props.tasks.next) }}
                    </span>
                </div>
            </div>

            <p class="mt-1.5 text-[12.5px] leading-relaxed text-[#5a6e6c]">{{ props.tasks.next.description }}</p>

            <p v-if="props.tasks.next.date" class="mt-2 text-[11.5px] font-medium text-[#9bb0af]">
                {{ props.tasks.next.date_label }}: <span class="font-bold text-[#10393b]">{{ formatDate(props.tasks.next.date) }}</span>
            </p>

            <div class="mt-3.5 flex flex-wrap items-center gap-2 border-t border-[#f2f6f5] pt-3.5">
                <button
                    v-if="props.tasks.next.action"
                    type="button"
                    :disabled="busy"
                    class="rounded-[11px] bg-[#10393b] px-3.5 py-2 text-[12.5px] font-bold text-white transition-opacity hover:opacity-90 disabled:opacity-40"
                    @click="runNextAction"
                >
                    {{ busy ? 'Wird ausgeführt …' : props.tasks.next.action.label }}
                </button>

                <button
                    v-if="showSectionLink"
                    type="button"
                    class="rounded-[11px] border border-[#e9efee] bg-white px-3.5 py-2 text-[12.5px] font-bold text-[#10393b] transition-all hover:border-[#10393b] hover:bg-[#f4f7f6]"
                    @click="focusNextSection"
                >
                    Zum Abschnitt
                </button>
            </div>
        </div>

        <div v-else class="flex items-center gap-2.5 rounded-[16px] border border-[#e9efee] bg-[#f8faf9] p-4">
            <MdiCheckCircleOutline class="size-[18px] shrink-0 text-[#00856a]" />
            <p class="text-[12.5px] font-bold text-[#10393b]">
                {{ props.tasks.is_closed ? 'Der Auftrag ist abgeschlossen — keine offenen Aufgaben.' : 'Derzeit keine offene Aufgabe.' }}
            </p>
        </div>

        <div v-if="detached.length" class="mt-3.5 border-t border-[#f2f6f5] pt-3.5" data-role="detached">
            <p class="text-[11.5px] font-bold tracking-[-0.1px] text-[#6f8585]">Zusätzliche Follow-ups</p>

            <ul class="mt-2 flex flex-col gap-2">
                <li
                    v-for="task in detached"
                    :key="task.key"
                    class="rounded-[13px] border border-[#E5533D]/25 bg-[#E5533D]/5 p-3"
                    data-role="detached-task"
                >
                    <div class="flex flex-col gap-1.5 sm:flex-row sm:items-start sm:justify-between sm:gap-2.5">
                        <p class="flex min-w-0 items-start gap-1.5 text-[12.5px] font-extrabold text-[#10393b]">
                            <MdiPhoneOutline class="mt-px size-[14px] shrink-0 text-[#c0392b]" />
                            <span class="min-w-0">{{ task.title }}</span>
                        </p>

                        <span
                            v-if="priorityTreatment(task.priority)"
                            class="flex w-fit items-center gap-1 rounded-full px-2 py-0.5 text-[10.5px] font-bold sm:shrink-0"
                            :class="priorityTreatment(task.priority)!.badge"
                        >
                            <span class="size-1.5 rounded-full" :class="priorityTreatment(task.priority)!.dot" aria-hidden="true" />
                            {{ priorityTreatment(task.priority)!.label }}
                        </span>
                    </div>

                    <p class="mt-1 text-[12px] leading-relaxed text-[#5a6e6c]">{{ task.description }}</p>

                    <p v-if="task.date" class="mt-1.5 text-[11.5px] font-medium text-[#9bb0af]">
                        {{ task.date_label }}: <span class="font-bold text-[#10393b]">{{ formatDate(task.date) }}</span>
                    </p>
                </li>
            </ul>
        </div>

        <div v-if="props.tasks.history.length" class="mt-3">
            <button
                type="button"
                class="flex items-center gap-1.5 text-[12px] font-bold text-[#6f8585] transition-colors hover:text-[#10393b]"
                @click="historyOpen = !historyOpen"
            >
                {{ historyOpen ? 'Erledigte Schritte ausblenden' : `Erledigte Schritte anzeigen (${props.tasks.history.length})` }}
            </button>

            <ul v-if="historyOpen" class="mt-2 flex flex-col">
                <li v-for="entry in props.tasks.history" :key="entry.key">
                    <button
                        type="button"
                        class="flex w-full items-center gap-2 border-b border-[#f2f6f5] py-2 text-left last:border-0 hover:opacity-70"
                        @click="focusSection(entry.section)"
                    >
                        <MdiCheckCircleOutline class="size-[15px] shrink-0 text-[#00856a]" />
                        <span class="min-w-0 flex-1 truncate text-[12.5px] font-bold text-[#10393b]">{{ entry.title }}</span>
                        <span class="shrink-0 text-[11.5px] text-[#9bb0af] tabular-nums">{{ formatDate(entry.date) }}</span>
                    </button>
                </li>
            </ul>
        </div>
    </div>
</template>

<style scoped>
button:not(:disabled) {
    cursor: pointer;
}
</style>

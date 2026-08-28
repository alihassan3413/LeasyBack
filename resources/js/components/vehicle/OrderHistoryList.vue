<script setup lang="ts">
/**
 * A vehicle's Auftragsverlauf: every order behind the current one, each a link
 * to its own page.
 *
 * The rows are summaries (`OrderHistoryEntry`) rather than orders, and they are
 * addressed by `id` — the whole point of this section is that a past order has
 * an address at all, which it did not while the portal rendered `orders[0]` and
 * nothing else.
 */
import { ORDER_OUTCOME_LABELS, ORDER_OUTCOME_PILL, orderHistoryDateLabel } from '@/lib/orderHistory';
import type { OrderHistoryEntry } from '@/types/vehicle';
import { Link } from '@inertiajs/vue3';
import MdiChevronRight from '~icons/mdi/chevron-right';

withDefaults(
    defineProps<{
        entries: OrderHistoryEntry[];
        title?: string;
        /** Marked as "current" rather than linked — the page the reader is already on. */
        activeId?: string | null;
        emptyText?: string;
        /**
         * The chrome, so the same list can sit on the customer's detail pages
         * and inside the dashboard panel's masonry grid without a second
         * implementation of the rows. Same escape hatch OrderMessages.vue uses.
         */
        containerClass?: string;
        headerClass?: string;
        titleClass?: string;
    }>(),
    {
        title: 'Auftragsverlauf',
        activeId: null,
        emptyText: 'Für dieses Fahrzeug gibt es noch keine früheren Aufträge.',
        containerClass: 'overflow-hidden rounded-[16px] border border-[#e6eded] bg-white',
        headerClass: 'flex items-baseline justify-between gap-3 border-b border-[#f1f5f5] px-5 py-4',
        titleClass: 'text-[15px] font-bold text-[#10393b]',
    },
);

function detailParts(entry: OrderHistoryEntry): string[] {
    const parts = [orderHistoryDateLabel(entry)];

    if (entry.offer_count) {
        parts.push(`${entry.offer_count} ${entry.offer_count === 1 ? 'Angebot' : 'Angebote'}`);
    }

    if (entry.document_count) {
        parts.push(`${entry.document_count} ${entry.document_count === 1 ? 'Dokument' : 'Dokumente'}`);
    }

    return parts;
}
</script>

<template>
    <section :class="containerClass">
        <header :class="headerClass">
            <h2 :class="titleClass">{{ title }}</h2>
            <span v-if="entries.length" class="text-[12px] font-semibold text-[#9aacac]">
                {{ entries.length }} {{ entries.length === 1 ? 'Auftrag' : 'Aufträge' }}
            </span>
        </header>

        <p v-if="!entries.length" class="px-5 py-8 text-center text-[13px] text-[#9aacac]">{{ emptyText }}</p>

        <ul v-else>
            <li v-for="entry in entries" :key="entry.id" class="border-b border-[#f1f5f5] last:border-b-0">
                <component
                    :is="entry.id === activeId ? 'div' : Link"
                    :href="entry.id === activeId ? undefined : route('orders.show', entry.id)"
                    class="flex w-full items-center gap-3 px-5 py-3 text-left transition"
                    :class="entry.id === activeId ? 'bg-[#f7faf9]' : 'hover:bg-[#f7faf9]'"
                >
                    <div class="min-w-0 flex-1">
                        <p class="flex items-center gap-2 truncate text-[13px] font-semibold text-[#10393b]">
                            {{ entry.auftragsnummer }}
                            <span v-if="entry.id === activeId" class="text-[11px] font-bold text-[#9aacac]">· angezeigt</span>
                        </p>
                        <p class="truncate text-[11.5px] text-[#9aacac]">{{ detailParts(entry).join(' · ') }}</p>
                    </div>

                    <!--
                        Server-decided (`has_open_payment`): a cancellation fee
                        outlives the order that incurred it, so a closed row is
                        exactly where an unpaid amount can still be sitting.
                    -->
                    <span v-if="entry.has_open_payment" class="shrink-0 rounded-full bg-amber-100 px-2.5 py-1 text-[11px] font-bold text-amber-900">
                        Zahlung offen
                    </span>

                    <span class="shrink-0 rounded-full px-2.5 py-1 text-[11px] font-bold" :style="ORDER_OUTCOME_PILL[entry.outcome]">
                        {{ ORDER_OUTCOME_LABELS[entry.outcome] }}
                    </span>

                    <MdiChevronRight v-if="entry.id !== activeId" class="shrink-0 text-[18px] text-[#c3d0d0]" />
                </component>
            </li>
        </ul>
    </section>
</template>

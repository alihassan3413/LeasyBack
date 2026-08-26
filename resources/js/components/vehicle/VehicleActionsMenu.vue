<script setup lang="ts">
/**
 * Row-level actions for one vehicle, on the customer dashboard.
 *
 * Lives on the row rather than inside the expanded panel on purpose. The panel
 * is a masonry of cards, and a full-width action strip inside it breaks that
 * flow for something that is not part of reading the order — the panel is for
 * looking at a process, and this is for acting on it.
 *
 * The menu renders only when it has something to offer, so a vehicle with no
 * available action shows no trigger rather than an empty one.
 */
import InputError from '@/components/InputError.vue';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { AppModal } from '@/components/ui/modal';
import { HttpError, http } from '@/lib/http';
import { isCustomerCancellable } from '@/lib/vehicleStatus';
import type { SharedData } from '@/types';
import { formatEuro } from '@/types/payment';
import type { VehicleData } from '@/types/vehicle';
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import MdiCloseCircleOutline from '~icons/mdi/close-circle-outline';
import MdiDotsVertical from '~icons/mdi/dots-vertical';

const props = defineProps<{ vehicle: VehicleData }>();

const page = usePage<SharedData>();

/**
 * The order this vehicle's actions apply to.
 *
 * `payment` is emitted for B2C orders only, so its presence is also the channel
 * check — a B2B order carries none and offers nothing here. The server refuses
 * anything else, so this only decides whether to show an action, never whether
 * it is allowed.
 */
const cancellableOrder = computed(
    () => props.vehicle.orders.find((order) => order.payment != null && isCustomerCancellable(order.order_status)) ?? null,
);

const hasActions = computed(() => cancellableOrder.value !== null);

const confirmOpen = ref(false);
const cancelling = ref(false);
const error = ref<string | null>(null);

/** Server-held, so the amount someone agrees to is the amount that is charged. */
const feeLabel = computed(() => formatEuro((page.props.payments?.cancellation_fee_cents ?? 20000) / 100));

function openConfirm() {
    error.value = null;
    confirmOpen.value = true;
}

async function confirmCancellation() {
    const order = cancellableOrder.value;

    if (!order || cancelling.value) {
        return;
    }

    cancelling.value = true;
    error.value = null;

    try {
        await http.post(route('orders.cancel', order.id));
        confirmOpen.value = false;
        // Reloaded rather than patched locally: the cancellation and the fee's
        // outcome are two separate server-side facts, and neither is knowable
        // from the click.
        router.reload({ preserveScroll: true });
    } catch (e) {
        error.value = (e instanceof HttpError ? e.serverMessage() : null) ?? 'Der Auftrag konnte nicht storniert werden.';
    } finally {
        cancelling.value = false;
    }
}
</script>

<template>
    <DropdownMenu v-if="hasActions">
        <DropdownMenuTrigger as-child>
            <button
                type="button"
                class="flex h-7 w-7 items-center justify-center rounded text-gray-400 transition-colors hover:bg-gray-100 hover:text-[#10393b]"
                title="Weitere Aktionen"
                aria-label="Weitere Aktionen"
                @click.stop
            >
                <MdiDotsVertical class="h-[18px] w-[18px]" />
            </button>
        </DropdownMenuTrigger>

        <!-- .stop throughout: the row underneath toggles the expanded panel on
             click, and opening a menu is not a request to expand anything. -->
        <DropdownMenuContent align="end" class="w-56" @click.stop>
            <DropdownMenuItem v-if="cancellableOrder" class="text-[#c0392b] focus:text-[#c0392b]" @select="openConfirm">
                <MdiCloseCircleOutline />
                Auftrag stornieren
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>

    <AppModal :open="confirmOpen" title="Auftrag stornieren?" :width="560" @update:open="(value) => (confirmOpen = value)">
        <div class="min-w-0 space-y-4 px-2" @click.stop>
            <p class="text-sm leading-relaxed text-black dark:text-white">
                Die Stornierung beendet und storniert den gesamten Auftrag
                <span v-if="cancellableOrder" class="font-bold">{{ cancellableOrder.auftragsnummer }}</span
                >. Es fällt eine Stornogebühr von <span class="font-bold">{{ feeLabel }}</span> an, die von Ihrer hinterlegten Zahlungsmethode
                eingezogen wird.
            </p>
            <p class="text-muted-foreground text-sm">Dieser Schritt kann nicht rückgängig gemacht werden.</p>
            <InputError :message="error" />
        </div>

        <template #footer>
            <button
                type="button"
                class="rounded-[5px] border border-[#e9efee] bg-white px-8 py-2.5 text-sm font-bold text-[#10393b] transition-colors hover:bg-[#f4f7f6]"
                @click.stop="confirmOpen = false"
            >
                Zurück
            </button>
            <button
                type="button"
                :disabled="cancelling"
                class="rounded-[5px] bg-[#E5533D] px-8 py-2.5 text-sm font-bold text-white transition-opacity hover:opacity-90 disabled:opacity-40"
                @click.stop="confirmCancellation"
            >
                {{ cancelling ? 'Wird storniert …' : 'Auftrag stornieren' }}
            </button>
        </template>
    </AppModal>
</template>

<style scoped>
button:not(:disabled) {
    cursor: pointer;
}
</style>

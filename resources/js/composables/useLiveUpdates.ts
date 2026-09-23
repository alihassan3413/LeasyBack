import { useNotifications, type AppNotification } from '@/composables/useNotifications';
import { router } from '@inertiajs/vue3';
import { onMounted, onUnmounted } from 'vue';

/**
 * Notification types that mean the page's own server data just changed.
 *
 * Deliberately a list rather than "every notification": a new message already
 * arrives through its own channel and updates the thread in place, so
 * reloading the page for one would throw away the work the message composable
 * just did.
 */
const DATA_CHANGING_TYPES: ReadonlySet<string> = new Set([
    'vehicle.ready_for_pickup',
    'order.status_changed',
    'order.approved',
    'offer.published',
    'offer.accepted',
    'offer.rejected',
    'payment.action_required',
    'workshop.quotation_received',
    'report.published',
    'document.published',
]);

/**
 * Reload the current page's props when a notification says its data moved.
 *
 * The bell has always updated itself over Reverb; the page around it did not.
 * That gap is most visible at the end of a B2C order: the repair charge
 * settles through a Stripe webhook minutes after the customer paid, and until
 * something reloaded the page, the customer still saw "Zahlung erforderlich"
 * and Admin still saw "Zahlungseingang abwarten" instead of the
 * "Abholung bestätigen" action that had just become available.
 *
 * Inertia's partial reload keeps component state and scroll position, so an
 * open modal or an expanded row survives the refresh.
 *
 * @param isRelevant Narrows which notifications reload this page — an order
 *                   detail page only cares about its own order. Omit on a page
 *                   that lists everything.
 */
export function useLiveUpdates(isRelevant?: (notification: AppNotification) => boolean): void {
    const { onNotification } = useNotifications();

    let stop: (() => void) | null = null;

    onMounted(() => {
        stop = onNotification((notification) => {
            if (!DATA_CHANGING_TYPES.has(notification.type)) {
                return;
            }

            if (isRelevant && !isRelevant(notification)) {
                return;
            }

            router.reload({ preserveScroll: true });
        });
    });

    onUnmounted(() => {
        stop?.();
        stop = null;
    });
}

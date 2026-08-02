import { useToast, type ToastVariant } from '@/composables/useToast';
import type { FlashBag } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { watch } from 'vue';

const VARIANTS: ToastVariant[] = ['success', 'error', 'warning', 'info'];

const VALIDATION_MESSAGE = 'Bitte prüfen Sie Ihre Eingaben.';

/**
 * Turns Laravel's flashed session messages into toasts, plus one generic toast
 * whenever a response comes back with validation errors — the offending field
 * is often scrolled out of view, so the inline message alone is easy to miss.
 */
export function useFlashToasts(): void {
    const page = usePage();
    const { toast, error } = useToast();

    const currentFlash = () => page.props.flash as FlashBag | undefined;

    watch(
        () => page.props.flash,
        () => {
            const flash = currentFlash();

            if (!flash) {
                return;
            }

            for (const variant of VARIANTS) {
                const message = flash[variant];

                if (message) {
                    toast(variant, message);
                }
            }
        },
        { immediate: true },
    );

    watch(
        () => page.props.errors,
        (errors) => {
            if (!errors || Object.keys(errors).length === 0) {
                return;
            }

            // A service failure flashes its own, more specific message.
            if (currentFlash()?.error) {
                return;
            }

            error(VALIDATION_MESSAGE);
        },
    );
}

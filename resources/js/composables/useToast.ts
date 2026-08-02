import { readonly, ref } from 'vue';

export type ToastVariant = 'success' | 'error' | 'warning' | 'info';

export interface ToastOptions {
    description?: string;
    duration?: number;
}

export interface ToastItem {
    id: number;
    variant: ToastVariant;
    title: string;
    description?: string;
    duration: number;
}

const DEFAULT_DURATION = 5000;
const MAX_VISIBLE = 4;

const items = ref<ToastItem[]>([]);
let sequence = 0;

function push(variant: ToastVariant, title: string, options: ToastOptions = {}): number {
    const id = ++sequence;

    items.value = [
        ...items.value,
        {
            id,
            variant,
            title,
            description: options.description,
            duration: options.duration ?? DEFAULT_DURATION,
        },
    ].slice(-MAX_VISIBLE);

    return id;
}

function dismiss(id: number): void {
    items.value = items.value.filter((item) => item.id !== id);
}

function clear(): void {
    items.value = [];
}

export function useToast() {
    return {
        toasts: readonly(items),
        dismiss,
        clear,
        toast: push,
        success: (title: string, options?: ToastOptions) => push('success', title, options),
        error: (title: string, options?: ToastOptions) => push('error', title, options),
        warning: (title: string, options?: ToastOptions) => push('warning', title, options),
        info: (title: string, options?: ToastOptions) => push('info', title, options),
    };
}

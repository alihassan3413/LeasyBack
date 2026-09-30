import { h, reactive, type Component } from 'vue';

/**
 * Fixture-only stand-in for @inertiajs/vue3.
 *
 * The MFA pages are ordinary components apart from three imports that need a
 * running Inertia app: `useForm`, `router` and `<Head>`. Aliasing just those —
 * only in the fixture's vite config, never in the application build — keeps the
 * real pages under test instead of a copy of their markup.
 *
 * Submits are recorded rather than sent; a layout fixture has no server.
 */
export const submissions: { url: string; data: Record<string, unknown> }[] = [];

export const Head: Component = { render: () => null };

export const router = {
    post(url: string, data: Record<string, unknown> = {}, options: { onSuccess?: () => void } = {}) {
        submissions.push({ url, data });
        options.onSuccess?.();
    },
    visit(url: string) {
        submissions.push({ url, data: {} });
    },
};

export function useForm<T extends Record<string, unknown>>(source: T | (() => T)) {
    // Inertia accepts either the fields or a factory returning them; the
    // factory form is what the pages use when a reset has to rebuild from
    // props, so both have to work here.
    const initial = (typeof source === 'function' ? source() : source) as T;

    const snapshot = JSON.stringify(initial);

    const form = reactive({
        ...initial,
        errors: {} as Record<string, string>,
        processing: false,
        recentlySuccessful: false,
        hasErrors: false,
        /** Compared against the starting values, the same question Inertia's
         *  own isDirty answers. */
        get isDirty(): boolean {
            const current: Record<string, unknown> = {};

            for (const field of Object.keys(initial)) {
                current[field] = (form as Record<string, unknown>)[field];
            }

            return JSON.stringify(current) !== snapshot;
        },
        transform() {
            return form;
        },
        post(url: string, options: { onError?: () => void; onFinish?: () => void } = {}) {
            submissions.push({ url, data: { ...initial } });
            options.onFinish?.();
        },
        put(url: string, options: { onFinish?: () => void } = {}) {
            submissions.push({ url, data: { ...initial } });
            options.onFinish?.();
        },
        reset(...fields: string[]) {
            for (const field of fields.length > 0 ? fields : Object.keys(initial)) {
                (form as Record<string, unknown>)[field] = (initial as Record<string, unknown>)[field];
            }
        },
        clearErrors() {
            form.errors = {};
        },
    });

    return form;
}

/** Everything else the fixtures' components import, so the alias does not
 *  break the bundles that do not use it. */
export const Link: Component = {
    props: { href: { type: String, default: '#' } },
    render(this: { href: string; $slots: { default?: () => unknown } }) {
        return h('a', { href: this.href }, this.$slots.default?.() as never);
    },
};

export function usePage() {
    return reactive({ props: {}, url: '/', component: '', version: null });
}

export function usePoll() {
    return { stop: () => {}, start: () => {} };
}

export function createInertiaApp() {
    throw new Error('createInertiaApp is not available in the layout fixture');
}

export { h };

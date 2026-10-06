import LicensePlateInput from '@/components/form/LicensePlateInput.vue';
import { createApp, h, ref } from 'vue';
import './app.css';

const params = new URLSearchParams(location.search);
const plate = ref(params.get('plate') ?? '');
const disabled = params.get('disabled') === '1';

/**
 * `LicensePlateInput` reaches for nothing outside itself, so it needs no Inertia
 * stub — only its own three inputs and the emitted value, which is rendered so a
 * spec can assert what the parent would have been handed.
 */
const app = createApp({
    setup() {
        return () =>
            h('div', { id: 'wrap' }, [
                h('div', { 'data-testid': 'emitted' }, plate.value),
                h(LicensePlateInput, {
                    modelValue: plate.value,
                    disabled,
                    'onUpdate:modelValue': (value: string) => {
                        plate.value = value;
                    },
                }),
            ]);
    },
});

app.mount('#app');

import B2bCompanyCard from '@/components/onboarding/B2bCompanyCard.vue';
import { companyFormData } from '@/lib/company';
import { useForm } from '@inertiajs/vue3';
import { createApp, h } from 'vue';
import './app.css';

// The real company card of the B2B registration page, on the same form object
// the page builds. Exposed so the spec can read exactly what would be posted.
const form = useForm(companyFormData(null, 'qa@example.de'));
Object.assign(window, { companyForm: form });

createApp({
    setup: () => () => h('div', { id: 'wrap', class: 'mx-auto max-w-3xl p-4' }, [h(B2bCompanyCard as never, { form } as never)]),
}).mount('#app');

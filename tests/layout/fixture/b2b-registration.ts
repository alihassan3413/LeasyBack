import { submissions } from '@inertiajs/vue3';
import { createApp, h } from 'vue';
import './app.css';

// The page reads route() and the signed-in user's email before it renders.
Object.assign(window, {
    route: (name: string) => `/${name}`,
    __fixturePageProps: { auth: { user: { email: 'qa@example.de' } } },
    fixtureSubmissions: submissions,
});

// Imported after the globals above exist: the page reads them while setting up.
void import('@/pages/onboarding/B2bRegistration.vue').then(({ default: B2bRegistration }) => {
    const app = createApp({ setup: () => () => h('div', { id: 'wrap' }, [h(B2bRegistration as never)]) });
    // Templates call route() as Ziggy's global property, as in the real app.
    app.config.globalProperties.route = (window as unknown as { route: (name: string) => string }).route;
    app.mount('#app');
});

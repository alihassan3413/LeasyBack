import RelocationOrderModal from '@/components/vehicle/RelocationOrderModal.vue';
import { createApp, h } from 'vue';
import './app.css';

Object.assign(window, { route: (name: string) => `/${name}` });

const vehicles = [{ vehicle_id: 'v1', license_plate: 'HH-RV 7016', make: 'Ford', model: 'Kuga Plug-In Hybrid ST-Line' }];

const addressProfiles = [
    {
        id: 'p1',
        profile_name: 'Werk Köln-Süd',
        details: { street: 'Musterstr.', number: '12', zip_code: '50667', city: 'Köln', country: 'Deutschland' },
    },
    {
        id: 'p2',
        profile_name: 'Hauptverwaltung Hamburg',
        details: { street: 'Hafenweg', number: '3', zip_code: '20095', city: 'Hamburg', country: 'Deutschland' },
    },
];

createApp({
    setup: () => () =>
        h('div', { id: 'wrap' }, [
            h(
                RelocationOrderModal as never,
                {
                    open: true,
                    vehicles,
                    addressProfiles,
                    costCentres: ['Vertrieb', 'Logistik'],
                } as never,
            ),
        ]),
}).mount('#app');

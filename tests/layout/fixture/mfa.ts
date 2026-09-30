import MfaSetup from '@/pages/auth/MfaSetup.vue';
import MfaVerify from '@/pages/auth/MfaVerify.vue';
import { createApp, h } from 'vue';
import './app.css';

const params = new URLSearchParams(location.search);
const screen = params.get('screen') ?? 'choose';

/**
 * The pages are Inertia pages, so the two things they reach for outside their
 * own props are stubbed: `route()` and `<Head>`. Everything else — props, form
 * state, the components under test — is the real thing.
 */
Object.assign(window, { route: (name: string) => `/${name}` });

const QR = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 25 25" shape-rendering="crispEdges">${Array.from({ length: 160 }, () => {
    const x = Math.floor(Math.random() * 25);
    const y = Math.floor(Math.random() * 25);

    return `<rect x="${x}" y="${y}" width="1" height="1" fill="#10393b"/>`;
}).join('')}</svg>`;

const CODES = ['9H6XL-JXVXD', '4LZK9-S4YU7', '7ZAZ3-9NGCL', 'VVL24-D66US', 'GF9MY-WFE9U', '67XZP-SUH83', 'YWK8M-ZL7DR', 'PV795-EA7T9'];

const setupProps = {
    enrolled: false,
    method: null,
    mandatory: screen !== 'optional',
    email: 'husaina@leasyback.de',
    secret: 'PBEKXPPJRCIM37PDCYVYVHDETLCENSDZ',
    otpauthUri: 'otpauth://totp/Leasyback:husaina@leasyback.de?secret=PBEKXPPJRCIM37PDCYVYVHDETLCENSDZ',
    qrCode: QR,
    recoveryCodes: screen === 'codes' ? CODES : null,
    status: null,
};

const verifyProps = {
    method: screen === 'verify-email' ? ('email' as const) : ('totp' as const),
    canUseRecoveryCode: true,
    emailCooldownSeconds: screen === 'verify-email' ? 24 : 0,
    status: screen === 'verify-email' ? 'Wir haben Ihnen einen neuen Code gesendet.' : null,
};

const isVerify = screen.startsWith('verify');
const app = createApp({
    setup() {
        return () => h('div', { id: 'wrap' }, [h(isVerify ? MfaVerify : MfaSetup, isVerify ? verifyProps : (setupProps as never))]);
    },
});

// Registered under a non-reserved name; the pages resolve <Head> through
// the aliased Inertia stub, so nothing needs a global component here.
app.config.globalProperties.route = ((name: string) => `/${name}`) as never;
app.mount('#app');

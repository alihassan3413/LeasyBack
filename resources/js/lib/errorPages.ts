export interface ErrorPageCopy {
    /** Short label above the status code. */
    eyebrow: string;
    title: string;
    description: string;
    /** Offers a "try again" action — only where repeating the request can plausibly help. */
    retryable: boolean;
    /** Offers a sign-in action instead of the dashboard link as the primary call to action. */
    requiresSignIn: boolean;
    /** Adds a support contact line — reserved for faults on our side. */
    offerSupport: boolean;
    retryLabel?: string;
}

const FALLBACK: ErrorPageCopy = {
    eyebrow: 'Fehler',
    title: 'Etwas ist schiefgelaufen',
    description: 'Die Seite konnte nicht geladen werden. Bitte versuchen Sie es erneut oder kehren Sie zur Übersicht zurück.',
    retryable: true,
    requiresSignIn: false,
    offerSupport: true,
};

const PAGES: Record<number, ErrorPageCopy> = {
    400: {
        eyebrow: 'Ungültige Anfrage',
        title: 'Diese Anfrage konnten wir nicht lesen',
        description: 'Die Anfrage war unvollständig oder fehlerhaft. Bitte prüfen Sie Ihre Eingaben und versuchen Sie es noch einmal.',
        retryable: true,
        requiresSignIn: false,
        offerSupport: false,
    },
    401: {
        eyebrow: 'Anmeldung erforderlich',
        title: 'Bitte melden Sie sich an',
        description: 'Für diesen Bereich benötigen Sie ein angemeldetes LeasyBack-Konto.',
        retryable: false,
        requiresSignIn: true,
        offerSupport: false,
    },
    403: {
        eyebrow: 'Kein Zugriff',
        title: 'Dieser Bereich ist für Sie gesperrt',
        description:
            'Ihr Konto hat keine Berechtigung für diese Seite. Wenden Sie sich an Ihre Administratorin oder Ihren Administrator, wenn Sie Zugriff benötigen.',
        retryable: false,
        requiresSignIn: false,
        offerSupport: false,
    },
    404: {
        eyebrow: 'Nicht gefunden',
        title: 'Diese Seite gibt es nicht',
        description: 'Die Adresse ist falsch geschrieben, veraltet oder der Inhalt wurde verschoben.',
        retryable: false,
        requiresSignIn: false,
        offerSupport: false,
    },
    405: {
        eyebrow: 'Nicht erlaubt',
        title: 'Diese Aktion ist hier nicht möglich',
        description: 'Der Aufruf passt nicht zu dieser Adresse. Bitte starten Sie den Vorgang noch einmal von vorn.',
        retryable: false,
        requiresSignIn: false,
        offerSupport: false,
    },
    408: {
        eyebrow: 'Zeitüberschreitung',
        title: 'Die Anfrage hat zu lange gedauert',
        description:
            'Die Verbindung wurde unterbrochen, bevor wir antworten konnten. Prüfen Sie Ihre Internetverbindung und versuchen Sie es erneut.',
        retryable: true,
        requiresSignIn: false,
        offerSupport: false,
    },
    419: {
        eyebrow: 'Sitzung abgelaufen',
        title: 'Ihre Sitzung ist abgelaufen',
        description:
            'Aus Sicherheitsgründen wurde Ihre Sitzung beendet. Laden Sie die Seite neu oder melden Sie sich erneut an — Ihre Daten sind gespeichert.',
        retryable: true,
        requiresSignIn: true,
        offerSupport: false,
        retryLabel: 'Seite neu laden',
    },
    422: {
        eyebrow: 'Eingabe fehlerhaft',
        title: 'Diese Angaben konnten wir nicht verarbeiten',
        description: 'Einzelne Felder waren unvollständig oder ungültig. Bitte füllen Sie das Formular erneut aus.',
        retryable: true,
        requiresSignIn: false,
        offerSupport: false,
    },
    429: {
        eyebrow: 'Zu viele Anfragen',
        title: 'Einen Moment bitte',
        description: 'Es kamen zu viele Anfragen in kurzer Zeit. Warten Sie einen Augenblick und versuchen Sie es dann noch einmal.',
        retryable: true,
        requiresSignIn: false,
        offerSupport: false,
    },
    500: {
        eyebrow: 'Serverfehler',
        title: 'Bei uns ist etwas schiefgelaufen',
        description: 'Der Fehler liegt auf unserer Seite und wurde automatisch protokolliert. Bitte versuchen Sie es in Kürze noch einmal.',
        retryable: true,
        requiresSignIn: false,
        offerSupport: true,
    },
    502: {
        eyebrow: 'Ungültige Antwort',
        title: 'Ein Dienst hat nicht korrekt geantwortet',
        description: 'Eine beteiligte Komponente ist gerade nicht erreichbar. Der Fehler ist meist nach wenigen Minuten behoben.',
        retryable: true,
        requiresSignIn: false,
        offerSupport: true,
    },
    503: {
        eyebrow: 'Wartungsarbeiten',
        title: 'LeasyBack ist kurz nicht erreichbar',
        description: 'Wir arbeiten gerade an der Plattform. Bitte versuchen Sie es in wenigen Minuten noch einmal.',
        retryable: true,
        requiresSignIn: false,
        offerSupport: true,
    },
    504: {
        eyebrow: 'Zeitüberschreitung',
        title: 'Die Antwort hat zu lange gedauert',
        description: 'Ein beteiligter Dienst hat nicht rechtzeitig geantwortet. Bitte versuchen Sie es gleich noch einmal.',
        retryable: true,
        requiresSignIn: false,
        offerSupport: true,
    },
};

export function resolveErrorCopy(status: number): ErrorPageCopy {
    return PAGES[status] ?? FALLBACK;
}

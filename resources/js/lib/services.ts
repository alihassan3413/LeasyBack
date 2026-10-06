import type { Component } from 'vue';
import MdiCarArrowRight from '~icons/mdi/car-arrow-right';
import MdiCarEmergency from '~icons/mdi/car-emergency';
import MdiCarSearchOutline from '~icons/mdi/car-search-outline';
import MdiClipboardCheckOutline from '~icons/mdi/clipboard-check-outline';
import MdiFileDocumentCheckOutline from '~icons/mdi/file-document-check-outline';
import MdiFileSearchOutline from '~icons/mdi/file-search-outline';

/**
 * The services LeasyBack offers company accounts, as listed on the company
 * dashboard.
 *
 * Firmenkunde only — a Privatkunde's dashboard is their vehicle list and
 * offers no catalogue, so every line here is written for a fleet customer.
 *
 * `bookable` services have a working order flow: Leasingrückgabe (the
 * dashboard's lead workflow), Überführung and Unfallschaden. The rest stay
 * `soon` until their flow exists — never a card that looks pressable and then
 * refuses.
 *
 * Adding a service is an entry here and nothing else; promoting one is a
 * single field. No component holds its own list.
 */
export type ServiceAvailability = 'bookable' | 'soon';

export interface ServiceDefinition {
    /** Stable key — matches `service_type` on the order where one exists. */
    key: string;
    title: string;
    /**
     * One short line. This is an operator's overview, not a landing page, so
     * the copy says what the service *is* and stops.
     */
    summary: string;
    icon: Component;
    availability: ServiceAvailability;
}

export const SERVICES: ServiceDefinition[] = [
    {
        key: 'leasingrueckgabe',
        title: 'Leasingrückgabe',
        summary: 'Abholung, Gutachten, Reparatur und Rückgabe.',
        icon: MdiClipboardCheckOutline,
        availability: 'bookable',
    },
    {
        key: 'ueberfuehrung',
        title: 'Überführung',
        summary: 'Fahrzeugtransport zwischen zwei Standorten.',
        icon: MdiCarArrowRight,
        availability: 'bookable',
    },
    {
             key: 'gutachten',
        title: 'Gutachten',
        summary: 'Zustandsbewertung durch zertifizierte Prüfer.',
        icon: MdiFileDocumentCheckOutline,
        availability: 'bookable',
    },
    {
        key: 'unfallschaden',
        title: 'Unfallschaden',
        summary: 'Schadenaufnahme und Abwicklung nach einem Unfall.',
        icon: MdiCarEmergency,
        availability: 'bookable',
    },
    {
        key: 'fahrzeuganfrage',
        title: 'Fahrzeuganfrage',
        summary: 'Fahrzeug über das Partnernetz anfragen.',
        icon: MdiCarSearchOutline,
        availability: 'soon',
    },
    {
        key: 'gutachten-pruefen',
        title: 'Gutachten prüfen',
        summary: 'Vorhandenes Gutachten unabhängig gegenprüfen.',
        icon: MdiFileSearchOutline,
        availability: 'soon',
    },
];

export const AVAILABILITY_LABELS: Record<Exclude<ServiceAvailability, 'bookable'>, string> = {
    soon: 'Bald verfügbar',
};

/**
 * The dashboard's lead workflow (the big "Starten" row). Also the fallback
 * label for orders created before `service_type` existed — keep this
 * Leasingrückgabe.
 */
export const BOOKABLE_SERVICE = SERVICES.find((service) => service.key === 'leasingrueckgabe')!;

/** Title for an order's service_type; old orders without one are Leasingrückgabe. */
export function serviceTitle(key?: string | null): string {
    return SERVICES.find((service) => service.key === key)?.title ?? BOOKABLE_SERVICE.title;
}
<script setup lang="ts">
import { ArrowRight, Calculator, Camera, FileText, ListChecks, MessageSquare, Wrench } from 'lucide-vue-next';

const mainFeatures = [
    {
        icon: FileText,
        title: 'Kostenloses Minderwert-Gutachten',
        text: 'Wir bewerten jeden Schaden nach anerkannten Maßstäben — bevor der Leasinggeber seine Rechnung schreibt.',
    },
    {
        icon: Wrench,
        title: 'Günstige Reparaturangebote',
        text: 'Geprüfte Betriebe aus unserem Netzwerk bieten auf deine Schäden. Du vergleichst und entscheidest.',
    },
    {
        icon: Camera,
        title: 'Schäden digital dokumentiert',
        text: 'Fotos hochladen, Schaden zuordnen, fertig. Alles bleibt nachvollziehbar dokumentiert — auch bei Streitfällen.',
    },
];

const secondaryFeatures = [
    {
        icon: Calculator,
        title: 'Kostenprognose vorab',
        text: 'Du weißt vor der Rückgabe, was auf dich zukommt.',
    },
    {
        icon: ListChecks,
        title: 'Rückgabe-Checkliste',
        text: 'Schritt für Schritt zum Übergabetermin — nichts vergessen.',
    },
    {
        icon: MessageSquare,
        title: 'Persönlicher Kontakt',
        text: 'Feste Ansprechpartner statt Warteschleife.',
    },
];
</script>

<template>
    <section id="leistungen" class="px-4 py-16 sm:px-6 lg:px-8 lg:py-24">
        <div class="mx-auto max-w-7xl">
            <div class="mx-auto max-w-2xl text-center">
                <p class="text-brand-green-gray text-xs font-medium tracking-[0.16em] uppercase">Leistungen</p>
                <h2 class="text-brand-teal mt-4 text-3xl leading-tight font-bold tracking-[-0.02em] sm:text-4xl lg:text-5xl">
                    Alles, was du für eine faire Rückgabe brauchst
                </h2>
                <p class="text-brand-black mt-4 text-base sm:text-lg">
                    Von der ersten Einschätzung bis zur Schlüsselübergabe — an einem Ort, ohne Papierkram.
                </p>
            </div>

            <div class="mt-12 grid gap-4 sm:grid-cols-2 lg:mt-16 lg:grid-cols-3">
                <article
                    v-for="feature in mainFeatures"
                    :key="feature.title"
                    class="border-brand-green-gray flex flex-col rounded-[10px] border bg-white p-6 shadow-[0_4px_4px_rgba(0,0,0,0.06)] transition-shadow hover:shadow-[0_4px_16px_rgba(16,57,59,0.12)] motion-reduce:transition-none sm:p-7"
                >
                    <span class="bg-brand-teal/[0.06] flex h-11 w-11 items-center justify-center rounded-[10px]">
                        <component :is="feature.icon" class="text-brand-teal h-5 w-5" aria-hidden="true" />
                    </span>
                    <h3 class="text-brand-teal mt-5 text-lg font-bold">
                        {{ feature.title }}
                    </h3>
                    <p class="text-brand-black mt-3 flex-1 text-sm leading-relaxed">
                        {{ feature.text }}
                    </p>
                    <a
                        href="#ablauf"
                        class="group text-brand-green focus-visible:ring-brand-green mt-6 inline-flex items-center gap-1.5 self-start rounded-[5px] text-sm font-bold underline decoration-[1.12px] underline-offset-[2.8px] focus-visible:ring-2 focus-visible:outline-none"
                    >
                        Mehr erfahren
                        <ArrowRight
                            class="h-3.5 w-3.5 transition-transform group-hover:translate-x-1 motion-reduce:transition-none"
                            aria-hidden="true"
                        />
                    </a>
                </article>
            </div>

            <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <article
                    v-for="feature in secondaryFeatures"
                    :key="feature.title"
                    class="border-brand-green-gray/60 bg-brand-teal/[0.03] rounded-[10px] border p-6"
                >
                    <component :is="feature.icon" class="text-brand-orange h-5 w-5" aria-hidden="true" />
                    <h3 class="text-brand-teal mt-4 text-base font-bold">
                        {{ feature.title }}
                    </h3>
                    <p class="text-brand-black mt-2 text-sm leading-relaxed">
                        {{ feature.text }}
                    </p>
                </article>
            </div>
        </div>
    </section>
</template>

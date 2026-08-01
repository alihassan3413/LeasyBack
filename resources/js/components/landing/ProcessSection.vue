<script setup lang="ts">
import { ArrowRight, Check } from 'lucide-vue-next';
import { ref } from 'vue';

const steps = [
    {
        id: 'erfassen',
        title: 'Fahrzeug erfassen',
        duration: 'ca. 5 Minuten',
        text: 'Fahrzeugdaten und Rückgabetermin eingeben, Schäden fotografieren. Mehr brauchen wir nicht, um loszulegen.',
        points: ['Fahrzeugschein-Daten übernehmen', 'Schäden per Foto hochladen', 'Rückgabetermin hinterlegen'],
    },
    {
        id: 'gutachten',
        title: 'Gutachten erhalten',
        duration: 'innerhalb von 48 Stunden',
        text: 'Unsere Sachverständigen bewerten jede Position nach anerkannten Maßstäben — und zeigen dir, was davon wirklich Minderwert ist.',
        points: ['Bewertung Position für Position', 'Normale Gebrauchsspuren klar abgegrenzt', 'Als PDF zum Download'],
    },
    {
        id: 'reparieren',
        title: 'Reparaturangebote vergleichen',
        duration: 'du entscheidest',
        text: 'Betriebe aus unserem Netzwerk bieten auf deine Schäden. Du siehst Preis, Dauer und Bewertung nebeneinander.',
        points: ['Mehrere Angebote pro Schaden', 'Festpreise statt Schätzungen', 'Termin direkt buchen'],
    },
    {
        id: 'zurueckgeben',
        title: 'Fahrzeug zurückgeben',
        duration: 'am Übergabetag',
        text: 'Mit Gutachten und Reparaturnachweisen in der Hand gehst du gelassen in die Übergabe — und weißt, was fair ist.',
        points: ['Vollständige Dokumentation', 'Argumente für die Übergabe', 'Unterstützung bei Nachforderungen'],
    },
];

const activeIndex = ref(0);
const tabRefs = ref<HTMLButtonElement[]>([]);

function setTabRef(el: unknown, index: number) {
    if (el instanceof HTMLButtonElement) {
        tabRefs.value[index] = el;
    }
}

function onKeydown(event: KeyboardEvent, index: number) {
    let next = index;

    if (event.key === 'ArrowDown' || event.key === 'ArrowRight') {
        next = (index + 1) % steps.length;
    } else if (event.key === 'ArrowUp' || event.key === 'ArrowLeft') {
        next = (index - 1 + steps.length) % steps.length;
    } else if (event.key === 'Home') {
        next = 0;
    } else if (event.key === 'End') {
        next = steps.length - 1;
    } else {
        return;
    }

    event.preventDefault();
    activeIndex.value = next;
    tabRefs.value[next]?.focus();
}
</script>

<template>
    <section id="ablauf" class="px-4 py-16 sm:px-6 lg:px-8 lg:py-24">
        <div class="mx-auto max-w-7xl">
            <div class="grid gap-10 lg:grid-cols-[1fr_1.1fr] lg:items-start lg:gap-16">
                <div>
                    <p class="text-brand-green-gray text-xs font-medium tracking-[0.16em] uppercase">Ablauf</p>
                    <h2 class="text-brand-teal mt-4 text-3xl leading-tight font-bold tracking-[-0.02em] sm:text-4xl lg:text-5xl">
                        In vier Schritten zur stressfreien Rückgabe
                    </h2>
                    <p class="text-brand-black mt-4 max-w-lg text-base sm:text-lg">
                        Kein Papierkram, keine Diskussion am Übergabetag. Du siehst jederzeit, wo dein Vorgang steht.
                    </p>

                    <div class="mt-10" role="tablist" aria-label="Ablauf der Rückgabe" aria-orientation="vertical">
                        <button
                            v-for="(step, index) in steps"
                            :key="step.id"
                            :ref="(el) => setTabRef(el, index)"
                            type="button"
                            role="tab"
                            :id="`step-tab-${step.id}`"
                            :aria-selected="activeIndex === index"
                            :aria-controls="`step-panel-${step.id}`"
                            :tabindex="activeIndex === index ? 0 : -1"
                            class="border-brand-green-gray/60 focus-visible:ring-brand-teal flex w-full items-center gap-4 border-t py-5 text-left transition-colors last:border-b focus-visible:ring-2 focus-visible:outline-none motion-reduce:transition-none"
                            @click="activeIndex = index"
                            @keydown="onKeydown($event, index)"
                        >
                            <span
                                class="shrink-0 text-sm font-bold tabular-nums transition-colors motion-reduce:transition-none"
                                :class="activeIndex === index ? 'text-brand-orange' : 'text-brand-green-gray'"
                            >
                                {{ String(index + 1).padStart(2, '0') }}
                            </span>
                            <span
                                class="min-w-0 flex-1 text-lg font-bold transition-colors motion-reduce:transition-none"
                                :class="activeIndex === index ? 'text-brand-teal' : 'text-brand-black'"
                            >
                                {{ step.title }}
                            </span>
                            <span
                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full transition-colors motion-reduce:transition-none"
                                :class="activeIndex === index ? 'bg-brand-orange text-white' : 'bg-brand-teal/[0.06] text-brand-green-gray'"
                                aria-hidden="true"
                            >
                                <ArrowRight class="h-4 w-4" />
                            </span>
                        </button>
                    </div>
                </div>

                <div class="bg-brand-teal relative overflow-hidden rounded-[24px] p-6 sm:p-10">
                    <img
                        src="/path-green.svg"
                        alt=""
                        aria-hidden="true"
                        class="pointer-events-none absolute -top-12 -right-16 w-[320px] max-w-none opacity-30"
                    />
                    <div
                        v-for="(step, index) in steps"
                        v-show="activeIndex === index"
                        :key="step.id"
                        :id="`step-panel-${step.id}`"
                        role="tabpanel"
                        :aria-labelledby="`step-tab-${step.id}`"
                        tabindex="0"
                        class="relative focus-visible:ring-2 focus-visible:ring-white/60 focus-visible:outline-none"
                    >
                        <p class="text-xs font-medium tracking-[0.16em] text-white/60 uppercase">
                            Schritt {{ index + 1 }} von {{ steps.length }} ·
                            {{ step.duration }}
                        </p>
                        <h3 class="mt-4 text-2xl leading-tight font-bold text-white sm:text-3xl">
                            {{ step.title }}
                        </h3>
                        <p class="mt-4 max-w-md text-sm leading-relaxed text-white/80 sm:text-base">
                            {{ step.text }}
                        </p>

                        <ul class="mt-8 space-y-3">
                            <li v-for="point in step.points" :key="point" class="flex items-start gap-3 rounded-[10px] bg-white/[0.07] p-4">
                                <Check class="text-brand-green mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                                <span class="text-sm text-white">{{ point }}</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>

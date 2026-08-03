<script setup lang="ts">
import { Star } from 'lucide-vue-next';

// PLATZHALTER: Beispieltexte. Vor dem Livegang durch echte, freigegebene Kundenstimmen ersetzen.
const testimonials = [
    {
        quote: 'Der Leasinggeber wollte über 1.800 Euro. Nach dem Gutachten und zwei Reparaturen waren es am Ende gut 1.000 Euro weniger.',
        name: 'Michael K.',
        role: 'Privatkunde, Hamburg',
        initials: 'MK',
    },
    {
        quote: 'Wir geben im Jahr rund 40 Fahrzeuge zurück. Dass alle Gutachten und Belege an einem Ort liegen, spart uns jedes Mal Tage.',
        name: 'Sandra T.',
        role: 'Fuhrparkleitung, Köln',
        initials: 'ST',
    },
    {
        quote: 'Die Angebote kamen innerhalb von zwei Tagen. Ich konnte in Ruhe vergleichen, statt mich am Rückgabetag überrumpeln zu lassen.',
        name: 'Jonas B.',
        role: 'Privatkunde, München',
        initials: 'JB',
    },
];
</script>

<template>
    <section id="stimmen" class="scroll-mt-24 px-4 py-12 sm:px-6 sm:py-16 lg:px-8 lg:py-24">
        <div class="mx-auto max-w-7xl">
            <div class="mx-auto max-w-2xl text-center">
                <p class="text-brand-green-gray text-xs font-medium tracking-[0.16em] uppercase">Stimmen</p>
                <h2 class="text-brand-teal mt-4 text-3xl leading-tight font-bold tracking-[-0.02em] sm:text-4xl lg:text-5xl">
                    Was Kundinnen und Kunden berichten
                </h2>
            </div>

            <ul class="mt-12 grid gap-4 sm:grid-cols-2 lg:mt-16 lg:grid-cols-3">
                <li
                    v-for="testimonial in testimonials"
                    :key="testimonial.name"
                    class="border-brand-green-gray flex flex-col rounded-[10px] border bg-white p-6 shadow-[0_4px_4px_rgba(0,0,0,0.06)] sm:p-7"
                >
                    <div class="flex gap-0.5" aria-label="5 von 5 Sternen">
                        <Star v-for="star in 5" :key="star" class="fill-brand-orange text-brand-orange h-4 w-4" aria-hidden="true" />
                    </div>
                    <blockquote class="text-brand-black mt-5 flex-1 text-sm leading-relaxed">„{{ testimonial.quote }}“</blockquote>
                    <div class="border-brand-green-gray/50 mt-6 flex items-center gap-3 border-t pt-5">
                        <span
                            class="bg-brand-teal flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-xs font-bold text-white"
                            aria-hidden="true"
                        >
                            {{ testimonial.initials }}
                        </span>
                        <div class="min-w-0">
                            <p class="text-brand-teal truncate text-sm font-bold">
                                {{ testimonial.name }}
                            </p>
                            <p class="text-brand-black truncate text-xs">
                                {{ testimonial.role }}
                            </p>
                        </div>
                    </div>
                </li>
            </ul>
        </div>
    </section>
</template>

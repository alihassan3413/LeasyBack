<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight, Check, ShieldCheck } from 'lucide-vue-next';

// PLATZHALTER: echte Kennzahl eintragen, sobald verfügbar.
const customerCount = '4.800+';

const damageRows = [
    { label: 'Kratzer Stoßstange hinten', claim: '480 €', offer: '210 €' },
    { label: 'Felgenschaden vorne links', claim: '390 €', offer: '165 €' },
    { label: 'Steinschlag Frontscheibe', claim: '970 €', offer: '692 €' },
];
</script>

<template>
    <section class="relative overflow-hidden px-4 pt-10 sm:px-6 lg:px-8 lg:pt-16">
        <div class="mx-auto max-w-7xl">
            <div
                class="border-brand-green-gray/50 relative overflow-hidden rounded-[24px] border bg-white px-5 pt-12 pb-12 shadow-[0_4px_24px_rgba(16,57,59,0.06)] sm:px-8 lg:px-14 lg:pt-20 lg:pb-20"
            >
                <img
                    src="/path-orange.svg"
                    alt=""
                    aria-hidden="true"
                    class="pointer-events-none absolute -top-16 -right-24 w-[420px] max-w-none opacity-20"
                />
                <img
                    src="/path-green.svg"
                    alt=""
                    aria-hidden="true"
                    class="pointer-events-none absolute -bottom-20 -left-24 w-[380px] max-w-none opacity-20"
                />

                <div class="relative grid items-center gap-12 lg:grid-cols-[1.05fr_1fr] lg:gap-10">
                    <div
                        class="translate-y-0 opacity-100 transition-all duration-700 motion-reduce:transition-none starting:translate-y-3 starting:opacity-0"
                    >
                        <p
                            class="border-brand-green-gray/70 bg-brand-teal/[0.04] text-brand-teal inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-medium tracking-[0.14em] uppercase"
                        >
                            <ShieldCheck class="text-brand-green h-3.5 w-3.5" aria-hidden="true" />
                            Leasingrückgabe ohne Risiko
                        </p>

                        <h1
                            class="text-brand-teal mt-6 text-[2.35rem] leading-[1.06] font-bold tracking-[-0.02em] break-words sm:text-6xl lg:text-[4.1rem]"
                        >
                            Leasing zurückgeben.<br />
                            Ohne teure Überraschung.
                        </h1>

                        <p class="text-brand-black mt-6 max-w-xl text-base leading-relaxed sm:text-lg">
                            Sicher dir jetzt dein kostenloses Minderwert-Gutachten, erhalte günstige
                            <span class="text-brand-orange font-semibold">Reparaturangebote</span>
                            und gib dein Leasingfahrzeug mit bis zu 42 % Ersparnis stressfrei zurück!
                        </p>

                        <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:items-center">
                            <Link
                                :href="route('register')"
                                class="group bg-brand-orange hover:bg-brand-orange/90 focus-visible:ring-brand-orange inline-flex w-full items-center justify-center gap-2 rounded-[5px] px-6 py-3 text-base font-bold text-white transition-colors focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none sm:w-auto"
                            >
                                Kostenloses Gutachten starten
                                <ArrowRight
                                    class="h-4 w-4 transition-transform group-hover:translate-x-1 motion-reduce:transition-none"
                                    aria-hidden="true"
                                />
                            </Link>
                            <a
                                href="#ablauf"
                                class="border-brand-green-gray text-brand-teal hover:bg-brand-teal/[0.04] focus-visible:ring-brand-teal inline-flex w-full items-center justify-center rounded-[5px] border px-6 py-3 text-base font-bold transition-colors focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none sm:w-auto"
                            >
                                So funktioniert’s
                            </a>
                        </div>

                        <ul class="mt-8 flex flex-wrap gap-x-6 gap-y-2">
                            <li
                                v-for="item in ['Gutachten kostenlos', 'Keine Vertragsbindung', 'In 5 Minuten erledigt']"
                                :key="item"
                                class="text-brand-black flex items-center gap-2 text-sm"
                            >
                                <Check class="text-brand-green h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ item }}
                            </li>
                        </ul>

                        <div class="border-brand-green-gray/50 mt-10 flex items-center gap-4 border-t pt-6">
                            <div class="flex -space-x-2" aria-hidden="true">
                                <span
                                    v-for="(initials, index) in ['MK', 'ST', 'JB', 'AL']"
                                    :key="initials"
                                    class="flex h-9 w-9 items-center justify-center rounded-full border-2 border-white text-[11px] font-bold text-white"
                                    :class="index % 2 === 0 ? 'bg-brand-teal' : 'bg-brand-green'"
                                >
                                    {{ initials }}
                                </span>
                            </div>
                            <p class="text-brand-black text-sm">
                                <span class="text-brand-teal font-bold">{{ customerCount }}</span>
                                Leasingrückgaben in Deutschland begleitet.
                            </p>
                        </div>
                    </div>

                    <!-- Signature-Element: Auszug aus einem Minderwert-Gutachten (Beispielwerte) -->
                    <div
                        class="translate-y-0 opacity-100 transition-all delay-150 duration-700 motion-reduce:transition-none starting:translate-y-4 starting:opacity-0"
                    >
                        <div
                            class="border-brand-green-gray mx-auto w-full max-w-md rounded-[10px] border bg-white p-5 shadow-[0_4px_4px_rgba(0,0,0,0.25)] sm:p-6"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-brand-green-gray text-xs font-medium tracking-[0.12em] uppercase">Minderwert-Gutachten</p>
                                    <p class="text-brand-teal mt-1 truncate text-base font-bold">BMW 320d Touring</p>
                                    <p class="text-brand-black mt-0.5 text-xs">Rückgabe in 34 Tagen · 3 Positionen</p>
                                </div>
                                <span class="bg-brand-green/10 text-brand-green shrink-0 rounded-full px-2.5 py-1 text-[11px] font-bold"
                                    >Geprüft</span
                                >
                            </div>

                            <dl class="mt-6 grid grid-cols-2 gap-3">
                                <div class="bg-brand-teal/[0.04] rounded-[10px] p-4">
                                    <dt class="text-brand-black text-xs">Forderung Leasinggeber</dt>
                                    <dd class="text-brand-black decoration-brand-green-gray mt-1 text-xl font-bold line-through sm:text-2xl">
                                        1.840 €
                                    </dd>
                                </div>
                                <div class="bg-brand-teal rounded-[10px] p-4">
                                    <dt class="text-xs text-white/70">Mit LeasyBack</dt>
                                    <dd class="mt-1 text-xl font-bold text-white sm:text-2xl">1.067 €</dd>
                                </div>
                            </dl>

                            <div class="border-brand-green-gray/60 mt-4 rounded-[10px] border p-4">
                                <div class="flex items-baseline justify-between gap-2">
                                    <p class="text-brand-black text-sm font-medium">Deine Ersparnis</p>
                                    <p class="text-brand-orange text-sm font-bold">42 %</p>
                                </div>
                                <div class="bg-brand-teal/10 mt-2 h-2 w-full overflow-hidden rounded-full">
                                    <div class="bg-brand-orange h-full w-[42%] rounded-full"></div>
                                </div>
                            </div>

                            <ul class="mt-5 space-y-3">
                                <li v-for="row in damageRows" :key="row.label" class="flex items-center justify-between gap-3 text-sm">
                                    <span class="text-brand-black min-w-0 flex-1 truncate">{{ row.label }}</span>
                                    <span class="text-brand-green-gray shrink-0 line-through">{{ row.claim }}</span>
                                    <span class="text-brand-teal shrink-0 font-bold">{{ row.offer }}</span>
                                </li>
                            </ul>

                            <p class="text-brand-green-gray mt-5 text-[11px] leading-relaxed">
                                Beispielhafte Darstellung. Dein tatsächliches Ergebnis hängt von Fahrzeug, Vertrag und Schadensbild ab.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>

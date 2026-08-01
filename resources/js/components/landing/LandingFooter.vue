<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Facebook, Instagram, Linkedin, Mail } from 'lucide-vue-next';

const columns = [
    {
        title: 'Produkt',
        links: [
            { label: 'Ablauf', href: '#ablauf' },
            { label: 'Leistungen', href: '#leistungen' },
            { label: 'Für wen', href: '#zielgruppen' },
            { label: 'Häufige Fragen', href: '#faq' },
        ],
    },
    {
        title: 'Für Partner',
        links: [
            { label: 'Werkstatt werden', href: '#zielgruppen' },
            { label: 'Flottenkonto', href: '#zielgruppen' },
            { label: 'Kundenstimmen', href: '#stimmen' },
        ],
    },
];

// PLATZHALTER: echte Profil-URLs eintragen.
const socials = [
    { label: 'Instagram', href: '#', icon: Instagram },
    { label: 'Facebook', href: '#', icon: Facebook },
    { label: 'LinkedIn', href: '#', icon: Linkedin },
    { label: 'E-Mail', href: 'mailto:hallo@leasyback.de', icon: Mail },
];

const currentYear = new Date().getFullYear();
</script>

<template>
    <footer class="px-4 pb-6 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-7xl">
            <div class="bg-brand-teal rounded-[24px] px-6 py-12 sm:px-10 lg:px-14 lg:py-16">
                <div class="grid gap-10 lg:grid-cols-[1.4fr_1fr_1fr_1fr]">
                    <div>
                        <img src="/leasyback-logo.svg" alt="LeasyBack" class="h-8 w-auto" />
                        <p class="mt-5 max-w-xs text-sm leading-relaxed text-white/70">
                            Kostenlose Minderwert-Gutachten, günstige Reparaturangebote und eine Leasingrückgabe ohne böse Überraschung.
                        </p>
                        <ul class="mt-6 flex items-center gap-3">
                            <li v-for="social in socials" :key="social.label">
                                <a
                                    :href="social.href"
                                    class="flex h-9 w-9 items-center justify-center rounded-full bg-white/10 text-white transition-colors hover:bg-white/20 focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none motion-reduce:transition-none"
                                >
                                    <span class="sr-only">{{ social.label }}</span>
                                    <component :is="social.icon" class="h-4 w-4" aria-hidden="true" />
                                </a>
                            </li>
                        </ul>
                    </div>

                    <div v-for="column in columns" :key="column.title">
                        <h2 class="text-sm font-bold text-white">{{ column.title }}</h2>
                        <ul class="mt-4 space-y-3">
                            <li v-for="link in column.links" :key="link.label">
                                <a
                                    :href="link.href"
                                    class="text-sm text-white/70 transition-colors hover:text-white focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none motion-reduce:transition-none"
                                >
                                    {{ link.label }}
                                </a>
                            </li>
                        </ul>
                    </div>

                    <div>
                        <h2 class="text-sm font-bold text-white">Konto</h2>
                        <ul class="mt-4 space-y-3">
                            <li>
                                <Link
                                    :href="route('login')"
                                    class="text-sm text-white/70 transition-colors hover:text-white focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none motion-reduce:transition-none"
                                >
                                    Anmelden
                                </Link>
                            </li>
                            <li>
                                <Link
                                    :href="route('register')"
                                    class="text-brand-green focus-visible:ring-brand-green text-sm font-bold underline decoration-[1.12px] underline-offset-[2.8px] focus-visible:ring-2 focus-visible:outline-none"
                                >
                                    Kostenlos registrieren
                                </Link>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="mt-12 flex flex-col gap-4 border-t border-white/15 pt-6 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-xs text-white/60">© {{ currentYear }} LeasyBack. Alle Rechte vorbehalten.</p>
                    <!-- PLATZHALTER: auf echte Rechtsseiten verlinken, sobald die Routen existieren. -->
                    <ul class="flex flex-wrap gap-x-6 gap-y-2">
                        <li v-for="legal in ['Impressum', 'Datenschutz', 'AGB']" :key="legal">
                            <a
                                href="#"
                                class="text-xs text-white/60 transition-colors hover:text-white focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none motion-reduce:transition-none"
                            >
                                {{ legal }}
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </footer>
</template>

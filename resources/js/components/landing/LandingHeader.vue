<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Menu, X } from 'lucide-vue-next';
import { ref } from 'vue';

const isOpen = ref(false);

const navLinks = [
    { label: 'Ablauf', href: '#ablauf' },
    { label: 'Leistungen', href: '#leistungen' },
    { label: 'Für wen', href: '#zielgruppen' },
    { label: 'Stimmen', href: '#stimmen' },
    { label: 'Fragen', href: '#faq' },
];

function close() {
    isOpen.value = false;
}
</script>

<template>
    <header class="sticky top-0 z-50 px-4 pt-4 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-7xl">
            <nav
                aria-label="Hauptnavigation"
                class="border-brand-green-gray/60 rounded-[16px] border bg-white/90 px-4 py-3 shadow-[0_4px_16px_rgba(16,57,59,0.06)] backdrop-blur-md sm:px-6"
            >
                <div class="flex items-center justify-between gap-4">
                    <Link
                        :href="route('home')"
                        class="focus-visible:ring-brand-teal shrink-0 rounded-[5px] focus-visible:ring-2 focus-visible:outline-none"
                    >
                        <img src="/leasyback-logo-dark.svg" alt="LeasyBack" class="h-7 w-auto sm:h-8" />
                    </Link>

                    <ul class="hidden items-center gap-7 lg:flex">
                        <li v-for="link in navLinks" :key="link.href">
                            <a
                                :href="link.href"
                                class="text-brand-black hover:text-brand-teal focus-visible:ring-brand-teal rounded-[5px] text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:outline-none"
                            >
                                {{ link.label }}
                            </a>
                        </li>
                    </ul>

                    <div class="flex items-center gap-2 sm:gap-3">
                        <template v-if="$page.props.auth?.user">
                            <Link
                                :href="route('dashboard')"
                                class="bg-brand-teal hover:bg-brand-teal/90 focus-visible:ring-brand-teal rounded-[5px] px-4 py-2.5 text-sm font-bold text-white transition-colors focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                            >
                                Zum Dashboard
                            </Link>
                        </template>
                        <template v-else>
                            <Link
                                :href="route('login')"
                                class="text-brand-black hover:text-brand-teal focus-visible:ring-brand-teal hidden rounded-[5px] px-3 py-2.5 text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:outline-none sm:inline-block"
                            >
                                Anmelden
                            </Link>
                            <Link
                                :href="route('register')"
                                class="bg-brand-orange hover:bg-brand-orange/90 focus-visible:ring-brand-orange rounded-[5px] px-4 py-2.5 text-sm font-bold text-white transition-colors focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none sm:px-5"
                            >
                                Kostenlos starten
                            </Link>
                        </template>

                        <button
                            type="button"
                            class="text-brand-teal hover:bg-brand-teal/5 focus-visible:ring-brand-teal rounded-[5px] p-2 transition-colors focus-visible:ring-2 focus-visible:outline-none lg:hidden"
                            :aria-expanded="isOpen"
                            aria-controls="landing-mobile-menu"
                            @click="isOpen = !isOpen"
                        >
                            <span class="sr-only">{{ isOpen ? 'Menü schließen' : 'Menü öffnen' }}</span>
                            <Menu v-if="!isOpen" class="h-5 w-5" aria-hidden="true" />
                            <X v-else class="h-5 w-5" aria-hidden="true" />
                        </button>
                    </div>
                </div>

                <div v-show="isOpen" id="landing-mobile-menu" class="lg:hidden">
                    <ul class="border-brand-green-gray/50 mt-4 flex flex-col gap-1 border-t pt-4">
                        <li v-for="link in navLinks" :key="link.href">
                            <a
                                :href="link.href"
                                class="text-brand-black hover:bg-brand-teal/5 hover:text-brand-teal focus-visible:ring-brand-teal block rounded-[5px] px-2 py-2.5 text-base font-medium transition-colors focus-visible:ring-2 focus-visible:outline-none"
                                @click="close"
                            >
                                {{ link.label }}
                            </a>
                        </li>
                        <li v-if="!$page.props.auth?.user">
                            <Link
                                :href="route('login')"
                                class="text-brand-green focus-visible:ring-brand-green block rounded-[5px] px-2 py-2.5 text-base font-bold underline decoration-[1.12px] underline-offset-[2.8px] focus-visible:ring-2 focus-visible:outline-none"
                            >
                                Anmelden
                            </Link>
                        </li>
                    </ul>
                </div>
            </nav>
        </div>
    </header>
</template>

<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
import { Menu, X } from "lucide-vue-next";
import { ref } from "vue";

const isOpen = ref(false);

const navLinks = [
  { label: "Ablauf", href: "#ablauf" },
  { label: "Leistungen", href: "#leistungen" },
  { label: "Für wen", href: "#zielgruppen" },
  { label: "Stimmen", href: "#stimmen" },
  { label: "Fragen", href: "#faq" },
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
        class="rounded-[16px] border border-brand-green-gray/60 bg-white/90 px-4 py-3 shadow-[0_4px_16px_rgba(16,57,59,0.06)] backdrop-blur-md sm:px-6"
      >
        <div class="flex items-center justify-between gap-4">
          <Link
            :href="route('home')"
            class="shrink-0 rounded-[5px] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal"
          >
            <img
              src="/leasyback-logo-dark.svg"
              alt="LeasyBack"
              class="h-7 w-auto sm:h-8"
            />
          </Link>

          <ul class="hidden items-center gap-7 lg:flex">
            <li v-for="link in navLinks" :key="link.href">
              <a
                :href="link.href"
                class="rounded-[5px] text-sm font-medium text-brand-black transition-colors hover:text-brand-teal focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal"
              >
                {{ link.label }}
              </a>
            </li>
          </ul>

          <div class="flex items-center gap-2 sm:gap-3">
            <template v-if="$page.props.auth?.user">
              <Link
                :href="route('dashboard')"
                class="rounded-[5px] bg-brand-teal px-4 py-2.5 text-sm font-bold text-white transition-colors hover:bg-brand-teal/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal focus-visible:ring-offset-2"
              >
                Zum Dashboard
              </Link>
            </template>
            <template v-else>
              <Link
                :href="route('login')"
                class="hidden rounded-[5px] px-3 py-2.5 text-sm font-medium text-brand-black transition-colors hover:text-brand-teal focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal sm:inline-block"
              >
                Anmelden
              </Link>
              <Link
                :href="route('register')"
                class="rounded-[5px] bg-brand-orange px-4 py-2.5 text-sm font-bold text-white transition-colors hover:bg-brand-orange/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-orange focus-visible:ring-offset-2 sm:px-5"
              >
                Kostenlos starten
              </Link>
            </template>

            <button
              type="button"
              class="rounded-[5px] p-2 text-brand-teal transition-colors hover:bg-brand-teal/5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal lg:hidden"
              :aria-expanded="isOpen"
              aria-controls="landing-mobile-menu"
              @click="isOpen = !isOpen"
            >
              <span class="sr-only">{{
                isOpen ? "Menü schließen" : "Menü öffnen"
              }}</span>
              <Menu v-if="!isOpen" class="h-5 w-5" aria-hidden="true" />
              <X v-else class="h-5 w-5" aria-hidden="true" />
            </button>
          </div>
        </div>

        <div v-show="isOpen" id="landing-mobile-menu" class="lg:hidden">
          <ul
            class="mt-4 flex flex-col gap-1 border-t border-brand-green-gray/50 pt-4"
          >
            <li v-for="link in navLinks" :key="link.href">
              <a
                :href="link.href"
                class="block rounded-[5px] px-2 py-2.5 text-base font-medium text-brand-black transition-colors hover:bg-brand-teal/5 hover:text-brand-teal focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal"
                @click="close"
              >
                {{ link.label }}
              </a>
            </li>
            <li v-if="!$page.props.auth?.user">
              <Link
                :href="route('login')"
                class="block rounded-[5px] px-2 py-2.5 text-base font-bold text-brand-green underline decoration-[1.12px] underline-offset-[2.8px] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-green"
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

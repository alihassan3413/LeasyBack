<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
import { ArrowRight, Check, ShieldCheck } from "lucide-vue-next";

// PLATZHALTER: echte Kennzahl eintragen, sobald verfügbar.
const customerCount = "4.800+";

const damageRows = [
  { label: "Kratzer Stoßstange hinten", claim: "480 €", offer: "210 €" },
  { label: "Felgenschaden vorne links", claim: "390 €", offer: "165 €" },
  { label: "Steinschlag Frontscheibe", claim: "970 €", offer: "692 €" },
];
</script>

<template>
  <section class="relative overflow-hidden px-4 pt-10 sm:px-6 lg:px-8 lg:pt-16">
    <div class="mx-auto max-w-7xl">
      <div
        class="relative overflow-hidden rounded-[24px] border border-brand-green-gray/50 bg-white px-5 pb-12 pt-12 shadow-[0_4px_24px_rgba(16,57,59,0.06)] sm:px-8 lg:px-14 lg:pb-20 lg:pt-20"
      >
        <img
          src="/path-orange.svg"
          alt=""
          aria-hidden="true"
          class="pointer-events-none absolute -right-24 -top-16 w-[420px] max-w-none opacity-20"
        />
        <img
          src="/path-green.svg"
          alt=""
          aria-hidden="true"
          class="pointer-events-none absolute -bottom-20 -left-24 w-[380px] max-w-none opacity-20"
        />

        <div
          class="relative grid items-center gap-12 lg:grid-cols-[1.05fr_1fr] lg:gap-10"
        >
          <div
            class="duration-700 starting:translate-y-3 starting:opacity-0 translate-y-0 opacity-100 transition-all motion-reduce:transition-none"
          >
            <p
              class="inline-flex items-center gap-2 rounded-full border border-brand-green-gray/70 bg-brand-teal/[0.04] px-3 py-1.5 text-xs font-medium uppercase tracking-[0.14em] text-brand-teal"
            >
              <ShieldCheck
                class="h-3.5 w-3.5 text-brand-green"
                aria-hidden="true"
              />
              Leasingrückgabe ohne Risiko
            </p>

            <h1
              class="mt-6 break-words text-[2.35rem] font-bold leading-[1.06] tracking-[-0.02em] text-brand-teal sm:text-6xl lg:text-[4.1rem]"
            >
              Leasing zurückgeben.<br />
              Ohne teure Überraschung.
            </h1>

            <p
              class="mt-6 max-w-xl text-base leading-relaxed text-brand-black sm:text-lg"
            >
              Sicher dir jetzt dein kostenloses Minderwert-Gutachten, erhalte
              günstige
              <span class="font-semibold text-brand-orange"
                >Reparaturangebote</span
              >
              und gib dein Leasingfahrzeug mit bis zu 42 % Ersparnis stressfrei
              zurück!
            </p>

            <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:items-center">
              <Link
                :href="route('register')"
                class="group inline-flex w-full items-center justify-center gap-2 rounded-[5px] bg-brand-orange px-6 py-3 text-base font-bold text-white transition-colors hover:bg-brand-orange/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-orange focus-visible:ring-offset-2 sm:w-auto"
              >
                Kostenloses Gutachten starten
                <ArrowRight
                  class="h-4 w-4 transition-transform group-hover:translate-x-1 motion-reduce:transition-none"
                  aria-hidden="true"
                />
              </Link>
              <a
                href="#ablauf"
                class="inline-flex w-full items-center justify-center rounded-[5px] border border-brand-green-gray px-6 py-3 text-base font-bold text-brand-teal transition-colors hover:bg-brand-teal/[0.04] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal focus-visible:ring-offset-2 sm:w-auto"
              >
                So funktioniert’s
              </a>
            </div>

            <ul class="mt-8 flex flex-wrap gap-x-6 gap-y-2">
              <li
                v-for="item in [
                  'Gutachten kostenlos',
                  'Keine Vertragsbindung',
                  'In 5 Minuten erledigt',
                ]"
                :key="item"
                class="flex items-center gap-2 text-sm text-brand-black"
              >
                <Check
                  class="h-4 w-4 shrink-0 text-brand-green"
                  aria-hidden="true"
                />
                {{ item }}
              </li>
            </ul>

            <div
              class="mt-10 flex items-center gap-4 border-t border-brand-green-gray/50 pt-6"
            >
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
              <p class="text-sm text-brand-black">
                <span class="font-bold text-brand-teal">{{
                  customerCount
                }}</span>
                Leasingrückgaben in Deutschland begleitet.
              </p>
            </div>
          </div>

          <!-- Signature-Element: Auszug aus einem Minderwert-Gutachten (Beispielwerte) -->
          <div
            class="duration-700 starting:translate-y-4 starting:opacity-0 translate-y-0 opacity-100 transition-all delay-150 motion-reduce:transition-none"
          >
            <div
              class="mx-auto w-full max-w-md rounded-[10px] border border-brand-green-gray bg-white p-5 shadow-[0_4px_4px_rgba(0,0,0,0.25)] sm:p-6"
            >
              <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                  <p
                    class="text-xs font-medium uppercase tracking-[0.12em] text-brand-green-gray"
                  >
                    Minderwert-Gutachten
                  </p>
                  <p class="mt-1 truncate text-base font-bold text-brand-teal">
                    BMW 320d Touring
                  </p>
                  <p class="mt-0.5 text-xs text-brand-black">
                    Rückgabe in 34 Tagen · 3 Positionen
                  </p>
                </div>
                <span
                  class="shrink-0 rounded-full bg-brand-green/10 px-2.5 py-1 text-[11px] font-bold text-brand-green"
                  >Geprüft</span
                >
              </div>

              <dl class="mt-6 grid grid-cols-2 gap-3">
                <div class="rounded-[10px] bg-brand-teal/[0.04] p-4">
                  <dt class="text-xs text-brand-black">
                    Forderung Leasinggeber
                  </dt>
                  <dd
                    class="mt-1 text-xl font-bold text-brand-black line-through decoration-brand-green-gray sm:text-2xl"
                  >
                    1.840 €
                  </dd>
                </div>
                <div class="rounded-[10px] bg-brand-teal p-4">
                  <dt class="text-xs text-white/70">Mit LeasyBack</dt>
                  <dd class="mt-1 text-xl font-bold text-white sm:text-2xl">
                    1.067 €
                  </dd>
                </div>
              </dl>

              <div
                class="mt-4 rounded-[10px] border border-brand-green-gray/60 p-4"
              >
                <div class="flex items-baseline justify-between gap-2">
                  <p class="text-sm font-medium text-brand-black">
                    Deine Ersparnis
                  </p>
                  <p class="text-sm font-bold text-brand-orange">42 %</p>
                </div>
                <div
                  class="mt-2 h-2 w-full overflow-hidden rounded-full bg-brand-teal/10"
                >
                  <div
                    class="h-full w-[42%] rounded-full bg-brand-orange"
                  ></div>
                </div>
              </div>

              <ul class="mt-5 space-y-3">
                <li
                  v-for="row in damageRows"
                  :key="row.label"
                  class="flex items-center justify-between gap-3 text-sm"
                >
                  <span class="min-w-0 flex-1 truncate text-brand-black">{{
                    row.label
                  }}</span>
                  <span class="shrink-0 text-brand-green-gray line-through">{{
                    row.claim
                  }}</span>
                  <span class="shrink-0 font-bold text-brand-teal">{{
                    row.offer
                  }}</span>
                </li>
              </ul>

              <p class="mt-5 text-[11px] leading-relaxed text-brand-green-gray">
                Beispielhafte Darstellung. Dein tatsächliches Ergebnis hängt von
                Fahrzeug, Vertrag und Schadensbild ab.
              </p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>

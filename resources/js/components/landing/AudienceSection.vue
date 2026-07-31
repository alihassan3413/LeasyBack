<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
import { ArrowRight, Check } from "lucide-vue-next";

const audiences = [
  {
    eyebrow: "Privatkunde",
    title: "Kostenlos",
    priceNote: "Gutachten inklusive",
    text: "Für alle, die ihr geleastes Fahrzeug in den nächsten Monaten zurückgeben.",
    features: [
      "Minderwert-Gutachten kostenlos",
      "Reparaturangebote aus dem Netzwerk",
      "Rückgabe-Checkliste",
      "Support per E-Mail",
    ],
    cta: "Kostenlos starten",
    featured: false,
  },
  {
    eyebrow: "Firmenkunde",
    title: "Flotte",
    priceNote: "Preis auf Anfrage",
    text: "Für Fuhrparks, die mehrere Rückläufer pro Jahr abwickeln — mit Sammelabrechnung.",
    features: [
      "Alle Leistungen für Privatkunden",
      "Mehrere Fahrzeuge in einem Konto",
      "Sammelabrechnung & Reporting",
      "Fester Ansprechpartner",
      "Priorisierte Bearbeitung",
    ],
    cta: "Flottenkonto anfragen",
    featured: true,
  },
  {
    eyebrow: "Werkstatt",
    title: "Partner",
    priceNote: "kostenfrei gelistet",
    text: "Für Betriebe, die planbare Aufträge aus qualifizierten Gutachten übernehmen möchten.",
    features: [
      "Anfragen aus deiner Region",
      "Angebote direkt einstellen",
      "Auftragsübersicht im Konto",
      "Keine Grundgebühr",
    ],
    cta: "Partner werden",
    featured: false,
  },
];
</script>

<template>
  <section id="zielgruppen" class="px-4 py-16 sm:px-6 lg:px-8 lg:py-24">
    <div class="mx-auto max-w-7xl">
      <div class="mx-auto max-w-2xl text-center">
        <p
          class="text-xs font-medium uppercase tracking-[0.16em] text-brand-green-gray"
        >
          Für wen
        </p>
        <h2
          class="mt-4 text-3xl font-bold leading-tight tracking-[-0.02em] text-brand-teal sm:text-4xl lg:text-5xl"
        >
          Passend für dein Fahrzeug — oder deine ganze Flotte
        </h2>
        <p class="mt-4 text-base text-brand-black sm:text-lg">
          Das Gutachten bleibt für Privatkunden kostenlos. Zahlen musst du nur
          die Reparaturen, die du wirklich beauftragst.
        </p>
      </div>

      <div class="mt-12 grid gap-4 lg:mt-16 lg:grid-cols-3 lg:items-start">
        <article
          v-for="audience in audiences"
          :key="audience.eyebrow"
          class="flex flex-col rounded-[10px] p-6 sm:p-8"
          :class="
            audience.featured
              ? 'bg-brand-teal shadow-[0_8px_24px_rgba(16,57,59,0.2)] lg:-mt-4 lg:pb-10 lg:pt-10'
              : 'border border-brand-green-gray bg-white shadow-[0_4px_4px_rgba(0,0,0,0.06)]'
          "
        >
          <div class="flex items-center justify-between gap-3">
            <p
              class="text-sm font-bold"
              :class="audience.featured ? 'text-white' : 'text-brand-teal'"
            >
              {{ audience.eyebrow }}
            </p>
            <span
              v-if="audience.featured"
              class="rounded-full bg-brand-orange px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide text-white"
            >
              Beliebt
            </span>
          </div>

          <p
            class="mt-6 text-3xl font-bold tracking-tight sm:text-4xl"
            :class="audience.featured ? 'text-white' : 'text-brand-teal'"
          >
            {{ audience.title }}
          </p>
          <p
            class="mt-1 text-sm"
            :class="
              audience.featured ? 'text-white/60' : 'text-brand-green-gray'
            "
          >
            {{ audience.priceNote }}
          </p>

          <p
            class="mt-4 text-sm leading-relaxed"
            :class="audience.featured ? 'text-white/80' : 'text-brand-black'"
          >
            {{ audience.text }}
          </p>

          <div
            class="mt-6 border-t pt-6"
            :class="
              audience.featured
                ? 'border-white/15'
                : 'border-brand-green-gray/60'
            "
          >
            <p
              class="text-xs font-medium uppercase tracking-[0.14em]"
              :class="
                audience.featured ? 'text-white/60' : 'text-brand-green-gray'
              "
            >
              Enthalten
            </p>
            <ul class="mt-4 space-y-3">
              <li
                v-for="feature in audience.features"
                :key="feature"
                class="flex items-start gap-3 text-sm"
              >
                <Check
                  class="mt-0.5 h-4 w-4 shrink-0 text-brand-green"
                  aria-hidden="true"
                />
                <span
                  :class="audience.featured ? 'text-white' : 'text-brand-black'"
                  >{{ feature }}</span
                >
              </li>
            </ul>
          </div>

          <Link
            :href="route('register')"
            class="group mt-8 inline-flex w-full items-center justify-center gap-2 rounded-[5px] px-5 py-3 text-sm font-bold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 motion-reduce:transition-none"
            :class="
              audience.featured
                ? 'bg-brand-orange text-white hover:bg-brand-orange/90 focus-visible:ring-brand-orange focus-visible:ring-offset-brand-teal'
                : 'border border-brand-teal text-brand-teal hover:bg-brand-teal hover:text-white focus-visible:ring-brand-teal'
            "
          >
            {{ audience.cta }}
            <ArrowRight
              class="h-4 w-4 transition-transform group-hover:translate-x-1 motion-reduce:transition-none"
              aria-hidden="true"
            />
          </Link>
        </article>
      </div>
    </div>
  </section>
</template>

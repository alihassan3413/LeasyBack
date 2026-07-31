<script setup lang="ts">
import {
  ArrowRight,
  Calculator,
  Camera,
  FileText,
  ListChecks,
  MessageSquare,
  Wrench,
} from "lucide-vue-next";

const mainFeatures = [
  {
    icon: FileText,
    title: "Kostenloses Minderwert-Gutachten",
    text: "Wir bewerten jeden Schaden nach anerkannten Maßstäben — bevor der Leasinggeber seine Rechnung schreibt.",
  },
  {
    icon: Wrench,
    title: "Günstige Reparaturangebote",
    text: "Geprüfte Betriebe aus unserem Netzwerk bieten auf deine Schäden. Du vergleichst und entscheidest.",
  },
  {
    icon: Camera,
    title: "Schäden digital dokumentiert",
    text: "Fotos hochladen, Schaden zuordnen, fertig. Alles bleibt nachvollziehbar dokumentiert — auch bei Streitfällen.",
  },
];

const secondaryFeatures = [
  {
    icon: Calculator,
    title: "Kostenprognose vorab",
    text: "Du weißt vor der Rückgabe, was auf dich zukommt.",
  },
  {
    icon: ListChecks,
    title: "Rückgabe-Checkliste",
    text: "Schritt für Schritt zum Übergabetermin — nichts vergessen.",
  },
  {
    icon: MessageSquare,
    title: "Persönlicher Kontakt",
    text: "Feste Ansprechpartner statt Warteschleife.",
  },
];
</script>

<template>
  <section id="leistungen" class="px-4 py-16 sm:px-6 lg:px-8 lg:py-24">
    <div class="mx-auto max-w-7xl">
      <div class="mx-auto max-w-2xl text-center">
        <p
          class="text-xs font-medium uppercase tracking-[0.16em] text-brand-green-gray"
        >
          Leistungen
        </p>
        <h2
          class="mt-4 text-3xl font-bold leading-tight tracking-[-0.02em] text-brand-teal sm:text-4xl lg:text-5xl"
        >
          Alles, was du für eine faire Rückgabe brauchst
        </h2>
        <p class="mt-4 text-base text-brand-black sm:text-lg">
          Von der ersten Einschätzung bis zur Schlüsselübergabe — an einem Ort,
          ohne Papierkram.
        </p>
      </div>

      <div class="mt-12 grid gap-4 sm:grid-cols-2 lg:mt-16 lg:grid-cols-3">
        <article
          v-for="feature in mainFeatures"
          :key="feature.title"
          class="flex flex-col rounded-[10px] border border-brand-green-gray bg-white p-6 shadow-[0_4px_4px_rgba(0,0,0,0.06)] transition-shadow hover:shadow-[0_4px_16px_rgba(16,57,59,0.12)] motion-reduce:transition-none sm:p-7"
        >
          <span
            class="flex h-11 w-11 items-center justify-center rounded-[10px] bg-brand-teal/[0.06]"
          >
            <component
              :is="feature.icon"
              class="h-5 w-5 text-brand-teal"
              aria-hidden="true"
            />
          </span>
          <h3 class="mt-5 text-lg font-bold text-brand-teal">
            {{ feature.title }}
          </h3>
          <p class="mt-3 flex-1 text-sm leading-relaxed text-brand-black">
            {{ feature.text }}
          </p>
          <a
            href="#ablauf"
            class="group mt-6 inline-flex items-center gap-1.5 self-start rounded-[5px] text-sm font-bold text-brand-green underline decoration-[1.12px] underline-offset-[2.8px] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-green"
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
          class="rounded-[10px] border border-brand-green-gray/60 bg-brand-teal/[0.03] p-6"
        >
          <component
            :is="feature.icon"
            class="h-5 w-5 text-brand-orange"
            aria-hidden="true"
          />
          <h3 class="mt-4 text-base font-bold text-brand-teal">
            {{ feature.title }}
          </h3>
          <p class="mt-2 text-sm leading-relaxed text-brand-black">
            {{ feature.text }}
          </p>
        </article>
      </div>
    </div>
  </section>
</template>

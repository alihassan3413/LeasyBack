<script setup lang="ts">
import { Minus, Plus } from "lucide-vue-next";
import { ref } from "vue";

const faqs = [
  {
    id: "kosten",
    question: "Was kostet mich das Gutachten?",
    answer:
      "Für Privatkunden ist das Minderwert-Gutachten kostenlos. Kosten entstehen erst, wenn du eine Reparatur beauftragst — und auch dann kennst du den Festpreis vorher.",
  },
  {
    id: "zeitpunkt",
    question: "Wann sollte ich starten?",
    answer:
      "Am besten sechs bis acht Wochen vor dem Rückgabetermin. Dann bleibt genug Zeit für Gutachten, Angebotsvergleich und eine mögliche Reparatur.",
  },
  {
    id: "gebrauchsspuren",
    question: "Was gilt als normale Gebrauchsspur?",
    answer:
      "Leichte Kratzer, kleine Steinschläge und übliche Innenraumspuren zählen in der Regel als vertragsgemäße Abnutzung. Unser Gutachten grenzt diese Positionen klar von echtem Minderwert ab.",
  },
  {
    id: "werkstatt",
    question: "Muss ich in einer eurer Werkstätten reparieren lassen?",
    answer:
      "Nein. Du entscheidest, ob und wo du reparieren lässt. Unser Netzwerk ist ein Angebot, keine Bedingung.",
  },
  {
    id: "nachforderung",
    question: "Was, wenn der Leasinggeber trotzdem mehr fordert?",
    answer:
      "Dann hast du mit dem Gutachten und der Fotodokumentation eine belastbare Grundlage. Wir unterstützen dich dabei, die Positionen nachvollziehbar zu prüfen.",
  },
  {
    id: "firmen",
    question: "Funktioniert das auch für Firmenfahrzeuge?",
    answer:
      "Ja. Für Fuhrparks gibt es ein Flottenkonto mit Sammelabrechnung, Reporting und festem Ansprechpartner.",
  },
];

const openId = ref<string | null>(faqs[0].id);

function toggle(id: string) {
  openId.value = openId.value === id ? null : id;
}
</script>

<template>
  <section id="faq" class="px-4 py-16 sm:px-6 lg:px-8 lg:py-24">
    <div class="mx-auto max-w-7xl">
      <div class="grid gap-10 lg:grid-cols-[0.8fr_1.2fr] lg:gap-16">
        <div>
          <p
            class="text-xs font-medium uppercase tracking-[0.16em] text-brand-green-gray"
          >
            Fragen
          </p>
          <h2
            class="mt-4 text-3xl font-bold leading-tight tracking-[-0.02em] text-brand-teal sm:text-4xl"
          >
            Häufige Fragen
          </h2>
          <p class="mt-4 max-w-sm text-base text-brand-black">
            Noch etwas offen? Schreib uns —
            <a
              href="mailto:hallo@leasyback.de"
              class="font-bold text-brand-green underline decoration-[1.12px] underline-offset-[2.8px] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-green"
            >
              hallo@leasyback.de
            </a>
          </p>
        </div>

        <dl>
          <div
            v-for="faq in faqs"
            :key="faq.id"
            class="border-b border-brand-green-gray/60 first:border-t"
          >
            <dt>
              <button
                type="button"
                class="flex w-full items-center justify-between gap-4 py-5 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-teal"
                :aria-expanded="openId === faq.id"
                :aria-controls="`faq-answer-${faq.id}`"
                @click="toggle(faq.id)"
              >
                <span class="text-base font-bold text-brand-teal sm:text-lg">{{
                  faq.question
                }}</span>
                <span
                  class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full transition-colors motion-reduce:transition-none"
                  :class="
                    openId === faq.id
                      ? 'bg-brand-orange text-white'
                      : 'bg-brand-teal/[0.06] text-brand-teal'
                  "
                  aria-hidden="true"
                >
                  <Minus v-if="openId === faq.id" class="h-4 w-4" />
                  <Plus v-else class="h-4 w-4" />
                </span>
              </button>
            </dt>
            <dd
              v-show="openId === faq.id"
              :id="`faq-answer-${faq.id}`"
              class="pb-6 pr-12 text-sm leading-relaxed text-brand-black"
            >
              {{ faq.answer }}
            </dd>
          </div>
        </dl>
      </div>
    </div>
  </section>
</template>

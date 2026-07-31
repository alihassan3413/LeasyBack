<script setup lang="ts">
// PLATZHALTER: durch echte Partner-/Presse-Logos (SVG in public/) ersetzen.
// Solange keine Logos vorliegen, bewusst als Wortmarken gesetzt statt als Fake-Logos.
const partners = [
  "Werkstattnetz DE",
  "Kfz-Sachverständige",
  "Flottenpartner",
  "Leasing-Portale",
  "Smart-Repair",
];
</script>

<template>
  <section
    aria-label="Partner und Netzwerk"
    class="px-4 py-12 sm:px-6 lg:px-8 lg:py-16"
  >
    <div class="mx-auto max-w-7xl">
      <p class="text-center text-sm text-brand-black">
        Über 300 Partnerbetriebe und Sachverständige in ganz Deutschland
      </p>
      <ul
        class="mt-6 flex flex-wrap items-center justify-center gap-x-8 gap-y-4 sm:gap-x-12"
      >
        <li
          v-for="partner in partners"
          :key="partner"
          class="text-base font-semibold tracking-tight text-brand-green-gray sm:text-lg"
        >
          {{ partner }}
        </li>
      </ul>
    </div>
  </section>
</template>

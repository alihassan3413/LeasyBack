<script setup lang="ts">
// PLATZHALTER: durch echte Partner-/Presse-Logos (SVG in public/) ersetzen.
// Solange keine Logos vorliegen, bewusst als Wortmarken gesetzt statt als Fake-Logos.
const partners = ['Werkstattnetz DE', 'Kfz-Sachverständige', 'Flottenpartner', 'Leasing-Portale', 'Smart-Repair'];
</script>

<template>
    <section aria-label="Partner und Netzwerk" class="px-4 py-10 sm:px-6 sm:py-12 lg:px-8 lg:py-16">
        <div class="mx-auto max-w-7xl">
            <p class="text-brand-black text-center text-sm">Über 300 Partnerbetriebe und Sachverständige in ganz Deutschland</p>
            <ul class="mt-6 flex flex-wrap items-center justify-center gap-x-6 gap-y-3 sm:gap-x-12 sm:gap-y-4">
                <li v-for="partner in partners" :key="partner" class="text-brand-green-gray text-sm font-semibold tracking-tight sm:text-lg">
                    {{ partner }}
                </li>
            </ul>
        </div>
    </section>
</template>

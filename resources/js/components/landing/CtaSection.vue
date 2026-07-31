<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
import { ArrowRight } from "lucide-vue-next";
</script>

<template>
  <section class="px-4 pb-16 pt-4 sm:px-6 lg:px-8 lg:pb-24">
    <div class="mx-auto max-w-7xl">
      <div
        class="relative overflow-hidden rounded-[24px] bg-brand-teal px-6 py-14 text-center sm:px-10 lg:px-16 lg:py-20"
      >
        <img
          src="/path-orange.svg"
          alt=""
          aria-hidden="true"
          class="pointer-events-none absolute -left-20 -top-16 w-[360px] max-w-none opacity-25"
        />
        <img
          src="/path-green.svg"
          alt=""
          aria-hidden="true"
          class="pointer-events-none absolute -bottom-16 -right-20 w-[360px] max-w-none opacity-30"
        />

        <div class="relative mx-auto max-w-2xl">
          <h2
            class="text-3xl font-bold leading-tight tracking-[-0.02em] text-white sm:text-4xl lg:text-5xl"
          >
            Rückgabetermin im Kalender? Dann leg jetzt los.
          </h2>
          <p class="mt-5 text-base text-white/80 sm:text-lg">
            Fahrzeug erfassen, Gutachten erhalten, Angebote vergleichen.
            Kostenlos und unverbindlich.
          </p>
          <div
            class="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row"
          >
            <Link
              :href="route('register')"
              class="group inline-flex w-full items-center justify-center gap-2 rounded-[5px] bg-brand-orange px-6 py-3 text-base font-bold text-white transition-colors hover:bg-brand-orange/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-orange focus-visible:ring-offset-2 focus-visible:ring-offset-brand-teal sm:w-auto"
            >
              Kostenloses Gutachten starten
              <ArrowRight
                class="h-4 w-4 transition-transform group-hover:translate-x-1 motion-reduce:transition-none"
                aria-hidden="true"
              />
            </Link>
            <Link
              :href="route('login')"
              class="inline-flex w-full items-center justify-center rounded-[5px] border border-white/30 px-6 py-3 text-base font-bold text-white transition-colors hover:bg-white/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-brand-teal sm:w-auto"
            >
              Ich habe schon ein Konto
            </Link>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>

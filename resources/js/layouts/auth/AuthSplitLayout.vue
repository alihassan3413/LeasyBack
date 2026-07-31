<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <main
        class="relative grid min-h-dvh items-center justify-center overflow-x-hidden px-4 py-8 sm:px-6 lg:max-w-none lg:grid-cols-2 lg:px-0 lg:py-0"
    >
        <!-- min-w-0/overflow-x-hidden: defensive against a very long unbroken
             string (e.g. a long email typed with no spaces) ever forcing
             this column wider than the viewport — verified via an actual
             mobile-width screenshot during Checkpoint 6 that this does not
             currently happen, kept anyway as cheap insurance. -->
        <div class="min-w-0 lg:p-8">
            <div class="mx-auto flex w-full max-w-[420px] min-w-0 flex-col justify-center gap-6">
                <Link :href="route('home')" class="mx-auto lg:hidden">
                    <img src="/leasyback-logo-dark.svg" alt="LeasyBack" class="h-auto w-full max-w-55" />
                </Link>

                <div class="flex flex-col gap-2 text-center">
                    <h1 v-if="title" class="text-brand-black text-xl font-medium tracking-tight">{{ title }}</h1>
                    <p v-if="description" class="text-muted-foreground text-sm">{{ description }}</p>
                </div>

                <slot />
            </div>
        </div>

        <div class="bg-brand-teal relative hidden h-full flex-col items-center justify-center px-12 text-white lg:flex">
            <div class="relative z-10 flex w-full max-w-[480px] flex-col items-center text-center">
                <Link :href="route('home')">
                    <img src="/leasyback-logo.svg" alt="LeasyBack" class="h-auto w-full max-w-70" />
                </Link>

                <p class="mt-10 text-lg leading-normal font-normal text-white">
                    Sicher dir jetzt dein kostenloses Minderwert-Gutachten, erhalte günstige
                    <span class="text-brand-orange">Reparaturangebote</span>
                    und gib dein Leasingfahrzeug mit bis zu 42&nbsp;% Ersparnis stressfrei zurück!
                </p>
            </div>
        </div>
    </main>
</template>

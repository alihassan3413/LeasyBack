<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

const currentYear = new Date().getFullYear();
</script>

<template>
    <div
        class="text-brand-black relative flex min-h-svh flex-col overflow-hidden"
        style="
            background:
                radial-gradient(900px 500px at 82% -8%, rgba(1, 185, 144, 0.07), transparent 55%),
                radial-gradient(700px 420px at -5% 100%, rgba(16, 57, 59, 0.05), transparent 50%), linear-gradient(180deg, #fbfcfb 0%, #f3f6f5 100%);
        "
    >
        <img
            src="/path-green.svg"
            alt=""
            aria-hidden="true"
            class="pointer-events-none absolute -bottom-32 -left-40 hidden w-[620px] max-w-none opacity-[0.1] lg:block"
        />

        <header class="relative z-10 px-6 pt-8 sm:px-10 sm:pt-10">
            <Link
                :href="route('home')"
                class="focus-visible:ring-brand-teal inline-block rounded-[5px] focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
            >
                <img src="/leasyback-logo-dark.svg" alt="LeasyBack" class="h-7 w-auto sm:h-8" />
            </Link>
        </header>

        <main class="relative z-10 flex flex-1 items-center px-6 py-14 sm:px-10 sm:py-20">
            <div class="mx-auto w-full max-w-5xl">
                <slot />
            </div>
        </main>

        <footer class="relative z-10 px-6 pb-8 sm:px-10 sm:pb-10">
            <p class="text-brand-black/50 mx-auto max-w-5xl text-xs">© {{ currentYear }} LeasyBack. Alle Rechte vorbehalten.</p>
        </footer>
    </div>
</template>

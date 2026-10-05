<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{
    title?: string;
    description?: string;
}>();

// Same address the landing page footer publishes; there is no help, Impressum
// or Datenschutz route yet, so nothing else is linked.
const helpEmail = 'hallo@leasyback.de';
const year = new Date().getFullYear();
</script>

<template>
    <!--
        Framed centre: white header and footer bound the page, the tinted
        ground (#F7F9F8, DESIGN-FUTURE surface-bordered) sets the white sheet
        off as the focal point. Hairlines use the app's #e6eded. Below md the
        sheet dissolves and the whole page is the white form surface.
    -->
    <div class="text-brand-black flex min-h-svh flex-col bg-white md:bg-[#F7F9F8]">
        <header class="flex h-14 shrink-0 items-center justify-between border-b border-[#e6eded] bg-white px-4 md:h-16 md:px-6">
            <Link :href="route('home')" class="focus-visible:outline-brand-teal rounded-[2px] focus-visible:outline-2 focus-visible:outline-offset-4">
                <img src="/leasyback-logo-dark.svg" alt="LeasyBack – zur Startseite" class="h-6 w-auto md:h-7" />
            </Link>
            <a
                :href="`mailto:${helpEmail}`"
                :aria-label="`Hilfe per E-Mail: ${helpEmail}`"
                class="text-brand-teal decoration-brand-teal/30 hover:decoration-brand-teal focus-visible:outline-brand-teal rounded-[2px] text-sm font-semibold underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2"
            >
                Hilfe
            </a>
        </header>

        <!-- Unequal vertical padding lifts the sheet slightly above the optical centre. -->
        <main class="flex flex-1 flex-col px-4 pt-8 pb-10 md:items-center md:justify-center md:pt-10 md:pb-[10vh]">
            <div class="md:border-brand-teal/15 w-full md:w-[448px] md:rounded-[10px] md:border md:bg-white md:p-8">
                <div v-if="title || description" class="mb-6">
                    <h1 v-if="title" class="text-brand-teal text-2xl leading-tight font-bold">{{ title }}</h1>
                    <p v-if="description" class="text-brand-teal/70 mt-1.5 text-sm leading-normal">{{ description }}</p>
                </div>
                <slot />
            </div>

            <!-- Secondary navigation sits outside the sheet on desktop; on mobile it follows a hairline. -->
            <div v-if="$slots.after" class="mt-8 border-t border-[#e6eded] pt-6 text-sm md:mt-6 md:w-[448px] md:border-0 md:pt-0 md:text-center">
                <slot name="after" />
            </div>
        </main>

        <footer
            class="text-brand-teal/70 flex shrink-0 flex-col gap-1 border-t border-[#e6eded] bg-white px-4 py-4 text-xs sm:flex-row sm:items-center sm:justify-between md:px-6"
        >
            <p>© {{ year }} LeasyBack</p>
            <a
                :href="`mailto:${helpEmail}`"
                class="hover:text-brand-teal focus-visible:outline-brand-teal w-fit rounded-[2px] underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2"
                >{{ helpEmail }}</a
            >
        </footer>
    </div>
</template>

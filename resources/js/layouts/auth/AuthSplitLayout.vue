<script setup lang="ts">
defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div class="relative flex min-h-screen overflow-hidden">
        <!-- Left background -->
        <div class="absolute top-0 left-0 z-0 h-full w-full lg:w-1/2" style="background-color: #fafafa"></div>

        <!-- Decorative paths on the left side, behind the card -->
        <img
            src="/path-green.svg"
            alt=""
            class="pointer-events-none absolute z-10"
            style="left: -6vw; top: 1vw; width: 112vw; height: auto; opacity: 0.54"
        />
        <img src="/path-orange.svg" alt="" class="pointer-events-none absolute z-10" style="left: -19vw; top: -12vw; width: 105vw; height: auto" />

        <!-- Left side — form card -->
        <div class="relative flex w-full items-center justify-center px-4 py-8 sm:px-6 sm:py-12 lg:w-1/2">
            <div class="relative z-20 w-full max-w-[420px]">
                <div class="border-brand-green-gray rounded-[10px] border bg-white px-5 pb-7 shadow-[0_4px_4px_rgba(0,0,0,0.25)] sm:px-9 sm:pb-9">
                    <!--
                        Optional, generic heading — only rendered when a page passes
                        title/description. Login.vue supplies its own bespoke heading
                        (matching the leasyback_web design exactly) as slot content
                        instead, so this block doesn't apply to it; other auth pages
                        that haven't been redesigned yet keep using this as before.
                    -->
                    <div v-if="title || description" class="flex flex-col gap-2 pt-7 text-center sm:pt-9">
                        <h1 v-if="title" class="text-brand-black text-xl font-medium tracking-tight">{{ title }}</h1>
                        <p v-if="description" class="text-muted-foreground text-sm">{{ description }}</p>
                    </div>
                    <slot />
                </div>
            </div>
        </div>

        <!-- Right side — branding panel -->
        <div class="bg-brand-teal relative hidden w-1/2 items-center justify-center px-12 lg:flex">
            <div class="relative z-10 flex w-full max-w-[565px] flex-col items-center text-left">
                <img
                    src="/leasyback-logo.svg"
                    alt="LeasyBack"
                    class="h-auto w-full max-w-[220px] md:max-w-[300px] lg:max-w-[400px] xl:max-w-[565px]"
                />

                <p
                    class="mt-8 w-full max-w-[386px] text-[20px] leading-normal font-normal text-white lg:text-[24px] xl:mt-10 xl:max-w-[454px] xl:text-[32px] 2xl:mt-12 2xl:max-w-[533px]"
                >
                    Sicher dir jetzt dein kostenloses Minderwert-Gutachten, erhalte günstige
                    <span class="text-brand-orange">Reparaturangebote</span>
                    und gib dein Leasingfahrzeug mit bis zu 42&nbsp;% Ersparnis stressfrei zurück!
                </p>
            </div>
        </div>
    </div>
</template>

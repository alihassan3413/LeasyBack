<script setup lang="ts">
/**
 * Terminal page after a workshop submits. Carries no order data — the token is
 * already spent at this point and the workshop is a guest.
 */
import { Head } from '@inertiajs/vue3';
import MdiCheckCircleOutline from '~icons/mdi/check-circle-outline';
</script>

<template>
    <Head title="Angebot übermittelt" />

    <div class="flex min-h-screen items-center justify-center bg-[#f6f9f8] px-4">
        <div class="flex w-full max-w-md flex-col items-center rounded-3xl border border-[#ececec] bg-white p-8 text-center">
            <span class="flex h-14 w-14 items-center justify-center rounded-full bg-[#01B990]/10 text-[#00856a]">
                <MdiCheckCircleOutline class="size-8" />
            </span>

            <h1 class="mt-4 text-[20px] font-extrabold tracking-[-0.4px] text-[#10393b]">Vielen Dank</h1>

            <p class="mt-2 text-[13.5px] text-[#6f8585]">
                Ihr Angebot wurde übermittelt. Leasyback prüft es und meldet sich bei Ihnen, falls Rückfragen bestehen.
            </p>

            <p class="mt-4 text-[12px] text-[#9bb0af]">Dieser Link ist damit verbraucht und kann nicht erneut verwendet werden.</p>
        </div>
    </div>
</template>

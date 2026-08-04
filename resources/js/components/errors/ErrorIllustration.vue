<script setup lang="ts">
import type { ErrorIllustrationVariant } from '@/lib/errorPages';

defineProps<{
    variant: ErrorIllustrationVariant;
}>();
</script>

<template>
    <svg viewBox="0 0 240 200" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
        <ellipse cx="120" cy="178" rx="74" ry="8" fill="#10393b" fill-opacity="0.06" />

        <template v-if="variant === 'not-found'">
            <path d="M44 170c24-16 50-6 74-18" stroke="#b7c2c2" stroke-width="2.5" stroke-linecap="round" stroke-dasharray="5 9" />
            <path d="M120 170V62" stroke="#10393b" stroke-width="2.5" stroke-linecap="round" />
            <path d="M120 78h56l14 13-14 13h-56z" stroke="#10393b" stroke-width="2.5" stroke-linejoin="round" fill="#10393b" fill-opacity="0.04" />
            <path d="M120 114H64l-14 13 14 13h56z" stroke="#b7c2c2" stroke-width="2.5" stroke-linejoin="round" />
            <circle cx="120" cy="56" r="6" fill="#01b990" />
        </template>

        <template v-else-if="variant === 'locked'">
            <rect x="74" y="96" width="92" height="68" rx="14" stroke="#10393b" stroke-width="2.5" fill="#10393b" fill-opacity="0.04" />
            <path d="M96 96V76a24 24 0 0 1 48 0v20" stroke="#10393b" stroke-width="2.5" stroke-linecap="round" />
            <circle cx="120" cy="124" r="8" stroke="#01b990" stroke-width="2.5" />
            <path d="M120 132v12" stroke="#01b990" stroke-width="2.5" stroke-linecap="round" />
        </template>

        <template v-else-if="variant === 'timeout'">
            <circle cx="120" cy="112" r="52" stroke="#10393b" stroke-width="2.5" fill="#10393b" fill-opacity="0.04" />
            <path d="M120 60v6M172 112h-6M120 164v-6M68 112h6" stroke="#b7c2c2" stroke-width="2.5" stroke-linecap="round" />
            <path d="M120 80v32l24 16" stroke="#10393b" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
            <circle cx="120" cy="112" r="4.5" fill="#01b990" />
        </template>

        <template v-else-if="variant === 'expired'">
            <path d="M84 56h72M84 164h72" stroke="#10393b" stroke-width="2.5" stroke-linecap="round" />
            <path
                d="M92 58h56v12c0 22-28 26-28 40s28 18 28 40v12H92v-12c0-22 28-26 28-40s-28-18-28-40V58z"
                stroke="#10393b"
                stroke-width="2.5"
                stroke-linejoin="round"
                fill="#10393b"
                fill-opacity="0.03"
            />
            <path d="M100 158c4-14 12-22 20-22s16 8 20 22z" fill="#01b990" fill-opacity="0.25" />
            <path d="M120 114v18" stroke="#01b990" stroke-width="2.5" stroke-linecap="round" />
        </template>

        <template v-else-if="variant === 'input'">
            <rect x="64" y="44" width="112" height="122" rx="14" stroke="#10393b" stroke-width="2.5" fill="#10393b" fill-opacity="0.03" />
            <path d="M84 72h48M84 92h72M84 144h56" stroke="#b7c2c2" stroke-width="2.5" stroke-linecap="round" />
            <rect x="84" y="108" width="72" height="20" rx="7" stroke="#ef8450" stroke-width="2.5" fill="#ef8450" fill-opacity="0.08" />
            <circle cx="166" cy="118" r="4" fill="#ef8450" />
        </template>

        <template v-else-if="variant === 'blocked'">
            <path d="M44 112h22" stroke="#b7c2c2" stroke-width="2.5" stroke-linecap="round" stroke-dasharray="5 9" />
            <path d="M74 112h38" stroke="#10393b" stroke-width="2.5" stroke-linecap="round" />
            <path d="M100 98l14 14-14 14" stroke="#10393b" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
            <circle cx="156" cy="112" r="30" stroke="#ef8450" stroke-width="2.5" fill="#ef8450" fill-opacity="0.06" />
            <path d="M138 112h36" stroke="#ef8450" stroke-width="2.5" stroke-linecap="round" />
        </template>

        <template v-else-if="variant === 'throttle'">
            <path d="M62 142a58 58 0 0 1 116 0" stroke="#b7c2c2" stroke-width="3" stroke-linecap="round" />
            <path d="M161 101a58 58 0 0 1 17 41" stroke="#ef8450" stroke-width="3" stroke-linecap="round" />
            <path d="M120 142l32-32" stroke="#10393b" stroke-width="3" stroke-linecap="round" />
            <circle cx="120" cy="142" r="6.5" fill="#10393b" />
            <path d="M64 160h112" stroke="#10393b" stroke-width="2.5" stroke-linecap="round" />
        </template>

        <template v-else-if="variant === 'server'">
            <rect x="66" y="56" width="108" height="42" rx="12" stroke="#10393b" stroke-width="2.5" fill="#10393b" fill-opacity="0.04" />
            <rect x="66" y="122" width="108" height="42" rx="12" stroke="#10393b" stroke-width="2.5" fill="#10393b" fill-opacity="0.04" />
            <circle cx="88" cy="77" r="5" fill="#01b990" />
            <circle cx="88" cy="143" r="5" fill="#ef8450" />
            <path d="M108 77h44M108 143h44" stroke="#b7c2c2" stroke-width="2.5" stroke-linecap="round" />
            <path d="M126 100l-12 10h12l-12 10" stroke="#ef8450" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
        </template>

        <template v-else-if="variant === 'maintenance'">
            <circle cx="120" cy="112" r="38" stroke="#10393b" stroke-width="2.5" fill="#10393b" fill-opacity="0.04" />
            <path
                d="M120 72V58M120 152v14M80 112H66M174 112h-14M92 84l-10-10M148 140l10 10M148 84l10-10M92 140l-10 10"
                stroke="#10393b"
                stroke-width="2.5"
                stroke-linecap="round"
            />
            <circle cx="120" cy="112" r="14" stroke="#01b990" stroke-width="2.5" />
        </template>

        <template v-else>
            <circle cx="120" cy="112" r="50" stroke="#10393b" stroke-width="2.5" fill="#10393b" fill-opacity="0.04" />
            <circle cx="120" cy="112" r="30" stroke="#b7c2c2" stroke-width="2.5" stroke-dasharray="5 9" />
            <circle cx="120" cy="112" r="7" fill="#01b990" />
        </template>
    </svg>
</template>

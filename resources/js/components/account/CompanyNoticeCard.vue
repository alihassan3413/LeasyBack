<script setup lang="ts">
/**
 * Stands in for the company cards on Mein Konto when there is nothing to show:
 * either the Firmenkunde has not registered a company yet — in which case the
 * one-time registration form is one click away — or they are a member whose
 * role does not include company.view.
 */
import { Link } from '@inertiajs/vue3';

defineProps<{ canRegister: boolean }>();
</script>

<template>
    <div class="overflow-hidden rounded-2xl border border-[#D1DCDC] bg-white shadow-sm">
        <div class="border-b border-[#EDF2F2] px-4 py-4 sm:px-8 sm:py-5">
            <h2 class="text-[16px] font-bold text-[#10393B] sm:text-[17px]">Firmendaten</h2>
            <p class="mt-0.5 text-[12px] text-[#7A9699] sm:text-[13px]">Angaben zu Ihrem Unternehmen</p>
        </div>

        <div class="px-4 py-6 sm:px-8 sm:py-8">
            <div class="flex items-start gap-3 rounded-2xl border border-[#EF8450]/25 bg-[#EF8450]/[0.07] px-4 py-3">
                <IconMdiOfficeBuildingOutline class="mt-0.5 size-5 shrink-0 text-[#EF8450]" />
                <div>
                    <template v-if="canRegister">
                        <p class="text-[13px] font-bold text-[#10393B]">Noch keine Firmendaten hinterlegt</p>
                        <p class="mt-0.5 text-[12px] text-[#7A9699]">
                            Registrieren Sie Ihr Unternehmen, damit Anschrift und Ansprechpartner hier erscheinen.
                        </p>
                    </template>
                    <template v-else>
                        <p class="text-[13px] font-bold text-[#10393B]">Keine Berechtigung für die Firmendaten</p>
                        <p class="mt-0.5 text-[12px] text-[#7A9699]">Wenden Sie sich an einen Inhaber Ihres Unternehmens.</p>
                    </template>
                </div>
            </div>

            <Link
                v-if="canRegister"
                :href="route('onboarding.b2b.show')"
                class="bg-brand-green mt-5 inline-flex h-[38px] items-center rounded-lg px-6 text-sm font-semibold text-white transition-all hover:bg-[#019d7a]"
            >
                Firmendaten hinterlegen
            </Link>
        </div>
    </div>
</template>

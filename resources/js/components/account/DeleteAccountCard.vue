<template>
    <div class="overflow-hidden rounded-2xl border border-[#F0D9D9] border-l-4 border-l-[#B4544F] bg-white shadow-sm">
        <div class="flex flex-col gap-4 px-4 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-8 sm:py-6">
            <div class="flex items-start gap-4">
                <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-[#FBF2F2] text-[#B4544F] shadow-sm sm:size-12">
                    <IconMdiTrashCanOutline class="size-4 sm:size-5" />
                </div>
                <div>
                    <h2 class="text-[16px] font-bold text-[#10393B] sm:text-[17px]">Konto löschen</h2>
                    <p class="mt-0.5 text-[12px] leading-relaxed text-[#7A9699] sm:text-[13px]">
                        Wenn Sie Ihr Konto dauerhaft löschen möchten, kontaktieren Sie uns bitte per E-Mail. Wir kümmern uns um den Rest.
                    </p>
                </div>
            </div>

            <a
                href="mailto:office@leasyback.com"
                class="flex w-full shrink-0 items-center justify-center gap-2 self-start rounded-lg border border-[#E8C9C9] px-4 py-2.5 text-sm font-semibold text-[#B4544F] transition-all hover:border-[#B4544F] hover:bg-[#FBF2F2] sm:w-auto sm:self-auto"
            >
                <IconMdiEmailOutline class="size-4" />
                office@leasyback.com
            </a>
        </div>
    </div>
</template>

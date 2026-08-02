<script setup lang="ts">
import AddressMapPicker from '@/components/form/AddressMapPicker.vue';
import type { StationData } from '@/types/order';
import { computed } from 'vue';

const props = defineProps<{ station: StationData | null }>();

const address = computed(() => {
    if (!props.station) {
        return '';
    }

    return `${props.station.strasse}, ${props.station.plz} ${props.station.ort}, Deutschland`;
});
</script>

<template>
    <div class="size-full overflow-hidden rounded-2xl border border-gray-300">
        <AddressMapPicker v-if="station" :latitude="null" :longitude="null" :address="address" :interactive="false" />
        <div v-else class="flex size-full items-center justify-center bg-[#F1F5F5] px-4 text-center text-xs text-[#7A9699]">
            Wählen Sie eine Prüfstation, um sie auf der Karte zu sehen.
        </div>
    </div>
</template>

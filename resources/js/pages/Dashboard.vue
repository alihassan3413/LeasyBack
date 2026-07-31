<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AddVehicleModal from '@/components/vehicle/AddVehicleModal.vue';
import UploadDocumentModal from '@/components/vehicle/UploadDocumentModal.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { getVehicleStatusDisplay, isVehicleCompleted } from '@/lib/vehicleStatus';
import { type BreadcrumbItem } from '@/types';
import type { VehicleData } from '@/types/vehicle';
import { Head } from '@inertiajs/vue3';
import { FileUp, Pencil, Plus } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{ vehicles: VehicleData[] }>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

function latestOrderStatus(vehicle: VehicleData): string | undefined {
    return vehicle.orders[0]?.order_status;
}

const activeVehicles = computed(() => props.vehicles.filter((vehicle) => !isVehicleCompleted(latestOrderStatus(vehicle))));
const completedVehicles = computed(() => props.vehicles.filter((vehicle) => isVehicleCompleted(latestOrderStatus(vehicle))));

const addVehicleOpen = ref(false);
const editingVehicle = ref<VehicleData | null>(null);

function openCreateVehicle() {
    editingVehicle.value = null;
    addVehicleOpen.value = true;
}

function openEditVehicle(vehicle: VehicleData) {
    editingVehicle.value = vehicle;
    addVehicleOpen.value = true;
}

const uploadModalOpen = ref(false);
const uploadVehicleId = ref<string | null>(null);
const uploadDocuments = computed(() => props.vehicles.find((vehicle) => vehicle.vehicle_id === uploadVehicleId.value)?.documents ?? []);

function openUploadDocument(vehicle: VehicleData) {
    uploadVehicleId.value = vehicle.vehicle_id;
    uploadModalOpen.value = true;
}

function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }
    return new Date(value).toLocaleDateString('de-DE');
}
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-semibold">Meine Fahrzeuge</h1>
                    <p class="text-muted-foreground text-sm">Verwalten Sie Ihre Fahrzeuge und deren Dokumente.</p>
                </div>
                <Button @click="openCreateVehicle">
                    <Plus class="size-4" aria-hidden="true" />
                    Neues Fahrzeug anlegen
                </Button>
            </div>

            <div class="rounded-xl border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Kennzeichen</TableHead>
                            <TableHead>Marke · Modell</TableHead>
                            <TableHead>Leasingende</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead class="text-right">Optionen</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableEmpty v-if="activeVehicles.length === 0" :colspan="5">
                            Sie haben noch keine Fahrzeuge angelegt.
                        </TableEmpty>
                        <TableRow v-for="vehicle in activeVehicles" :key="vehicle.vehicle_id">
                            <TableCell class="font-medium">{{ vehicle.license_plate }}</TableCell>
                            <TableCell>{{ [vehicle.make, vehicle.model].filter(Boolean).join(' · ') || '—' }}</TableCell>
                            <TableCell>{{ formatDate(vehicle.leasing_end_date) }}</TableCell>
                            <TableCell>
                                <Badge :variant="getVehicleStatusDisplay(latestOrderStatus(vehicle)).variant">
                                    {{ getVehicleStatusDisplay(latestOrderStatus(vehicle)).label }}
                                </Badge>
                            </TableCell>
                            <TableCell class="text-right">
                                <div class="flex justify-end gap-2">
                                    <Button variant="ghost" size="icon" aria-label="Dokument hochladen" @click="openUploadDocument(vehicle)">
                                        <FileUp class="size-4" aria-hidden="true" />
                                    </Button>
                                    <Button variant="ghost" size="icon" aria-label="Fahrzeug bearbeiten" @click="openEditVehicle(vehicle)">
                                        <Pencil class="size-4" aria-hidden="true" />
                                    </Button>
                                </div>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>

            <div v-if="completedVehicles.length > 0" class="rounded-xl border">
                <h2 class="border-b px-4 py-3 text-sm font-medium">Abgeschlossene Vorgänge</h2>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Kennzeichen</TableHead>
                            <TableHead>Marke · Modell</TableHead>
                            <TableHead>Leasingende</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead class="text-right">Optionen</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="vehicle in completedVehicles" :key="vehicle.vehicle_id">
                            <TableCell class="font-medium">{{ vehicle.license_plate }}</TableCell>
                            <TableCell>{{ [vehicle.make, vehicle.model].filter(Boolean).join(' · ') || '—' }}</TableCell>
                            <TableCell>{{ formatDate(vehicle.leasing_end_date) }}</TableCell>
                            <TableCell>
                                <Badge :variant="getVehicleStatusDisplay(latestOrderStatus(vehicle)).variant">
                                    {{ getVehicleStatusDisplay(latestOrderStatus(vehicle)).label }}
                                </Badge>
                            </TableCell>
                            <TableCell class="text-right">
                                <Button variant="ghost" size="icon" aria-label="Dokument hochladen" @click="openUploadDocument(vehicle)">
                                    <FileUp class="size-4" aria-hidden="true" />
                                </Button>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
        </div>

        <AddVehicleModal v-model:open="addVehicleOpen" :vehicle="editingVehicle" />
        <UploadDocumentModal
            v-if="uploadVehicleId"
            v-model:open="uploadModalOpen"
            :vehicle-id="uploadVehicleId"
            :documents="uploadDocuments"
        />
    </AppLayout>
</template>

<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AddVehicleModal from '@/components/vehicle/AddVehicleModal.vue';
import OffersCard from '@/components/vehicle/OffersCard.vue';
import OrderCreationModal from '@/components/vehicle/OrderCreationModal.vue';
import OrderStatusTimeline from '@/components/vehicle/OrderStatusTimeline.vue';
import UploadDocumentModal from '@/components/vehicle/UploadDocumentModal.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { getVehicleStatusDisplay, isVehicleCompleted } from '@/lib/vehicleStatus';
import { type BreadcrumbItem } from '@/types';
import type { StationData } from '@/types/order';
import type { VehicleData } from '@/types/vehicle';
import { Head } from '@inertiajs/vue3';
import { ChevronDown, ChevronRight, FileUp, Pencil, Plus } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{ vehicles: VehicleData[]; stations: StationData[] }>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

function latestOrderStatus(vehicle: VehicleData): string | undefined {
    return vehicle.orders[0]?.order_status;
}

/** Mirrors VehicleService::hasUnfinishedOrder()'s terminal-status set. */
function hasUnfinishedOrder(vehicle: VehicleData): boolean {
    const status = latestOrderStatus(vehicle);
    return !!status && !['delivered', 'cancelled', 'discarded'].includes(status);
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

const orderModalOpen = ref(false);
const orderVehicleId = ref<string | null>(null);

function openCreateOrder(vehicle: VehicleData) {
    orderVehicleId.value = vehicle.vehicle_id;
    orderModalOpen.value = true;
}

const expandedVehicleId = ref<string | null>(null);

function toggleExpanded(vehicle: VehicleData) {
    expandedVehicleId.value = expandedVehicleId.value === vehicle.vehicle_id ? null : vehicle.vehicle_id;
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
                            <TableHead class="w-10" />
                            <TableHead>Kennzeichen</TableHead>
                            <TableHead>Marke · Modell</TableHead>
                            <TableHead>Leasingende</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead class="text-right">Optionen</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableEmpty v-if="activeVehicles.length === 0" :colspan="6"> Sie haben noch keine Fahrzeuge angelegt. </TableEmpty>
                        <template v-for="vehicle in activeVehicles" :key="vehicle.vehicle_id">
                            <TableRow class="cursor-pointer" @click="toggleExpanded(vehicle)">
                                <TableCell>
                                    <ChevronDown
                                        v-if="expandedVehicleId === vehicle.vehicle_id"
                                        class="text-muted-foreground size-4"
                                        aria-hidden="true"
                                    />
                                    <ChevronRight v-else class="text-muted-foreground size-4" aria-hidden="true" />
                                </TableCell>
                                <TableCell class="font-medium">{{ vehicle.license_plate }}</TableCell>
                                <TableCell>{{ [vehicle.make, vehicle.model].filter(Boolean).join(' · ') || '—' }}</TableCell>
                                <TableCell>{{ formatDate(vehicle.leasing_end_date) }}</TableCell>
                                <TableCell>
                                    <Badge :variant="getVehicleStatusDisplay(latestOrderStatus(vehicle)).variant">
                                        {{ getVehicleStatusDisplay(latestOrderStatus(vehicle)).label }}
                                    </Badge>
                                </TableCell>
                                <TableCell class="text-right">
                                    <div class="flex justify-end gap-2" @click.stop>
                                        <Button variant="ghost" size="icon" aria-label="Dokument hochladen" @click="openUploadDocument(vehicle)">
                                            <FileUp class="size-4" aria-hidden="true" />
                                        </Button>
                                        <Button variant="ghost" size="icon" aria-label="Fahrzeug bearbeiten" @click="openEditVehicle(vehicle)">
                                            <Pencil class="size-4" aria-hidden="true" />
                                        </Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                            <TableRow v-if="expandedVehicleId === vehicle.vehicle_id">
                                <TableCell :colspan="6" class="bg-muted/30">
                                    <div class="space-y-4 py-2">
                                        <div v-if="!hasUnfinishedOrder(vehicle)" class="flex justify-end">
                                            <Button size="sm" @click="openCreateOrder(vehicle)">Vorgang starten</Button>
                                        </div>
                                        <template v-if="vehicle.orders[0]">
                                            <OrderStatusTimeline :status="vehicle.orders[0].order_status" />
                                            <OffersCard :offers="vehicle.orders[0].offers" />
                                        </template>
                                        <p v-else class="text-muted-foreground text-sm">Noch kein Vorgang gestartet.</p>
                                    </div>
                                </TableCell>
                            </TableRow>
                        </template>
                    </TableBody>
                </Table>
            </div>

            <div v-if="completedVehicles.length > 0" class="rounded-xl border">
                <h2 class="border-b px-4 py-3 text-sm font-medium">Abgeschlossene Vorgänge</h2>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead class="w-10" />
                            <TableHead>Kennzeichen</TableHead>
                            <TableHead>Marke · Modell</TableHead>
                            <TableHead>Leasingende</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead class="text-right">Optionen</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <template v-for="vehicle in completedVehicles" :key="vehicle.vehicle_id">
                            <TableRow class="cursor-pointer" @click="toggleExpanded(vehicle)">
                                <TableCell>
                                    <ChevronDown
                                        v-if="expandedVehicleId === vehicle.vehicle_id"
                                        class="text-muted-foreground size-4"
                                        aria-hidden="true"
                                    />
                                    <ChevronRight v-else class="text-muted-foreground size-4" aria-hidden="true" />
                                </TableCell>
                                <TableCell class="font-medium">{{ vehicle.license_plate }}</TableCell>
                                <TableCell>{{ [vehicle.make, vehicle.model].filter(Boolean).join(' · ') || '—' }}</TableCell>
                                <TableCell>{{ formatDate(vehicle.leasing_end_date) }}</TableCell>
                                <TableCell>
                                    <Badge :variant="getVehicleStatusDisplay(latestOrderStatus(vehicle)).variant">
                                        {{ getVehicleStatusDisplay(latestOrderStatus(vehicle)).label }}
                                    </Badge>
                                </TableCell>
                                <TableCell class="text-right">
                                    <div @click.stop>
                                        <Button variant="ghost" size="icon" aria-label="Dokument hochladen" @click="openUploadDocument(vehicle)">
                                            <FileUp class="size-4" aria-hidden="true" />
                                        </Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                            <TableRow v-if="expandedVehicleId === vehicle.vehicle_id">
                                <TableCell :colspan="6" class="bg-muted/30">
                                    <div class="space-y-4 py-2">
                                        <div v-if="!hasUnfinishedOrder(vehicle)" class="flex justify-end">
                                            <Button size="sm" @click="openCreateOrder(vehicle)">Vorgang starten</Button>
                                        </div>
                                        <template v-if="vehicle.orders[0]">
                                            <OrderStatusTimeline :status="vehicle.orders[0].order_status" />
                                            <OffersCard :offers="vehicle.orders[0].offers" />
                                        </template>
                                    </div>
                                </TableCell>
                            </TableRow>
                        </template>
                    </TableBody>
                </Table>
            </div>
        </div>

        <AddVehicleModal v-model:open="addVehicleOpen" :vehicle="editingVehicle" />
        <UploadDocumentModal v-if="uploadVehicleId" v-model:open="uploadModalOpen" :vehicle-id="uploadVehicleId" :documents="uploadDocuments" />
        <OrderCreationModal v-if="orderVehicleId" v-model:open="orderModalOpen" :vehicle-id="orderVehicleId" :stations="stations" />
    </AppLayout>
</template>

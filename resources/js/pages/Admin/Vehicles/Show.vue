<script setup lang="ts">
import UploadReportDocumentModal from '@/components/admin/UploadReportDocumentModal.vue';
import type { SelectFieldOption } from '@/components/form/SelectField.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { getVehicleStatusDisplay } from '@/lib/vehicleStatus';
import type { AdminVehicleRow } from '@/types/admin';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{ vehicle: AdminVehicleRow }>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Fahrzeuge', href: route('admin.vehicles.index') },
    { title: props.vehicle.license_plate, href: route('admin.vehicles.show', props.vehicle.vehicle_id) },
]);

const ownerRoute = computed(() => {
    if (props.vehicle.vehicle_belongs === 'B2C' && props.vehicle.user_id) {
        return route('admin.customers.show', { type: 'b2c', id: props.vehicle.user_id });
    }
    if (props.vehicle.vehicle_belongs === 'B2B' && props.vehicle.b2b_id) {
        return route('admin.customers.show', { type: 'b2b', id: props.vehicle.b2b_id });
    }
    return null;
});

const ownerLabel = computed(() => (props.vehicle.vehicle_belongs === 'B2B' ? props.vehicle.company_name : props.vehicle.user_email) ?? '—');

function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }
    return new Date(value).toLocaleDateString('de-DE');
}

const auftragsnummerOptions = computed<SelectFieldOption[]>(() =>
    props.vehicle.order_history.map((order) => ({ value: order.auftragsnummer, label: order.auftragsnummer })),
);

const reportDocuments = computed(() =>
    props.vehicle.order_history.flatMap((order) => order.report_documents.map((doc) => ({ ...doc, auftragsnummer: order.auftragsnummer }))),
);

const uploadModalOpen = ref(false);
const publishingId = ref<string | null>(null);
const confirmingDeleteId = ref<string | null>(null);

function togglePublished(documentId: string, published: boolean) {
    publishingId.value = documentId;
    router.patch(
        route('admin.vehicles.reports.publish', documentId),
        { published: !published },
        { preserveScroll: true, onFinish: () => (publishingId.value = null) },
    );
}

function deleteDocument(documentId: string) {
    router.delete(route('admin.vehicles.reports.delete', documentId), {
        preserveScroll: true,
        onFinish: () => (confirmingDeleteId.value = null),
    });
}
</script>

<template>
    <Head :title="vehicle.license_plate" />

    <AdminLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <Card>
                <CardContent class="flex flex-wrap items-center justify-between gap-4 pt-6">
                    <div>
                        <div class="flex items-center gap-2">
                            <h1 class="text-lg font-semibold">{{ vehicle.license_plate }}</h1>
                            <Badge :variant="getVehicleStatusDisplay(vehicle.current_order_status ?? undefined).variant">
                                {{ getVehicleStatusDisplay(vehicle.current_order_status ?? undefined).label }}
                            </Badge>
                        </div>
                        <p class="text-muted-foreground text-sm">{{ [vehicle.make, vehicle.model].filter(Boolean).join(' · ') || '—' }}</p>
                    </div>
                    <Link v-if="ownerRoute" :href="ownerRoute" class="text-sm hover:underline"> Halter: {{ ownerLabel }} </Link>
                    <span v-else class="text-muted-foreground text-sm">Halter: {{ ownerLabel }}</span>
                </CardContent>
            </Card>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <Card>
                    <CardHeader>
                        <CardTitle class="text-sm">Fahrzeugdaten</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-1 text-sm">
                        <p>VIN: {{ vehicle.vin ?? '—' }}</p>
                        <p>Erstzulassung: {{ formatDate(vehicle.first_registration_date) }}</p>
                        <p>Leasingende: {{ formatDate(vehicle.leasing_end_date) }}</p>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle class="text-sm">Leasing</CardTitle>
                    </CardHeader>
                    <CardContent class="text-sm">
                        <p>Leasinggeber: {{ vehicle.leasinggeber ?? '—' }}</p>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle class="text-sm">Meta</CardTitle>
                    </CardHeader>
                    <CardContent class="text-sm">
                        <p>Angelegt: {{ formatDate(vehicle.created_at) }}</p>
                    </CardContent>
                </Card>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle class="text-sm">Auftragsverlauf</CardTitle>
                </CardHeader>
                <CardContent>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Auftragsnummer</TableHead>
                                <TableHead>Partner</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Erstellt</TableHead>
                                <TableHead>Bestätigt</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableEmpty v-if="vehicle.order_history.length === 0" :colspan="5"> Kein Auftragsverlauf. </TableEmpty>
                            <TableRow v-for="order in vehicle.order_history" :key="order.id">
                                <TableCell class="font-medium">{{ order.auftragsnummer }}</TableCell>
                                <TableCell>{{ order.leasyback_partner }}</TableCell>
                                <TableCell>
                                    <Badge :variant="getVehicleStatusDisplay(order.order_status).variant">
                                        {{ getVehicleStatusDisplay(order.order_status).label }}
                                    </Badge>
                                </TableCell>
                                <TableCell>{{ formatDate(order.created_at) }}</TableCell>
                                <TableCell>{{ formatDate(order.confirmation_date) }}</TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>

            <Card>
                <CardHeader class="flex-row items-start justify-between gap-4 space-y-0">
                    <div>
                        <CardTitle class="text-sm">Report-Dokumente</CardTitle>
                        <CardDescription>Gutachten und Rechnungen je Auftrag.</CardDescription>
                    </div>
                    <Button size="sm" variant="outline" :disabled="vehicle.order_history.length === 0" @click="uploadModalOpen = true">
                        Hochladen
                    </Button>
                </CardHeader>
                <CardContent class="space-y-2">
                    <p v-if="reportDocuments.length === 0" class="text-muted-foreground text-sm">Keine Report-Dokumente.</p>
                    <div
                        v-for="doc in reportDocuments"
                        :key="doc.id"
                        class="flex items-center justify-between gap-4 rounded-lg border p-3 text-sm"
                    >
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="font-medium">{{ doc.document_title || doc.document_type || 'Dokument' }}</span>
                                <Badge variant="outline">{{ doc.auftragsnummer }}</Badge>
                                <Badge :variant="doc.published ? 'success' : 'outline'">{{ doc.published ? 'Veröffentlicht' : 'Entwurf' }}</Badge>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <Button
                                variant="ghost"
                                size="sm"
                                :loading="publishingId === doc.id"
                                @click="togglePublished(doc.id, doc.published)"
                            >
                                {{ doc.published ? 'Zurückziehen' : 'Veröffentlichen' }}
                            </Button>
                            <template v-if="confirmingDeleteId === doc.id">
                                <Button variant="ghost" size="sm" @click="confirmingDeleteId = null">Abbrechen</Button>
                                <Button variant="destructive" size="sm" @click="deleteDocument(doc.id)">Bestätigen</Button>
                            </template>
                            <Button v-else variant="destructive" size="sm" @click="confirmingDeleteId = doc.id"> Löschen </Button>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="text-sm">Kundendokumente</CardTitle>
                    <CardDescription>Vom Kunden hochgeladene Dokumente (nur Ansicht).</CardDescription>
                </CardHeader>
                <CardContent>
                    <p v-if="vehicle.documents.length === 0" class="text-muted-foreground text-sm">Keine Dokumente.</p>
                    <ul v-else class="space-y-1 text-sm">
                        <li v-for="doc in vehicle.documents" :key="doc.document_id">
                            {{ doc.document_type }} — {{ doc.original_file_name }} ({{ formatDate(doc.created_at) }})
                        </li>
                    </ul>
                </CardContent>
            </Card>
        </div>

        <UploadReportDocumentModal
            v-model:open="uploadModalOpen"
            :vehicle-id="vehicle.vehicle_id"
            :auftragsnummer-options="auftragsnummerOptions"
        />
    </AdminLayout>
</template>

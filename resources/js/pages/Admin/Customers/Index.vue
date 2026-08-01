<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { AdminCustomerList, AdminCustomerType } from '@/types/admin';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    type: AdminCustomerType;
    customers: AdminCustomerList;
    filters: { search: string; is_active: string | null };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Kunden', href: route('admin.customers.index') }];

const search = ref(props.filters.search);
const statusFilter = ref<'all' | 'active' | 'inactive'>(
    props.filters.is_active === 'true' ? 'active' : props.filters.is_active === 'false' ? 'inactive' : 'all',
);

function reload(params: Record<string, string>) {
    router.get(route('admin.customers.index'), params, { preserveState: true, preserveScroll: true, replace: true });
}

function currentParams(overrides: Record<string, string> = {}): Record<string, string> {
    const params: Record<string, string> = { type: props.type };
    if (search.value) {
        params.search = search.value;
    }
    if (statusFilter.value === 'active') {
        params.is_active = 'true';
    } else if (statusFilter.value === 'inactive') {
        params.is_active = 'false';
    }
    return { ...params, ...overrides };
}

function switchTab(type: AdminCustomerType) {
    router.get(route('admin.customers.index'), { type }, { preserveScroll: true });
}

let searchDebounce: ReturnType<typeof setTimeout> | undefined;
watch(search, () => {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => reload(currentParams()), 300);
});

function setStatusFilter(value: 'all' | 'active' | 'inactive') {
    statusFilter.value = value;
    reload(currentParams());
}

function goToPage(page: number) {
    reload(currentParams({ page: String(page) }));
}

const totalPages = computed(() => Math.max(1, Math.ceil(props.customers.total / props.customers.limit)));

function displayName(customer: AdminCustomerList['data'][number]): string {
    if (props.type === 'b2b' && customer.company_name) {
        return customer.company_name;
    }
    return [customer.salutation, customer.first_name, customer.last_name].filter(Boolean).join(' ') || customer.user_email;
}

function formatDate(value: string): string {
    return new Date(value).toLocaleDateString('de-DE');
}
</script>

<template>
    <Head title="Kunden" />

    <AdminLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-xl font-semibold">Kunden</h1>
                    <p class="text-muted-foreground text-sm">B2C- und B2B-Kunden verwalten.</p>
                </div>
                <div class="flex items-center gap-2">
                    <Badge variant="success">{{ customers.total_active }} Aktiv</Badge>
                    <Badge variant="outline">{{ customers.total_inactive }} Inaktiv</Badge>
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="inline-flex rounded-lg border p-1">
                    <Button :variant="type === 'b2c' ? 'default' : 'ghost'" size="sm" @click="switchTab('b2c')"> Privatkunden </Button>
                    <Button :variant="type === 'b2b' ? 'default' : 'ghost'" size="sm" @click="switchTab('b2b')"> Firmenkunden </Button>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <div class="inline-flex rounded-lg border p-1">
                        <Button :variant="statusFilter === 'all' ? 'default' : 'ghost'" size="sm" @click="setStatusFilter('all')">Alle</Button>
                        <Button :variant="statusFilter === 'active' ? 'default' : 'ghost'" size="sm" @click="setStatusFilter('active')">
                            Aktiv
                        </Button>
                        <Button :variant="statusFilter === 'inactive' ? 'default' : 'ghost'" size="sm" @click="setStatusFilter('inactive')">
                            Inaktiv
                        </Button>
                    </div>
                    <Input v-model="search" placeholder="Suchen…" class="w-56" />
                </div>
            </div>

            <div class="rounded-xl border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Name</TableHead>
                            <TableHead>E-Mail</TableHead>
                            <TableHead>Stadt</TableHead>
                            <TableHead>Land</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead>Beigetreten</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableEmpty v-if="customers.data.length === 0" :colspan="6"> Keine Kunden gefunden. </TableEmpty>
                        <TableRow
                            v-for="customer in customers.data"
                            :key="`${customer.user_id}-${customer.b2b_id ?? ''}`"
                            class="cursor-pointer"
                        >
                            <TableCell class="font-medium">
                                <Link :href="route('admin.customers.show', { type, id: type === 'b2b' ? customer.b2b_id : customer.user_id })">
                                    {{ displayName(customer) }}
                                </Link>
                            </TableCell>
                            <TableCell>{{ customer.user_email }}</TableCell>
                            <TableCell>{{ customer.city ?? '—' }}</TableCell>
                            <TableCell>{{ customer.country ?? '—' }}</TableCell>
                            <TableCell>
                                <Badge :variant="customer.is_active ? 'success' : 'outline'">
                                    {{ customer.is_active ? 'Aktiv' : 'Inaktiv' }}
                                </Badge>
                            </TableCell>
                            <TableCell>{{ formatDate(customer.created_at) }}</TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>

            <div v-if="totalPages > 1" class="flex items-center justify-between">
                <p class="text-muted-foreground text-sm">Seite {{ customers.page }} von {{ totalPages }}</p>
                <div class="flex gap-2">
                    <Button variant="outline" size="sm" :disabled="customers.page <= 1" @click="goToPage(customers.page - 1)"> Zurück </Button>
                    <Button variant="outline" size="sm" :disabled="customers.page >= totalPages" @click="goToPage(customers.page + 1)">
                        Weiter
                    </Button>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>

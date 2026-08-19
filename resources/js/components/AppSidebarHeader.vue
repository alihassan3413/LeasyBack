<script setup lang="ts">
import NotificationBell from '@/components/NotificationBell.vue';
import { Breadcrumb, BreadcrumbItem, BreadcrumbLink, BreadcrumbList, BreadcrumbPage, BreadcrumbSeparator } from '@/components/ui/breadcrumb';
import type { BreadcrumbItemType } from '@/types';

defineProps<{
    breadcrumbs?: BreadcrumbItemType[];
}>();
</script>

<template>
    <!--
        `min-h-16` rather than a hard `h-16`: page headers put a title *and* a
        search field in the default slot, which wraps to a second row on phones.
        A fixed height clipped that second row.
    -->
    <header
        class="flex min-h-16 shrink-0 flex-wrap items-center gap-x-3 gap-y-2 border-b border-[#e6eded] px-4 py-2.5 md:flex-nowrap md:px-6 md:py-0"
    >
        <slot name="leading" />

        <div class="flex min-w-0 flex-1 flex-wrap items-center gap-x-3 gap-y-2">
            <slot>
                <Breadcrumb v-if="breadcrumbs && breadcrumbs.length > 0">
                    <BreadcrumbList>
                        <template v-for="(item, index) in breadcrumbs" :key="index">
                            <BreadcrumbItem>
                                <template v-if="index === breadcrumbs.length - 1">
                                    <BreadcrumbPage>{{ item.title }}</BreadcrumbPage>
                                </template>
                                <template v-else>
                                    <BreadcrumbLink :href="item.href">
                                        {{ item.title }}
                                    </BreadcrumbLink>
                                </template>
                            </BreadcrumbItem>
                            <BreadcrumbSeparator v-if="index !== breadcrumbs.length - 1" />
                        </template>
                    </BreadcrumbList>
                </Breadcrumb>
            </slot>
        </div>

        <NotificationBell />
    </header>
</template>

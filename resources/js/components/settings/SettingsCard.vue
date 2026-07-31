<script setup lang="ts">
/**
 * Reusable "read or edit" settings card: shows a static summary by default
 * with a single header action (Edit/Create), and swaps to a form with a
 * Cancel/Save footer while editing. Used by every self-service Profile
 * section (Address & contact, Preferences) so each page only supplies its
 * own field markup via the #read/#edit slots.
 */
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Pencil, Plus } from 'lucide-vue-next';

withDefaults(
    defineProps<{
        title: string;
        description?: string;
        editing: boolean;
        creating?: boolean;
        processing?: boolean;
        editLabel?: string;
        createLabel?: string;
        saveLabel?: string;
        cancelLabel?: string;
    }>(),
    {
        creating: false,
        processing: false,
        editLabel: 'Bearbeiten',
        createLabel: 'Erstellen',
        saveLabel: 'Speichern',
        cancelLabel: 'Abbrechen',
    },
);

const emit = defineEmits<{ (e: 'edit'): void; (e: 'cancel'): void }>();
</script>

<template>
    <Card>
        <CardHeader class="flex-row items-start justify-between gap-4 space-y-0">
            <div class="space-y-1.5">
                <CardTitle class="text-base">{{ title }}</CardTitle>
                <CardDescription v-if="description">{{ description }}</CardDescription>
            </div>
            <Button v-if="!editing" type="button" variant="outline" size="sm" @click="emit('edit')">
                <Plus v-if="creating" class="size-4" aria-hidden="true" />
                <Pencil v-else class="size-4" aria-hidden="true" />
                {{ creating ? createLabel : editLabel }}
            </Button>
        </CardHeader>

        <CardContent>
            <slot v-if="editing" name="edit" />
            <slot v-else name="read" />
        </CardContent>

        <CardFooter v-if="editing" class="bg-muted/30 justify-end gap-3 border-t">
            <Button type="button" variant="ghost" :disabled="processing" @click="emit('cancel')">
                {{ cancelLabel }}
            </Button>
            <Button type="submit" :loading="processing">
                {{ saveLabel }}
            </Button>
        </CardFooter>
    </Card>
</template>

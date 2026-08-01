<script setup lang="ts">
/**
 * Presentational dot+line timeline for an order's progress, driven by
 * lib/orderFlow.ts's simplified linear stage list. Cancelled/discarded
 * orders are terminal off-ramps, shown as a single badge instead of a
 * partially-filled timeline (there's no meaningful "how far did it get"
 * position to highlight for those).
 */
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { getOffRampLabel, getOrderFlowSteps, isTerminalOffRamp } from '@/lib/orderFlow';
import { computed } from 'vue';

const props = defineProps<{ status: string }>();

const offRampLabel = computed(() => (isTerminalOffRamp(props.status) ? getOffRampLabel(props.status) : null));
const steps = computed(() => getOrderFlowSteps(props.status));
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle class="text-base">Verlauf</CardTitle>
        </CardHeader>
        <CardContent>
            <Badge v-if="offRampLabel" variant="outline">{{ offRampLabel }}</Badge>

            <ol v-else class="flex flex-wrap items-center gap-y-4">
                <li v-for="(step, index) in steps" :key="step.key" class="flex items-center">
                    <div class="flex items-center gap-2">
                        <span
                            class="flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-medium"
                            :class="step.state === 'upcoming' ? 'bg-muted text-muted-foreground' : 'bg-primary text-primary-foreground'"
                        >
                            {{ index + 1 }}
                        </span>
                        <span class="text-sm" :class="step.state === 'upcoming' ? 'text-muted-foreground' : 'font-medium'">
                            {{ step.label }}
                        </span>
                    </div>
                    <div v-if="index < steps.length - 1" class="bg-border mx-3 h-px w-6 shrink-0" aria-hidden="true" />
                </li>
            </ol>
        </CardContent>
    </Card>
</template>

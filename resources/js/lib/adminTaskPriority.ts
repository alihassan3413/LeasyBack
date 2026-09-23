import type { AdminOrderTaskPriority } from '@/types/admin';

/**
 * How each TaskPriority looks, wherever Admin shows one — copied verbatim
 * from Admin/Dashboard.vue's task list (`TASK_PRIORITY_STYLE`), not
 * reinvented, so the order list and the dashboard's task list can never
 * disagree about what "urgent" looks like.
 *
 * Admin/Dashboard.vue keeps its own copy of this map for now — pulling it in
 * from here is a follow-up, out of scope for this change (see the order
 * list's own doc comment).
 */
export const TASK_PRIORITY_STYLE: Record<AdminOrderTaskPriority, { label: string; accent: string; badge: string }> = {
    immediate_red: { label: 'Sofort', accent: 'bg-[#E5533D]', badge: 'bg-[#E5533D] text-white' },
    red: { label: 'Überfällig', accent: 'bg-[#E5533D]/70', badge: 'bg-[#E5533D]/10 text-[#c0392b]' },
    yellow: { label: 'Bald fällig', accent: 'bg-[#EF8450]', badge: 'bg-[#EF8450]/12 text-[#c0622e]' },
    green: { label: 'Im Zeitplan', accent: 'bg-[#01B990]', badge: 'bg-[#01B990]/10 text-[#00856a]' },
    neutral: { label: 'Offen', accent: 'bg-[#dbe4e3]', badge: 'bg-[#f4f7f6] text-[#6f8585]' },
};

/** Null for a closed order — there is no task left to rank, so there is nothing to style. */
export function taskPriorityStyle(priority: AdminOrderTaskPriority | null): { label: string; accent: string; badge: string } | null {
    return priority ? TASK_PRIORITY_STYLE[priority] : null;
}

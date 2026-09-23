export function formatCollectionTimeSlot(value: string | null): string {
    const slots: Record<string, string> = {
        '08:00-10:00': '08:00 AM - 10:00 AM',
        '10:00-12:00': '10:00 AM - 12:00 PM',
        '12:00-14:00': '12:00 PM - 02:00 PM',
        '14:00-16:00': '02:00 PM - 04:00 PM',
        '16:00-18:00': '04:00 PM - 06:00 PM',
    };

    return slots[value ?? ''] ?? '—';
}
/**
 * When an order was placed (became `order_placed`): `sent_at`, written by the
 * TÜV SÜD booking, a direct B2C booking and — since it was fixed — a B2B
 * approval. B2B orders approved before that carry no `sent_at`, so the recorded
 * `order_placed` status change answers instead: a real date, never a guess.
 * Null while the order is only requested.
 */
export function orderPlacedAt(
    sentAt: string | null | undefined,
    statusUpdates: { new_status: string; created_at: string | null }[] = [],
): string | null {
    return sentAt ?? statusUpdates.find((update) => update.new_status === 'order_placed')?.created_at ?? null;
}

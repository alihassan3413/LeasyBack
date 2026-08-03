/** Matches OrderMessageResource — one message in an order's thread. */
export interface OrderMessage {
    id: string;
    order_id: string;
    sender_id: number | null;
    sender_name: string;
    sender_is_admin: boolean;
    body: string;
    created_at: string | null;
}

/** Matches OrderMessageService::paginate()'s envelope. */
export interface OrderMessagePage {
    data: OrderMessage[];
    unread_count: number;
    next_page: number | null;
}

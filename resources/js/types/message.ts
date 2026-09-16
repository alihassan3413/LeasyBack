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

/** Matches OrderMessageController::index() — OrderMessageService::paginate()'s envelope plus the send permission. */
export interface OrderMessagePage {
    data: OrderMessage[];
    unread_count: number;
    next_page: number | null;
    /** OrderPolicy::sendMessage for the reader — false for a read-only company member. */
    can_send: boolean;
}

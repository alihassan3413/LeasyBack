function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/** Carries the parsed body so callers can surface a server-supplied message. */
export class HttpError extends Error {
    constructor(
        message: string,
        public readonly status: number,
        public readonly data: unknown = null,
    ) {
        super(message);
        this.name = 'HttpError';
    }

    /** Laravel's `{error}` or the first `{errors: {field: [msg]}}` entry. */
    serverMessage(): string | null {
        const body = this.data as { error?: string; message?: string; errors?: Record<string, string[]> } | null;

        if (!body) {
            return null;
        }

        if (typeof body.error === 'string') {
            return body.error;
        }

        const firstField = body.errors ? Object.values(body.errors)[0] : undefined;

        if (Array.isArray(firstField) && typeof firstField[0] === 'string') {
            return firstField[0];
        }

        return typeof body.message === 'string' ? body.message : null;
    }
}

async function request<T>(method: string, url: string, body?: unknown): Promise<T> {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    if (!response.ok) {
        let parsed: unknown = null;

        try {
            parsed = await response.json();
        } catch {
            parsed = null;
        }

        throw new HttpError(`${method} ${url} failed with ${response.status}`, response.status, parsed);
    }

    if (response.status === 204) {
        return undefined as T;
    }

    return (await response.json()) as T;
}

function withQuery(url: string, params?: Record<string, string | number | undefined>): string {
    if (!params) {
        return url;
    }

    const search = new URLSearchParams();

    for (const [key, value] of Object.entries(params)) {
        if (value !== undefined) {
            search.set(key, String(value));
        }
    }

    const query = search.toString();

    return query ? `${url}?${query}` : url;
}

export const http = {
    get: <T>(url: string, params?: Record<string, string | number | undefined>) => request<T>('GET', withQuery(url, params)),
    post: <T>(url: string, body?: unknown) => request<T>('POST', url, body),
    delete: <T>(url: string, body?: unknown) => request<T>('DELETE', url, body),
};

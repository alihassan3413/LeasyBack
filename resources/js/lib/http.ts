function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
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
        throw new Error(`${method} ${url} failed with ${response.status}`);
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

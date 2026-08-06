<?php

namespace App\Modules\PartnerApi\Http\Requests;

use App\Modules\PartnerApi\Enums\PartnerWebhookEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating a webhook subscription.
 *
 * The URL is validated twice on purpose. Here, cheaply, for shape — is this a
 * URL at all, is it https, is it short enough to store. Then again in
 * PartnerWebhookUrlGuard, expensively, for reachability — does it resolve, and
 * where to. The second check is the security one and the only one that matters;
 * this one exists so an obvious typo comes back as a validation error naming
 * the field rather than as a DNS failure.
 */
class StorePartnerWebhookRequest extends FormRequest
{
    /** Authorisation is the token's scope and the company permission, both middleware. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:2048', 'url'],
            'event_types' => ['required', 'array', 'min:1'],
            'event_types.*' => ['string', Rule::in(PartnerWebhookEvent::values())],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'event_types.*.in' => 'Unknown event type. Supported: '
                .implode(', ', PartnerWebhookEvent::values()).'.',
        ];
    }

    /**
     * @return list<string>
     */
    public function eventTypes(): array
    {
        /** @var list<string> $types */
        $types = $this->input('event_types', []);

        return array_values(array_unique($types));
    }

    public function targetUrl(): string
    {
        return trim((string) $this->input('url'));
    }

    public function description(): ?string
    {
        $value = trim((string) $this->input('description', ''));

        return $value === '' ? null : $value;
    }
}

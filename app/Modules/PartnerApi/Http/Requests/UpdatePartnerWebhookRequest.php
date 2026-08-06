<?php

namespace App\Modules\PartnerApi\Http\Requests;

use App\Modules\PartnerApi\Enums\PartnerWebhookEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Updating a webhook subscription: url, event list, description, on/off.
 *
 * Every field is `sometimes`, so a PATCH that only flips `is_active` does not
 * have to re-send the event list — and, more importantly, cannot accidentally
 * clear it by omitting it.
 *
 * The secret is not here. Rotating it is its own endpoint because it returns a
 * value that is shown once, and folding that into a general update would mean
 * every PATCH response had to be treated as secret material.
 */
class UpdatePartnerWebhookRequest extends FormRequest
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
            'url' => ['sometimes', 'string', 'max:2048', 'url'],
            'event_types' => ['sometimes', 'array', 'min:1'],
            'event_types.*' => ['string', Rule::in(PartnerWebhookEvent::values())],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Only the keys actually present, so `update()` can tell "set this to null"
     * from "leave it alone".
     *
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        $changes = [];

        if ($this->has('url')) {
            $changes['url'] = trim((string) $this->input('url'));
        }

        if ($this->has('event_types')) {
            /** @var list<string> $types */
            $types = $this->input('event_types', []);
            $changes['event_types'] = array_values(array_unique($types));
        }

        if ($this->has('description')) {
            $value = trim((string) $this->input('description', ''));
            $changes['description'] = $value === '' ? null : $value;
        }

        if ($this->has('is_active')) {
            $changes['is_active'] = $this->boolean('is_active');
        }

        return $changes;
    }
}

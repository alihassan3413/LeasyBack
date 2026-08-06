<?php

namespace App\Modules\PartnerApi\Http\Requests;

use App\Modules\UserProfile\Order\Services\OrderCollectionService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Creating a B2B leasing-return order over the Partner API.
 *
 * OrderCollectionService::b2bOrderRules() is the whole rule set, verbatim: the
 * same collection date, address and note a company member submits from the
 * portal, and the same outright refusal of `station_id`, `termin`, `provider`
 * and `remarks`. Those four belong to the B2C inspection flow, and a partner
 * that sends one is on the wrong endpoint — which is worth telling them,
 * rather than accepting a request that will not do what they think.
 *
 * There is no order type to choose. The Partner API creates B2B collection
 * orders and nothing else; the TÜV SÜD booking flow is not exposed, so the
 * "which flow" decision has no input to take.
 */
class StorePartnerOrderRequest extends FormRequest
{
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
            ...OrderCollectionService::b2bOrderRules(),
            'external_order_id' => ['nullable', 'string', 'max:191'],
        ];
    }

    public function externalOrderId(): ?string
    {
        $value = trim((string) $this->input('external_order_id', ''));

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function orderAttributes(): array
    {
        return collect($this->validated())->except(['external_order_id'])->all();
    }
}

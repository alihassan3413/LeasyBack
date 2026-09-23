<?php

namespace App\Modules\PartnerApi\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A partner's own identifier for one of our records.
 *
 * `partner_integration_client_id` is not fillable: which partner a mapping
 * belongs to comes from the authenticated context, never from a payload.
 * Writes go through PartnerExternalReferenceRegistry, which sets it.
 */
class PartnerExternalReference extends Model
{
    use HasUuids;

    protected $table = 'partner_external_references';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'reference_type',
        'external_id',
        'internal_id',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(PartnerIntegrationClient::class, 'partner_integration_client_id', 'id');
    }
}

<?php

namespace App\Modules\UserProfile\Payment\Models;

use App\Modules\UserProfile\Order\Models\LeasybackOrder;
use App\Modules\UserProfile\Payment\Enums\LexwareInvoiceStatus;
use App\Modules\UserProfile\Vehicle\Models\VehicleReportDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class LexwareInvoice extends Model
{
    public const PURPOSE_REPAIR = 'repair';

    protected $table = 'lexware_invoices';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'order_id',
        'auftragsnummer',
        'purpose',
        'status',
        'lexware_invoice_id',
        'voucher_number',
        'resource_uri',
        'lexware_version',
        'voucher_status',
        'document_id',
        'submitted_at',
        'invoiced_at',
        'documented_at',
        'billing_email_sent_at',
        'failure_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LexwareInvoiceStatus::class,
            'lexware_version' => 'integer',
            'submitted_at' => 'datetime',
            'invoiced_at' => 'datetime',
            'documented_at' => 'datetime',
            'billing_email_sent_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(LeasybackOrder::class, 'order_id', 'id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(VehicleReportDocument::class, 'document_id', 'id');
    }

    public function hasInvoice(): bool
    {
        return $this->lexware_invoice_id !== null;
    }

    public function hasDocument(): bool
    {
        return $this->document_id !== null;
    }
}

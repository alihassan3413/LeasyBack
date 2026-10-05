<?php

namespace App\Modules\UserProfile\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A file stored with an order on the private `documents` disk.
 *
 * `kind` says whose it is: the customer's supporting files from the booking
 * form, or the admin's final documents that complete a service.
 */
class OrderAttachment extends Model
{
    public const KIND_CUSTOMER_UPLOAD = 'customer_upload';

    public const KIND_FINAL_DOCUMENT = 'final_document';

    public const DISK = 'documents';

    protected $table = 'leasyback_order_attachments';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'order_id', 'auftragsnummer', 'kind', 'original_name', 'path', 'mime_type', 'size', 'uploaded_by_user_id',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }
}
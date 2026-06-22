<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AmoExportRecord extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_EXPORTED = 'exported';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'title',
        'contact_name',
        'phone',
        'email',
        'price',
        'payload',
        'status',
        'amo_lead_id',
        'exported_at',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'price' => 'integer',
            'exported_at' => 'datetime',
        ];
    }
}


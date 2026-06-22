<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AmoCrmToken extends Model
{
    protected $fillable = [
        'account_base_domain',
        'access_token',
        'refresh_token',
        'token_type',
        'expires_at',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }
}


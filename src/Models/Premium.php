<?php

namespace LittleSkin\PremiumVerification\Models;

use Illuminate\Database\Eloquent\Model;

class Premium extends Model
{
    protected $casts = [
        'id' => 'integer',
        'uid' => 'integer',
        'pid' => 'integer',
        'uuid' => 'string'
    ];

    protected $fillable = ['uid', 'pid', 'uuid'];

    protected $table = 'premium_verification';
}

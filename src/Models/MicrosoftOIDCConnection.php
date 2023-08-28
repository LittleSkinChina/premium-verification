<?php

namespace LittleSkin\PremiumVerification\Models;

use Illuminate\Database\Eloquent\Model;

class MicrosoftOIDCConnection extends Model {

    protected $table = 'oauth_microsoftoidc_connections';

    protected $casts = [
        'id' => 'integer',
        'uid' => 'integer',
        'oid' => 'string',
    ];

    protected $fillable = [
        'uid',
        'oid',
    ];
}

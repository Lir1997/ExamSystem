<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

class AdminAccessToken extends Model
{
    protected $name = 'admin_access_tokens';

    protected $pk = 'id';

    protected $schema = [
        'id' => 'int',
        'admin_user_id' => 'int',
        'token' => 'string',
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'last_used_ip' => 'string',
        'revoked_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}

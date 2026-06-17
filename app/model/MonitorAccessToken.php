<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

class MonitorAccessToken extends Model
{
    protected $name = 'monitor_access_tokens';

    protected $pk = 'id';

    protected $schema = [
        'id' => 'int',
        'exam_id' => 'int',
        'issued_by_admin_id' => 'int',
        'token' => 'string',
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'last_used_ip' => 'string',
        'revoked_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}

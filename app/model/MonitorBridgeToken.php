<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

class MonitorBridgeToken extends Model
{
    protected $name = 'monitor_bridge_tokens';

    protected $pk = 'id';

    protected $schema = [
        'id' => 'int',
        'exam_id' => 'int',
        'admin_user_id' => 'int',
        'token' => 'string',
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'used_ip' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}

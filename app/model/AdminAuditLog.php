<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

class AdminAuditLog extends Model
{
    protected $name = 'admin_audit_logs';

    protected $pk = 'id';

    protected $schema = [
        'id' => 'int',
        'admin_user_id' => 'int',
        'admin_name' => 'string',
        'role_code' => 'string',
        'module' => 'string',
        'action_key' => 'string',
        'request_method' => 'string',
        'request_path' => 'string',
        'request_ip' => 'string',
        'request_params_json' => 'string',
        'response_code' => 'int',
        'response_message' => 'string',
        'duration_ms' => 'int',
        'created_at' => 'datetime',
    ];
}

<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

class AdminUser extends Model
{
    protected $name = 'admin_users';

    protected $pk = 'id';

    protected $schema = [
        'id' => 'int',
        'username' => 'string',
        'password' => 'string',
        'name' => 'string',
        'role_code' => 'string',
        'status' => 'int',
        'last_login_at' => 'datetime',
        'last_login_ip' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}

<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

class AdminRolePermission extends Model
{
    protected $name = 'admin_role_permissions';

    protected $pk = 'id';
}

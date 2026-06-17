<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

class AdminPermission extends Model
{
    protected $name = 'admin_permissions';

    protected $pk = 'id';
}

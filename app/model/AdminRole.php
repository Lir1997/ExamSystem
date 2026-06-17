<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

class AdminRole extends Model
{
    protected $name = 'admin_roles';

    protected $pk = 'id';
}

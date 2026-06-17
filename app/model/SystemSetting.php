<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

class SystemSetting extends Model
{
    protected $name = 'system_settings';

    protected $pk = 'id';
}

<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

class Student extends Model
{
    protected $name = 'students';

    protected $pk = 'id';
}

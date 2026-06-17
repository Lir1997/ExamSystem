<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

class StudentGroup extends Model
{
    protected $name = 'student_groups';

    protected $pk = 'id';
}

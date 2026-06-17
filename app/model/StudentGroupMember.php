<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

class StudentGroupMember extends Model
{
    protected $name = 'student_group_members';

    protected $pk = 'id';
}

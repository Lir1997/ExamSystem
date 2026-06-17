<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

class StudentAccessToken extends Model
{
    protected $name = 'student_access_tokens';

    protected $pk = 'id';
}

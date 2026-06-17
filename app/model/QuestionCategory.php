<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

class QuestionCategory extends Model
{
    protected $name = 'question_categories';

    protected $pk = 'id';
}

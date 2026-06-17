<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

class ExamAnswer extends Model
{
    protected $name = 'exam_answers';

    protected $pk = 'id';

    protected $schema = [
        'id' => 'int',
        'session_id' => 'int',
        'question_id' => 'int',
        'answer_json' => 'string',
        'answered_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}

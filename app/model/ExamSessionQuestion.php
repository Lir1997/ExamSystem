<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

class ExamSessionQuestion extends Model
{
    protected $name = 'exam_session_questions';

    protected $pk = 'id';

    protected $schema = [
        'id' => 'int',
        'session_id' => 'int',
        'question_id' => 'int',
        'display_order' => 'int',
        'question_type' => 'string',
        'score' => 'int',
        'question_snapshot_json' => 'string',
        'created_at' => 'datetime',
    ];
}

<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

class ExamResultItemReview extends Model
{
    protected $name = 'exam_result_item_reviews';

    protected $pk = 'id';

    protected $schema = [
        'id' => 'int',
        'result_id' => 'int',
        'result_item_id' => 'int',
        'session_id' => 'int',
        'question_id' => 'int',
        'reviewer_admin_id' => 'int',
        'reviewer_name' => 'string',
        'score_before' => 'int',
        'score_after' => 'int',
        'review_note' => 'string',
        'created_at' => 'datetime',
    ];
}

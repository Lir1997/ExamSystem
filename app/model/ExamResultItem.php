<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

class ExamResultItem extends Model
{
    protected $name = 'exam_result_items';

    protected $pk = 'id';

    protected $schema = [
        'id' => 'int',
        'result_id' => 'int',
        'session_id' => 'int',
        'question_id' => 'int',
        'display_order' => 'int',
        'question_type' => 'string',
        'score' => 'int',
        'earned_score' => 'int',
        'is_answered' => 'int',
        'is_correct' => 'int',
        'needs_manual_review' => 'int',
        'review_status' => 'string',
        'review_note' => 'string',
        'reviewed_by_admin_id' => 'int',
        'reviewed_at' => 'datetime',
        'answer_json' => 'string',
        'reference_answer_json' => 'string',
        'question_snapshot_json' => 'string',
        'answered_at' => 'datetime',
        'scored_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public const REVIEW_STATUS_AUTO_SCORED = 'auto_scored';
    public const REVIEW_STATUS_PENDING = 'pending_review';
    public const REVIEW_STATUS_REVIEWED = 'reviewed';
}

<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

class ExamResult extends Model
{
    protected $name = 'exam_results';

    protected $pk = 'id';

    protected $schema = [
        'id' => 'int',
        'session_id' => 'int',
        'exam_id' => 'int',
        'paper_id' => 'int',
        'student_id' => 'int',
        'attempt_no' => 'int',
        'session_status' => 'string',
        'objective_score' => 'int',
        'subjective_score' => 'int',
        'total_score' => 'int',
        'objective_total_score' => 'int',
        'subjective_total_score' => 'int',
        'answered_count' => 'int',
        'correct_count' => 'int',
        'pending_manual_count' => 'int',
        'manual_review_status' => 'string',
        'penalty_score' => 'int',
        'final_score' => 'int',
        'cheating_status' => 'string',
        'violation_count' => 'int',
        'submitted_at' => 'datetime',
        'generated_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public const REVIEW_STATUS_PENDING = 'pending';
    public const REVIEW_STATUS_COMPLETED = 'completed';
}

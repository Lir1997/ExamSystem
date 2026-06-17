<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

class ExamSession extends Model
{
    protected $name = 'exam_sessions';

    protected $pk = 'id';

    protected $schema = [
        'id' => 'int',
        'exam_id' => 'int',
        'paper_id' => 'int',
        'student_id' => 'int',
        'attempt_no' => 'int',
        'status' => 'string',
        'started_at' => 'datetime',
        'deadline_at' => 'datetime',
        'submitted_at' => 'datetime',
        'last_saved_at' => 'datetime',
        'last_question_id' => 'int',
        'focus_loss_count' => 'int',
        'last_focus_loss_at' => 'datetime',
        'focus_loss_action_applied' => 'int',
        'penalty_score' => 'int',
        'force_zero_score' => 'int',
        'client_ip' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_FORCED_SUBMITTED = 'forced_submitted';
    public const STATUS_TIMEOUT_SUBMITTED = 'timeout_submitted';
}

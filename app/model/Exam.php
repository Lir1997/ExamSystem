<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

class Exam extends Model
{
    protected $name = 'exams';

    protected $pk = 'id';

    protected $schema = [
        'id' => 'int',
        'title' => 'string',
        'intro_html' => 'string',
        'enable_notice' => 'int',
        'notice_html' => 'string',
        'paper_id' => 'int',
        'status' => 'int',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'attempt_limit' => 'int',
        'deadline_strategy' => 'string',
        'allow_view_score' => 'int',
        'allow_view_paper' => 'int',
        'allow_view_analysis' => 'int',
        'show_question_score' => 'int',
        'show_question_difficulty' => 'int',
        'auto_fullscreen' => 'int',
        'enable_focus_monitor' => 'int',
        'focus_loss_limit' => 'int',
        'focus_loss_action' => 'string',
        'focus_loss_deduct_score' => 'int',
        'exam_code' => 'string',
        'monitor_slug' => 'string',
        'monitor_password_hash' => 'string',
        'monitor_password_ciphertext' => 'string',
        'monitor_password_iv' => 'string',
        'created_by' => 'int',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}

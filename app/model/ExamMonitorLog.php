<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

class ExamMonitorLog extends Model
{
    protected $name = 'exam_monitor_logs';

    protected $pk = 'id';

    protected $schema = [
        'id' => 'int',
        'exam_id' => 'int',
        'session_id' => 'int',
        'student_id' => 'int',
        'source' => 'string',
        'log_type' => 'string',
        'severity' => 'string',
        'action_type' => 'string',
        'action_value' => 'int',
        'note' => 'string',
        'payload_json' => 'string',
        'created_at' => 'datetime',
    ];
}

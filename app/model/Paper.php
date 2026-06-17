<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

class Paper extends Model
{
    protected $name = 'papers';

    protected $pk = 'id';

    protected $schema = [
        'id' => 'int',
        'title' => 'string',
        'structure_code' => 'string',
        'client_requirement' => 'string',
        'randomize_questions' => 'int',
        'randomize_options' => 'int',
        'total_score' => 'int',
        'config_json' => 'string',
        'status' => 'int',
        'created_by' => 'int',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}

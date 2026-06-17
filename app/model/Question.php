<?php

declare(strict_types=1);

namespace app\model;

use think\Model;

class Question extends Model
{
    protected $name = 'questions';

    protected $pk = 'id';

    protected $schema = [
        'id' => 'int',
        'title' => 'string',
        'question_type' => 'string',
        'category_id' => 'int',
        'difficulty_level' => 'string',
        'stem_html' => 'string',
        'analysis_html' => 'string',
        'payload_json' => 'string',
        'status' => 'int',
        'created_by' => 'int',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public const TYPE_SINGLE = 'single';
    public const TYPE_MULTIPLE = 'multiple';
    public const TYPE_JUDGE = 'judge';
    public const TYPE_BLANK = 'blank';
    public const TYPE_SHORT = 'short';
    public const TYPE_OPERATION = 'operation';

    public const TYPE_LABELS = [
        self::TYPE_SINGLE => '单选题',
        self::TYPE_MULTIPLE => '多选题',
        self::TYPE_JUDGE => '判断题',
        self::TYPE_BLANK => '填空题',
        self::TYPE_SHORT => '简答题',
        self::TYPE_OPERATION => '操作题',
    ];
}

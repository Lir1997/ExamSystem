<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseApiController;
use app\model\AdminUser;
use app\model\Question;
use app\model\QuestionCategory;
use app\service\RbacService;
use app\trait\AdminAuthorization;
use think\facade\Db;
use think\Response;

class QuestionController extends BaseApiController
{
    use AdminAuthorization;

    protected const IMPORT_FIXED_HEADERS = [
        'title' => ['试题标题', 'title'],
        'question_type' => ['题型', 'question_type', '试题类型'],
        'category' => ['试题分类', 'category', '分类'],
        'difficulty' => ['难易度', '难度', 'difficulty', 'difficulty_level'],
        'stem' => ['题干', 'stem'],
        'answer' => ['正确答案', 'answer'],
        'analysis' => ['解析', 'analysis'],
        'option_count' => ['选项数量', 'option_count', 'options_count'],
    ];

    protected const IMPORT_QUESTION_TYPE_MAP = [
        'single' => 'single',
        '单选' => 'single',
        '单选题' => 'single',
        'radio' => 'single',
        'multiple' => 'multiple',
        '多选' => 'multiple',
        '多选题' => 'multiple',
        'checkbox' => 'multiple',
        'judge' => 'judge',
        '判断' => 'judge',
        '判断题' => 'judge',
        'truefalse' => 'judge',
        'blank' => 'blank',
        '填空' => 'blank',
        '填空题' => 'blank',
        'short' => 'short',
        '简答' => 'short',
        '简答题' => 'short',
        'operation' => 'operation',
        '操作' => 'operation',
        '操作题' => 'operation',
    ];

    protected const IMPORT_DIFFICULTY_MAP = [
        'easy' => 'easy',
        '容易' => 'easy',
        '简单' => 'easy',
        '易' => 'easy',
        'medium' => 'medium',
        '中等' => 'medium',
        '适中' => 'medium',
        '中' => 'medium',
        '普通' => 'medium',
        'hard' => 'hard',
        '困难' => 'hard',
        '较难' => 'hard',
        '难' => 'hard',
    ];

    public function index(): Response
    {
        $unauthorized = $this->requirePermission('question.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        /** @var AdminUser|null $admin */
        $admin = $this->currentAdmin();
        if ($admin === null) {
            return $this->error('未获取到当前登录用户', 401);
        }

        $keyword = trim((string) $this->request->get('keyword', ''));
        $questionType = trim((string) $this->request->get('question_type', ''));
        $difficultyLevel = trim((string) $this->request->get('difficulty_level', ''));
        $categoryId = (int) $this->request->get('category_id', 0);
        [$page, $pageSize] = $this->paginationParams();

        $query = Db::name('questions')
            ->alias('q')
            ->leftJoin('question_categories c', 'c.id = q.category_id')
            ->field([
                'q.id',
                'q.title',
                'q.question_type',
                'q.category_id',
                'q.difficulty_level',
                'q.stem_html',
                'q.analysis_html',
                'q.payload_json',
                'q.status',
                'q.created_by',
                'q.created_at',
                'q.updated_at',
                'c.name' => 'category_name',
                'c.code' => 'category_code',
            ])
            ->order('q.id asc');

        if ($keyword !== '') {
            $likeKeyword = '%' . $keyword . '%';
            $query->where(function ($builder) use ($likeKeyword): void {
                $builder->whereRaw(
                    '(q.title LIKE :keyword_title OR q.stem_html LIKE :keyword_stem)',
                    [
                        'keyword_title' => $likeKeyword,
                        'keyword_stem' => $likeKeyword,
                    ]
                );
            });
        }

        if ($questionType !== '') {
            $query->where('q.question_type', $questionType);
        }

        if ($difficultyLevel !== '') {
            $query->where('q.difficulty_level', $difficultyLevel);
        }

        if ($categoryId > 0) {
            $query->where('q.category_id', $categoryId);
        }

        $rbacService = app(RbacService::class);
        if ($rbacService->hasDataScopeRestriction($admin, 'question')) {
            $query->whereIn('q.id', array_map('intval', $rbacService->getDataScopes($admin, 'question')));
        }

        $total = (clone $query)->count();
        $items = $query
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        return $this->success($this->paginationData($items, $total, $page, $pageSize), '获取试题列表成功');
    }

    public function save(): Response
    {
        $unauthorized = $this->requirePermission('question.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        $payload = $this->payload();

        $id = (int) ($payload['id'] ?? 0);
        $title = trim((string) ($payload['title'] ?? ''));
        $questionType = trim((string) ($payload['question_type'] ?? ''));
        $categoryId = (int) ($payload['category_id'] ?? 0);
        $difficultyLevel = trim((string) ($payload['difficulty_level'] ?? 'medium'));
        $stemHtml = (string) ($payload['stem_html'] ?? '');
        $analysisHtml = (string) ($payload['analysis_html'] ?? '');
        $payloadJson = trim((string) ($payload['payload_json'] ?? ''));
        $status = (int) ($payload['status'] ?? 1);
        $payloadData = [];
        $resolvedTitle = $title !== '' ? $title : $this->buildQuestionTitleFromStem($stemHtml);

        if (!array_key_exists($questionType, Question::TYPE_LABELS)) {
            return $this->error('试题类型不正确', 422);
        }

        if (!in_array($difficultyLevel, ['easy', 'medium', 'hard'], true)) {
            return $this->error('试题难度不正确', 422);
        }

        if ($categoryId <= 0 || QuestionCategory::find($categoryId) === null) {
            return $this->error('试题分类不存在', 422);
        }

        if ($resolvedTitle === '') {
            return $this->error('试题标题和题干不能同时为空', 422);
        }

        if ($payloadJson !== '') {
            $decoded = json_decode($payloadJson, true);
            if (!is_array($decoded)) {
                return $this->error('试题约束数据格式不正确', 422);
            }
            $payloadData = $decoded;
        }

        if (in_array($questionType, ['single', 'multiple'], true)) {
            $options = $payloadData['options'] ?? null;
            $answer = $payloadData['answer'] ?? null;
            if (!is_array($options) || count($options) < 2) {
                return $this->error('选择题至少应包含两个选项', 422);
            }
            if ($questionType === 'single') {
                if (!is_string($answer) || $answer === '') {
                    return $this->error('单选题必须指定正确答案', 422);
                }
            } elseif (!is_array($answer) || $answer === []) {
                return $this->error('多选题必须指定多个正确答案', 422);
            }
        }

        if ($questionType === 'judge') {
            $answer = $payloadData['answer'] ?? null;
            if (!is_string($answer) || !in_array($answer, ['true', 'false'], true)) {
                return $this->error('判断题答案必须是 true 或 false', 422);
            }
        }

        if ($questionType === 'blank') {
            $answers = $payloadData['answers'] ?? null;
            if (!is_array($answers) || $answers === []) {
                return $this->error('填空题至少应包含一个参考答案', 422);
            }
        }

        if ($questionType === 'short') {
            $answer = $payloadData['answer'] ?? '';
            if (!is_string($answer) || trim($answer) === '') {
                return $this->error('简答题必须填写参考答案', 422);
            }
        }

        if ($questionType === 'operation') {
            $requirement = $payloadData['requirement'] ?? null;
            if (!is_string($requirement) || trim($requirement) === '') {
                return $this->error('操作题必须保留操作要求', 422);
            }

            $package = $payloadData['package'] ?? null;
            if (!is_array($package)) {
                return $this->error('操作题必须提供题目压缩包', 422);
            }

            $packageUrl = $package['url'] ?? null;
            if (!is_string($packageUrl) || trim($packageUrl) === '') {
                return $this->error('操作题压缩包地址无效', 422);
            }

            $clientTask = $payloadData['client_task'] ?? null;
            if (!is_array($clientTask)) {
                return $this->error('操作题必须提供客户端任务配置', 422);
            }
        }

        /** @var AdminUser|null $admin */
        $admin = $this->currentAdmin();
        if ($admin === null) {
            return $this->error('未获取到当前登录用户', 401);
        }

        /** @var Question|null $question */
        $question = $id > 0 ? Question::find($id) : new Question();
        if ($question === null) {
            return $this->error('试题不存在', 404);
        }

        if ($id > 0 && !app(RbacService::class)->hasScopeAccess($admin, 'question', $id)) {
            return $this->error('无权编辑该试题', 403);
        }

        $question->title = $resolvedTitle;
        $question->question_type = $questionType;
        $question->category_id = $categoryId;
        $question->difficulty_level = $difficultyLevel;
        $question->stem_html = $stemHtml;
        $question->analysis_html = $analysisHtml;
        $question->payload_json = $payloadJson !== '' ? $payloadJson : null;
        $question->status = $status === 1 ? 1 : 0;

        if ((int) $question->id === 0) {
            $question->created_by = (int) $admin->id;
        }

        $question->save();
        $questionId = $this->resolvePersistedQuestionId($question, (int) $question->id === 0);

        return $this->success([
            'id' => $questionId,
            'title' => (string) $question->title,
        ], '保存试题成功');
    }

    public function importPreview(): Response
    {
        $unauthorized = $this->requirePermission('question.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        try {
            $payload = $this->parseQuestionImportUpload();
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return $this->success($payload, $payload['can_import'] ? '试题导入解析完成' : '试题导入解析发现错误');
    }

    public function importTemplate(): Response
    {
        $unauthorized = $this->requirePermission('question.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        $format = strtolower(trim((string) $this->request->get('format', 'xlsx')));
        $rows = $this->questionImportTemplateRows();

        if ($format === 'csv') {
            $content = $this->buildCsvTemplate($rows);
            return $this->downloadBinaryTemplate($content, 'question_import_template.csv', 'text/csv; charset=utf-8');
        }

        $content = $this->buildXlsxTemplate($rows);
        return $this->downloadBinaryTemplate($content, 'question_import_template.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function importCommit(): Response
    {
        $unauthorized = $this->requirePermission('question.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        /** @var AdminUser|null $admin */
        $admin = $this->currentAdmin();
        if ($admin === null) {
            return $this->error('未获取到当前登录用户', 401);
        }

        try {
            $payload = $this->parseQuestionImportUpload();
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        if (($payload['error_count'] ?? 0) > 0 || !is_array($payload['items'] ?? null) || $payload['items'] === []) {
            return $this->error('导入文件里仍有未处理的问题，请先根据解析结果修改后再重新导入。', 422);
        }

        $insertedIds = [];

        Db::transaction(function () use ($payload, $admin, &$insertedIds): void {
            foreach ($payload['items'] as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $question = new Question();
                $question->title = (string) ($item['title'] ?? '');
                $question->question_type = (string) ($item['question_type'] ?? '');
                $question->category_id = (int) ($item['category_id'] ?? 0);
                $question->difficulty_level = (string) ($item['difficulty_level'] ?? 'medium');
                $question->stem_html = (string) ($item['stem_html'] ?? '');
                $question->analysis_html = (string) ($item['analysis_html'] ?? '');
                $question->payload_json = isset($item['payload_json']) && is_string($item['payload_json']) ? $item['payload_json'] : null;
                $question->status = 1;
                $question->created_by = (int) $admin->id;
                $question->save();

                $insertedIds[] = $this->resolvePersistedQuestionId($question, true);
            }
        });

        return $this->success([
            'inserted_count' => count($insertedIds),
            'inserted_ids' => $insertedIds,
        ], '试题批量导入成功');
    }

    public function delete(int $id): Response
    {
        $unauthorized = $this->requirePermission('question.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        if ($id <= 0) {
            return $this->error('试题 ID 无效', 422);
        }

        /** @var Question|null $question */
        $question = Question::find($id);
        if ($question === null) {
            return $this->error('试题不存在', 404);
        }

        /** @var AdminUser|null $admin */
        $admin = $this->currentAdmin();
        if ($admin === null) {
            return $this->error('未获取到当前登录用户', 401);
        }

        if (!app(RbacService::class)->hasScopeAccess($admin, 'question', $id)) {
            return $this->error('无权删除该试题', 403);
        }

        Db::name('questions')->where('id', $id)->delete();

        return $this->success([
            'id' => $id,
        ], '删除试题成功');
    }

    protected function parseQuestionImportUpload(): array
    {
        $file = $this->request->file('file');
        if ($file === null) {
            throw new \RuntimeException('请先选择要导入的试题文件。');
        }

        $realPath = $file->getRealPath();
        if (!is_string($realPath) || $realPath === '' || !is_file($realPath)) {
            throw new \RuntimeException('当前选择的导入文件无效，请重新选择后再试。');
        }

        $originalName = method_exists($file, 'getOriginalName') ? (string) $file->getOriginalName() : '';
        $extension = strtolower(pathinfo($originalName !== '' ? $originalName : $realPath, PATHINFO_EXTENSION));
        if (!in_array($extension, ['xlsx', 'csv'], true)) {
            throw new \RuntimeException('当前仅支持 Excel（xlsx）或 CSV 文件导入，请重新选择模板文件。');
        }

        $rows = $extension === 'csv'
            ? $this->readCsvRows($realPath)
            : $this->readSimpleXlsxRows($realPath);

        if ($rows === []) {
            throw new \RuntimeException('导入文件为空，请检查内容后重试。');
        }

        $headers = array_map(static fn ($value): string => trim((string) $value), (array) array_shift($rows));
        $headerMap = $this->resolveImportHeaderMap($headers);
        $optionHeaderMap = $this->resolveImportOptionHeaderMap($headers);

        $categoryRows = Db::name('question_categories')->field(['id', 'name', 'code'])->select()->toArray();
        $categoryMap = [];
        foreach ($categoryRows as $categoryRow) {
            $id = (int) ($categoryRow['id'] ?? 0);
            $name = trim((string) ($categoryRow['name'] ?? ''));
            $code = strtolower(trim((string) ($categoryRow['code'] ?? '')));

            if ($id <= 0) {
                continue;
            }
            if ($name !== '') {
                $categoryMap[strtolower($name)] = ['id' => $id, 'name' => $name];
            }
            if ($code !== '') {
                $categoryMap[$code] = ['id' => $id, 'name' => $name !== '' ? $name : $code];
            }
        }

        $items = [];
        $errors = [];
        $rowNo = 1;

        foreach ($rows as $row) {
            $rowNo++;
            $parsed = $this->parseImportRow($row, $rowNo, $headerMap, $optionHeaderMap, $categoryMap);
            if ($parsed['error'] !== null) {
                $errors[] = [
                    'row_no' => $rowNo,
                    'message' => $parsed['error'],
                ];
                continue;
            }

            $items[] = $parsed['item'];
        }

        return [
            'file_name' => $originalName !== '' ? $originalName : basename($realPath),
            'items' => $items,
            'errors' => $errors,
            'total_rows' => count($rows),
            'valid_count' => count($items),
            'error_count' => count($errors),
            'can_import' => $errors === [] && $items !== [],
        ];
    }

    protected function resolveImportHeaderMap(array $headers): array
    {
        $map = [];

        foreach (self::IMPORT_FIXED_HEADERS as $field => $aliases) {
            foreach ($headers as $index => $header) {
                if (in_array(trim((string) $header), $aliases, true)) {
                    $map[$field] = $index;
                    break;
                }
            }
        }

        return $map;
    }

    protected function resolveImportOptionHeaderMap(array $headers): array
    {
        $map = [];

        foreach ($headers as $index => $header) {
            $normalized = strtoupper(trim((string) $header));
            $normalized = preg_replace('/\s+/', '', $normalized) ?? $normalized;

            if (preg_match('/^(?:选项)?([A-Z])$/u', $normalized, $matches) !== 1) {
                continue;
            }

            $map[$matches[1]] = $index;
        }

        return $map;
    }

    protected function parseImportRow(array $row, int $rowNo, array $headerMap, array $optionHeaderMap, array $categoryMap): array
    {
        $title = trim((string) ($row[$headerMap['title'] ?? -1] ?? ''));
        $questionTypeText = trim((string) ($row[$headerMap['question_type'] ?? -1] ?? ''));
        $questionType = $this->normalizeImportQuestionType($questionTypeText);
        $categoryText = trim((string) ($row[$headerMap['category'] ?? -1] ?? ''));
        $difficultyText = trim((string) ($row[$headerMap['difficulty'] ?? -1] ?? 'medium'));
        $stemHtml = trim((string) ($row[$headerMap['stem'] ?? -1] ?? ''));
        $answerText = trim((string) ($row[$headerMap['answer'] ?? -1] ?? ''));
        $analysisHtml = trim((string) ($row[$headerMap['analysis'] ?? -1] ?? ''));
        $optionCountText = trim((string) ($row[$headerMap['option_count'] ?? -1] ?? ''));

        if ($title === '' && $stemHtml === '') {
            return ['item' => null, 'error' => '题干和标题不能同时为空'];
        }

        if (!array_key_exists($questionType, Question::TYPE_LABELS)) {
            return ['item' => null, 'error' => '题型无效'];
        }

        $difficultyLevel = $this->normalizeImportDifficulty($difficultyText);
        if ($difficultyLevel === null) {
            return ['item' => null, 'error' => '难度无效'];
        }

        $category = $categoryMap[strtolower($categoryText)] ?? null;
        if (!is_array($category)) {
            return ['item' => null, 'error' => '分类不存在'];
        }

        $payload = [];
        $options = [];
        $optionCount = $this->parsePositiveInteger($optionCountText, 0);

        if (in_array($questionType, ['single', 'multiple'], true)) {
            $optionCount = $optionCount > 0 ? $optionCount : 4;
            for ($index = 0; $index < $optionCount; $index++) {
                $key = chr(ord('A') + $index);
                $optionColumnIndex = $optionHeaderMap[$key] ?? null;
                $value = $optionColumnIndex !== null
                    ? trim((string) ($row[$optionColumnIndex] ?? ''))
                    : '';
                if ($value !== '') {
                    $options[] = ['key' => $key, 'content' => $value];
                }
            }

            if (count($options) < 2) {
                return ['item' => null, 'error' => '选择题至少需要两个选项'];
            }

            $payload['options'] = $options;
            $normalizedChoiceAnswers = $this->normalizeImportChoiceAnswers($answerText, $options);
            if ($normalizedChoiceAnswers['invalid_tokens'] !== []) {
                return [
                    'item' => null,
                    'error' => '正确答案包含不存在的选项：' . implode('、', $normalizedChoiceAnswers['invalid_tokens']),
                ];
            }

            if ($questionType === 'single') {
                if (count($normalizedChoiceAnswers['answers']) !== 1) {
                    return ['item' => null, 'error' => '单选题必须填写且只能填写一个正确答案'];
                }

                $payload['answer'] = $normalizedChoiceAnswers['answers'][0];
            } else {
                if ($normalizedChoiceAnswers['answers'] === []) {
                    return ['item' => null, 'error' => '多选题必须至少填写一个正确答案'];
                }

                $payload['answer'] = $normalizedChoiceAnswers['answers'];
            }
        } elseif ($questionType === 'judge') {
            $payload['answer'] = in_array(strtolower($answerText), ['true', '对', '正确', '1'], true) ? 'true' : 'false';
        } elseif ($questionType === 'blank') {
            $payload['answers'] = array_values(array_filter(array_map('trim', preg_split('/[|\n]+/', $answerText) ?: [])));
        } elseif ($questionType === 'short') {
            $payload['answer'] = $answerText;
        } elseif ($questionType === 'operation') {
            return ['item' => null, 'error' => '当前导入模板暂不支持操作题'];
        }

        return [
            'item' => [
                'row_no' => $rowNo,
                'title' => $title !== '' ? $title : $this->buildQuestionTitleFromStem($stemHtml),
                'question_type' => $questionType,
                'question_type_label' => Question::TYPE_LABELS[$questionType],
                'category_id' => (int) $category['id'],
                'category_name' => (string) $category['name'],
                'difficulty_level' => $difficultyLevel,
                'difficulty_label' => match ($difficultyLevel) {
                    'easy' => '简单',
                    'hard' => '困难',
                    default => '中等',
                },
                'stem_html' => $stemHtml,
                'stem_preview' => strip_tags($stemHtml),
                'analysis_html' => $analysisHtml,
                'analysis_preview' => strip_tags($analysisHtml),
                'answer_display' => $answerText,
                'option_count' => count($options),
                'options' => $options,
                'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
            'error' => null,
        ];
    }

    protected function normalizeImportChoiceAnswers(string $answerText, array $options): array
    {
        $normalized = strtoupper(trim($answerText));
        if ($normalized === '') {
            return [
                'answers' => [],
                'invalid_tokens' => [],
            ];
        }

        $allowedKeys = [];
        foreach ($options as $option) {
            $key = strtoupper(trim((string) ($option['key'] ?? '')));
            if ($key !== '') {
                $allowedKeys[] = $key;
            }
        }

        $rawTokens = preg_split('/[\s,\|\/\\\\+，｜、；;＋]+/u', $normalized) ?: [];
        $tokens = [];

        foreach ($rawTokens as $rawToken) {
            $token = strtoupper(trim((string) $rawToken));
            if ($token === '') {
                continue;
            }

            if (strlen($token) > 1 && preg_match('/^[A-Z]+$/', $token) === 1) {
                foreach (str_split($token) as $char) {
                    $tokens[] = $char;
                }
                continue;
            }

            $tokens[] = $token;
        }

        $selectedMap = [];
        $invalidTokenMap = [];

        foreach ($tokens as $token) {
            if (preg_match('/^[A-Z]$/', $token) !== 1 || !in_array($token, $allowedKeys, true)) {
                $invalidTokenMap[$token] = true;
                continue;
            }

            $selectedMap[$token] = true;
        }

        $answers = [];
        foreach ($allowedKeys as $key) {
            if (isset($selectedMap[$key])) {
                $answers[] = $key;
            }
        }

        return [
            'answers' => $answers,
            'invalid_tokens' => array_keys($invalidTokenMap),
        ];
    }

    protected function buildQuestionTitleFromStem(string $stemHtml): string
    {
        $plain = trim(preg_replace('/\s+/', ' ', strip_tags($stemHtml)) ?? '');
        if ($plain === '') {
            return '';
        }

        if (function_exists('mb_substr')) {
            return mb_substr($plain, 0, 50);
        }

        return substr($plain, 0, 50);
    }

    protected function parsePositiveInteger(string $value, int $default = 0): int
    {
        $value = trim($value);
        if ($value === '' || preg_match('/^[1-9]\d*$/', $value) !== 1) {
            return $default;
        }

        return (int) $value;
    }

    protected function normalizeImportQuestionType(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = str_replace([' ', '_', '-'], '', $normalized);

        return self::IMPORT_QUESTION_TYPE_MAP[$normalized] ?? '';
    }

    protected function normalizeImportDifficulty(string $value): ?string
    {
        $normalized = strtolower(trim($value));
        $normalized = str_replace([' ', '_', '-'], '', $normalized);

        return self::IMPORT_DIFFICULTY_MAP[$normalized] ?? null;
    }

    protected function questionImportTemplateRows(): array
    {
        return [
            ['试题标题', '题型', '试题分类', '难度', '题干', '正确答案', '解析', '选项数量', 'A', 'B', 'C', 'D'],
            ['示例单选题', '单选题', 'default', '中等', '<p>下列哪项正确？</p>', 'A', '<p>这是解析</p>', '4', '选项A', '选项B', '选项C', '选项D'],
        ];
    }

    protected function readCsvRows(string $path): array
    {
        $rows = [];
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('无法读取 CSV 文件');
        }

        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = array_map(static fn ($item): string => (string) $item, $row);
        }

        fclose($handle);
        return $rows;
    }

    protected function readSimpleXlsxRows(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('无法读取 Excel 文件');
        }

        $sharedStrings = [];
        $sharedStringsXml = $zip->getFromName('xl/sharedStrings.xml');
        if (is_string($sharedStringsXml) && $sharedStringsXml !== '') {
            $xml = simplexml_load_string($sharedStringsXml);
            if ($xml !== false) {
                foreach ($xml->si as $si) {
                    $sharedStrings[] = trim((string) $si->t);
                }
            }
        }

        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if (!is_string($sheetXml) || $sheetXml === '') {
            throw new \RuntimeException('Excel 文件缺少工作表');
        }

        $xml = simplexml_load_string($sheetXml);
        if ($xml === false) {
            throw new \RuntimeException('Excel 工作表解析失败');
        }

        $rows = [];
        foreach ($xml->sheetData->row as $row) {
            $values = [];
            foreach ($row->c as $cell) {
                $type = (string) ($cell['t'] ?? '');
                $reference = (string) ($cell['r'] ?? '');
                $columnIndex = $this->excelColumnIndex($reference);
                $value = '';

                if ($type === 's') {
                    $sharedIndex = (int) ($cell->v ?? 0);
                    $value = $sharedStrings[$sharedIndex] ?? '';
                } else {
                    $value = trim((string) ($cell->v ?? ''));
                }

                $values[$columnIndex] = $value;
            }

            if ($values === []) {
                continue;
            }

            ksort($values);
            $rows[] = array_values($values);
        }

        return $rows;
    }

    protected function excelColumnIndex(string $reference): int
    {
        if ($reference === '' || preg_match('/^[A-Z]+/', strtoupper($reference), $matches) !== 1) {
            return 0;
        }

        $column = $matches[0];
        $index = 0;
        foreach (str_split($column) as $char) {
            $index = ($index * 26) + (ord($char) - 64);
        }

        return max($index - 1, 0);
    }

    protected function buildCsvTemplate(array $rows): string
    {
        $stream = fopen('php://temp', 'w+b');
        foreach ($rows as $row) {
            fputcsv($stream, $row);
        }
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return "\xEF\xBB\xBF" . ($content === false ? '' : $content);
    }

    protected function buildXlsxTemplate(array $rows): string
    {
        return $this->buildSimpleXlsx($rows);
    }

    protected function buildSimpleXlsx(array $rows): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'question-template-');
        if ($zipPath === false) {
            throw new \RuntimeException('无法创建临时模板文件');
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('无法创建模板压缩包');
        }

        $sharedStrings = [];
        $sharedStringIndex = [];
        $sheetRowsXml = '';

        foreach ($rows as $rowIndex => $row) {
            $sheetRowsXml .= '<row r="' . ($rowIndex + 1) . '">';
            foreach (array_values($row) as $columnIndex => $value) {
                $text = (string) $value;
                if (!array_key_exists($text, $sharedStringIndex)) {
                    $sharedStringIndex[$text] = count($sharedStrings);
                    $sharedStrings[] = $text;
                }

                $cellRef = $this->excelColumnName($columnIndex) . ($rowIndex + 1);
                $sheetRowsXml .= '<c r="' . $cellRef . '" t="s"><v>' . $sharedStringIndex[$text] . '</v></c>';
            }
            $sheetRowsXml .= '</row>';
        }

        $sharedStringsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($sharedStrings) . '" uniqueCount="' . count($sharedStrings) . '">';
        foreach ($sharedStrings as $text) {
            $sharedStringsXml .= '<si><t>' . htmlspecialchars($text, ENT_XML1) . '</t></si>';
        }
        $sharedStringsXml .= '</sst>';

        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            . $sheetRowsXml
            . '</sheetData></worksheet>';

        $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>';

        $workbookRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
            . '</Relationships>';

        $rootRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';

        $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            . '</Types>';

        $zip->addFromString('[Content_Types].xml', $contentTypesXml);
        $zip->addFromString('_rels/.rels', $rootRelsXml);
        $zip->addFromString('xl/workbook.xml', $workbookXml);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRelsXml);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->addFromString('xl/sharedStrings.xml', $sharedStringsXml);
        $zip->close();

        $content = file_get_contents($zipPath);
        @unlink($zipPath);

        if (!is_string($content)) {
            throw new \RuntimeException('无法读取生成的模板文件');
        }

        return $content;
    }

    protected function excelColumnName(int $index): string
    {
        $index = max($index, 0);
        $name = '';

        do {
            $name = chr(($index % 26) + 65) . $name;
            $index = intdiv($index, 26) - 1;
        } while ($index >= 0);

        return $name;
    }

    protected function resolvePersistedQuestionId(Question $question, bool $isNew): int
    {
        if (!$isNew) {
            return (int) $question->id;
        }

        return (int) Db::name('questions')
            ->where('title', (string) $question->title)
            ->where('created_by', (int) $question->created_by)
            ->order('id desc')
            ->value('id');
    }
}

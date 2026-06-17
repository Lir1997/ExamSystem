<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseApiController;
use app\model\AdminUser;
use app\model\Paper;
use app\model\Question;
use app\service\RbacService;
use app\trait\AdminAuthorization;
use think\facade\Db;
use think\Response;

class PaperController extends BaseApiController
{
    use AdminAuthorization;

    public function index(): Response
    {
        $unauthorized = $this->requirePermission('paper.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        /** @var AdminUser|null $admin */
        $admin = $this->currentAdmin();
        if ($admin === null) {
            return $this->error('未获取到当前登录用户', 401);
        }

        [$page, $pageSize] = $this->paginationParams();
        $query = Paper::order('id asc');

        $rbacService = app(RbacService::class);
        if ($rbacService->hasDataScopeRestriction($admin, 'paper')) {
            $query->whereIn('id', array_map('intval', $rbacService->getDataScopes($admin, 'paper')));
        }

        $total = (clone $query)->count();
        $items = $query->page($page, $pageSize)->select()->toArray();

        return $this->success($this->paginationData($items, $total, $page, $pageSize), '获取试卷列表成功');
    }

    public function save(): Response
    {
        $unauthorized = $this->requirePermission('paper.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        $payload = $this->payload();

        $id = (int) ($payload['id'] ?? 0);
        $title = trim((string) ($payload['title'] ?? ''));
        $clientRequirement = trim((string) ($payload['client_requirement'] ?? 'unrestricted'));
        $randomizeQuestions = (int) ($payload['randomize_questions'] ?? 0);
        $randomizeOptions = (int) ($payload['randomize_options'] ?? 0);
        $totalScore = (int) ($payload['total_score'] ?? 0);
        $configJson = trim((string) ($payload['config_json'] ?? ''));
        $status = (int) ($payload['status'] ?? 1);

        if ($title === '') {
            return $this->error('试卷标题不能为空', 422);
        }

        if (!in_array($clientRequirement, ['unrestricted', 'web_only', 'client_only'], true)) {
            return $this->error('试卷客户端限制不正确', 422);
        }

        $config = [];
        if ($configJson !== '') {
            $decoded = json_decode($configJson, true);
            if (!is_array($decoded)) {
                return $this->error('试卷配置格式不正确', 422);
            }
            $config = $decoded;
        }

        $mode = (string) ($config['mode'] ?? '');
        if (!in_array($mode, ['fixed', 'random'], true)) {
            return $this->error('试卷模式不正确', 422);
        }

        $durationMinutes = (int) ($config['duration_minutes'] ?? 0);
        if ($durationMinutes <= 0) {
            return $this->error('考试时长必须为大于 0 的分钟数', 422);
        }

        try {
            if ($mode === 'fixed') {
                $this->validateFixedConfig($config, $totalScore);
            }

            if ($mode === 'random') {
                $this->validateRandomConfig($config, $totalScore);
            }
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        /** @var AdminUser|null $admin */
        $admin = $this->currentAdmin();
        if ($admin === null) {
            return $this->error('未获取到当前登录用户', 401);
        }

        /** @var Paper|null $paper */
        $paper = $id > 0 ? Paper::find($id) : new Paper();
        if ($paper === null) {
            return $this->error('试卷不存在', 404);
        }

        if ($id > 0 && !app(RbacService::class)->hasScopeAccess($admin, 'paper', $id)) {
            return $this->error('无权编辑该试卷', 403);
        }

        $paper->title = $title;
        $paper->structure_code = 'default';
        $paper->client_requirement = $clientRequirement;
        $paper->randomize_questions = $randomizeQuestions === 1 ? 1 : 0;
        $paper->randomize_options = $randomizeOptions === 1 ? 1 : 0;
        $paper->total_score = $totalScore;
        $paper->config_json = $configJson !== '' ? $configJson : null;
        $paper->status = $status === 1 ? 1 : 0;

        if ((int) $paper->id === 0) {
            $paper->created_by = (int) $admin->id;
        }

        $paper->save();

        return $this->success([
            'id' => (int) $paper->id,
        ], '保存试卷成功');
    }

    public function delete(int $id): Response
    {
        $unauthorized = $this->requirePermission('paper.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        if ($id <= 0) {
            return $this->error('试卷 ID 无效', 422);
        }

        /** @var Paper|null $paper */
        $paper = Paper::find($id);
        if ($paper === null) {
            return $this->error('试卷不存在', 404);
        }

        /** @var AdminUser|null $admin */
        $admin = $this->currentAdmin();
        if ($admin === null) {
            return $this->error('未获取到当前登录用户', 401);
        }

        if (!app(RbacService::class)->hasScopeAccess($admin, 'paper', $id)) {
            return $this->error('无权删除该试卷', 403);
        }

        $examRefCount = (int) Db::name('exams')
            ->where('paper_id', $id)
            ->count();
        if ($examRefCount > 0) {
            return $this->error("当前试卷仍被 {$examRefCount} 场考试引用，无法删除", 422);
        }

        $scopeRefCount = (int) Db::name('admin_data_scopes')
            ->where('scope_type', 'paper')
            ->where('scope_value', (string) $id)
            ->count();
        if ($scopeRefCount > 0) {
            return $this->error("当前试卷仍在 {$scopeRefCount} 条用户数据范围中使用，无法删除", 422);
        }

        Db::name('papers')->where('id', $id)->delete();

        return $this->success([
            'id' => $id,
        ], '删除试卷成功');
    }

    protected function validateFixedConfig(array $config, int $totalScore): void
    {
        $questionItems = $config['question_items'] ?? null;
        if (!is_array($questionItems) || $questionItems === []) {
            throw new \RuntimeException('固定试卷模式必须至少选择一道题目');
        }

        $typeScoreRules = $config['type_score_rules'] ?? [];
        if (!is_array($typeScoreRules)) {
            throw new \RuntimeException('固定试卷的题型统一分值配置不正确');
        }

        $allowedQuestionTypes = array_keys(Question::TYPE_LABELS);
        $normalizedTypeScores = [];
        foreach ($typeScoreRules as $questionType => $score) {
            if (!in_array((string) $questionType, $allowedQuestionTypes, true)) {
                throw new \RuntimeException('固定试卷包含不支持的题型统一分值配置');
            }

            if ($score === null || $score === '') {
                continue;
            }

            if (!$this->isPositiveInteger($score)) {
                throw new \RuntimeException('固定试卷题型统一分值必须为大于 0 的整数');
            }

            $normalizedTypeScores[(string) $questionType] = (int) $score;
        }

        $questionIds = [];
        foreach ($questionItems as $item) {
            $questionId = (int) ($item['question_id'] ?? 0);
            if ($questionId <= 0) {
                throw new \RuntimeException('固定试卷包含无效题目');
            }

            if (in_array($questionId, $questionIds, true)) {
                throw new \RuntimeException('固定试卷中存在重复题目');
            }

            $questionIds[] = $questionId;
        }

        $questionRows = Db::name('questions')
            ->whereIn('id', $questionIds)
            ->field(['id', 'question_type'])
            ->select()
            ->toArray();

        if (count($questionRows) !== count($questionIds)) {
            throw new \RuntimeException('固定试卷包含不存在的题目');
        }

        $questionTypeMap = [];
        foreach ($questionRows as $row) {
            $questionTypeMap[(int) $row['id']] = (string) $row['question_type'];
        }

        $sum = 0;
        foreach ($questionItems as $item) {
            $questionId = (int) ($item['question_id'] ?? 0);
            $questionType = $questionTypeMap[$questionId] ?? '';
            $score = $item['score'] ?? null;

            if ($score !== null && $score !== '') {
                if (!$this->isPositiveInteger($score)) {
                    throw new \RuntimeException('固定试卷中的单题分值必须为大于 0 的整数');
                }
                $sum += (int) $score;
                continue;
            }

            $typeScore = $normalizedTypeScores[$questionType] ?? 0;
            if ($typeScore <= 0) {
                throw new \RuntimeException('固定试卷中存在未设置有效分值的题目');
            }

            $sum += $typeScore;
        }

        if ($sum !== $totalScore) {
            throw new \RuntimeException('固定试卷总分与题目得分累计不一致');
        }
    }

    protected function validateRandomConfig(array $config, int $totalScore): void
    {
        $typeRules = $config['type_rules'] ?? null;
        if (!is_array($typeRules) || $typeRules === []) {
            throw new \RuntimeException('随机试卷模式必须至少配置一个题型设置');
        }

        $sum = 0;
        $totalQuestionCount = 0;

        foreach ($typeRules as $typeRule) {
            $questionType = trim((string) ($typeRule['question_type'] ?? ''));
            if (!array_key_exists($questionType, Question::TYPE_LABELS)) {
                throw new \RuntimeException('随机试卷规则中的题型无效');
            }

            $categoryRules = $typeRule['category_rules'] ?? null;
            if (!is_array($categoryRules) || $categoryRules === []) {
                throw new \RuntimeException('随机试卷中的题型设置必须至少包含一个分类规则');
            }

            foreach ($categoryRules as $categoryRule) {
                $categoryId = (int) ($categoryRule['category_id'] ?? 0);
                if ($categoryId <= 0) {
                    throw new \RuntimeException('随机试卷规则中的分类无效');
                }

                foreach (['easy', 'medium', 'hard'] as $difficulty) {
                    $count = (int) ($categoryRule[$difficulty . '_count'] ?? 0);
                    $score = $categoryRule[$difficulty . '_score'] ?? null;

                    if ($count < 0) {
                        throw new \RuntimeException('随机试卷规则中的数量不能小于 0');
                    }

                    if ($score !== null && $score !== '' && !$this->isPositiveInteger($score)) {
                        throw new \RuntimeException('随机试卷规则中的分值必须为正整数');
                    }

                    if ($count === 0) {
                        continue;
                    }

                    $effectiveScore = null;
                    if ($score !== null && $score !== '') {
                        $effectiveScore = (int) $score;
                    } else {
                        $globalScore = $config['global_type_scores'][$questionType] ?? null;
                        if ($globalScore !== null && $globalScore !== '' && $this->isPositiveInteger($globalScore)) {
                            $effectiveScore = (int) $globalScore;
                        }
                    }

                    if ($effectiveScore === null || $effectiveScore <= 0) {
                        throw new \RuntimeException('随机试卷存在已配置抽题数量但未设置有效分值的规则');
                    }

                    $totalQuestionCount += $count;
                    $sum += $count * $effectiveScore;
                }
            }
        }

        if ($totalQuestionCount <= 0) {
            throw new \RuntimeException('随机试卷模式必须至少配置一条抽题规则');
        }

        if ($sum !== $totalScore) {
            throw new \RuntimeException('随机试卷总分与抽题规则累计得分不一致');
        }
    }

    protected function isPositiveInteger(mixed $value): bool
    {
        if (is_int($value)) {
            return $value > 0;
        }

        if (!is_string($value) && !is_numeric($value)) {
            return false;
        }

        return preg_match('/^[1-9]\d*$/', trim((string) $value)) === 1;
    }
}

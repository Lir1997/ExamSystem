<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseApiController;
use app\model\AdminUser;
use app\model\Exam;
use app\model\Paper;
use app\service\ExamScopeService;
use app\service\RbacService;
use app\trait\AdminAuthorization;
use DateTimeImmutable;
use think\facade\Db;
use think\Response;

class ExamController extends BaseApiController
{
    use AdminAuthorization;

    public function index(): Response
    {
        $unauthorized = $this->requirePermission('exam.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        /** @var AdminUser|null $admin */
        $admin = $this->currentAdmin();
        if ($admin === null) {
            return $this->error('未获取到当前登录用户', 401);
        }

        [$page, $pageSize] = $this->paginationParams();
        $query = Exam::order('id asc');

        $rbacService = app(RbacService::class);
        if ($rbacService->hasDataScopeRestriction($admin, 'exam')) {
            $query->whereIn('id', array_map('intval', $rbacService->getDataScopes($admin, 'exam')));
        }

        $total = (clone $query)->count();
        $items = $query->page($page, $pageSize)->select()->toArray();
        $examIds = array_column($items, 'id');
        $paperIds = array_values(array_unique(array_filter(array_map(
            static fn (array $item): int => (int) ($item['paper_id'] ?? 0),
            $items
        ))));
        $paperMap = [];
        $scopeService = app(ExamScopeService::class);

        if ($paperIds !== []) {
            $paperRows = Db::name('papers')
                ->whereIn('id', $paperIds)
                ->field(['id', 'title'])
                ->select()
                ->toArray();

            foreach ($paperRows as $row) {
                $paperMap[(int) $row['id']] = (string) $row['title'];
            }
        }

        $groupMap = $scopeService->groupRefsForExamIds($examIds);
        $studentMap = $scopeService->studentRefsForExamIds($examIds);

        foreach ($items as &$item) {
            $paperId = (int) ($item['paper_id'] ?? 0);
            $item['paper_title'] = $paperId > 0 ? ($paperMap[$paperId] ?? null) : null;
            $item['intro_html'] = (string) ($item['intro_html'] ?? '');
            $item['enable_notice'] = (int) ($item['enable_notice'] ?? 0);
            $item['notice_html'] = (string) ($item['notice_html'] ?? '');
            $item['attempt_limit'] = (int) ($item['attempt_limit'] ?? 1);
            $item['deadline_strategy'] = (string) ($item['deadline_strategy'] ?? 'force_close');
            $item['allow_view_score'] = (int) ($item['allow_view_score'] ?? 0);
            $item['allow_view_paper'] = (int) ($item['allow_view_paper'] ?? 0);
            $item['allow_view_analysis'] = (int) ($item['allow_view_analysis'] ?? 0);
            $item['show_question_score'] = (int) ($item['show_question_score'] ?? 1);
            $item['show_question_difficulty'] = (int) ($item['show_question_difficulty'] ?? 1);
            $item['auto_fullscreen'] = (int) ($item['auto_fullscreen'] ?? 0);
            $item['enable_focus_monitor'] = (int) ($item['enable_focus_monitor'] ?? 0);
            $item['focus_loss_limit'] = $this->normalizedFocusSettings($item)['focus_loss_limit'];
            $item['focus_loss_action'] = $this->normalizedFocusSettings($item)['focus_loss_action'];
            $item['focus_loss_deduct_score'] = $this->normalizedFocusSettings($item)['focus_loss_deduct_score'];
            $item['exam_code'] = (string) ($item['exam_code'] ?? '');
            $item['groups'] = $groupMap[$item['id']] ?? [];
            $item['students'] = $studentMap[$item['id']] ?? [];
        }
        unset($item);

        return $this->success($this->paginationData($items, $total, $page, $pageSize), '获取考试列表成功');
    }

    public function assignGroups(): Response
    {
        $unauthorized = $this->requirePermission('exam.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        $payload = $this->payload();
        $examId = (int) ($payload['exam_id'] ?? 0);
        if ($examId <= 0) {
            return $this->error('考试 ID 无效', 422);
        }

        /** @var Exam|null $exam */
        $exam = Exam::find($examId);
        if ($exam === null) {
            return $this->error('考试不存在', 404);
        }

        /** @var AdminUser|null $admin */
        $admin = $this->currentAdmin();
        if ($admin === null) {
            return $this->error('未获取到当前登录用户', 401);
        }

        if (!app(RbacService::class)->hasScopeAccess($admin, 'exam', $examId)) {
            return $this->error('无权配置该考试范围', 403);
        }

        $groupIds = (array) ($payload['group_ids'] ?? []);
        $studentIds = (array) ($payload['student_ids'] ?? []);
        $scopeService = app(ExamScopeService::class);

        $groupIds = $scopeService->existingGroupIds($groupIds);
        $studentIds = $scopeService->existingStudentIds($studentIds);

        Db::transaction(function () use ($examId, $groupIds, $studentIds): void {
            Db::name('exam_student_groups')->where('exam_id', $examId)->delete();
            Db::name('exam_student_students')->where('exam_id', $examId)->delete();

            foreach ($groupIds as $groupId) {
                Db::name('exam_student_groups')->insert([
                    'exam_id' => $examId,
                    'student_group_id' => $groupId,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }

            foreach ($studentIds as $studentId) {
                Db::name('exam_student_students')->insert([
                    'exam_id' => $examId,
                    'student_id' => $studentId,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
        });

        return $this->success([
            'exam_id' => $examId,
            'group_ids' => $groupIds,
            'student_ids' => $studentIds,
        ], '保存考试范围成功');
    }

    public function scope(int $id): Response
    {
        $unauthorized = $this->requirePermission('exam.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        if ($id <= 0) {
            return $this->error('考试 ID 无效', 422);
        }

        /** @var Exam|null $exam */
        $exam = Exam::find($id);
        if ($exam === null) {
            return $this->error('考试不存在', 404);
        }

        /** @var AdminUser|null $admin */
        $admin = $this->currentAdmin();
        if ($admin === null) {
            return $this->error('未获取到当前登录用户', 401);
        }

        if (!app(RbacService::class)->hasScopeAccess($admin, 'exam', $id)) {
            return $this->error('无权访问该考试范围', 403);
        }

        return $this->success(app(ExamScopeService::class)->scopePayload($id), '获取考试范围成功');
    }

    public function save(): Response
    {
        $unauthorized = $this->requirePermission('exam.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        try {
            $payload = $this->payload();

            $id = (int) ($payload['id'] ?? 0);
            $title = trim((string) ($payload['title'] ?? ''));
            $introHtml = (string) ($payload['intro_html'] ?? '');
            $enableNotice = (int) ($payload['enable_notice'] ?? 0);
            $noticeHtml = (string) ($payload['notice_html'] ?? '');
            $paperId = (int) ($payload['paper_id'] ?? 0);
            $status = (int) ($payload['status'] ?? 1);
            $startedAt = $this->normalizeDateTime($payload['started_at'] ?? null, '开始时间');
            $endedAt = $this->normalizeDateTime($payload['ended_at'] ?? null, '结束时间');
            $attemptLimit = (int) ($payload['attempt_limit'] ?? 1);
            $deadlineStrategy = trim((string) ($payload['deadline_strategy'] ?? 'force_close'));
            $allowViewScore = (int) ($payload['allow_view_score'] ?? 0);
            $allowViewPaper = (int) ($payload['allow_view_paper'] ?? 0);
            $allowViewAnalysis = (int) ($payload['allow_view_analysis'] ?? 0);
            $showQuestionScore = (int) ($payload['show_question_score'] ?? 1);
            $showQuestionDifficulty = (int) ($payload['show_question_difficulty'] ?? 1);
            $autoFullscreen = (int) ($payload['auto_fullscreen'] ?? 0);
            $enableFocusMonitor = (int) ($payload['enable_focus_monitor'] ?? 0);
            $focusLossLimit = (int) ($payload['focus_loss_limit'] ?? 0);
            $focusLossAction = trim((string) ($payload['focus_loss_action'] ?? 'none'));
            $focusLossDeductScore = (int) ($payload['focus_loss_deduct_score'] ?? 0);

            if ($title === '') {
                return $this->error('考试标题不能为空', 422);
            }

            if ($paperId <= 0) {
                return $this->error('请选择试卷', 422);
            }

            /** @var Paper|null $paper */
            $paper = Paper::find($paperId);
            if ($paper === null || (int) ($paper->status ?? 0) !== 1) {
                return $this->error('试卷不存在或已停用', 404);
            }

            if ($startedAt === null || $endedAt === null) {
                return $this->error('开始时间和结束时间不能为空', 422);
            }

            if (strtotime($startedAt) > strtotime($endedAt)) {
                return $this->error('结束时间不能早于开始时间', 422);
            }

            if ($attemptLimit <= 0) {
                return $this->error('允许次数必须大于 0', 422);
            }

            if (!in_array($deadlineStrategy, ['force_close', 'continue_until_duration'], true)) {
                return $this->error('截止策略不正确', 422);
            }

            if (!in_array($focusLossAction, ['none', 'force_submit', 'zero_score', 'deduct_score'], true)) {
                return $this->error('切屏处理方式不正确', 422);
            }

            if ($focusLossLimit < 0) {
                return $this->error('切屏次数阈值不能小于 0', 422);
            }

            if ($focusLossDeductScore < 0) {
                return $this->error('切屏扣分不能小于 0', 422);
            }

            if ($autoFullscreen !== 1 && $enableFocusMonitor !== 1) {
                $focusLossAction = 'none';
                $focusLossLimit = 0;
                $focusLossDeductScore = 0;
            } else {
                if ($focusLossAction === 'deduct_score' && $focusLossDeductScore <= 0) {
                    return $this->error('选择扣分处罚时，扣分分值必须大于 0', 422);
                }

                if ($focusLossAction !== 'none' && $focusLossLimit <= 0) {
                    return $this->error('开启违规处罚后，触发次数必须大于 0', 422);
                }

                if ($focusLossAction !== 'deduct_score') {
                    $focusLossDeductScore = 0;
                }

                if ($focusLossAction === 'none') {
                    $focusLossLimit = 0;
                }
            }

            /** @var AdminUser|null $admin */
            $admin = $this->currentAdmin();
            if ($admin === null) {
                return $this->error('未获取到当前登录用户', 401);
            }

            if ($id > 0 && !app(RbacService::class)->hasScopeAccess($admin, 'exam', $id)) {
                return $this->error('无权编辑该考试', 403);
            }

            /** @var Exam|null $exam */
            $exam = $id > 0 ? Exam::find($id) : new Exam();
            if ($exam === null) {
                return $this->error('考试不存在', 404);
            }

            $exam->title = $title;
            $exam->intro_html = $introHtml;
            $exam->enable_notice = $enableNotice === 1 ? 1 : 0;
            $exam->notice_html = $noticeHtml;
            $exam->paper_id = $paperId;
            $exam->status = $status === 1 ? 1 : 0;
            $exam->started_at = $startedAt;
            $exam->ended_at = $endedAt;
            $exam->attempt_limit = $attemptLimit;
            $exam->deadline_strategy = $deadlineStrategy;
            $exam->allow_view_score = $allowViewScore === 1 ? 1 : 0;
            $exam->allow_view_paper = $allowViewPaper === 1 ? 1 : 0;
            $exam->allow_view_analysis = $allowViewAnalysis === 1 ? 1 : 0;
            $exam->show_question_score = $showQuestionScore === 1 ? 1 : 0;
            $exam->show_question_difficulty = $showQuestionDifficulty === 1 ? 1 : 0;
            $exam->auto_fullscreen = $autoFullscreen === 1 ? 1 : 0;
            $exam->enable_focus_monitor = $enableFocusMonitor === 1 ? 1 : 0;
            $exam->focus_loss_limit = $focusLossLimit;
            $exam->focus_loss_action = $focusLossAction;
            $exam->focus_loss_deduct_score = $focusLossDeductScore;

            if ((int) $exam->id === 0) {
                $exam->created_by = (int) $admin->id;
            }

            $exam->save();

            return $this->success([
                'id' => (int) $exam->id,
            ], '保存考试成功');
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }
    }

    public function delete(int $id): Response
    {
        $unauthorized = $this->requirePermission('exam.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        if ($id <= 0) {
            return $this->error('考试 ID 无效', 422);
        }

        /** @var Exam|null $exam */
        $exam = Exam::find($id);
        if ($exam === null) {
            return $this->error('考试不存在', 404);
        }

        /** @var AdminUser|null $admin */
        $admin = $this->currentAdmin();
        if ($admin === null) {
            return $this->error('未获取到当前登录用户', 401);
        }

        if (!app(RbacService::class)->hasScopeAccess($admin, 'exam', $id)) {
            return $this->error('无权删除该考试', 403);
        }

        $groupRefCount = (int) Db::name('exam_student_groups')
            ->where('exam_id', $id)
            ->count();
        if ($groupRefCount > 0) {
            return $this->error("当前考试仍有关联学生分组 {$groupRefCount} 条，无法删除", 422);
        }

        $studentRefCount = (int) Db::name('exam_student_students')
            ->where('exam_id', $id)
            ->count();
        if ($studentRefCount > 0) {
            return $this->error("当前考试仍有关联指定学生 {$studentRefCount} 条，无法删除", 422);
        }

        Db::name('exams')->where('id', $id)->delete();

        return $this->success([
            'id' => $id,
        ], '删除考试成功');
    }

    protected function normalizeDateTime(mixed $value, string $label): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        $dateTime = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $text);
        if ($dateTime === false) {
            throw new \RuntimeException($label . '格式不正确');
        }

        $errors = DateTimeImmutable::getLastErrors();
        if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
            throw new \RuntimeException($label . '格式不正确');
        }

        return $dateTime->format('Y-m-d H:i:s');
    }

    protected function normalizedFocusSettings(array $item): array
    {
        $autoFullscreen = (int) ($item['auto_fullscreen'] ?? 0);
        $enableFocusMonitor = (int) ($item['enable_focus_monitor'] ?? 0);

        if ($autoFullscreen !== 1 && $enableFocusMonitor !== 1) {
            return [
                'focus_loss_limit' => 0,
                'focus_loss_action' => 'none',
                'focus_loss_deduct_score' => 0,
            ];
        }

        $action = (string) ($item['focus_loss_action'] ?? 'none');
        $limit = max((int) ($item['focus_loss_limit'] ?? 0), 0);
        $deductScore = max((int) ($item['focus_loss_deduct_score'] ?? 0), 0);

        if ($action !== 'deduct_score') {
            $deductScore = 0;
        }

        if ($action === 'none') {
            $limit = 0;
        }

        return [
            'focus_loss_limit' => $limit,
            'focus_loss_action' => $action,
            'focus_loss_deduct_score' => $deductScore,
        ];
    }
}

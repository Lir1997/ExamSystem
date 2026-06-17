<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\controller\BaseApiController;
use app\model\AdminUser;
use app\service\ExamResultService;
use app\service\RbacService;
use app\trait\AdminAuthorization;
use think\facade\Db;
use think\Response;

class MarkingController extends BaseApiController
{
    use AdminAuthorization;

    protected const EXPORT_HEADERS = [
        '成绩ID',
        '考试名称',
        '试卷名称',
        '学生姓名',
        '学生分组',
        '学号',
        '账号',
        '第几次作答',
        '交卷状态',
        '阅卷状态',
        '违规状态',
        '违规次数',
        '处罚扣分',
        '客观得分',
        '客观总分',
        '主观得分',
        '主观总分',
        '原始总分',
        '总分满分',
        '最终得分',
        '已答题数',
        '判对题数',
        '待阅卷题数',
        '交卷时间',
        '生成时间',
    ];

    public function index(): Response
    {
        $unauthorized = $this->requirePermission('marking.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        /** @var AdminUser|null $admin */
        $admin = $this->currentAdmin();
        if ($admin === null) {
            return $this->error('未获取到当前登录用户', 401);
        }

        $filters = $this->resultFilters();
        [$page, $pageSize] = $this->paginationParams();

        $query = $this->buildResultQuery($admin, $filters);
        $total = (clone $query)->count();
        $items = $query
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $items = $this->normalizeResultItems($items);

        return $this->success($this->paginationData($items, $total, $page, $pageSize), '获取成绩列表成功');
    }

    public function export(): Response
    {
        $unauthorized = $this->requirePermission('marking.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        /** @var AdminUser|null $admin */
        $admin = $this->currentAdmin();
        if ($admin === null) {
            return $this->error('未获取到当前登录用户', 401);
        }

        $query = $this->buildResultQuery($admin, $this->resultFilters());
        $items = $this->normalizeResultItems($query->select()->toArray());
        $rows = [self::EXPORT_HEADERS];

        foreach ($items as $item) {
            $rows[] = [
                (string) ($item['id'] ?? 0),
                (string) ($item['exam_title'] ?? ''),
                (string) ($item['paper_title'] ?? ''),
                (string) ($item['student_name'] ?? ''),
                $this->formatStudentGroups($item['student_groups'] ?? []),
                (string) ($item['student_no'] ?? ''),
                (string) ($item['student_username'] ?? ''),
                (string) ($item['attempt_no'] ?? 1),
                $this->sessionStatusLabel((string) ($item['session_status'] ?? '')),
                $this->manualReviewStatusLabel((string) ($item['manual_review_status'] ?? '')),
                $this->cheatingStatusLabel($item),
                (string) ($item['violation_count'] ?? 0),
                (string) ($item['penalty_score'] ?? 0),
                (string) ($item['objective_score'] ?? 0),
                (string) ($item['objective_total_score'] ?? 0),
                (string) ($item['subjective_score'] ?? 0),
                (string) ($item['subjective_total_score'] ?? 0),
                (string) ($item['total_score'] ?? 0),
                (string) (((int) ($item['objective_total_score'] ?? 0)) + ((int) ($item['subjective_total_score'] ?? 0))),
                (string) ($item['final_score'] ?? 0),
                (string) ($item['answered_count'] ?? 0),
                (string) ($item['correct_count'] ?? 0),
                (string) ($item['pending_manual_count'] ?? 0),
                (string) ($item['submitted_at'] ?? ''),
                (string) ($item['generated_at'] ?? ''),
            ];
        }

        return $this->downloadBinaryTemplate(
            $this->buildSimpleXlsx($rows),
            'marking_results_export.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );
    }

    public function detail(int $id, ExamResultService $examResultService): Response
    {
        $unauthorized = $this->requirePermission('marking.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        /** @var AdminUser|null $admin */
        $admin = $this->currentAdmin();
        if ($admin === null) {
            return $this->error('未获取到当前登录用户', 401);
        }

        if ($id <= 0) {
            return $this->error('成绩结果 ID 无效', 422);
        }

        try {
            $this->assertResultAccessible($admin, $id);
            $data = $examResultService->detail($id);
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return $this->success($data, '获取成绩详情成功');
    }

    public function review(int $id, ExamResultService $examResultService): Response
    {
        $unauthorized = $this->requirePermission('marking.manage');
        if ($unauthorized !== null) {
            return $unauthorized;
        }

        /** @var AdminUser|null $admin */
        $admin = $this->currentAdmin();
        if ($admin === null) {
            return $this->error('未获取到当前登录用户', 401);
        }

        if ($id <= 0) {
            return $this->error('成绩结果 ID 无效', 422);
        }

        $payload = $this->payload();
        $reviews = $payload['reviews'] ?? null;
        if (!is_array($reviews) || $reviews === []) {
            return $this->error('至少需要提交一条阅卷记录', 422);
        }

        try {
            $this->assertResultAccessible($admin, $id);
            $summary = $examResultService->review($id, $reviews, $admin);
            $detail = $examResultService->detail($id);
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return $this->success([
            'summary' => $summary,
            'detail' => $detail,
        ], '保存阅卷结果成功');
    }

    protected function assertResultAccessible(AdminUser $admin, int $resultId): void
    {
        $result = Db::name('exam_results')
            ->where('id', $resultId)
            ->find();

        if (!is_array($result)) {
            throw new \RuntimeException('成绩结果不存在。');
        }

        if (!app(RbacService::class)->hasScopeAccess($admin, 'paper', (string) ($result['paper_id'] ?? '0'))) {
            throw new \RuntimeException('无权限访问当前成绩结果。');
        }
    }

    protected function resultFilters(): array
    {
        return [
            'keyword' => trim((string) $this->request->get('keyword', '')),
            'exam_id' => (int) $this->request->get('exam_id', 0),
            'paper_id' => (int) $this->request->get('paper_id', 0),
            'manual_review_status' => trim((string) $this->request->get('manual_review_status', '')),
        ];
    }

    protected function buildResultQuery(AdminUser $admin, array $filters)
    {
        $keyword = (string) ($filters['keyword'] ?? '');
        $examId = (int) ($filters['exam_id'] ?? 0);
        $paperId = (int) ($filters['paper_id'] ?? 0);
        $manualReviewStatus = (string) ($filters['manual_review_status'] ?? '');

        $query = Db::name('exam_results')
            ->alias('r')
            ->leftJoin('exams e', 'e.id = r.exam_id')
            ->leftJoin('students s', 's.id = r.student_id')
            ->leftJoin('papers p', 'p.id = r.paper_id')
            ->field([
                'r.id',
                'r.session_id',
                'r.exam_id',
                'r.paper_id',
                'r.student_id',
                'r.attempt_no',
                'r.session_status',
                'r.objective_score',
                'r.subjective_score',
                'r.total_score',
                'r.objective_total_score',
                'r.subjective_total_score',
                'r.answered_count',
                'r.correct_count',
                'r.pending_manual_count',
                'r.manual_review_status',
                'r.penalty_score',
                'r.final_score',
                'r.cheating_status',
                'r.violation_count',
                'r.submitted_at',
                'r.generated_at',
                'e.title' => 'exam_title',
                'p.title' => 'paper_title',
                's.username' => 'student_username',
                's.student_no' => 'student_no',
                's.name' => 'student_name',
            ])
            ->order('r.id desc');

        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword): void {
                $builder
                    ->where('e.title', 'like', '%' . $keyword . '%')
                    ->whereOr('s.username', 'like', '%' . $keyword . '%')
                    ->whereOr('s.student_no', 'like', '%' . $keyword . '%')
                    ->whereOr('s.name', 'like', '%' . $keyword . '%');
            });
        }

        if ($examId > 0) {
            $query->where('r.exam_id', $examId);
        }

        if ($paperId > 0) {
            $query->where('r.paper_id', $paperId);
        }

        if ($manualReviewStatus !== '') {
            $query->where('r.manual_review_status', $manualReviewStatus);
        }

        $rbacService = app(RbacService::class);
        if ($rbacService->hasDataScopeRestriction($admin, 'paper')) {
            $query->whereIn('r.paper_id', array_map('intval', $rbacService->getDataScopes($admin, 'paper')));
        }

        return $query;
    }

    protected function normalizeResultItems(array $items): array
    {
        $studentIds = array_values(array_unique(array_filter(array_map(
            static fn (array $item): int => (int) ($item['student_id'] ?? 0),
            $items
        ))));
        $groupMap = [];

        if ($studentIds !== []) {
            $groupRows = Db::name('student_group_members')
                ->alias('m')
                ->join('student_groups g', 'g.id = m.student_group_id')
                ->whereIn('m.student_id', $studentIds)
                ->field([
                    'm.student_id',
                    'g.id' => 'group_id',
                    'g.name' => 'group_name',
                    'g.code' => 'group_code',
                ])
                ->order('g.id asc')
                ->select()
                ->toArray();

            foreach ($groupRows as $row) {
                $studentId = (int) ($row['student_id'] ?? 0);
                if ($studentId <= 0) {
                    continue;
                }

                $groupMap[$studentId][] = [
                    'id' => (int) ($row['group_id'] ?? 0),
                    'name' => (string) ($row['group_name'] ?? ''),
                    'code' => (string) ($row['group_code'] ?? ''),
                ];
            }
        }

        foreach ($items as &$item) {
            $item['id'] = (int) ($item['id'] ?? 0);
            $item['session_id'] = (int) ($item['session_id'] ?? 0);
            $item['exam_id'] = (int) ($item['exam_id'] ?? 0);
            $item['paper_id'] = (int) ($item['paper_id'] ?? 0);
            $item['student_id'] = (int) ($item['student_id'] ?? 0);
            $item['attempt_no'] = (int) ($item['attempt_no'] ?? 1);
            $item['objective_score'] = (int) ($item['objective_score'] ?? 0);
            $item['subjective_score'] = (int) ($item['subjective_score'] ?? 0);
            $item['total_score'] = (int) ($item['total_score'] ?? 0);
            $item['objective_total_score'] = (int) ($item['objective_total_score'] ?? 0);
            $item['subjective_total_score'] = (int) ($item['subjective_total_score'] ?? 0);
            $item['answered_count'] = (int) ($item['answered_count'] ?? 0);
            $item['correct_count'] = (int) ($item['correct_count'] ?? 0);
            $item['pending_manual_count'] = (int) ($item['pending_manual_count'] ?? 0);
            $item['penalty_score'] = (int) ($item['penalty_score'] ?? 0);
            $item['final_score'] = (int) ($item['final_score'] ?? 0);
            $item['violation_count'] = (int) ($item['violation_count'] ?? 0);
            $item['student_groups'] = $groupMap[$item['student_id']] ?? [];
        }
        unset($item);

        return $items;
    }

    protected function formatStudentGroups(array $groups): string
    {
        $names = array_values(array_filter(array_map(
            static fn (array $group): string => trim((string) ($group['name'] ?? '')),
            $groups
        )));

        return $names === [] ? '' : implode('、', $names);
    }

    protected function sessionStatusLabel(string $status): string
    {
        return match ($status) {
            'submitted' => '已交卷',
            'timeout' => '超时收卷',
            'forced' => '强制收卷',
            'in_progress' => '作答中',
            default => $status,
        };
    }

    protected function manualReviewStatusLabel(string $status): string
    {
        return $status === 'pending' ? '待阅卷' : '已完成';
    }

    protected function cheatingStatusLabel(array $item): string
    {
        $status = (string) ($item['cheating_status'] ?? 'none');
        return match ($status) {
            'warning' => '警告',
            'deducted' => '已扣分',
            'zero_score' => '记零分',
            default => '无',
        };
    }
}

<?php

declare(strict_types=1);

namespace app\controller\exam;

use app\controller\BaseApiController;
use app\model\Exam;
use app\model\Student;
use app\service\ExamResultService;
use app\service\StudentAuthService;
use think\Response;

class ResultController extends BaseApiController
{
    public function detail(
        int $examId,
        StudentAuthService $studentAuthService,
        ExamResultService $examResultService
    ): Response {
        /** @var Student|null $student */
        $student = $this->request->studentUser ?? null;
        if ($student === null) {
            return $this->error('未获取到当前学生账号。', 401);
        }

        if ($examId <= 0) {
            return $this->error('考试 ID 无效。', 422);
        }

        /** @var Exam|null $exam */
        $exam = Exam::find($examId);
        if ($exam === null) {
            return $this->error('考试不存在。', 404);
        }

        $availableExams = $studentAuthService->availableExams($student);
        $availableExamIds = array_map(static fn (array $item): int => (int) $item['id'], $availableExams);
        if (!in_array($examId, $availableExamIds, true)) {
            return $this->error('当前学生无权访问该考试成绩。', 403);
        }

        if ((int) ($exam->allow_view_score ?? 0) !== 1) {
            return $this->error('当前考试未开放成绩查看。', 403);
        }

        $detail = $examResultService->latestResultDetailForStudent($exam, $student);
        if ($detail === null) {
            return $this->error('当前考试暂无可查看成绩。', 404);
        }

        return $this->success([
            'exam' => [
                'id' => (int) $exam->id,
                'title' => (string) $exam->title,
                'allow_view_score' => (int) ($exam->allow_view_score ?? 0),
            ],
            'result' => $detail['result'],
            'visibility' => $detail['visibility'],
            'items' => $detail['items'],
        ], '获取考试成绩成功。');
    }
}

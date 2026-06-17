<?php

declare(strict_types=1);

namespace app\controller\exam;

use app\controller\BaseApiController;
use app\model\Exam;
use app\model\ExamSession;
use app\model\Student;
use app\service\ExamIntegrityService;
use app\service\ExamResultService;
use app\service\ExamSessionService;
use app\service\SystemSettingService;
use think\Response;

class AnswerController extends BaseApiController
{
    public function save(int $examId, ExamSessionService $examSessionService): Response
    {
        /** @var Student|null $student */
        $student = $this->request->studentUser ?? null;
        if ($student === null) {
            return $this->error('未获取到当前学生账号。', 401);
        }

        /** @var Exam|null $exam */
        $exam = Exam::find($examId);
        if ($exam === null) {
            return $this->error('考试不存在。', 404);
        }

        $payload = $this->payload();

        try {
            $result = $examSessionService->saveAnswers($exam, $student, $payload);
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return $this->success($result, '保存答案成功。');
    }

    public function submit(
        int $examId,
        ExamSessionService $examSessionService,
        ExamResultService $examResultService
    ): Response {
        /** @var Student|null $student */
        $student = $this->request->studentUser ?? null;
        if ($student === null) {
            return $this->error('未获取到当前学生账号。', 401);
        }

        /** @var Exam|null $exam */
        $exam = Exam::find($examId);
        if ($exam === null) {
            return $this->error('考试不存在。', 404);
        }

        $payload = $this->payload();

        try {
            $result = $examSessionService->submitSession($exam, $student, $payload, $examResultService);
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        /** @var ExamSession|null $session */
        $session = isset($payload['session_id']) ? ExamSession::find((int) $payload['session_id']) : null;
        app(ExamIntegrityService::class)->logExamOperation(
            $exam,
            $session,
            $student,
            ExamIntegrityService::LOG_EXAM_SUBMIT,
            '考试端完成交卷',
            [
                'session_status' => $result['session']['status'] ?? null,
                'submitted_at' => $result['session']['submitted_at'] ?? null,
            ],
        );

        $settings = app(SystemSettingService::class)->all();

        return $this->success([
            'session' => $result['session'],
            'student' => [
                'id' => (int) $student->id,
                'username' => (string) $student->username,
                'student_no' => (string) $student->student_no,
                'name' => (string) $student->name,
            ],
            'exam' => [
                'id' => (int) $exam->id,
                'title' => (string) $exam->title,
            ],
            'finish' => [
                'auto_logout' => (int) ($settings['exam_finish_auto_logout'] ?? 0),
                'message' => (string) ($settings['exam_finish_message'] ?? '您已完成考试，请根据监考人员要求有序离开考场。'),
            ],
        ], '交卷成功。');
    }
}

<?php

declare(strict_types=1);

namespace app\controller\exam;

use app\controller\BaseApiController;
use app\model\Exam;
use app\model\Student;
use app\service\ExamPaperService;
use app\service\ExamSessionService;
use app\service\StudentAuthService;
use think\Response;

class PaperController extends BaseApiController
{
    public function detail(
        int $examId,
        StudentAuthService $studentAuthService,
        ExamPaperService $examPaperService,
        ExamSessionService $examSessionService
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

        try {
            $sessionLifecycle = 'resume';
            $session = $examSessionService->findActiveSession($exam, $student);

            if ($session === null) {
                $sessionLifecycle = 'new';
                $availableExams = $studentAuthService->availableExams($student);
                $availableExamIds = array_map(static fn (array $item): int => (int) $item['id'], $availableExams);
                if (!in_array($examId, $availableExamIds, true)) {
                    return $this->error('当前学生无权访问该考试。', 403);
                }

                $accessState = $examSessionService->accessState($exam, $student);
                if (!(bool) ($accessState['can_enter'] ?? false)) {
                    return $this->error(
                        isset($accessState['message']) && $accessState['message'] !== null
                            ? (string) $accessState['message']
                            : '当前考试暂不可进入。',
                        422
                    );
                }

                $paperPayload = $examPaperService->buildExamPaper($exam, $student);
                $session = $examSessionService->createSession($exam, $student, $paperPayload);
            } else {
                $sessionId = (int) $session->id;
                $questionCount = $examSessionService->sessionQuestionCount($sessionId);

                if ($questionCount <= 0) {
                    $answerCount = $examSessionService->answerCount($sessionId);
                    if ($answerCount > 0) {
                        return $this->error('当前作答会话数据不完整，无法自动恢复。', 422);
                    }

                    $paperPayload = $examPaperService->buildExamPaper($exam, $student);
                    $examSessionService->replaceSessionQuestions($session, (array) ($paperPayload['questions'] ?? []));
                    $session = $examSessionService->findActiveSession($exam, $student);
                    if ($session === null) {
                        return $this->error('当前作答会话无法重新加载。', 500);
                    }
                } else {
                    $paperPayload = $examPaperService->buildExamPaperFromSession($exam, $student, $session);
                }
            }

            unset($paperPayload['session_questions']);
            $paperPayload['session'] = $examSessionService->sessionPayload($session, $exam);
            $paperPayload['answers'] = $examSessionService->answerMap((int) $session->id);
            $paperPayload['session_lifecycle'] = $sessionLifecycle;
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return $this->success($paperPayload, '获取考试试卷成功。');
    }

    public function session(
        int $examId,
        ExamSessionService $examSessionService
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

        $session = $examSessionService->latestSessionByStudent($exam, $student);
        if ($session === null) {
            return $this->error('当前考试暂无作答会话。', 404);
        }

        return $this->success([
            'session' => $examSessionService->sessionPayload($session, $exam),
        ], '获取作答会话成功。');
    }
}

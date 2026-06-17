<?php

declare(strict_types=1);

namespace app\controller\monitor;

use app\controller\BaseApiController;
use app\model\Exam;
use app\model\ExamSession;
use app\service\ExamIntegrityService;
use app\service\ExamSessionService;
use think\Response;

class OperationController extends BaseApiController
{
    public function extendTime(ExamSessionService $examSessionService): Response
    {
        $exam = $this->currentExam();
        if ($exam === null) {
            return $this->error('未获取到当前监考考试', 401);
        }

        $payload = $this->payload();
        $studentId = (int) ($payload['student_id'] ?? 0);
        $extendMinutes = (int) ($payload['extend_minutes'] ?? 0);

        if ($studentId <= 0) {
            return $this->error('考生 ID 无效', 422);
        }

        $session = $examSessionService->activeSessionByStudentId($exam, $studentId);
        if (!$session instanceof ExamSession) {
            return $this->error('当前考生没有作答中的会话', 422);
        }

        try {
            $session = $examSessionService->extendSessionDeadline($exam, $session, $extendMinutes);
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        app(ExamIntegrityService::class)->logMonitorOperation(
            $exam,
            $session,
            $studentId,
            ExamIntegrityService::LOG_EXTEND_TIME,
            '监考端临时加时',
            ['extend_minutes' => $extendMinutes],
        );

        return $this->success([
            'session' => $examSessionService->sessionPayload($session, $exam),
        ], '临时加时成功');
    }

    public function forceSubmit(ExamSessionService $examSessionService): Response
    {
        $exam = $this->currentExam();
        if ($exam === null) {
            return $this->error('未获取到当前监考考试', 401);
        }

        $payload = $this->payload();
        $studentId = (int) ($payload['student_id'] ?? 0);

        if ($studentId <= 0) {
            return $this->error('考生 ID 无效', 422);
        }

        $session = $examSessionService->activeSessionByStudentId($exam, $studentId);
        if (!$session instanceof ExamSession) {
            return $this->error('当前考生没有作答中的会话', 422);
        }

        try {
            $result = $examSessionService->forceSubmitSession($exam, $session);
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        app(ExamIntegrityService::class)->logMonitorOperation(
            $exam,
            $session,
            $studentId,
            ExamIntegrityService::LOG_FORCE_SUBMIT,
            '监考端强制收卷',
        );

        return $this->success($result, '强制收卷成功');
    }

    public function bulkForceSubmit(ExamSessionService $examSessionService): Response
    {
        $exam = $this->currentExam();
        if ($exam === null) {
            return $this->error('未获取到当前监考考试', 401);
        }

        $payload = $this->payload();
        $studentIds = is_array($payload['student_ids'] ?? null) ? $payload['student_ids'] : [];
        $studentIds = array_values(array_unique(array_filter(array_map('intval', $studentIds), static fn (int $id): bool => $id > 0)));
        $studentIds = array_values(array_filter($studentIds, static fn (int $id): bool => $id > 0));

        if ($studentIds === []) {
            return $this->error('请先选择需要强制收卷的考生', 422);
        }

        $sessionMap = $examSessionService->activeSessionsByStudentIds($exam, $studentIds);
        $submittedItems = [];
        $skippedItems = [];

        foreach ($studentIds as $studentId) {
            $session = $sessionMap[$studentId] ?? null;

            if (!$session instanceof ExamSession) {
                $skippedItems[] = [
                    'student_id' => $studentId,
                    'reason' => '当前考生没有作答中的会话',
                ];
                continue;
            }

            try {
                $result = $examSessionService->forceSubmitSession($exam, $session);
                $submittedItems[] = [
                    'student_id' => $studentId,
                    'session' => $result['session'] ?? null,
                    'result' => $result['result'] ?? null,
                ];
            } catch (\RuntimeException $exception) {
                $skippedItems[] = [
                    'student_id' => $studentId,
                    'reason' => $exception->getMessage(),
                ];
            }
        }

        if ($submittedItems === []) {
            return $this->error('所选考生均未执行强制收卷', 422, [
                'submitted' => [],
                'skipped' => $skippedItems,
            ]);
        }

        app(ExamIntegrityService::class)->logMonitorOperation(
            $exam,
            null,
            0,
            ExamIntegrityService::LOG_BULK_FORCE_SUBMIT,
            '监考端批量强制收卷',
            [
                'student_ids' => $studentIds,
                'submitted_count' => count($submittedItems),
                'skipped_count' => count($skippedItems),
            ],
        );

        return $this->success([
            'submitted' => $submittedItems,
            'skipped' => $skippedItems,
            'submitted_count' => count($submittedItems),
            'skipped_count' => count($skippedItems),
        ], '批量强制收卷成功');
    }

    public function bulkExtendTime(ExamSessionService $examSessionService): Response
    {
        $exam = $this->currentExam();
        if ($exam === null) {
            return $this->error('未获取到当前监考考试', 401);
        }

        $payload = $this->payload();
        $studentIds = is_array($payload['student_ids'] ?? null) ? $payload['student_ids'] : [];
        $studentIds = array_values(array_unique(array_filter(array_map('intval', $studentIds), static fn (int $id): bool => $id > 0)));
        $extendMinutes = (int) ($payload['extend_minutes'] ?? 0);

        if ($studentIds === []) {
            return $this->error('请先选择需要加时的考生', 422);
        }

        if ($extendMinutes <= 0) {
            return $this->error('加时时长必须大于 0 分钟', 422);
        }

        $sessionMap = $examSessionService->activeSessionsByStudentIds($exam, $studentIds);
        $extendedItems = [];
        $skippedItems = [];

        foreach ($studentIds as $studentId) {
            $session = $sessionMap[$studentId] ?? null;

            if (!$session instanceof ExamSession) {
                $skippedItems[] = [
                    'student_id' => $studentId,
                    'reason' => '当前考生没有作答中的会话',
                ];
                continue;
            }

            try {
                $updatedSession = $examSessionService->extendSessionDeadline($exam, $session, $extendMinutes);
                $extendedItems[] = [
                    'student_id' => $studentId,
                    'session' => $examSessionService->sessionPayload($updatedSession, $exam),
                ];
            } catch (\RuntimeException $exception) {
                $skippedItems[] = [
                    'student_id' => $studentId,
                    'reason' => $exception->getMessage(),
                ];
            }
        }

        if ($extendedItems === []) {
            return $this->error('所选考生均未执行临时加时', 422, [
                'extended' => [],
                'skipped' => $skippedItems,
            ]);
        }

        app(ExamIntegrityService::class)->logMonitorOperation(
            $exam,
            null,
            0,
            ExamIntegrityService::LOG_BULK_EXTEND_TIME,
            '监考端批量临时加时',
            [
                'student_ids' => $studentIds,
                'extend_minutes' => $extendMinutes,
                'extended_count' => count($extendedItems),
                'skipped_count' => count($skippedItems),
            ],
        );

        return $this->success([
            'extended' => $extendedItems,
            'skipped' => $skippedItems,
            'extended_count' => count($extendedItems),
            'skipped_count' => count($skippedItems),
            'extend_minutes' => $extendMinutes,
        ], '批量临时加时成功');
    }

    protected function currentExam(): ?Exam
    {
        $exam = $this->request->monitorExam ?? null;

        return $exam instanceof Exam ? $exam : null;
    }
}

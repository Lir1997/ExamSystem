<?php

declare(strict_types=1);

namespace app\service;

use app\model\Exam;
use app\model\ExamSession;
use app\model\Question;
use app\model\Student;
use think\facade\Db;

class ExamSessionService
{
    protected const TIMEOUT_FINALIZE_GRACE_SECONDS = 120;

    public function findActiveSession(Exam $exam, Student $student): ?ExamSession
    {
        $this->closeExpiredSessions($exam, $student);

        /** @var ExamSession|null $session */
        $session = ExamSession::where('exam_id', (int) $exam->id)
            ->where('student_id', (int) $student->id)
            ->where('status', ExamSession::STATUS_IN_PROGRESS)
            ->order('id desc')
            ->find();

        return $session;
    }

    public function canAccessExam(Exam $exam, Student $student): bool
    {
        return $this->accessState($exam, $student)['can_enter'];
    }

    public function accessState(Exam $exam, Student $student): array
    {
        $activeSession = $this->findActiveSession($exam, $student);
        if ($activeSession !== null) {
            return $this->continueSessionState($exam, $activeSession);
        }

        if ((int) ($exam->status ?? 0) !== 1) {
            return [
                'can_enter' => false,
                'reason' => 'exam_disabled',
                'message' => '当前考试已停用。',
            ];
        }

        $now = time();
        $startedAt = $exam->started_at ? strtotime((string) $exam->started_at) : null;
        $endedAt = $exam->ended_at ? strtotime((string) $exam->ended_at) : null;

        if ($startedAt !== null && $startedAt > $now) {
            return [
                'can_enter' => false,
                'reason' => 'not_started',
                'message' => '未到考试时间',
            ];
        }

        if ($endedAt !== null && $endedAt < $now) {
            $hasResult = Db::name('exam_results')
                ->where('exam_id', (int) $exam->id)
                ->where('student_id', (int) $student->id)
                ->count() > 0;

            $hasAttempt = $hasResult || $this->countAttempts($exam, $student) > 0;

            return [
                'can_enter' => false,
                'reason' => $hasAttempt ? 'exam_completed' : 'exam_absent',
                'message' => $hasAttempt ? '已完成考试' : '缺考',
            ];
        }

        $attemptLimit = (int) ($exam->attempt_limit ?? 1);
        if ($attemptLimit <= 0) {
            return [
                'can_enter' => true,
                'reason' => 'new_session',
                'message' => null,
            ];
        }

        if ($this->countAttempts($exam, $student) >= $attemptLimit) {
            return [
                'can_enter' => false,
                'reason' => 'attempt_limit_reached',
                'message' => '已完成考试',
            ];
        }

        return [
            'can_enter' => true,
            'reason' => 'new_session',
            'message' => null,
        ];
    }

    public function createSession(Exam $exam, Student $student, array $paperPayload): ExamSession
    {
        $questions = $this->resolveSessionQuestions($paperPayload);
        if ($questions === []) {
            throw new \RuntimeException('当前考试未生成任何题目。');
        }

        $attemptNo = $this->countAttempts($exam, $student) + 1;
        $attemptLimit = (int) ($exam->attempt_limit ?? 1);
        if ($attemptLimit > 0 && $attemptNo > $attemptLimit) {
            throw new \RuntimeException('当前考试可作答次数已用尽。');
        }

        $durationMinutes = (int) ($paperPayload['paper']['duration_minutes'] ?? 0);
        $startedAt = date('Y-m-d H:i:s');
        $deadlineAt = $durationMinutes > 0 ? date('Y-m-d H:i:s', time() + ($durationMinutes * 60)) : null;

        $sessionId = Db::transaction(function () use ($exam, $student, $questions, $paperPayload, $attemptNo, $startedAt, $deadlineAt): int {
            $sessionId = (int) Db::name('exam_sessions')->insertGetId([
                'exam_id' => (int) $exam->id,
                'paper_id' => (int) ($paperPayload['paper']['id'] ?? 0),
                'student_id' => (int) $student->id,
                'attempt_no' => $attemptNo,
                'status' => ExamSession::STATUS_IN_PROGRESS,
                'started_at' => $startedAt,
                'deadline_at' => $deadlineAt,
                // New sessions should start from the first display question on the client.
                // Only persist last_question_id after the student actually navigates or saves progress.
                'last_question_id' => null,
                'client_ip' => request()->ip(),
                'created_at' => $startedAt,
                'updated_at' => $startedAt,
            ]);

            foreach ($questions as $question) {
                $questionId = (int) ($question['id'] ?? 0);
                if ($questionId <= 0) {
                    continue;
                }

                Db::name('exam_session_questions')->insert([
                    'session_id' => $sessionId,
                    'question_id' => $questionId,
                    'display_order' => (int) ($question['display_order'] ?? 0),
                    'question_type' => (string) ($question['question_type'] ?? ''),
                    'score' => (int) ($question['score'] ?? 0),
                    'question_snapshot_json' => json_encode($question, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'created_at' => $startedAt,
                ]);
            }

            return $sessionId;
        });

        /** @var ExamSession|null $session */
        $session = ExamSession::find($sessionId);
        if ($session === null) {
            throw new \RuntimeException('新建会话加载失败。');
        }

        return $session;
    }

    public function answerMap(int $sessionId): array
    {
        $rows = Db::name('exam_answers')
            ->where('session_id', $sessionId)
            ->select()
            ->toArray();

        $result = [];

        foreach ($rows as $row) {
            $questionId = (int) ($row['question_id'] ?? 0);
            if ($questionId <= 0) {
                continue;
            }

            $decoded = json_decode((string) ($row['answer_json'] ?? ''), true);
            $result[$questionId] = $decoded;
        }

        return $result;
    }

    public function sessionQuestionCount(int $sessionId): int
    {
        return (int) Db::name('exam_session_questions')
            ->where('session_id', $sessionId)
            ->count();
    }

    public function answerCount(int $sessionId): int
    {
        return (int) Db::name('exam_answers')
            ->where('session_id', $sessionId)
            ->count();
    }

    public function replaceSessionQuestions(ExamSession $session, array $questions): void
    {
        if ($questions === []) {
            throw new \RuntimeException('当前考试未生成任何题目。');
        }

        $now = date('Y-m-d H:i:s');

        Db::transaction(function () use ($session, $questions, $now): void {
            Db::name('exam_session_questions')
                ->where('session_id', (int) $session->id)
                ->delete();

            foreach ($questions as $question) {
                $questionId = (int) ($question['id'] ?? 0);
                if ($questionId <= 0) {
                    continue;
                }

                Db::name('exam_session_questions')->insert([
                    'session_id' => (int) $session->id,
                    'question_id' => $questionId,
                    'display_order' => (int) ($question['display_order'] ?? 0),
                    'question_type' => (string) ($question['question_type'] ?? ''),
                    'score' => (int) ($question['score'] ?? 0),
                    'question_snapshot_json' => json_encode($question, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'created_at' => $now,
                ]);
            }

            Db::name('exam_sessions')
                ->where('id', (int) $session->id)
                ->update([
                    // Rebuilt question snapshots should not force the client into a non-initial position
                    // unless there is real saved progress later written by save/submit flows.
                    'last_question_id' => null,
                    'updated_at' => $now,
                ]);
        });
    }

    public function sessionPayload(ExamSession $session, ?Exam $exam = null): array
    {
        $effectiveDeadlineAt = $exam instanceof Exam
            ? $this->effectiveDeadlineAt($exam, $session)
            : ($session->deadline_at ? (string) $session->deadline_at : null);
        $remainingSeconds = $this->sessionRemainingSeconds($session, $exam);

        return [
            'id' => (int) $session->id,
            'exam_id' => (int) $session->exam_id,
            'paper_id' => (int) $session->paper_id,
            'student_id' => (int) $session->student_id,
            'attempt_no' => (int) ($session->attempt_no ?? 1),
            'status' => (string) ($session->status ?? ExamSession::STATUS_IN_PROGRESS),
            'started_at' => $session->started_at ? (string) $session->started_at : null,
            'deadline_at' => $session->deadline_at ? (string) $session->deadline_at : null,
            'effective_deadline_at' => $effectiveDeadlineAt,
            'remaining_seconds' => $remainingSeconds,
            'submitted_at' => $session->submitted_at ? (string) $session->submitted_at : null,
            'last_saved_at' => $session->last_saved_at ? (string) $session->last_saved_at : null,
            'last_question_id' => $session->last_question_id !== null ? (int) $session->last_question_id : null,
        ];
    }

    public function saveAnswers(Exam $exam, Student $student, array $payload): array
    {
        $sessionId = (int) ($payload['session_id'] ?? 0);
        $answers = isset($payload['answers']) && is_array($payload['answers']) ? $payload['answers'] : [];
        $lastQuestionId = isset($payload['last_question_id']) ? (int) $payload['last_question_id'] : null;

        if ($sessionId <= 0) {
            throw new \RuntimeException('作答会话不存在。');
        }

        $session = $this->loadOwnedSession($sessionId, $exam, $student);
        $status = (string) ($session->status ?? '');
        if ($status !== ExamSession::STATUS_IN_PROGRESS) {
            if ($status === ExamSession::STATUS_TIMEOUT_SUBMITTED) {
                throw new \RuntimeException('当前作答会话已超时并自动交卷。');
            }

            if ($status === ExamSession::STATUS_FORCED_SUBMITTED) {
                throw new \RuntimeException('当前作答会话已被监考端强制收卷。');
            }

            throw new \RuntimeException('当前作答会话已关闭。');
        }

        if ($this->isSessionExpired($exam, $session)) {
            $this->markSessionTimedOut($session, $this->effectiveDeadlineAt($exam, $session));
            throw new \RuntimeException('当前作答会话已超时并自动交卷。');
        }

        $savedQuestionIds = $this->persistAnswersToSession($exam, $session, $answers, $lastQuestionId);
        $session = $this->loadOwnedSession((int) $session->id, $exam, $student);

        return [
            'session' => $this->sessionPayload($session, $exam),
            'saved_question_ids' => array_values(array_unique($savedQuestionIds)),
        ];
    }

    public function submitSession(
        Exam $exam,
        Student $student,
        array $payload,
        ?ExamResultService $examResultService = null
    ): array {
        $sessionId = (int) ($payload['session_id'] ?? 0);
        $answers = isset($payload['answers']) && is_array($payload['answers']) ? $payload['answers'] : [];
        $lastQuestionId = isset($payload['last_question_id']) ? (int) $payload['last_question_id'] : null;

        if ($sessionId <= 0) {
            throw new \RuntimeException('作答会话不存在。');
        }

        $session = $this->loadOwnedSession($sessionId, $exam, $student);
        $status = (string) ($session->status ?? '');

        if ($status !== ExamSession::STATUS_IN_PROGRESS && $status !== ExamSession::STATUS_TIMEOUT_SUBMITTED) {
            if ($status === ExamSession::STATUS_FORCED_SUBMITTED) {
                throw new \RuntimeException('当前作答会话已被监考端强制收卷。');
            }

            throw new \RuntimeException('当前作答会话已关闭。');
        }

        $isTimeoutSubmission = $status === ExamSession::STATUS_TIMEOUT_SUBMITTED || $this->isSessionExpired($exam, $session);
        if ($status === ExamSession::STATUS_TIMEOUT_SUBMITTED && !$this->canFinalizeTimedOutSession($session)) {
            throw new \RuntimeException('当前作答会话已超时并自动交卷。');
        }

        $savedQuestionIds = $this->persistAnswersToSession($exam, $session, $answers, $lastQuestionId, false);
        $now = date('Y-m-d H:i:s');
        $submittedAt = $isTimeoutSubmission
            ? ($this->effectiveDeadlineAt($exam, $session) ?: ($session->submitted_at ? (string) $session->submitted_at : $now))
            : $now;

        Db::name('exam_sessions')
            ->where('id', (int) $session->id)
            ->update([
                'status' => $isTimeoutSubmission ? ExamSession::STATUS_TIMEOUT_SUBMITTED : ExamSession::STATUS_SUBMITTED,
                'submitted_at' => $submittedAt,
                'last_saved_at' => $now,
                'updated_at' => $now,
            ]);

        $session = $this->loadOwnedSession((int) $session->id, $exam, $student);
        $examResultService = $examResultService ?: app(ExamResultService::class);
        $result = $examResultService->generateFromSession($exam, $student, $session);

        return [
            'session' => $this->sessionPayload($session, $exam),
            'saved_question_ids' => array_values(array_unique($savedQuestionIds)),
            'result' => $result,
        ];
    }

    public function sessionProgressSummary(Exam $exam, ExamSession $session): array
    {
        $effectiveDeadlineAt = $this->effectiveDeadlineAt($exam, $session);
        $remainingSeconds = $this->sessionRemainingSeconds($session, $exam);

        return [
            'session_id' => (int) $session->id,
            'status' => (string) ($session->status ?? ExamSession::STATUS_IN_PROGRESS),
            'answered_count' => (int) Db::name('exam_answers')
                ->where('session_id', (int) $session->id)
                ->whereRaw('answered_at IS NOT NULL')
                ->count(),
            'question_count' => (int) Db::name('exam_session_questions')
                ->where('session_id', (int) $session->id)
                ->count(),
            'remaining_seconds' => $remainingSeconds,
            'effective_deadline_at' => $effectiveDeadlineAt,
            'last_saved_at' => $session->last_saved_at ? (string) $session->last_saved_at : null,
        ];
    }

    public function latestSessionByStudent(Exam $exam, Student $student): ?ExamSession
    {
        /** @var ExamSession|null $session */
        $session = ExamSession::where('exam_id', (int) $exam->id)
            ->where('student_id', (int) $student->id)
            ->order('id desc')
            ->find();

        return $session;
    }

    public function activeSessionByStudentId(Exam $exam, int $studentId): ?ExamSession
    {
        /** @var ExamSession|null $session */
        $session = ExamSession::where('exam_id', (int) $exam->id)
            ->where('student_id', $studentId)
            ->where('status', ExamSession::STATUS_IN_PROGRESS)
            ->order('id desc')
            ->find();

        return $session;
    }

    public function activeSessionsByStudentIds(Exam $exam, array $studentIds): array
    {
        $studentIds = array_values(array_unique(array_filter(array_map('intval', $studentIds), static fn (int $id): bool => $id > 0)));
        if ($studentIds === []) {
            return [];
        }

        $sessions = ExamSession::where('exam_id', (int) $exam->id)
            ->whereIn('student_id', $studentIds)
            ->where('status', ExamSession::STATUS_IN_PROGRESS)
            ->order('id desc')
            ->select();

        $sessionMap = [];

        foreach ($sessions as $session) {
            if (!$session instanceof ExamSession) {
                continue;
            }

            $studentId = (int) ($session->student_id ?? 0);
            if ($studentId <= 0 || isset($sessionMap[$studentId])) {
                continue;
            }

            $sessionMap[$studentId] = $session;
        }

        return $sessionMap;
    }

    public function extendSessionDeadline(Exam $exam, ExamSession $session, int $extendMinutes): ExamSession
    {
        if ($extendMinutes <= 0) {
            throw new \RuntimeException('加时时长必须大于 0 分钟。');
        }

        if ((string) ($session->status ?? '') !== ExamSession::STATUS_IN_PROGRESS) {
            throw new \RuntimeException('当前会话不是作答中状态，无法加时。');
        }

        $currentDeadline = $session->deadline_at ? strtotime((string) $session->deadline_at) : null;
        if ($currentDeadline === null) {
            throw new \RuntimeException('当前会话没有可调整的截止时间。');
        }

        $newDeadline = date('Y-m-d H:i:s', $currentDeadline + ($extendMinutes * 60));
        Db::name('exam_sessions')
            ->where('id', (int) $session->id)
            ->update([
                'deadline_at' => $newDeadline,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        /** @var ExamSession|null $reloaded */
        $reloaded = ExamSession::find((int) $session->id);
        if ($reloaded === null) {
            throw new \RuntimeException('加时后会话加载失败。');
        }

        return $reloaded;
    }

    public function forceSubmitSession(
        Exam $exam,
        ExamSession $session,
        ?ExamResultService $examResultService = null,
        ?string $submittedAt = null
    ): array {
        if ((string) ($session->status ?? '') !== ExamSession::STATUS_IN_PROGRESS) {
            throw new \RuntimeException('当前会话不是作答中状态，无法强制收卷。');
        }

        $now = date('Y-m-d H:i:s');
        $submittedAt = $submittedAt ?: $now;

        Db::name('exam_sessions')
            ->where('id', (int) $session->id)
            ->update([
                'status' => ExamSession::STATUS_FORCED_SUBMITTED,
                'submitted_at' => $submittedAt,
                'last_saved_at' => $session->last_saved_at ? (string) $session->last_saved_at : $now,
                'updated_at' => $now,
            ]);

        /** @var ExamSession|null $reloaded */
        $reloaded = ExamSession::find((int) $session->id);
        if ($reloaded === null) {
            throw new \RuntimeException('强制收卷后会话加载失败。');
        }

        $student = Student::find((int) $session->student_id);
        if (!$student instanceof Student) {
            throw new \RuntimeException('当前会话对应考生不存在。');
        }

        $examResultService = $examResultService ?: app(ExamResultService::class);
        $result = $examResultService->generateFromSession($exam, $student, $reloaded);

        return [
            'session' => $this->sessionPayload($reloaded, $exam),
            'result' => $result,
        ];
    }

    public function finalizeTimedOutSessions(
        int $limit = 500,
        ?ExamResultService $examResultService = null
    ): array {
        $limit = max(1, min($limit, 1000));
        $examResultService = $examResultService ?: app(ExamResultService::class);

        $scannedInProgress = 0;
        $scannedTimedOutPending = 0;
        $timedOut = 0;
        $resultsGenerated = 0;
        $skipped = 0;
        $errors = [];

        $inProgressSessions = ExamSession::where('status', ExamSession::STATUS_IN_PROGRESS)
            ->order('id asc')
            ->limit($limit)
            ->select();

        foreach ($inProgressSessions as $session) {
            if (!$session instanceof ExamSession) {
                continue;
            }

            $scannedInProgress++;

            /** @var Exam|null $exam */
            $exam = Exam::find((int) $session->exam_id);
            if (!$exam instanceof Exam) {
                $skipped++;
                $errors[] = '会话 #' . (int) $session->id . ' 对应考试不存在。';
                continue;
            }

            if (!$this->isSessionExpired($exam, $session)) {
                continue;
            }

            $wasTimedOut = $this->markSessionTimedOut($session, $this->effectiveDeadlineAt($exam, $session));
            /** @var ExamSession|null $reloaded */
            $reloaded = ExamSession::find((int) $session->id);
            if (!$reloaded instanceof ExamSession) {
                $skipped++;
                $errors[] = '会话 #' . (int) $session->id . ' 超时收卷后重新加载失败。';
                continue;
            }

            if ((string) ($reloaded->status ?? '') !== ExamSession::STATUS_TIMEOUT_SUBMITTED) {
                continue;
            }

            if ($wasTimedOut) {
                $timedOut++;
            }

            try {
                if ($this->generateTimedOutSessionResult($reloaded, $exam, $examResultService)) {
                    $resultsGenerated++;
                }
            } catch (\RuntimeException $exception) {
                $skipped++;
                $errors[] = '会话 #' . (int) $reloaded->id . ' 生成超时成绩失败：' . $exception->getMessage();
            }
        }

        $repairLimit = max($limit - $scannedInProgress, 0);
        if ($repairLimit > 0) {
            $timedOutSessions = ExamSession::where('status', ExamSession::STATUS_TIMEOUT_SUBMITTED)
                ->order('id asc')
                ->limit(max($repairLimit * 3, $repairLimit))
                ->select();

            foreach ($timedOutSessions as $session) {
                if (!$session instanceof ExamSession) {
                    continue;
                }

                if ($scannedTimedOutPending >= $repairLimit) {
                    break;
                }

                if ($this->resultExistsForSession((int) $session->id)) {
                    continue;
                }

                $scannedTimedOutPending++;

                try {
                    if ($this->generateTimedOutSessionResult($session, null, $examResultService)) {
                        $resultsGenerated++;
                    }
                } catch (\RuntimeException $exception) {
                    $skipped++;
                    $errors[] = '会话 #' . (int) $session->id . ' 修复超时成绩失败：' . $exception->getMessage();
                }
            }
        }

        return [
            'scanned_in_progress' => $scannedInProgress,
            'scanned_timeout_pending' => $scannedTimedOutPending,
            'timed_out' => $timedOut,
            'results_generated' => $resultsGenerated,
            'skipped' => $skipped,
            'limit' => $limit,
            'finished_at' => date('Y-m-d H:i:s'),
            'errors' => array_slice($errors, 0, 10),
        ];
    }

    protected function persistAnswersToSession(
        Exam $exam,
        ExamSession $session,
        array $answers,
        ?int $lastQuestionId,
        bool $enforceDeadline = true
    ): array {
        if ($enforceDeadline && $this->isSessionExpired($exam, $session)) {
            $this->markSessionTimedOut($session, $this->effectiveDeadlineAt($exam, $session));
            throw new \RuntimeException('当前作答会话已超时并自动交卷。');
        }

        $questionMap = Db::name('exam_session_questions')
            ->where('session_id', (int) $session->id)
            ->column('question_type', 'question_id');

        if ($questionMap === []) {
            throw new \RuntimeException('当前作答会话缺少题目快照。');
        }

        $savedQuestionIds = [];
        $now = date('Y-m-d H:i:s');

        Db::transaction(function () use ($session, $answers, $lastQuestionId, $questionMap, $now, &$savedQuestionIds): void {
            foreach ($answers as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $questionId = (int) ($item['question_id'] ?? 0);
                if ($questionId <= 0 || !isset($questionMap[$questionId])) {
                    continue;
                }

                $answer = $this->normalizeAnswerByType((string) $questionMap[$questionId], $item['answer'] ?? null);
                $answeredAt = $this->isAnswerProvided($answer) ? $now : null;
                $encodedAnswer = json_encode($answer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                $existing = Db::name('exam_answers')
                    ->where('session_id', (int) $session->id)
                    ->where('question_id', $questionId)
                    ->find();

                if (is_array($existing)) {
                    Db::name('exam_answers')
                        ->where('id', (int) $existing['id'])
                        ->update([
                            'answer_json' => $encodedAnswer,
                            'answered_at' => $answeredAt,
                            'updated_at' => $now,
                        ]);
                } else {
                    Db::name('exam_answers')->insert([
                        'session_id' => (int) $session->id,
                        'question_id' => $questionId,
                        'answer_json' => $encodedAnswer,
                        'answered_at' => $answeredAt,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                $savedQuestionIds[] = $questionId;
            }

            $updateData = [
                'last_saved_at' => $now,
                'updated_at' => $now,
            ];

            if ($lastQuestionId !== null && $lastQuestionId > 0 && isset($questionMap[$lastQuestionId])) {
                $updateData['last_question_id'] = $lastQuestionId;
            }

            Db::name('exam_sessions')
                ->where('id', (int) $session->id)
                ->update($updateData);
        });

        return $savedQuestionIds;
    }

    protected function normalizeAnswerByType(string $questionType, mixed $answer): mixed
    {
        return match ($questionType) {
            Question::TYPE_MULTIPLE => $this->normalizeStringArray($answer),
            Question::TYPE_BLANK => $this->normalizeBlankArray($answer),
            Question::TYPE_OPERATION => is_array($answer) ? $answer : (is_object($answer) ? (array) $answer : trim((string) $answer)),
            default => trim((string) $answer),
        };
    }

    protected function normalizeStringArray(mixed $answer): array
    {
        if (!is_array($answer)) {
            return [];
        }

        $normalized = [];
        foreach ($answer as $item) {
            $value = trim((string) $item);
            if ($value === '' || in_array($value, $normalized, true)) {
                continue;
            }

            $normalized[] = $value;
        }

        sort($normalized);

        return $normalized;
    }

    protected function normalizeBlankArray(mixed $answer): array
    {
        if (!is_array($answer)) {
            return [trim((string) $answer)];
        }

        return array_map(static fn ($item): string => trim((string) $item), $answer);
    }

    protected function isAnswerProvided(mixed $answer): bool
    {
        if (is_array($answer)) {
            foreach ($answer as $item) {
                if (is_array($item) && $item !== []) {
                    return true;
                }

                if (trim((string) $item) !== '') {
                    return true;
                }
            }

            return false;
        }

        return trim((string) $answer) !== '';
    }

    protected function resolveSessionQuestions(array $paperPayload): array
    {
        $questions = isset($paperPayload['session_questions']) && is_array($paperPayload['session_questions'])
            ? $paperPayload['session_questions']
            : (isset($paperPayload['questions']) && is_array($paperPayload['questions']) ? $paperPayload['questions'] : []);

        return array_values(array_filter($questions, static fn ($item): bool => is_array($item)));
    }

    protected function loadOwnedSession(int $sessionId, Exam $exam, Student $student): ExamSession
    {
        /** @var ExamSession|null $session */
        $session = ExamSession::where('id', $sessionId)
            ->where('exam_id', (int) $exam->id)
            ->where('student_id', (int) $student->id)
            ->find();

        if ($session === null) {
            throw new \RuntimeException('作答会话不存在。');
        }

        return $session;
    }

    protected function countAttempts(Exam $exam, Student $student): int
    {
        return (int) ExamSession::where('exam_id', (int) $exam->id)
            ->where('student_id', (int) $student->id)
            ->count();
    }

    protected function closeExpiredSessions(Exam $exam, Student $student): void
    {
        $now = date('Y-m-d H:i:s');
        $nowTimestamp = time();

        $activeSessions = ExamSession::where('exam_id', (int) $exam->id)
            ->where('student_id', (int) $student->id)
            ->where('status', ExamSession::STATUS_IN_PROGRESS)
            ->select();

        foreach ($activeSessions as $session) {
            if ($session instanceof ExamSession && $this->isSessionExpired($exam, $session, $nowTimestamp)) {
                $this->markSessionTimedOut($session, $this->effectiveDeadlineAt($exam, $session), $now);
            }
        }
    }

    protected function markSessionTimedOut(ExamSession $session, ?string $submittedAt = null, ?string $now = null): bool
    {
        $now = $now ?: date('Y-m-d H:i:s');
        $submittedAt = $submittedAt ?: ($session->deadline_at ? (string) $session->deadline_at : $now);
        $lastSavedAt = $session->last_saved_at ? (string) $session->last_saved_at : $now;

        $affected = Db::name('exam_sessions')
            ->where('id', (int) $session->id)
            ->where('status', ExamSession::STATUS_IN_PROGRESS)
            ->update([
                'status' => ExamSession::STATUS_TIMEOUT_SUBMITTED,
                'submitted_at' => $submittedAt,
                'last_saved_at' => $lastSavedAt,
                'updated_at' => $now,
            ]);

        return $affected > 0;
    }

    protected function canContinueSession(Exam $exam, ExamSession $session): bool
    {
        return $this->continueSessionState($exam, $session)['can_enter'];
    }

    protected function continueSessionState(Exam $exam, ExamSession $session): array
    {
        if ((int) ($exam->status ?? 0) !== 1) {
            return [
                'can_enter' => false,
                'reason' => 'exam_disabled',
                'message' => '当前考试已停用。',
            ];
        }

        $now = time();
        $startedAt = $exam->started_at ? strtotime((string) $exam->started_at) : null;

        if ($startedAt !== null && $startedAt > $now) {
            return [
                'can_enter' => false,
                'reason' => 'not_started',
                'message' => '考试未开始',
            ];
        }

        if ($this->isSessionExpired($exam, $session, $now)) {
            return [
                'can_enter' => false,
                'reason' => 'session_timeout',
                'message' => '当前作答会话已超时。',
            ];
        }

        return [
            'can_enter' => true,
            'reason' => 'active_session',
            'message' => null,
        ];
    }

    protected function effectiveDeadlineAt(Exam $exam, ExamSession $session): ?string
    {
        $timestamp = $this->effectiveDeadlineTimestamp($exam, $session);

        return $timestamp !== null ? date('Y-m-d H:i:s', $timestamp) : null;
    }

    protected function sessionRemainingSeconds(ExamSession $session, ?Exam $exam = null): ?int
    {
        $deadlineTimestamp = $exam instanceof Exam
            ? $this->effectiveDeadlineTimestamp($exam, $session)
            : ($session->deadline_at ? strtotime((string) $session->deadline_at) : null);

        if ($deadlineTimestamp === null) {
            return null;
        }

        return max($deadlineTimestamp - time(), 0);
    }

    protected function effectiveDeadlineTimestamp(Exam $exam, ExamSession $session): ?int
    {
        $sessionDeadline = $session->deadline_at ? strtotime((string) $session->deadline_at) : null;
        $endedAt = $exam->ended_at ? strtotime((string) $exam->ended_at) : null;
        $deadlineStrategy = (string) ($exam->deadline_strategy ?? 'force_close');

        if ($deadlineStrategy === 'continue_until_duration') {
            return $sessionDeadline ?? $endedAt;
        }

        if ($sessionDeadline === null) {
            return $endedAt;
        }

        if ($endedAt === null) {
            return $sessionDeadline;
        }

        return min($sessionDeadline, $endedAt);
    }

    protected function isSessionExpired(Exam $exam, ExamSession $session, ?int $now = null): bool
    {
        $deadlineTimestamp = $this->effectiveDeadlineTimestamp($exam, $session);
        if ($deadlineTimestamp === null) {
            return false;
        }

        return $deadlineTimestamp <= ($now ?? time());
    }

    protected function canFinalizeTimedOutSession(ExamSession $session): bool
    {
        $submittedAt = $session->submitted_at ? strtotime((string) $session->submitted_at) : null;
        if ($submittedAt === null) {
            return false;
        }

        return $submittedAt + self::TIMEOUT_FINALIZE_GRACE_SECONDS >= time();
    }

    protected function resultExistsForSession(int $sessionId): bool
    {
        return Db::name('exam_results')
            ->where('session_id', $sessionId)
            ->count() > 0;
    }

    protected function generateTimedOutSessionResult(
        ExamSession $session,
        ?Exam $exam,
        ExamResultService $examResultService
    ): bool {
        if ($this->resultExistsForSession((int) $session->id)) {
            return false;
        }

        if (!$exam instanceof Exam) {
            /** @var Exam|null $loadedExam */
            $loadedExam = Exam::find((int) $session->exam_id);
            $exam = $loadedExam;
        }

        if (!$exam instanceof Exam) {
            throw new \RuntimeException('对应考试不存在。');
        }

        /** @var Student|null $student */
        $student = Student::find((int) $session->student_id);
        if (!$student instanceof Student) {
            throw new \RuntimeException('对应考生不存在。');
        }

        $examResultService->generateFromSession($exam, $student, $session);

        return true;
    }
}

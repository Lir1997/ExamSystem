<?php

declare(strict_types=1);

namespace app\service;

use app\model\Exam;
use app\model\ExamMonitorLog;
use app\model\ExamSession;
use app\model\Student;
use think\facade\Db;

class ExamIntegrityService
{
    public const SOURCE_EXAM_CLIENT = 'exam_client';
    public const SOURCE_MONITOR = 'monitor';
    public const SOURCE_SYSTEM = 'system';

    public const LOG_VISIBILITY_HIDDEN = 'visibility_hidden';
    public const LOG_WINDOW_BLUR = 'window_blur';
    public const LOG_FULLSCREEN_EXIT = 'fullscreen_exit';
    public const LOG_THRESHOLD_ACTION = 'focus_threshold_action';
    public const LOG_MONITOR_WARNING = 'monitor_warning';
    public const LOG_MONITOR_DEDUCT_SCORE = 'monitor_deduct_score';
    public const LOG_MONITOR_ZERO_SCORE = 'monitor_zero_score';
    public const LOG_MONITOR_FORCE_SUBMIT = 'monitor_force_submit';
    public const LOG_EXTEND_TIME = 'extend_time';
    public const LOG_FORCE_SUBMIT = 'force_submit';
    public const LOG_BULK_EXTEND_TIME = 'bulk_extend_time';
    public const LOG_BULK_FORCE_SUBMIT = 'bulk_force_submit';
    public const LOG_EXAM_LOGIN = 'exam_login';
    public const LOG_ANSWER_SAVED = 'answer_saved';
    public const LOG_EXAM_SUBMIT = 'exam_submit';
    public const LOG_OPERATION_RESULT_UPLOAD = 'operation_result_upload';

    public const ACTION_NONE = 'none';
    public const ACTION_WARNING = 'warning';
    public const ACTION_DEDUCT_SCORE = 'deduct_score';
    public const ACTION_ZERO_SCORE = 'zero_score';
    public const ACTION_FORCE_SUBMIT = 'force_submit';

    protected const DUPLICATE_WINDOW_SECONDS = 8;

    public function reportFocusEvent(Exam $exam, Student $student, array $payload): array
    {
        $sessionId = (int) ($payload['session_id'] ?? 0);
        $eventType = trim((string) ($payload['event_type'] ?? ''));

        if (!in_array($eventType, [
            self::LOG_VISIBILITY_HIDDEN,
            self::LOG_WINDOW_BLUR,
            self::LOG_FULLSCREEN_EXIT,
        ], true)) {
            throw new \RuntimeException('异常类型不正确。');
        }

        $session = $this->loadOwnedActiveSession($exam, $student, $sessionId);
        if ($this->isDuplicateFocusEvent((int) $session->id, $eventType)) {
            return [
                'session' => app(ExamSessionService::class)->sessionPayload($session, $exam),
                'focus_loss_count' => (int) ($session->focus_loss_count ?? 0),
                'penalty_score' => (int) ($session->penalty_score ?? 0),
                'force_zero_score' => (int) ($session->force_zero_score ?? 0),
                'action' => null,
                'warning' => null,
                'ignored' => true,
            ];
        }

        $now = date('Y-m-d H:i:s');

        Db::name('exam_sessions')
            ->where('id', (int) $session->id)
            ->update([
                'focus_loss_count' => Db::raw('focus_loss_count + 1'),
                'last_focus_loss_at' => $now,
                'updated_at' => $now,
            ]);

        /** @var ExamSession|null $reloaded */
        $reloaded = ExamSession::find((int) $session->id);
        if (!$reloaded instanceof ExamSession) {
            throw new \RuntimeException('异常会话刷新失败。');
        }

        $count = (int) ($reloaded->focus_loss_count ?? 0);
        $this->createLog([
            'exam_id' => (int) $exam->id,
            'session_id' => (int) $reloaded->id,
            'student_id' => (int) $student->id,
            'source' => self::SOURCE_EXAM_CLIENT,
            'log_type' => $eventType,
            'severity' => $count > 0 ? 'warning' : 'info',
            'action_type' => null,
            'action_value' => 0,
            'note' => $this->focusEventNote($eventType, $count),
            'payload' => [
                'focus_loss_count' => $count,
                'last_question_id' => isset($payload['last_question_id']) ? (int) $payload['last_question_id'] : null,
            ],
        ]);

        $action = $this->applyConfiguredThresholdAction($exam, $reloaded, $student, $count, $now);
        /** @var ExamSession|null $finalSession */
        $finalSession = ExamSession::find((int) $reloaded->id);
        if ($finalSession instanceof ExamSession) {
            $reloaded = $finalSession;
        }

        return [
            'session' => app(ExamSessionService::class)->sessionPayload($reloaded, $exam),
            'focus_loss_count' => (int) ($reloaded->focus_loss_count ?? 0),
            'penalty_score' => (int) ($reloaded->penalty_score ?? 0),
            'force_zero_score' => (int) ($reloaded->force_zero_score ?? 0),
            'action' => $action,
            'warning' => $this->buildFocusWarningPayload($exam, $eventType, $count, $action),
            'ignored' => false,
        ];
    }

    public function recordMonitorViolation(Exam $exam, ExamSession $session, array $payload): array
    {
        $actionType = trim((string) ($payload['action_type'] ?? ''));
        $deductScore = max((int) ($payload['deduct_score'] ?? 0), 0);
        $note = $this->sanitizeNote($payload['note'] ?? null);

        if (!in_array($actionType, [
            self::ACTION_WARNING,
            self::ACTION_DEDUCT_SCORE,
            self::ACTION_ZERO_SCORE,
            self::ACTION_FORCE_SUBMIT,
        ], true)) {
            throw new \RuntimeException('作弊处理方式不正确。');
        }

        if ($actionType === self::ACTION_DEDUCT_SCORE && $deductScore <= 0) {
            throw new \RuntimeException('扣分分值必须大于 0。');
        }

        $now = date('Y-m-d H:i:s');
        $forceSubmitResult = null;

        if ($actionType === self::ACTION_DEDUCT_SCORE) {
            Db::name('exam_sessions')
                ->where('id', (int) $session->id)
                ->update([
                    'penalty_score' => Db::raw('penalty_score + ' . $deductScore),
                    'updated_at' => $now,
                ]);
        } elseif ($actionType === self::ACTION_ZERO_SCORE) {
            Db::name('exam_sessions')
                ->where('id', (int) $session->id)
                ->update([
                    'force_zero_score' => 1,
                    'updated_at' => $now,
                ]);
            $forceSubmitResult = app(ExamSessionService::class)->forceSubmitSession($exam, $session);
        } elseif ($actionType === self::ACTION_FORCE_SUBMIT) {
            $forceSubmitResult = app(ExamSessionService::class)->forceSubmitSession($exam, $session);
        }

        /** @var ExamSession|null $reloaded */
        $reloaded = ExamSession::find((int) $session->id);
        if (!$reloaded instanceof ExamSession) {
            throw new \RuntimeException('作弊处理后会话刷新失败。');
        }

        $logType = match ($actionType) {
            self::ACTION_WARNING => self::LOG_MONITOR_WARNING,
            self::ACTION_DEDUCT_SCORE => self::LOG_MONITOR_DEDUCT_SCORE,
            self::ACTION_ZERO_SCORE => self::LOG_MONITOR_ZERO_SCORE,
            self::ACTION_FORCE_SUBMIT => self::LOG_MONITOR_FORCE_SUBMIT,
            default => self::LOG_MONITOR_WARNING,
        };

        $this->createLog([
            'exam_id' => (int) $exam->id,
            'session_id' => (int) $reloaded->id,
            'student_id' => (int) $reloaded->student_id,
            'source' => self::SOURCE_MONITOR,
            'log_type' => $logType,
            'severity' => $actionType === self::ACTION_WARNING ? 'warning' : 'danger',
            'action_type' => $actionType,
            'action_value' => $actionType === self::ACTION_DEDUCT_SCORE ? $deductScore : 0,
            'note' => $note ?: $this->manualViolationDefaultNote($actionType, $deductScore),
            'payload' => [
                'penalty_score' => (int) ($reloaded->penalty_score ?? 0),
                'force_zero_score' => (int) ($reloaded->force_zero_score ?? 0),
                'status' => (string) ($reloaded->status ?? ''),
            ],
        ]);

        return [
            'session' => app(ExamSessionService::class)->sessionPayload($reloaded, $exam),
            'action' => [
                'type' => $actionType,
                'deduct_score' => $deductScore,
                'note' => $note,
                'message' => $this->manualViolationMessage($actionType, $deductScore),
            ],
            'result' => $forceSubmitResult['result'] ?? null,
        ];
    }

    public function logMonitorOperation(
        Exam $exam,
        ?ExamSession $session,
        int $studentId,
        string $logType,
        string $note,
        array $payload = []
    ): void {
        $this->createLog([
            'exam_id' => (int) $exam->id,
            'session_id' => $session?->id ? (int) $session->id : null,
            'student_id' => $studentId > 0 ? $studentId : null,
            'source' => self::SOURCE_MONITOR,
            'log_type' => $logType,
            'severity' => 'info',
            'action_type' => null,
            'action_value' => 0,
            'note' => $note,
            'payload' => $payload,
        ]);
    }

    public function logExamOperation(
        Exam $exam,
        ?ExamSession $session,
        ?Student $student,
        string $logType,
        string $note,
        array $payload = []
    ): void {
        $this->createLog([
            'exam_id' => (int) $exam->id,
            'session_id' => $session?->id ? (int) $session->id : null,
            'student_id' => $student?->id ? (int) $student->id : null,
            'source' => self::SOURCE_EXAM_CLIENT,
            'log_type' => $logType,
            'severity' => 'info',
            'action_type' => null,
            'action_value' => 0,
            'note' => $note,
            'payload' => $payload,
        ]);
    }

    public function recentLogsForExam(Exam $exam, int $limit = 40): array
    {
        return $this->recentLogsForExamId((int) $exam->id, [], $limit);
    }

    public function recentLogsForExamId(int $examId, array $filters = [], int $limit = 40): array
    {
        $limit = max(1, min($limit, 200));

        $query = Db::name('exam_monitor_logs')
            ->alias('l')
            ->leftJoin('students s', 's.id = l.student_id')
            ->field([
                'l.id',
                'l.exam_id',
                'l.session_id',
                'l.student_id',
                'l.source',
                'l.log_type',
                'l.severity',
                'l.action_type',
                'l.action_value',
                'l.note',
                'l.payload_json',
                'l.created_at',
                's.name' => 'student_name',
                's.username' => 'student_username',
                's.student_no' => 'student_no',
            ])
            ->where('l.exam_id', $examId);

        $sessionId = (int) ($filters['session_id'] ?? 0);
        if ($sessionId > 0) {
            $query->where('l.session_id', $sessionId);
        }

        $studentId = (int) ($filters['student_id'] ?? 0);
        if ($studentId > 0) {
            $query->where('l.student_id', $studentId);
        }

        $rows = $query
            ->order('l.id desc')
            ->limit($limit)
            ->select()
            ->toArray();

        return array_map(function (array $row): array {
            return [
                'id' => (int) ($row['id'] ?? 0),
                'session_id' => isset($row['session_id']) ? (int) $row['session_id'] : null,
                'student_id' => isset($row['student_id']) ? (int) $row['student_id'] : null,
                'student_name' => (string) ($row['student_name'] ?? ''),
                'student_username' => (string) ($row['student_username'] ?? ''),
                'student_no' => (string) ($row['student_no'] ?? ''),
                'source' => (string) ($row['source'] ?? ''),
                'source_label' => $this->sourceLabel((string) ($row['source'] ?? '')),
                'log_type' => (string) ($row['log_type'] ?? ''),
                'log_type_label' => $this->logTypeLabel((string) ($row['log_type'] ?? '')),
                'severity' => (string) ($row['severity'] ?? 'info'),
                'action_type' => $this->nullableString($row['action_type'] ?? null),
                'action_value' => (int) ($row['action_value'] ?? 0),
                'note' => $this->nullableString($row['note'] ?? null),
                'payload' => $this->decodePayload($row['payload_json'] ?? null),
                'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : null,
            ];
        }, $rows);
    }

    protected function applyConfiguredThresholdAction(
        Exam $exam,
        ExamSession $session,
        Student $student,
        int $count,
        string $now
    ): ?array {
        if (!$this->isFocusMonitoringEnabled($exam)) {
            return null;
        }

        $limit = max((int) ($exam->focus_loss_limit ?? 0), 0);
        if ($limit <= 0 || $count < $limit) {
            return null;
        }

        if ((int) ($session->focus_loss_action_applied ?? 0) === 1) {
            return null;
        }

        $actionType = trim((string) ($exam->focus_loss_action ?? self::ACTION_NONE));
        $deductScore = max((int) ($exam->focus_loss_deduct_score ?? 0), 0);
        $message = '异常次数已达到阈值。';

        if ($actionType === self::ACTION_FORCE_SUBMIT) {
            app(ExamSessionService::class)->forceSubmitSession($exam, $session);
            Db::name('exam_sessions')
                ->where('id', (int) $session->id)
                ->update([
                    'focus_loss_action_applied' => 1,
                    'updated_at' => $now,
                ]);
            $message = '异常次数已达到阈值，系统已强制收卷。';
        } elseif ($actionType === self::ACTION_ZERO_SCORE) {
            Db::name('exam_sessions')
                ->where('id', (int) $session->id)
                ->update([
                    'focus_loss_action_applied' => 1,
                    'force_zero_score' => 1,
                    'updated_at' => $now,
                ]);
            app(ExamSessionService::class)->forceSubmitSession($exam, $session);
            $message = '异常次数已达到阈值，本次考试已按 0 分处理并强制交卷。';
        } elseif ($actionType === self::ACTION_DEDUCT_SCORE && $deductScore > 0) {
            Db::name('exam_sessions')
                ->where('id', (int) $session->id)
                ->update([
                    'focus_loss_action_applied' => 1,
                    'penalty_score' => Db::raw('penalty_score + ' . $deductScore),
                    'updated_at' => $now,
                ]);
            $message = '异常次数已达到阈值，系统已自动扣分 ' . $deductScore . ' 分。';
        } elseif ($actionType === self::ACTION_NONE) {
            Db::name('exam_sessions')
                ->where('id', (int) $session->id)
                ->update([
                    'focus_loss_action_applied' => 1,
                    'updated_at' => $now,
                ]);
            $message = '异常次数已达到阈值，当前配置仅记录异常，不自动处罚。';
        } else {
            return null;
        }

        $this->createLog([
            'exam_id' => (int) $exam->id,
            'session_id' => (int) $session->id,
            'student_id' => (int) $student->id,
            'source' => self::SOURCE_SYSTEM,
            'log_type' => self::LOG_THRESHOLD_ACTION,
            'severity' => $actionType === self::ACTION_NONE ? 'warning' : 'danger',
            'action_type' => $actionType,
            'action_value' => $actionType === self::ACTION_DEDUCT_SCORE ? $deductScore : 0,
            'note' => $message,
            'payload' => [
                'focus_loss_count' => $count,
                'focus_loss_limit' => $limit,
            ],
        ]);

        return [
            'type' => $actionType,
            'deduct_score' => $actionType === self::ACTION_DEDUCT_SCORE ? $deductScore : 0,
            'message' => $message,
        ];
    }

    protected function loadOwnedActiveSession(Exam $exam, Student $student, int $sessionId): ExamSession
    {
        /** @var ExamSession|null $session */
        $session = ExamSession::where('id', $sessionId)
            ->where('exam_id', (int) $exam->id)
            ->where('student_id', (int) $student->id)
            ->where('status', ExamSession::STATUS_IN_PROGRESS)
            ->find();

        if (!$session instanceof ExamSession) {
            throw new \RuntimeException('当前作答会话不存在或已结束。');
        }

        return $session;
    }

    protected function isDuplicateFocusEvent(int $sessionId, string $eventType): bool
    {
        $row = Db::name('exam_monitor_logs')
            ->where('session_id', $sessionId)
            ->where('source', self::SOURCE_EXAM_CLIENT)
            ->where('log_type', $eventType)
            ->order('id desc')
            ->find();

        if (!is_array($row) || empty($row['created_at'])) {
            return false;
        }

        $createdAt = strtotime((string) $row['created_at']);
        if ($createdAt === false) {
            return false;
        }

        return $createdAt + self::DUPLICATE_WINDOW_SECONDS >= time();
    }

    protected function createLog(array $data): void
    {
        ExamMonitorLog::create([
            'exam_id' => (int) ($data['exam_id'] ?? 0),
            'session_id' => isset($data['session_id']) ? (int) $data['session_id'] : null,
            'student_id' => isset($data['student_id']) ? (int) $data['student_id'] : null,
            'source' => (string) ($data['source'] ?? self::SOURCE_SYSTEM),
            'log_type' => (string) ($data['log_type'] ?? ''),
            'severity' => (string) ($data['severity'] ?? 'info'),
            'action_type' => $this->nullableString($data['action_type'] ?? null),
            'action_value' => (int) ($data['action_value'] ?? 0),
            'note' => $this->sanitizeNote($data['note'] ?? null),
            'payload_json' => $this->encodePayload($data['payload'] ?? []),
        ]);
    }

    protected function sourceLabel(string $value): string
    {
        return match ($value) {
            self::SOURCE_EXAM_CLIENT => '考试端',
            self::SOURCE_MONITOR => '监考端',
            default => '系统',
        };
    }

    protected function logTypeLabel(string $value): string
    {
        return match ($value) {
            self::LOG_VISIBILITY_HIDDEN => '页面切到后台',
            self::LOG_WINDOW_BLUR => '窗口失焦',
            self::LOG_FULLSCREEN_EXIT => '退出全屏',
            self::LOG_THRESHOLD_ACTION => '达到异常阈值',
            self::LOG_MONITOR_WARNING => '监考记录异常',
            self::LOG_MONITOR_DEDUCT_SCORE => '监考扣分',
            self::LOG_MONITOR_ZERO_SCORE => '监考记 0 分',
            self::LOG_MONITOR_FORCE_SUBMIT => '监考强制收卷',
            self::LOG_EXTEND_TIME => '临时加时',
            self::LOG_FORCE_SUBMIT => '强制收卷',
            self::LOG_BULK_EXTEND_TIME => '批量加时',
            self::LOG_BULK_FORCE_SUBMIT => '批量强制收卷',
            default => $value,
        };
    }

    protected function focusEventNote(string $eventType, int $count): string
    {
        $prefix = match ($eventType) {
            self::LOG_VISIBILITY_HIDDEN => '考试端检测到页面切到后台。',
            self::LOG_WINDOW_BLUR => '考试端检测到窗口失焦。',
            self::LOG_FULLSCREEN_EXIT => '考试端检测到退出全屏。',
            default => '考试端检测到异常行为。',
        };

        return $prefix . ' 当前累计异常 ' . $count . ' 次。';
    }

    protected function manualViolationDefaultNote(string $actionType, int $deductScore): string
    {
        return match ($actionType) {
            self::ACTION_WARNING => '监考端记录考生异常行为。',
            self::ACTION_DEDUCT_SCORE => '监考端对考生执行扣分 ' . $deductScore . ' 分。',
            self::ACTION_ZERO_SCORE => '监考端将本次考试记为 0 分并立即强制交卷。',
            self::ACTION_FORCE_SUBMIT => '监考端对考生执行强制收卷。',
            default => '监考端执行异常处理。',
        };
    }

    protected function manualViolationMessage(string $actionType, int $deductScore): string
    {
        return match ($actionType) {
            self::ACTION_WARNING => '已记录考生异常行为。',
            self::ACTION_DEDUCT_SCORE => '已对考生扣分 ' . $deductScore . ' 分。',
            self::ACTION_ZERO_SCORE => '本次考试已按 0 分处理并强制交卷。',
            self::ACTION_FORCE_SUBMIT => '已对考生执行强制收卷。',
            default => '作弊处理已完成。',
        };
    }

    protected function sanitizeNote(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, 1000);
        }

        return substr($value, 0, 1000);
    }

    protected function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    protected function encodePayload(array $payload): ?string
    {
        if ($payload === []) {
            return null;
        }

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    protected function decodePayload(mixed $payload): array
    {
        if (!is_string($payload) || trim($payload) === '') {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function isFocusMonitoringEnabled(Exam $exam): bool
    {
        return (int) ($exam->auto_fullscreen ?? 0) === 1 || (int) ($exam->enable_focus_monitor ?? 0) === 1;
    }

    protected function buildFocusWarningPayload(Exam $exam, string $eventType, int $count, ?array $action): ?array
    {
        if (!$this->isFocusMonitoringEnabled($exam)) {
            return null;
        }

        $limit = max((int) ($exam->focus_loss_limit ?? 0), 0);
        $actionType = trim((string) ($exam->focus_loss_action ?? self::ACTION_NONE));
        $deductScore = max((int) ($exam->focus_loss_deduct_score ?? 0), 0);
        $triggered = $action !== null;
        $eventLabel = $this->focusEventLabel($eventType);
        $actionLabel = $this->focusActionDisplayLabel($actionType, $deductScore);

        if ($limit > 0) {
            $message = sprintf(
                '系统检测到您存在“%s”行为，当前累计 %d 次。累计达到 %d 次时，将%s。',
                $eventLabel,
                $count,
                $limit,
                $actionLabel
            );
        } else {
            $message = sprintf(
                '系统检测到您存在“%s”行为，当前累计 %d 次。你的本次违规行为已被记录，请规范答题。',
                $eventLabel,
                $count
            );
        }

        if ($triggered && isset($action['message'])) {
            $message .= ' ' . (string) $action['message'];
        }

        return [
            'title' => $triggered ? '违规处理提醒' : '违规行为提醒',
            'message' => $message,
            'count' => $count,
            'limit' => $limit,
            'event_label' => $eventLabel,
            'action_label' => $actionLabel,
            'triggered' => $triggered,
        ];
    }

    protected function focusEventLabel(string $eventType): string
    {
        return match ($eventType) {
            self::LOG_VISIBILITY_HIDDEN => '切换到其他页面',
            self::LOG_WINDOW_BLUR => '切换到其他窗口',
            self::LOG_FULLSCREEN_EXIT => '退出全屏',
            default => '违规操作',
        };
    }

    protected function focusActionDisplayLabel(string $actionType, int $deductScore): string
    {
        return match ($actionType) {
            self::ACTION_DEDUCT_SCORE => '自动扣除 ' . $deductScore . ' 分',
            self::ACTION_ZERO_SCORE => '按零分处理本次考试并立即强制交卷',
            self::ACTION_FORCE_SUBMIT => '立即强制交卷',
            default => '记录本次违规行为，并提醒您规范答题',
        };
    }
}

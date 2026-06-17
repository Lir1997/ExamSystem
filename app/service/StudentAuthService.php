<?php

declare(strict_types=1);

namespace app\service;

use app\model\Exam;
use app\model\Paper;
use app\model\Student;
use app\model\StudentAccessToken;
use think\facade\Db;

class StudentAuthService
{
    public const TOKEN_TTL_SECONDS = 14400;
    public const TOKEN_REFRESH_THRESHOLD_SECONDS = 3600;

    public function attempt(string $account, string $credential): ?array
    {
        $settings = app(SystemSettingService::class)->all();
        $loginMode = (string) ($settings['student_login_mode'] ?? 'username_password');

        /** @var Student|null $student */
        $student = match ($loginMode) {
            'student_no_password', 'student_no_id_card' => Student::where('student_no', $account)->find(),
            default => Student::where('username', $account)->find(),
        };

        if ($student === null || (int) $student->status !== 1) {
            return null;
        }

        if ($loginMode === 'student_no_id_card') {
            $idCard = trim((string) ($student->id_card ?? ''));
            if ($idCard === '' || $credential !== $idCard) {
                return null;
            }
        } else {
            if (!password_verify($credential, (string) $student->password)) {
                return null;
            }
        }

        $issuedAt = time();
        $plainToken = hash('sha256', $account . '|' . $issuedAt . '|' . bin2hex(random_bytes(16)));
        $tokenHash = hash('sha256', $plainToken);
        $studentId = (int) $student->id;
        $requestIp = request()->ip();

        Db::transaction(function () use ($studentId, $tokenHash, $issuedAt, $requestIp): void {
            StudentAccessToken::where('student_id', $studentId)->delete();

            $table = $this->studentAccessTokenTable();
            $expiresAt = date('Y-m-d H:i:s', $issuedAt + self::TOKEN_TTL_SECONDS);
            $lastUsedAt = date('Y-m-d H:i:s', $issuedAt);

            Db::execute(
                "INSERT INTO `{$table}` (`student_id`, `token`, `expires_at`, `last_used_at`, `last_used_ip`) VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    `token` = VALUES(`token`),
                    `expires_at` = VALUES(`expires_at`),
                    `last_used_at` = VALUES(`last_used_at`),
                    `last_used_ip` = VALUES(`last_used_ip`)",
                [$studentId, $tokenHash, $expiresAt, $lastUsedAt, $requestIp]
            );
        });

        return [
            'token' => $plainToken,
            'student' => [
                'id' => $studentId,
                'username' => (string) $student->username,
                'student_no' => (string) $student->student_no,
                'name' => (string) $student->name,
            ],
            'expires_in' => self::TOKEN_TTL_SECONDS,
            'issued_at' => $issuedAt,
        ];
    }

    public function availableExams(Student $student): array
    {
        $settings = app(SystemSettingService::class)->all();
        $visibleBeforeHours = max((int) ($settings['exam_visible_before_hours'] ?? 0), 0);
        $visibleAfterHours = max((int) ($settings['exam_visible_after_hours'] ?? 0), 0);

        $examIds = app(ExamScopeService::class)->examIdsForStudent((int) $student->id);

        if ($examIds === []) {
            return [];
        }

        $examSessionService = app(ExamSessionService::class);
        $examResultService = app(ExamResultService::class);

        $exams = Exam::whereIn('id', $examIds)
            ->where('status', 1)
            ->order('id asc')
            ->select();

        $resultMap = $examResultService->latestResultMapForStudent($student, $examIds);

        $items = [];

        foreach ($exams as $exam) {
            if (!$exam instanceof Exam) {
                continue;
            }

            if (!$this->isExamVisibleInList($exam, $visibleBeforeHours, $visibleAfterHours)) {
                continue;
            }

            $accessState = $examSessionService->accessState($exam, $student);
            if (!$this->shouldShowExamInList($exam, $accessState)) {
                continue;
            }

            $latestResult = $resultMap[(int) $exam->id] ?? null;
            $scoreSummary = null;
            if ($latestResult !== null && (int) ($exam->allow_view_score ?? 0) === 1) {
                $scoreSummary = $examResultService->studentVisibleSummary($exam, $latestResult);
            }

            $activeSession = $examSessionService->findActiveSession($exam, $student);
            $sessionProgress = $activeSession instanceof \app\model\ExamSession
                ? $examSessionService->sessionProgressSummary($exam, $activeSession)
                : null;

            $paperClientRequirement = null;
            $paperId = $exam->paper_id !== null ? (int) $exam->paper_id : 0;
            if ($paperId > 0) {
                /** @var Paper|null $paper */
                $paper = Paper::find($paperId);
                if ($paper instanceof Paper) {
                    $paperClientRequirement = (string) ($paper->client_requirement ?? 'unrestricted');
                }
            }

            $items[] = [
                'id' => (int) $exam->id,
                'title' => (string) $exam->title,
                'intro_html' => (string) ($exam->intro_html ?? ''),
                'enable_notice' => (int) ($exam->enable_notice ?? 0),
                'notice_html' => (string) ($exam->notice_html ?? ''),
                'paper_id' => $exam->paper_id !== null ? (int) $exam->paper_id : null,
                'paper_client_requirement' => $paperClientRequirement,
                'status' => (int) ($exam->status ?? 0),
                'started_at' => $exam->started_at ? (string) $exam->started_at : null,
                'ended_at' => $exam->ended_at ? (string) $exam->ended_at : null,
                'can_enter' => (bool) ($accessState['can_enter'] ?? false),
                'access_reason' => isset($accessState['reason']) ? (string) $accessState['reason'] : null,
                'access_message' => isset($accessState['message']) && $accessState['message'] !== null
                    ? (string) $accessState['message']
                    : null,
                'score_summary' => $scoreSummary,
                'session_progress' => $sessionProgress,
            ];
        }

        return $items;
    }

    protected function shouldShowExamInList(Exam $exam, array $accessState): bool
    {
        if ((bool) ($accessState['can_enter'] ?? false)) {
            return true;
        }

        return in_array((string) ($accessState['reason'] ?? ''), [
            'attempt_limit_reached',
            'not_started',
            'exam_ended',
            'exam_completed',
            'exam_absent',
        ], true);
    }

    protected function isExamVisibleInList(Exam $exam, int $beforeHours, int $afterHours): bool
    {
        $now = time();
        $startedAt = $exam->started_at ? strtotime((string) $exam->started_at) : null;
        $endedAt = $exam->ended_at ? strtotime((string) $exam->ended_at) : null;

        if ($startedAt !== null && $beforeHours >= 0) {
            $visibleFrom = $startedAt - ($beforeHours * 3600);
            if ($now < $visibleFrom) {
                return false;
            }
        }

        if ($endedAt !== null && $afterHours >= 0) {
            $visibleUntil = $endedAt + ($afterHours * 3600);
            if ($now > $visibleUntil) {
                return false;
            }
        }

        return true;
    }

    public function loginMode(): string
    {
        $settings = app(SystemSettingService::class)->all();
        return (string) ($settings['student_login_mode'] ?? 'username_password');
    }

    protected function studentAccessTokenTable(): string
    {
        $defaultConnection = (string) config('database.default', 'mysql');
        $prefix = (string) config('database.connections.' . $defaultConnection . '.prefix', '');

        return $prefix . 'student_access_tokens';
    }
}

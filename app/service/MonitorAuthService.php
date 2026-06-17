<?php

declare(strict_types=1);

namespace app\service;

use app\model\AdminUser;
use app\model\Exam;
use app\model\MonitorAccessToken;
use app\model\MonitorBridgeToken;
use RuntimeException;
use think\Request;
use think\facade\Db;

class MonitorAuthService
{
    public const ACCESS_TOKEN_TTL_SECONDS = 7200;
    public const ACCESS_TOKEN_REFRESH_THRESHOLD_SECONDS = 1800;
    protected const BRIDGE_TOKEN_TTL_SECONDS = 120;
    protected const PASSWORD_LENGTH = 6;
    protected const CREDENTIAL_LENGTH = 6;
    protected const PASSWORD_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    protected const CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public function ensureCredentials(Exam $exam): array
    {
        $needsRefresh = trim((string) ($exam->exam_code ?? '')) === ''
            || trim((string) ($exam->monitor_password_hash ?? '')) === ''
            || trim((string) ($exam->monitor_password_ciphertext ?? '')) === ''
            || trim((string) ($exam->monitor_password_iv ?? '')) === '';

        if ($needsRefresh) {
            return $this->regenerateCredentials($exam);
        }

        $plainPassword = $this->decryptPassword(
            (string) $exam->monitor_password_ciphertext,
            (string) $exam->monitor_password_iv
        );

        $examCode = strtoupper(trim((string) ($exam->exam_code ?? '')));
        $plainPassword = strtoupper(trim((string) $plainPassword));

        if (
            $plainPassword === ''
            || !$this->isValidCredentialCode($examCode)
            || !$this->isValidPassword($plainPassword)
        ) {
            return $this->regenerateCredentials($exam);
        }

        if (strtoupper(trim((string) ($exam->monitor_slug ?? ''))) !== $examCode) {
            $exam->monitor_slug = $examCode;
            $exam->save();
        }

        return [
            'exam_code' => $examCode,
            'monitor_password' => $plainPassword,
        ];
    }

    public function regenerateCredentials(Exam $exam): array
    {
        $examCode = $this->generateUniqueCredentialCode();
        $plainPassword = $this->generatePassword();
        $encrypted = $this->encryptPassword($plainPassword);

        $exam->exam_code = $examCode;
        $exam->monitor_slug = $examCode;
        $exam->monitor_password_hash = password_hash($plainPassword, PASSWORD_DEFAULT);
        $exam->monitor_password_ciphertext = $encrypted['ciphertext'];
        $exam->monitor_password_iv = $encrypted['iv'];
        $exam->save();

        return [
            'exam_code' => $examCode,
            'monitor_password' => $plainPassword,
        ];
    }

    public function loginByExamCode(string $examCode, string $password, Request $request): ?array
    {
        /** @var Exam|null $exam */
        $exam = Exam::where('exam_code', strtoupper($examCode))->find();
        if ($exam === null) {
            return null;
        }

        return $this->loginForExam($exam, $password, $request);
    }

    public function createBridgeUrl(Exam $exam, AdminUser $admin, Request $request): string
    {
        $credentials = $this->ensureCredentials($exam);
        $issuedAt = time();
        $plainToken = hash('sha256', 'monitor-bridge|' . $exam->id . '|' . $admin->id . '|' . $issuedAt . '|' . bin2hex(random_bytes(16)));
        $tokenHash = hash('sha256', $plainToken);

        MonitorBridgeToken::create([
            'exam_id' => (int) $exam->id,
            'admin_user_id' => (int) $admin->id,
            'token' => $tokenHash,
            'expires_at' => date('Y-m-d H:i:s', $issuedAt + self::BRIDGE_TOKEN_TTL_SECONDS),
        ]);

        return $this->buildMonitorUrl($request, (string) $credentials['exam_code'], [
            'bridge' => $plainToken,
        ]);
    }

    public function consumeBridgeToken(string $plainToken, Request $request): ?array
    {
        $tokenHash = hash('sha256', trim($plainToken));

        /** @var MonitorBridgeToken|null $record */
        $record = MonitorBridgeToken::where('token', $tokenHash)->find();
        if ($record === null) {
            return null;
        }

        if ($record->used_at !== null) {
            return null;
        }

        if ($record->expires_at !== null && strtotime((string) $record->expires_at) < time()) {
            return null;
        }

        /** @var Exam|null $exam */
        $exam = Exam::find((int) $record->exam_id);
        if ($exam === null) {
            return null;
        }

        $record->used_at = date('Y-m-d H:i:s');
        $record->used_ip = $request->ip();
        $record->save();

        return $this->issueAccessPayload($exam, $request, (int) ($record->admin_user_id ?? 0) ?: null);
    }

    public function currentExamFromToken(string $plainToken): ?Exam
    {
        $tokenHash = hash('sha256', trim($plainToken));

        /** @var MonitorAccessToken|null $record */
        $record = MonitorAccessToken::where('token', $tokenHash)->find();
        if ($record === null || $record->revoked_at !== null) {
            return null;
        }

        if ($record->expires_at !== null && strtotime((string) $record->expires_at) < time()) {
            return null;
        }

        /** @var Exam|null $exam */
        $exam = Exam::find((int) $record->exam_id);
        if ($exam === null) {
            return null;
        }

        return $exam;
    }

    public function touchAccessToken(string $plainToken, Request $request): ?Exam
    {
        $tokenHash = hash('sha256', trim($plainToken));

        /** @var MonitorAccessToken|null $record */
        $record = MonitorAccessToken::where('token', $tokenHash)->find();
        if ($record === null || $record->revoked_at !== null) {
            return null;
        }

        if ($record->expires_at !== null && strtotime((string) $record->expires_at) < time()) {
            return null;
        }

        /** @var Exam|null $exam */
        $exam = Exam::find((int) $record->exam_id);
        if ($exam === null) {
            return null;
        }

        $record->last_used_at = date('Y-m-d H:i:s');
        $record->last_used_ip = $request->ip();

        $expiresAt = $record->expires_at ? strtotime((string) $record->expires_at) : null;
        if ($expiresAt !== null && ($expiresAt - time()) <= self::ACCESS_TOKEN_REFRESH_THRESHOLD_SECONDS) {
            $record->expires_at = date('Y-m-d H:i:s', time() + self::ACCESS_TOKEN_TTL_SECONDS);
        }

        $record->save();

        return $exam;
    }

    public function credentialsPayload(Exam $exam, Request $request): array
    {
        $credentials = $this->ensureCredentials($exam);
        $monitorPath = '/monitor/' . $credentials['exam_code'];

        return [
            'exam_id' => (int) $exam->id,
            'exam_title' => (string) $exam->title,
            'exam_code' => $credentials['exam_code'],
            'monitor_password' => $credentials['monitor_password'],
            'started_at' => $exam->started_at ? (string) $exam->started_at : null,
            'ended_at' => $exam->ended_at ? (string) $exam->ended_at : null,
            'attempt_limit' => (int) ($exam->attempt_limit ?? 1),
            'deadline_strategy' => (string) ($exam->deadline_strategy ?? 'force_close'),
            'monitor_url' => $this->buildMonitorUrl($request, $credentials['exam_code']),
            'monitor_path' => $monitorPath,
            'monitor_root_url' => $this->buildMonitorRootUrl($request),
        ];
    }

    public function examOverview(Exam $exam): array
    {
        $integrityService = app(ExamIntegrityService::class);
        $studentIds = app(ExamScopeService::class)->studentIdsForExam((int) $exam->id);
        $studentRows = [];
        if ($studentIds !== []) {
            $studentRows = Db::name('students')
                ->whereIn('id', $studentIds)
                ->where('status', 1)
                ->field(['id', 'username', 'student_no', 'name'])
                ->order('id asc')
                ->select()
                ->toArray();
        }

        $sessions = Db::name('exam_sessions')
            ->where('exam_id', (int) $exam->id)
            ->order('id desc')
            ->select()
            ->toArray();

        $latestSessionMap = [];
        foreach ($sessions as $session) {
            $studentId = (int) ($session['student_id'] ?? 0);
            if ($studentId <= 0 || isset($latestSessionMap[$studentId])) {
                continue;
            }

            $latestSessionMap[$studentId] = $session;
        }

        $items = [];
        $counts = [
            'total' => count($studentRows),
            'not_started' => 0,
            'in_progress' => 0,
            'submitted' => 0,
            'timeout_submitted' => 0,
            'violations' => 0,
        ];

        foreach ($studentRows as $student) {
            $studentId = (int) ($student['id'] ?? 0);
            $session = $latestSessionMap[$studentId] ?? null;
            $status = is_array($session) ? (string) ($session['status'] ?? 'not_started') : 'not_started';
            $violationCount = is_array($session) ? (int) ($session['focus_loss_count'] ?? 0) : 0;
            $penaltyScore = is_array($session) ? (int) ($session['penalty_score'] ?? 0) : 0;
            $forceZeroScore = is_array($session) ? (int) ($session['force_zero_score'] ?? 0) : 0;

            if (!isset($counts[$status])) {
                $counts[$status] = 0;
            }
            $counts[$status]++;
            if ($violationCount > 0) {
                $counts['violations']++;
            }

            $items[] = [
                'id' => $studentId,
                'username' => (string) ($student['username'] ?? ''),
                'student_no' => (string) ($student['student_no'] ?? ''),
                'name' => (string) ($student['name'] ?? ''),
                'status' => $status,
                'attempt_no' => is_array($session) ? (int) ($session['attempt_no'] ?? 0) : 0,
                'focus_loss_count' => $violationCount,
                'penalty_score' => $penaltyScore,
                'force_zero_score' => $forceZeroScore,
                'started_at' => is_array($session) && isset($session['started_at']) ? (string) $session['started_at'] : null,
                'deadline_at' => is_array($session) && isset($session['deadline_at']) ? (string) $session['deadline_at'] : null,
                'submitted_at' => is_array($session) && isset($session['submitted_at']) ? (string) $session['submitted_at'] : null,
                'last_saved_at' => is_array($session) && isset($session['last_saved_at']) ? (string) $session['last_saved_at'] : null,
            ];
        }

        return [
            'exam' => [
                'id' => (int) $exam->id,
                'title' => (string) $exam->title,
                'exam_code' => strtoupper((string) ($exam->exam_code ?? '')),
                'started_at' => $exam->started_at ? (string) $exam->started_at : null,
                'ended_at' => $exam->ended_at ? (string) $exam->ended_at : null,
                'status' => (int) ($exam->status ?? 0),
                'auto_fullscreen' => (int) ($exam->auto_fullscreen ?? 0),
                'enable_focus_monitor' => (int) ($exam->enable_focus_monitor ?? 0),
                'focus_loss_limit' => (int) ($exam->focus_loss_limit ?? 0),
                'focus_loss_action' => (string) ($exam->focus_loss_action ?? 'none'),
            ],
            'counts' => $counts,
            'students' => $items,
            'recent_logs' => $integrityService->recentLogsForExam($exam, 30),
        ];
    }

    protected function loginForExam(Exam $exam, string $password, Request $request): ?array
    {
        $this->ensureCredentials($exam);

        if ((int) ($exam->status ?? 0) !== 1) {
            return null;
        }

        $hash = trim((string) ($exam->monitor_password_hash ?? ''));
        $normalizedPassword = strtoupper(trim($password));
        if ($hash === '' || !password_verify($normalizedPassword, $hash)) {
            return null;
        }

        return $this->issueAccessPayload($exam, $request, null);
    }

    protected function issueAccessPayload(Exam $exam, Request $request, ?int $issuedByAdminId): array
    {
        $issuedAt = time();
        $plainToken = hash('sha256', 'monitor|' . $exam->id . '|' . $issuedAt . '|' . bin2hex(random_bytes(16)));
        $tokenHash = hash('sha256', $plainToken);

        MonitorAccessToken::create([
            'exam_id' => (int) $exam->id,
            'issued_by_admin_id' => $issuedByAdminId,
            'token' => $tokenHash,
            'expires_at' => date('Y-m-d H:i:s', $issuedAt + self::ACCESS_TOKEN_TTL_SECONDS),
            'last_used_at' => date('Y-m-d H:i:s', $issuedAt),
            'last_used_ip' => $request->ip(),
        ]);

        return [
            'token' => $plainToken,
            'exam' => [
                'id' => (int) $exam->id,
                'title' => (string) $exam->title,
                'exam_code' => strtoupper((string) ($exam->exam_code ?? '')),
            ],
            'expires_in' => self::ACCESS_TOKEN_TTL_SECONDS,
            'issued_at' => $issuedAt,
        ];
    }

    protected function buildMonitorUrl(Request $request, string $slug, array $query = []): string
    {
        $base = $this->detectOrigin($request);
        $path = '/monitor/' . rawurlencode($slug);

        if ($query === []) {
            return $base . $path;
        }

        return $base . $path . '?' . http_build_query($query);
    }

    protected function buildMonitorRootUrl(Request $request): string
    {
        return $this->detectOrigin($request) . '/monitor';
    }

    protected function detectOrigin(Request $request): string
    {
        $scheme = $request->scheme();
        $host = trim((string) $request->host(true));

        if ($host === '') {
            $host = trim((string) $request->server('HTTP_HOST', ''));
        }

        if ($host === '') {
            throw new RuntimeException('当前请求缺少有效域名，无法生成监考端地址。');
        }

        return $scheme . '://' . $host;
    }

    protected function generateUniqueCredentialCode(): string
    {
        do {
            $code = $this->randomFromAlphabet(self::CODE_ALPHABET, self::CREDENTIAL_LENGTH);
        } while (Exam::where('exam_code', $code)->count() > 0);

        return $code;
    }

    protected function generatePassword(): string
    {
        return $this->randomFromAlphabet(self::PASSWORD_ALPHABET, self::PASSWORD_LENGTH);
    }

    protected function randomFromAlphabet(string $alphabet, int $length): string
    {
        $result = '';
        $maxIndex = strlen($alphabet) - 1;

        for ($i = 0; $i < $length; $i++) {
            $result .= $alphabet[random_int(0, $maxIndex)];
        }

        return $result;
    }

    protected function encryptPassword(string $plainPassword): array
    {
        $appKey = $this->resolveAppKey();
        if ($appKey === '') {
            throw new RuntimeException('APP_KEY 未配置，无法加密监考密码。');
        }

        $cipher = 'AES-256-CBC';
        $key = hash('sha256', $appKey, true);
        $ivRaw = random_bytes(16);
        $ciphertext = openssl_encrypt($plainPassword, $cipher, $key, OPENSSL_RAW_DATA, $ivRaw);

        if (!is_string($ciphertext) || $ciphertext === '') {
            throw new RuntimeException('监考密码加密失败。');
        }

        return [
            'ciphertext' => base64_encode($ciphertext),
            'iv' => base64_encode($ivRaw),
        ];
    }

    protected function decryptPassword(string $ciphertext, string $iv): ?string
    {
        $appKey = $this->resolveAppKey();
        if ($appKey === '') {
            return null;
        }

        $cipherBytes = base64_decode($ciphertext, true);
        $ivBytes = base64_decode($iv, true);

        if (!is_string($cipherBytes) || !is_string($ivBytes) || $cipherBytes === '' || strlen($ivBytes) !== 16) {
            return null;
        }

        $plain = openssl_decrypt($cipherBytes, 'AES-256-CBC', hash('sha256', $appKey, true), OPENSSL_RAW_DATA, $ivBytes);

        return is_string($plain) && $plain !== '' ? $plain : null;
    }

    protected function isValidCredentialCode(string $value): bool
    {
        return preg_match('/^[2-9A-HJ-KMNPQRSTUVWXYZ]{6}$/', $value) === 1;
    }

    protected function isValidPassword(string $value): bool
    {
        return preg_match('/^[2-9A-HJ-KMNPQRSTUVWXYZ]{6}$/', $value) === 1;
    }

    protected function resolveAppKey(): string
    {
        $candidates = [
            trim((string) env('APP_KEY', '')),
            trim((string) env('app.app_key', '')),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== '') {
                return $candidate;
            }
        }

        $envFile = root_path() . '.env';
        if (!is_file($envFile)) {
            return '';
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return '';
        }

        $currentSection = '';

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
                continue;
            }

            if (preg_match('/^\[(.+)\]$/', $line, $sectionMatch) === 1) {
                $currentSection = strtoupper(trim($sectionMatch[1]));
                continue;
            }

            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }

            $key = strtoupper(trim(substr($line, 0, $pos)));
            $value = trim(substr($line, $pos + 1));
            $value = trim($value, " \t\n\r\0\x0B\"'");

            if ($key === 'APP_KEY' && ($currentSection === '' || $currentSection === 'APP')) {
                return $value;
            }
        }

        return '';
    }
}

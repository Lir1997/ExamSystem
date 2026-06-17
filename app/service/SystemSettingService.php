<?php

declare(strict_types=1);

namespace app\service;

use app\model\SystemSetting;

class SystemSettingService
{
    public function all(): array
    {
        $rows = SystemSetting::field(['setting_key', 'setting_value'])->select()->toArray();
        $settings = [];

        foreach ($rows as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }

        return [
            'site_name' => (string) ($settings['site_name'] ?? '在线考试系统'),
            'auth_mode' => (string) ($settings['auth_mode'] ?? 'bearer-token'),
            'frontend_admin_path' => (string) ($settings['frontend_admin_path'] ?? '/admin#/'),
            'frontend_exam_path' => (string) ($settings['frontend_exam_path'] ?? '/exam#/'),
            'student_login_mode' => (string) ($settings['student_login_mode'] ?? 'username_password'),
            'student_default_password' => (string) ($settings['student_default_password'] ?? 'student123'),
            'exam_visible_before_hours' => (int) ($settings['exam_visible_before_hours'] ?? 0),
            'exam_visible_after_hours' => (int) ($settings['exam_visible_after_hours'] ?? 0),
            'exam_finish_auto_logout' => (int) ($settings['exam_finish_auto_logout'] ?? 0),
            'exam_finish_message' => (string) ($settings['exam_finish_message'] ?? '您已完成考试，请根据监考人员要求有序离开考场。'),
            'exam_timeout_task_token' => $this->normalizeTaskToken((string) ($settings['exam_timeout_task_token'] ?? '')),
            'question_types' => [
                'single' => '单选题',
                'multiple' => '多选题',
                'judge' => '判断题',
                'blank' => '填空题',
                'short' => '简答题',
                'operation' => '操作题',
            ],
            'student_login_mode_options' => [
                'username_password' => '账号 + 密码',
                'student_no_password' => '学号 + 密码',
                'student_no_id_card' => '学号 + 身份证号',
            ],
        ];
    }

    public function save(array $payload): void
    {
        $allowedKeys = [
            'site_name',
            'auth_mode',
            'frontend_admin_path',
            'frontend_exam_path',
            'student_login_mode',
            'student_default_password',
            'exam_visible_before_hours',
            'exam_visible_after_hours',
            'exam_finish_auto_logout',
            'exam_finish_message',
        ];

        foreach ($allowedKeys as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }

            $record = SystemSetting::where('setting_key', $key)->find();
            if ($record === null) {
                $record = new SystemSetting();
                $record->setting_key = $key;
            }

            $record->setting_value = (string) $payload[$key];
            $record->save();
        }
    }

    public function ensureExamTimeoutTaskToken(): string
    {
        $record = SystemSetting::where('setting_key', 'exam_timeout_task_token')->find();
        $value = $record !== null ? $this->normalizeTaskToken((string) ($record->setting_value ?? '')) : '';

        if ($value !== '') {
            return $value;
        }

        return $this->regenerateExamTimeoutTaskToken();
    }

    public function regenerateExamTimeoutTaskToken(): string
    {
        $token = bin2hex(random_bytes(24));

        $record = SystemSetting::where('setting_key', 'exam_timeout_task_token')->find();
        if ($record === null) {
            $record = new SystemSetting();
            $record->setting_key = 'exam_timeout_task_token';
        }

        $record->setting_value = $token;
        $record->save();

        return $token;
    }

    protected function normalizeTaskToken(string $value): string
    {
        $value = trim($value);

        return preg_match('/^[a-f0-9]{48}$/', $value) === 1 ? $value : '';
    }
}

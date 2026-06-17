<?php

declare(strict_types=1);

namespace app\controller\exam;

use app\controller\BaseApiController;
use app\model\Exam;
use app\model\Student;
use app\service\ExamIntegrityService;
use app\service\StudentAuthService;
use think\Response;

class AuthController extends BaseApiController
{
    public function settings(StudentAuthService $studentAuthService): Response
    {
        return $this->success([
            'login_mode' => $studentAuthService->loginMode(),
        ], '获取考试端登录设置成功');
    }

    public function login(StudentAuthService $studentAuthService): Response
    {
        $payload = $this->payload();

        $account = trim((string) ($payload['account'] ?? ''));
        $credential = trim((string) ($payload['credential'] ?? ''));

        if ($account === '' || $credential === '') {
            return $this->error('请输入完整的登录信息', 422);
        }

        $result = $studentAuthService->attempt($account, $credential);
        if ($result === null) {
            return $this->error('登录信息不正确，请重新输入', 401);
        }

        /** @var Student|null $student */
        $student = Student::find((int) ($result['student']['id'] ?? 0));
        if ($student instanceof Student) {
            $examIds = array_map(static fn (array $item): int => (int) ($item['id'] ?? 0), $studentAuthService->availableExams($student));
            $exam = $examIds !== [] ? Exam::find($examIds[0]) : null;
            if ($exam instanceof Exam) {
                app(ExamIntegrityService::class)->logExamOperation(
                    $exam,
                    null,
                    $student,
                    ExamIntegrityService::LOG_EXAM_LOGIN,
                    '考试端登录成功',
                    [
                        'login_mode' => $studentAuthService->loginMode(),
                        'available_exam_count' => count($examIds),
                    ],
                );
            }
        }

        return $this->success(array_merge($result, [
            'login_mode' => $studentAuthService->loginMode(),
        ]), '登录成功');
    }

    public function profile(StudentAuthService $studentAuthService): Response
    {
        /** @var Student|null $student */
        $student = $this->request->studentUser ?? null;
        if ($student === null) {
            return $this->error('登录状态已失效，请重新登录', 401);
        }

        return $this->success([
            'student' => [
                'id' => (int) $student->id,
                'username' => (string) $student->username,
                'student_no' => (string) $student->student_no,
                'name' => (string) $student->name,
            ],
            'exams' => $studentAuthService->availableExams($student),
            'login_mode' => $studentAuthService->loginMode(),
        ], '获取学生资料成功');
    }
}

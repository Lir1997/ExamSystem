<?php

declare(strict_types=1);

namespace app\controller\exam;

use app\controller\BaseApiController;
use app\model\Exam;
use app\model\Student;
use app\service\ExamIntegrityService;
use think\Response;

class IntegrityController extends BaseApiController
{
    public function reportFocusEvent(int $examId, ExamIntegrityService $examIntegrityService): Response
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

        try {
            $result = $examIntegrityService->reportFocusEvent($exam, $student, $this->payload());
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return $this->success($result, '已记录考试异常。');
    }
}

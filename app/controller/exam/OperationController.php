<?php

declare(strict_types=1);

namespace app\controller\exam;

use app\controller\BaseApiController;
use app\model\Exam;
use app\model\ExamSession;
use app\model\Question;
use app\model\Student;
use app\service\ExamIntegrityService;
use app\service\ExamSessionService;
use think\facade\Filesystem;
use think\Response;

class OperationController extends BaseApiController
{
    public function detail(int $questionId): Response
    {
        $question = $this->loadOperationQuestion($questionId);
        if ($question instanceof Response) {
            return $question;
        }

        $payload = $this->decodePayload((string) ($question->payload_json ?? ''));
        unset($payload['reference_answer']);

        return $this->success([
            'question' => [
                'id' => (int) $question->id,
                'title' => (string) $question->title,
                'question_type' => (string) $question->question_type,
                'structure_code' => (string) $question->structure_code,
                'stem_html' => (string) ($question->stem_html ?? ''),
                'analysis_html' => '',
                'operation' => $payload,
            ],
        ], '获取操作题详情成功');
    }

    public function downloadMeta(int $questionId): Response
    {
        $question = $this->loadOperationQuestion($questionId);
        if ($question instanceof Response) {
            return $question;
        }

        $payload = $this->decodePayload((string) ($question->payload_json ?? ''));

        return $this->success([
            'package' => $payload['package'] ?? null,
            'requirement' => (string) ($payload['requirement'] ?? ''),
            'client_task' => $payload['client_task'] ?? null,
        ], '获取操作题下载信息成功');
    }

    public function uploadResult(int $questionId, ExamSessionService $examSessionService): Response
    {
        $question = $this->loadOperationQuestion($questionId);
        if ($question instanceof Response) {
            return $question;
        }

        /** @var Student|null $student */
        $student = $this->request->studentUser ?? null;
        if ($student === null) {
            return $this->error('未获取到当前学生账号。', 401);
        }

        $sessionId = (int) $this->request->post('session_id', 0);
        $examId = (int) $this->request->post('exam_id', 0);
        $lastQuestionId = (int) $this->request->post('last_question_id', 0);
        $answerText = trim((string) $this->request->post('answer_text', ''));
        $sourceFileName = trim((string) $this->request->post('source_file_name', ''));
        $file = $this->request->file('file');

        if ($sessionId <= 0) {
            return $this->error('作答会话不存在。', 422);
        }

        if ($examId <= 0) {
            return $this->error('考试 ID 无效。', 422);
        }

        if ($file === null) {
            return $this->error('未获取到上传文件。', 422);
        }

        /** @var Exam|null $exam */
        $exam = Exam::find($examId);
        if ($exam === null) {
            return $this->error('考试不存在。', 404);
        }

        $originalName = method_exists($file, 'getOriginalName') ? trim((string) $file->getOriginalName()) : '';
        $savedName = Filesystem::disk('public')->putFile('operation-results', $file);
        $fileUrl = '/storage/' . str_replace('\\', '/', $savedName);

        $answer = [
            'mode' => 'upload',
            'answer_text' => $answerText,
            'result_file' => [
                'url' => $fileUrl,
                'name' => $originalName !== '' ? $originalName : basename($savedName),
                'source_name' => $sourceFileName !== '' ? $sourceFileName : ($originalName !== '' ? $originalName : basename($savedName)),
                'stored_name' => basename($savedName),
                'uploaded_at' => date('Y-m-d H:i:s'),
            ],
        ];

        try {
            $saved = $examSessionService->saveAnswers($exam, $student, [
                'session_id' => $sessionId,
                'last_question_id' => $lastQuestionId > 0 ? $lastQuestionId : $questionId,
                'answers' => [[
                    'question_id' => (int) $question->id,
                    'answer' => $answer,
                ]],
            ]);
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        $savedQuestionIds = isset($saved['saved_question_ids']) && is_array($saved['saved_question_ids'])
            ? array_map('intval', $saved['saved_question_ids'])
            : [];
        if (!in_array((int) $question->id, $savedQuestionIds, true)) {
            return $this->error('当前操作题不在本次考试会话中。', 422);
        }

        /** @var ExamSession|null $session */
        $session = ExamSession::find($sessionId);
        app(ExamIntegrityService::class)->logExamOperation(
            $exam,
            $session,
            $student,
            ExamIntegrityService::LOG_OPERATION_RESULT_UPLOAD,
            '上传操作题结果文件',
            [
                'question_id' => (int) $question->id,
                'source_file_name' => $sourceFileName !== '' ? $sourceFileName : ($originalName !== '' ? $originalName : basename($savedName)),
            ],
        );

        return $this->success([
            'question_id' => (int) $question->id,
            'answer' => $answer,
            'saved_question_ids' => $savedQuestionIds,
            'session' => $saved['session'] ?? null,
        ], '操作题结果上传成功');
    }

    protected function loadOperationQuestion(int $questionId): Question|Response
    {
        if ($questionId <= 0) {
            return $this->error('操作题 ID 无效', 422);
        }

        /** @var Question|null $question */
        $question = Question::find($questionId);
        if ($question === null || (string) $question->question_type !== Question::TYPE_OPERATION) {
            return $this->error('操作题不存在', 404);
        }

        return $question;
    }

    protected function decodePayload(string $payloadJson): array
    {
        if (trim($payloadJson) === '') {
            return [];
        }

        $decoded = json_decode($payloadJson, true);

        return is_array($decoded) ? $decoded : [];
    }
}

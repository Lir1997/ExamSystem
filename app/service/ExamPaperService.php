<?php

declare(strict_types=1);

namespace app\service;

use app\model\Exam;
use app\model\ExamSession;
use app\model\Paper;
use app\model\Question;
use app\model\Student;
use think\facade\Db;

class ExamPaperService
{
    public function buildExamPaper(Exam $exam, Student $student): array
    {
        $this->assertExamIsOpen($exam);

        $paperId = (int) ($exam->paper_id ?? 0);
        if ($paperId <= 0) {
            throw new \RuntimeException('Current exam has no linked paper.');
        }

        /** @var Paper|null $paper */
        $paper = Paper::find($paperId);
        if ($paper === null || (int) ($paper->status ?? 0) !== 1) {
            throw new \RuntimeException('The linked paper is unavailable.');
        }

        $config = $this->decodeConfig((string) ($paper->config_json ?? ''));
        $mode = (string) ($config['mode'] ?? '');

        $randomizeOptions = (int) ($paper->randomize_options ?? 0) === 1;

        if ($mode === 'fixed') {
            $questions = $this->buildFixedQuestions($config);
        } elseif ($mode === 'random') {
            $questions = $this->buildRandomQuestions($config);
        } else {
            throw new \RuntimeException('The paper configuration is incomplete.');
        }

        if ($randomizeOptions) {
            $questions = $this->randomizeQuestionOptions($questions);
        }

        $questions = $this->orderQuestionsForDisplay(
            $questions,
            $mode,
            (int) ($paper->randomize_questions ?? 0)
        );

        return $this->buildPayload($exam, $paper, $student, $config, $mode, $questions);
    }

    public function buildExamPaperFromSession(Exam $exam, Student $student, ExamSession $session): array
    {
        $this->assertSessionCanContinue($exam, $session);

        $paperId = (int) (($session->paper_id ?? 0) ?: ($exam->paper_id ?? 0));
        if ($paperId <= 0) {
            throw new \RuntimeException('Current exam session has no linked paper.');
        }

        /** @var Paper|null $paper */
        $paper = Paper::find($paperId);
        if ($paper === null || (int) ($paper->status ?? 0) !== 1) {
            throw new \RuntimeException('The linked paper is unavailable.');
        }

        $questions = $this->loadSessionQuestions((int) $session->id);
        if ($questions === []) {
            throw new \RuntimeException('No question snapshot was found for this session.');
        }

        $config = $this->decodeConfig((string) ($paper->config_json ?? ''));
        $mode = (string) ($config['mode'] ?? '');

        return $this->buildPayload($exam, $paper, $student, $config, $mode, $questions);
    }

    protected function buildPayload(
        Exam $exam,
        Paper $paper,
        Student $student,
        array $config,
        string $mode,
        array $questions
    ): array {
        $examQuestions = $this->sanitizeQuestionsForExam($questions);
        $groupedQuestions = $this->groupQuestionsByType($examQuestions);

        return [
            'exam' => [
                'id' => (int) $exam->id,
                'title' => (string) $exam->title,
                'paper_id' => (int) $paper->id,
                'status' => (int) ($exam->status ?? 0),
                'started_at' => $exam->started_at ? (string) $exam->started_at : null,
                'ended_at' => $exam->ended_at ? (string) $exam->ended_at : null,
                'attempt_limit' => (int) ($exam->attempt_limit ?? 1),
                'deadline_strategy' => (string) ($exam->deadline_strategy ?? 'force_close'),
                'allow_view_score' => (int) ($exam->allow_view_score ?? 0),
                'allow_view_paper' => (int) ($exam->allow_view_paper ?? 0),
                'allow_view_analysis' => (int) ($exam->allow_view_analysis ?? 0),
                'show_question_score' => (int) ($exam->show_question_score ?? 1),
                'show_question_difficulty' => (int) ($exam->show_question_difficulty ?? 1),
                'auto_fullscreen' => (int) ($exam->auto_fullscreen ?? 0),
                'enable_focus_monitor' => (int) ($exam->enable_focus_monitor ?? 0),
                'focus_loss_limit' => $this->resolvedFocusLossLimit($exam),
                'focus_loss_action' => $this->resolvedFocusLossAction($exam),
                'focus_loss_deduct_score' => $this->resolvedFocusLossDeductScore($exam),
            ],
            'paper' => [
                'id' => (int) $paper->id,
                'title' => (string) $paper->title,
                'mode' => $mode,
                'client_requirement' => (string) ($paper->client_requirement ?? 'unrestricted'),
                'duration_minutes' => (int) ($config['duration_minutes'] ?? 0),
                'total_score' => (int) ($paper->total_score ?? 0),
                'randomize_questions' => (int) ($paper->randomize_questions ?? 0),
                'randomize_options' => (int) ($paper->randomize_options ?? 0),
            ],
            'student' => [
                'id' => (int) $student->id,
                'username' => (string) $student->username,
                'student_no' => (string) ($student->student_no ?? ''),
                'name' => (string) $student->name,
            ],
            'session_questions' => $questions,
            'question_groups' => $groupedQuestions,
            'questions' => $examQuestions,
        ];
    }

    protected function buildFixedQuestions(array $config): array
    {
        $typeScoreRules = is_array($config['type_score_rules'] ?? null) ? $config['type_score_rules'] : [];
        $questionItems = is_array($config['question_items'] ?? null) ? $config['question_items'] : [];

        if ($questionItems === []) {
            throw new \RuntimeException('No questions were configured for this fixed paper.');
        }

        $questionIds = [];
        foreach ($questionItems as $item) {
            $questionId = (int) ($item['question_id'] ?? 0);
            if ($questionId > 0) {
                $questionIds[] = $questionId;
            }
        }

        if ($questionIds === []) {
            throw new \RuntimeException('No valid questions were found in the paper configuration.');
        }

        $rows = Db::name('questions')
            ->whereIn('id', $questionIds)
            ->where('status', 1)
            ->orderRaw('field(id,' . implode(',', array_map('intval', $questionIds)) . ')')
            ->select()
            ->toArray();

        $questionMap = [];
        foreach ($rows as $row) {
            $questionMap[(int) $row['id']] = $row;
        }

        $questions = [];
        foreach ($questionItems as $item) {
            $questionId = (int) ($item['question_id'] ?? 0);
            $question = $questionMap[$questionId] ?? null;
            if ($question === null) {
                continue;
            }

            $questionType = (string) ($question['question_type'] ?? '');
            $customScore = $this->toPositiveIntOrNull($item['score'] ?? null);
            $defaultScore = $this->toPositiveIntOrNull($typeScoreRules[$questionType] ?? null);
            $score = $customScore ?? $defaultScore;

            if ($score === null) {
                throw new \RuntimeException('A fixed-paper question is missing a valid score.');
            }

            $questions[] = $this->formatQuestion($question, $score);
        }

        return $questions;
    }

    protected function buildRandomQuestions(array $config): array
    {
        $globalTypeScores = is_array($config['global_type_scores'] ?? null) ? $config['global_type_scores'] : [];
        $typeRules = is_array($config['type_rules'] ?? null) ? $config['type_rules'] : [];

        if ($typeRules === []) {
            throw new \RuntimeException('No random-paper rules were configured.');
        }

        $questions = [];

        foreach ($typeRules as $typeRule) {
            $questionType = (string) ($typeRule['question_type'] ?? '');
            if (!array_key_exists($questionType, Question::TYPE_LABELS)) {
                continue;
            }

            $categoryRules = is_array($typeRule['category_rules'] ?? null) ? $typeRule['category_rules'] : [];
            foreach ($categoryRules as $categoryRule) {
                $categoryId = (int) ($categoryRule['category_id'] ?? 0);
                if ($categoryId <= 0) {
                    continue;
                }

                foreach (['easy', 'medium', 'hard'] as $difficulty) {
                    $count = (int) ($categoryRule[$difficulty . '_count'] ?? 0);
                    if ($count <= 0) {
                        continue;
                    }

                    $score = $this->toPositiveIntOrNull($categoryRule[$difficulty . '_score'] ?? null)
                        ?? $this->toPositiveIntOrNull($globalTypeScores[$questionType] ?? null);

                    if ($score === null) {
                        throw new \RuntimeException('A random-paper rule is missing a valid score.');
                    }

                    $pickedQuestions = Db::name('questions')
                        ->where('status', 1)
                        ->where('question_type', $questionType)
                        ->where('category_id', $categoryId)
                        ->where('difficulty_level', $difficulty)
                        ->orderRaw('rand()')
                        ->limit($count)
                        ->select()
                        ->toArray();

                    if (count($pickedQuestions) < $count) {
                        $categoryName = Db::name('question_categories')->where('id', $categoryId)->value('name');
                        $difficultyLabel = $this->difficultyLabel($difficulty);
                        $questionTypeLabel = Question::TYPE_LABELS[$questionType] ?? $questionType;

                        throw new \RuntimeException(sprintf(
                            '%s / %s / %s question count is insufficient.',
                            $questionTypeLabel,
                            (string) ($categoryName ?: $categoryId),
                            $difficultyLabel
                        ));
                    }

                    foreach ($pickedQuestions as $question) {
                        $questions[] = $this->formatQuestion($question, $score);
                    }
                }
            }
        }

        if ($questions === []) {
            throw new \RuntimeException('No questions were picked for the random paper.');
        }

        return $questions;
    }

    protected function assertExamIsOpen(Exam $exam): void
    {
        if ((int) ($exam->status ?? 0) !== 1) {
            throw new \RuntimeException('The exam is disabled.');
        }

        $now = time();
        $startedAt = $exam->started_at ? strtotime((string) $exam->started_at) : null;
        $endedAt = $exam->ended_at ? strtotime((string) $exam->ended_at) : null;

        if ($startedAt !== null && $startedAt > $now) {
            throw new \RuntimeException('The exam has not started yet.');
        }

        if ($endedAt !== null && $endedAt < $now) {
            throw new \RuntimeException('The exam has ended.');
        }
    }

    protected function assertSessionCanContinue(Exam $exam, ExamSession $session): void
    {
        if ((int) ($exam->status ?? 0) !== 1) {
            throw new \RuntimeException('The exam is disabled.');
        }

        $now = time();
        $startedAt = $exam->started_at ? strtotime((string) $exam->started_at) : null;
        $endedAt = $exam->ended_at ? strtotime((string) $exam->ended_at) : null;
        $sessionDeadline = $session->deadline_at ? strtotime((string) $session->deadline_at) : null;

        if ($startedAt !== null && $startedAt > $now) {
            throw new \RuntimeException('The exam has not started yet.');
        }

        if ($sessionDeadline !== null && $sessionDeadline < $now) {
            throw new \RuntimeException('The session time has expired.');
        }

        if ($endedAt !== null && $endedAt < $now && (string) ($exam->deadline_strategy ?? 'force_close') !== 'continue_until_duration') {
            throw new \RuntimeException('The exam has ended.');
        }
    }

    protected function formatQuestion(array $question, int $score): array
    {
        return [
            'id' => (int) $question['id'],
            'title' => (string) $question['title'],
            'question_type' => (string) $question['question_type'],
            'question_type_label' => Question::TYPE_LABELS[(string) $question['question_type']] ?? (string) $question['question_type'],
            'category_id' => isset($question['category_id']) ? (int) $question['category_id'] : null,
            'difficulty_level' => (string) ($question['difficulty_level'] ?? ''),
            'difficulty_label' => $this->difficultyLabel((string) ($question['difficulty_level'] ?? '')),
            'stem_html' => (string) ($question['stem_html'] ?? ''),
            'analysis_html' => (string) ($question['analysis_html'] ?? ''),
            'payload' => $this->decodePayload((string) ($question['payload_json'] ?? '')),
            'score' => $score,
        ];
    }

    protected function randomizeQuestionOptions(array $questions): array
    {
        foreach ($questions as &$question) {
            $questionType = (string) ($question['question_type'] ?? '');
            if (!in_array($questionType, [Question::TYPE_SINGLE, Question::TYPE_MULTIPLE], true)) {
                continue;
            }

            $payload = is_array($question['payload'] ?? null) ? $question['payload'] : [];
            $options = $this->normalizeQuestionOptions($payload['options'] ?? null);
            if (count($options) <= 1) {
                continue;
            }

            $slotKeys = array_values(array_map(
                static fn (array $option): string => (string) ($option['key'] ?? ''),
                $options
            ));
            $shuffledOptions = $options;
            shuffle($shuffledOptions);

            $randomizedOptions = [];
            $answerKeyMap = [];

            foreach ($slotKeys as $index => $slotKey) {
                $sourceOption = $shuffledOptions[$index] ?? null;
                if (!is_array($sourceOption)) {
                    continue;
                }

                $sourceKey = trim((string) ($sourceOption['key'] ?? ''));
                $randomizedOptions[] = [
                    'key' => $slotKey,
                    'content' => (string) ($sourceOption['content'] ?? ''),
                ];

                if ($sourceKey !== '') {
                    $answerKeyMap[$sourceKey] = $slotKey;
                }
            }

            $payload['options'] = $randomizedOptions;
            $payload['answer'] = $this->remapChoiceAnswer($questionType, $payload['answer'] ?? null, $answerKeyMap);
            $question['payload'] = $payload;
        }
        unset($question);

        return $questions;
    }

    protected function normalizeQuestionOptions(mixed $options): array
    {
        if (!is_array($options)) {
            return [];
        }

        $normalized = [];
        foreach ($options as $option) {
            if (!is_array($option)) {
                continue;
            }

            $key = trim((string) ($option['key'] ?? ''));
            $content = (string) ($option['content'] ?? '');
            if ($key === '' && trim($content) === '') {
                continue;
            }

            $normalized[] = [
                'key' => $key,
                'content' => $content,
            ];
        }

        return $normalized;
    }

    protected function remapChoiceAnswer(string $questionType, mixed $answer, array $answerKeyMap): mixed
    {
        if ($questionType === Question::TYPE_SINGLE) {
            $answerKey = trim((string) $answer);
            if ($answerKey === '') {
                return $answer;
            }

            return $answerKeyMap[$answerKey] ?? $answerKey;
        }

        if ($questionType !== Question::TYPE_MULTIPLE) {
            return $answer;
        }

        $answerKeys = [];

        if (is_array($answer)) {
            foreach ($answer as $item) {
                $answerKey = trim((string) $item);
                if ($answerKey !== '' && !in_array($answerKey, $answerKeys, true)) {
                    $answerKeys[] = $answerKey;
                }
            }
        } elseif (is_string($answer)) {
            $text = strtoupper(trim($answer));
            if ($text !== '') {
                $parts = preg_match('/^[A-Z]+$/', $text) === 1
                    ? str_split($text)
                    : (preg_split('/[|,，、\/\s]+/u', $text) ?: []);

                foreach ($parts as $part) {
                    $answerKey = strtoupper(trim((string) $part));
                    if ($answerKey !== '' && !in_array($answerKey, $answerKeys, true)) {
                        $answerKeys[] = $answerKey;
                    }
                }
            }
        }

        $remapped = [];
        foreach ($answerKeys as $answerKey) {
            $mappedKey = $answerKeyMap[$answerKey] ?? $answerKey;
            if ($mappedKey !== '' && !in_array($mappedKey, $remapped, true)) {
                $remapped[] = $mappedKey;
            }
        }

        return $remapped;
    }

    protected function orderQuestionsForDisplay(array $questions, string $mode, int $randomizeQuestions): array
    {
        if ($mode === 'fixed') {
            if ($randomizeQuestions === 1) {
                shuffle($questions);
            }

            return $this->assignDisplayOrders($questions);
        }

        if ($mode === 'random') {
            $questions = $this->orderRandomQuestionsByType($questions, $randomizeQuestions === 1);
        }

        return $this->assignDisplayOrders($questions);
    }

    protected function orderRandomQuestionsByType(array $questions, bool $shuffleWithinType): array
    {
        $grouped = [];

        foreach ($questions as $question) {
            $questionType = (string) ($question['question_type'] ?? '');
            $grouped[$questionType][] = $question;
        }

        $ordered = [];
        $knownTypeOrder = array_keys(Question::TYPE_LABELS);

        foreach ($knownTypeOrder as $questionType) {
            $items = $grouped[$questionType] ?? [];
            if ($items === []) {
                continue;
            }

            if ($shuffleWithinType && count($items) > 1) {
                shuffle($items);
            }

            foreach ($items as $item) {
                $ordered[] = $item;
            }

            unset($grouped[$questionType]);
        }

        foreach ($grouped as $items) {
            if ($shuffleWithinType && count($items) > 1) {
                shuffle($items);
            }

            foreach ($items as $item) {
                $ordered[] = $item;
            }
        }

        return $ordered;
    }

    protected function assignDisplayOrders(array $questions): array
    {
        $displayOrder = 1;

        foreach ($questions as &$question) {
            $question['display_order'] = $displayOrder++;
        }
        unset($question);

        return $questions;
    }

    protected function groupQuestionsByType(array $questions): array
    {
        $groups = [];

        foreach ($questions as $question) {
            $questionType = (string) ($question['question_type'] ?? '');
            if ($questionType === '') {
                continue;
            }

            if (!isset($groups[$questionType])) {
                $groups[$questionType] = [
                    'question_type' => $questionType,
                    'question_type_label' => Question::TYPE_LABELS[$questionType] ?? $questionType,
                    'questions' => [],
                ];
            }

            $groups[$questionType]['questions'][] = $question;
        }

        return array_values($groups);
    }

    protected function sanitizeQuestionsForExam(array $questions): array
    {
        foreach ($questions as &$question) {
            if (!is_array($question)) {
                continue;
            }

            $question['analysis_html'] = '';
            $payload = is_array($question['payload'] ?? null) ? $question['payload'] : [];
            $question['payload'] = $this->sanitizeQuestionPayloadForExam(
                (string) ($question['question_type'] ?? ''),
                $payload
            );
        }
        unset($question);

        return $questions;
    }

    protected function sanitizeQuestionPayloadForExam(string $questionType, array $payload): array
    {
        if (in_array($questionType, [Question::TYPE_SINGLE, Question::TYPE_MULTIPLE], true)) {
            unset($payload['answer']);
            return $payload;
        }

        if ($questionType === Question::TYPE_BLANK) {
            $answers = is_array($payload['answers'] ?? null) ? $payload['answers'] : [];
            $payload['blank_count'] = max(count($answers), 1);
            unset($payload['answers']);
            return $payload;
        }

        if ($questionType === Question::TYPE_SHORT) {
            unset($payload['answer']);
            return $payload;
        }

        if ($questionType === Question::TYPE_OPERATION) {
            unset($payload['reference_answer']);
            return $payload;
        }

        if ($questionType === Question::TYPE_JUDGE) {
            unset($payload['answer']);
            return $payload;
        }

        return $payload;
    }

    protected function loadSessionQuestions(int $sessionId): array
    {
        $rows = Db::name('exam_session_questions')
            ->where('session_id', $sessionId)
            ->order('display_order asc')
            ->select()
            ->toArray();

        $questions = [];

        foreach ($rows as $row) {
            $decoded = json_decode((string) ($row['question_snapshot_json'] ?? ''), true);
            if (!is_array($decoded)) {
                continue;
            }

            $questions[] = $decoded;
        }

        return $questions;
    }

    protected function decodeConfig(string $configJson): array
    {
        if (trim($configJson) === '') {
            return [];
        }

        $decoded = json_decode($configJson, true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function decodePayload(string $payloadJson): array
    {
        if (trim($payloadJson) === '') {
            return [];
        }

        $decoded = json_decode($payloadJson, true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function toPositiveIntOrNull(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[1-9]\d*$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    protected function difficultyLabel(string $difficulty): string
    {
        return match ($difficulty) {
            'easy' => '容易',
            'medium' => '中等',
            'hard' => '困难',
            default => $difficulty,
        };
    }

    protected function resolvedFocusMonitoringEnabled(Exam $exam): bool
    {
        return (int) ($exam->auto_fullscreen ?? 0) === 1 || (int) ($exam->enable_focus_monitor ?? 0) === 1;
    }

    protected function resolvedFocusLossLimit(Exam $exam): int
    {
        if (!$this->resolvedFocusMonitoringEnabled($exam)) {
            return 0;
        }

        $action = trim((string) ($exam->focus_loss_action ?? 'none'));
        if ($action === 'none') {
            return 0;
        }

        return max((int) ($exam->focus_loss_limit ?? 0), 0);
    }

    protected function resolvedFocusLossAction(Exam $exam): string
    {
        if (!$this->resolvedFocusMonitoringEnabled($exam)) {
            return 'none';
        }

        return trim((string) ($exam->focus_loss_action ?? 'none'));
    }

    protected function resolvedFocusLossDeductScore(Exam $exam): int
    {
        if (!$this->resolvedFocusMonitoringEnabled($exam)) {
            return 0;
        }

        $action = trim((string) ($exam->focus_loss_action ?? 'none'));
        if ($action !== 'deduct_score') {
            return 0;
        }

        return max((int) ($exam->focus_loss_deduct_score ?? 0), 0);
    }
}

<?php

declare(strict_types=1);

namespace app\service;

use app\model\AdminUser;
use app\model\Exam;
use app\model\ExamResult;
use app\model\ExamResultItem;
use app\model\ExamResultItemReview;
use app\model\ExamSession;
use app\model\Question;
use app\model\Student;
use think\facade\Db;

class ExamResultService
{
    public function latestResultSummaryForStudent(Exam $exam, Student $student): ?array
    {
        $result = Db::name('exam_results')
            ->where('exam_id', (int) $exam->id)
            ->where('student_id', (int) $student->id)
            ->order('id desc')
            ->find();

        if (!is_array($result)) {
            return null;
        }

        return $this->studentVisibleSummary($exam, $result);
    }

    public function latestResultDetailForStudent(Exam $exam, Student $student): ?array
    {
        $result = Db::name('exam_results')
            ->where('exam_id', (int) $exam->id)
            ->where('student_id', (int) $student->id)
            ->order('id desc')
            ->find();

        if (!is_array($result)) {
            return null;
        }

        return $this->studentVisibleDetail($exam, $result);
    }

    public function latestResultMapForStudent(Student $student, array $examIds): array
    {
        if ($examIds === []) {
            return [];
        }

        $rows = Db::name('exam_results')
            ->where('student_id', (int) $student->id)
            ->whereIn('exam_id', array_values(array_unique(array_map('intval', $examIds))))
            ->order('id desc')
            ->select()
            ->toArray();

        $map = [];

        foreach ($rows as $row) {
            $examId = (int) ($row['exam_id'] ?? 0);
            if ($examId <= 0 || isset($map[$examId])) {
                continue;
            }

            $map[$examId] = $row;
        }

        return $map;
    }

    public function studentVisibleSummary(Exam $exam, array $result): array
    {
        $pendingManualCount = (int) ($result['pending_manual_count'] ?? 0);

        return [
            'result_id' => (int) ($result['id'] ?? 0),
            'session_id' => (int) ($result['session_id'] ?? 0),
            'exam_id' => (int) ($result['exam_id'] ?? 0),
            'attempt_no' => (int) ($result['attempt_no'] ?? 1),
            'session_status' => (string) ($result['session_status'] ?? ''),
            'objective_score' => (int) ($result['objective_score'] ?? 0),
            'subjective_score' => (int) ($result['subjective_score'] ?? 0),
            'total_score' => (int) ($result['total_score'] ?? 0),
            'objective_total_score' => (int) ($result['objective_total_score'] ?? 0),
            'subjective_total_score' => (int) ($result['subjective_total_score'] ?? 0),
            'answered_count' => (int) ($result['answered_count'] ?? 0),
            'correct_count' => (int) ($result['correct_count'] ?? 0),
            'pending_manual_count' => $pendingManualCount,
            'manual_review_status' => (string) ($result['manual_review_status'] ?? ''),
            'penalty_score' => (int) ($result['penalty_score'] ?? 0),
            'final_score' => (int) ($result['final_score'] ?? 0),
            'cheating_status' => (string) ($result['cheating_status'] ?? 'none'),
            'violation_count' => (int) ($result['violation_count'] ?? 0),
            'submitted_at' => isset($result['submitted_at']) ? (string) $result['submitted_at'] : null,
            'note' => $pendingManualCount > 0
                ? '当前仅展示客观题自动判分成绩，主观题仍在阅卷中。'
                : '当前仅展示允许公开的客观题自动判分成绩。',
            'allow_view_score' => (int) ($exam->allow_view_score ?? 0),
        ];
    }

    public function generateFromSession(Exam $exam, Student $student, ExamSession $session): array
    {
        $questionRows = Db::name('exam_session_questions')
            ->where('session_id', (int) $session->id)
            ->order('display_order asc')
            ->select()
            ->toArray();

        if ($questionRows === []) {
            throw new \RuntimeException('当前作答会话缺少题目快照，无法生成成绩结果。');
        }

        $answerRows = Db::name('exam_answers')
            ->where('session_id', (int) $session->id)
            ->select()
            ->toArray();

        $answerMap = [];
        foreach ($answerRows as $row) {
            $questionId = (int) ($row['question_id'] ?? 0);
            if ($questionId <= 0) {
                continue;
            }

            $answerMap[$questionId] = $row;
        }

        $now = date('Y-m-d H:i:s');
        $resultItems = [];
        $objectiveScore = 0;
        $subjectiveScore = 0;
        $objectiveTotalScore = 0;
        $subjectiveTotalScore = 0;
        $answeredCount = 0;
        $correctCount = 0;
        $pendingManualCount = 0;

        foreach ($questionRows as $row) {
            $questionId = (int) ($row['question_id'] ?? 0);
            if ($questionId <= 0) {
                continue;
            }

            $questionType = (string) ($row['question_type'] ?? '');
            $score = (int) ($row['score'] ?? 0);
            $questionSnapshot = $this->decodeJson((string) ($row['question_snapshot_json'] ?? ''));
            $answerRow = $answerMap[$questionId] ?? null;
            $answer = $this->decodeJsonOrScalar($answerRow['answer_json'] ?? null);
            $referenceAnswer = $this->extractReferenceAnswer($questionType, $questionSnapshot);
            $isAnswered = $this->isAnswerProvided($answer);

            if ($isAnswered) {
                $answeredCount++;
            }

            $needsManualReview = $this->needsManualReview($questionType);
            $reviewStatus = $needsManualReview ? ExamResultItem::REVIEW_STATUS_PENDING : ExamResultItem::REVIEW_STATUS_AUTO_SCORED;
            $isCorrect = null;
            $earnedScore = 0;
            $scoredAt = $now;

            if ($needsManualReview) {
                $pendingManualCount++;
                $subjectiveTotalScore += $score;
            } else {
                $objectiveTotalScore += $score;
                $isCorrect = $this->isAnswerCorrect($questionType, $answer, $referenceAnswer);
                $earnedScore = $isCorrect ? $score : 0;
                $objectiveScore += $earnedScore;

                if ($isCorrect) {
                    $correctCount++;
                }
            }

            $resultItems[] = [
                'question_id' => $questionId,
                'display_order' => (int) ($row['display_order'] ?? 0),
                'question_type' => $questionType,
                'score' => $score,
                'earned_score' => $earnedScore,
                'is_answered' => $isAnswered ? 1 : 0,
                'is_correct' => $isCorrect === null ? null : ($isCorrect ? 1 : 0),
                'needs_manual_review' => $needsManualReview ? 1 : 0,
                'review_status' => $reviewStatus,
                'answer_json' => $answer !== null ? json_encode($answer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'reference_answer_json' => $referenceAnswer !== null ? json_encode($referenceAnswer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'question_snapshot_json' => (string) ($row['question_snapshot_json'] ?? '{}'),
                'answered_at' => $answerRow['answered_at'] ?? null,
                'scored_at' => $scoredAt,
            ];
        }

        $manualReviewStatus = $pendingManualCount > 0 ? ExamResult::REVIEW_STATUS_PENDING : ExamResult::REVIEW_STATUS_COMPLETED;
        $totalScore = $objectiveScore + $subjectiveScore;
        $penaltyScore = max((int) ($session->penalty_score ?? 0), 0);
        $forceZeroScore = (int) ($session->force_zero_score ?? 0) === 1;
        $violationCount = max((int) ($session->focus_loss_count ?? 0), 0);
        $finalScore = $forceZeroScore ? 0 : max($totalScore - $penaltyScore, 0);
        $cheatingStatus = $this->resolveCheatingStatus($violationCount, $forceZeroScore, $penaltyScore);

        $resultId = Db::transaction(function () use (
            $exam,
            $student,
            $session,
            $now,
            $objectiveScore,
            $subjectiveScore,
            $totalScore,
            $objectiveTotalScore,
            $subjectiveTotalScore,
            $answeredCount,
            $correctCount,
            $pendingManualCount,
            $manualReviewStatus,
            $penaltyScore,
            $finalScore,
            $cheatingStatus,
            $violationCount,
            $resultItems
        ): int {
            $existingId = Db::name('exam_results')
                ->where('session_id', (int) $session->id)
                ->value('id');

            $resultData = [
                'session_id' => (int) $session->id,
                'exam_id' => (int) $exam->id,
                'paper_id' => (int) $session->paper_id,
                'student_id' => (int) $student->id,
                'attempt_no' => (int) ($session->attempt_no ?? 1),
                'session_status' => (string) ($session->status ?? ExamSession::STATUS_SUBMITTED),
                'objective_score' => $objectiveScore,
                'subjective_score' => $subjectiveScore,
                'total_score' => $totalScore,
                'objective_total_score' => $objectiveTotalScore,
                'subjective_total_score' => $subjectiveTotalScore,
                'answered_count' => $answeredCount,
                'correct_count' => $correctCount,
                'pending_manual_count' => $pendingManualCount,
                'manual_review_status' => $manualReviewStatus,
                'penalty_score' => $penaltyScore,
                'final_score' => $finalScore,
                'cheating_status' => $cheatingStatus,
                'violation_count' => $violationCount,
                'submitted_at' => $session->submitted_at ? (string) $session->submitted_at : null,
                'generated_at' => $now,
                'updated_at' => $now,
            ];

            if ($existingId !== null) {
                Db::name('exam_results')
                    ->where('id', (int) $existingId)
                    ->update($resultData);
                $resultId = (int) $existingId;
                Db::name('exam_result_items')->where('result_id', $resultId)->delete();
            } else {
                $resultId = (int) Db::name('exam_results')->insertGetId($resultData);
            }

            foreach ($resultItems as $item) {
                Db::name('exam_result_items')->insert(array_merge($item, [
                    'result_id' => $resultId,
                    'session_id' => (int) $session->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            }

            return $resultId;
        });

        return [
            'result_id' => $resultId,
            'objective_score' => $objectiveScore,
            'subjective_score' => $subjectiveScore,
            'total_score' => $totalScore,
            'objective_total_score' => $objectiveTotalScore,
            'subjective_total_score' => $subjectiveTotalScore,
            'answered_count' => $answeredCount,
            'correct_count' => $correctCount,
            'pending_manual_count' => $pendingManualCount,
            'manual_review_status' => $manualReviewStatus,
            'penalty_score' => $penaltyScore,
            'final_score' => $finalScore,
            'cheating_status' => $cheatingStatus,
            'violation_count' => $violationCount,
        ];
    }

    public function detail(int $resultId): array
    {
        $result = Db::name('exam_results')
            ->alias('r')
            ->leftJoin('exams e', 'e.id = r.exam_id')
            ->leftJoin('students s', 's.id = r.student_id')
            ->leftJoin('papers p', 'p.id = r.paper_id')
            ->field([
                'r.id',
                'r.session_id',
                'r.exam_id',
                'r.paper_id',
                'r.student_id',
                'r.attempt_no',
                'r.session_status',
                'r.objective_score',
                'r.subjective_score',
                'r.total_score',
                'r.objective_total_score',
                'r.subjective_total_score',
                'r.answered_count',
                'r.correct_count',
                'r.pending_manual_count',
                'r.manual_review_status',
                'r.penalty_score',
                'r.final_score',
                'r.cheating_status',
                'r.violation_count',
                'r.submitted_at',
                'r.generated_at',
                'e.title' => 'exam_title',
                'p.title' => 'paper_title',
                's.username' => 'student_username',
                's.student_no' => 'student_no',
                's.name' => 'student_name',
            ])
            ->where('r.id', $resultId)
            ->find();

        if (!is_array($result)) {
            throw new \RuntimeException('成绩结果不存在。');
        }

        $monitorLogs = app(ExamIntegrityService::class)->recentLogsForExamId((int) ($result['exam_id'] ?? 0), [
            'session_id' => (int) ($result['session_id'] ?? 0),
            'student_id' => (int) ($result['student_id'] ?? 0),
        ], 60);

        $items = Db::name('exam_result_items')
            ->alias('i')
            ->leftJoin('admin_users au', 'au.id = i.reviewed_by_admin_id')
            ->field([
                'i.*',
                'au.name' => 'reviewed_by_name',
                'au.username' => 'reviewed_by_username',
            ])
            ->where('i.result_id', $resultId)
            ->order('display_order asc, id asc')
            ->select()
            ->toArray();

        $reviewLogs = Db::name('exam_result_item_reviews')
            ->alias('r')
            ->leftJoin('admin_users au', 'au.id = r.reviewer_admin_id')
            ->field([
                'r.id',
                'r.result_id',
                'r.result_item_id',
                'r.session_id',
                'r.question_id',
                'r.reviewer_admin_id',
                'r.reviewer_name',
                'r.score_before',
                'r.score_after',
                'r.review_note',
                'r.created_at',
                'au.name' => 'reviewer_profile_name',
                'au.username' => 'reviewer_username',
            ])
            ->where('r.result_id', $resultId)
            ->order('r.id desc')
            ->select()
            ->toArray();

        $reviewLogMap = [];
        foreach ($reviewLogs as $log) {
            $resultItemId = (int) ($log['result_item_id'] ?? 0);
            if ($resultItemId <= 0) {
                continue;
            }

            $reviewLogMap[$resultItemId][] = [
                'id' => (int) ($log['id'] ?? 0),
                'result_item_id' => $resultItemId,
                'reviewer_admin_id' => isset($log['reviewer_admin_id']) ? (int) $log['reviewer_admin_id'] : null,
                'reviewer_name' => $this->resolveReviewerName($log),
                'score_before' => (int) ($log['score_before'] ?? 0),
                'score_after' => (int) ($log['score_after'] ?? 0),
                'review_note' => $this->nullableString($log['review_note'] ?? null),
                'created_at' => isset($log['created_at']) ? (string) $log['created_at'] : null,
            ];
        }

        foreach ($items as &$item) {
            $item['id'] = (int) ($item['id'] ?? 0);
            $item['question_id'] = (int) ($item['question_id'] ?? 0);
            $item['display_order'] = (int) ($item['display_order'] ?? 0);
            $item['score'] = (int) ($item['score'] ?? 0);
            $item['earned_score'] = (int) ($item['earned_score'] ?? 0);
            $item['is_answered'] = (int) ($item['is_answered'] ?? 0);
            $item['is_correct'] = isset($item['is_correct']) ? ($item['is_correct'] === null ? null : (int) $item['is_correct']) : null;
            $item['needs_manual_review'] = (int) ($item['needs_manual_review'] ?? 0);
            $item['reviewed_by_admin_id'] = isset($item['reviewed_by_admin_id']) ? (int) $item['reviewed_by_admin_id'] : null;
            $item['review_note'] = $this->nullableString($item['review_note'] ?? null);
            $item['reviewed_at'] = isset($item['reviewed_at']) ? (string) $item['reviewed_at'] : null;
            $item['reviewed_by_name'] = $this->resolveReviewerName([
                'reviewer_name' => null,
                'reviewer_profile_name' => $item['reviewed_by_name'] ?? null,
                'reviewer_username' => $item['reviewed_by_username'] ?? null,
            ]);
            $item['answer'] = $this->decodeJsonOrScalar($item['answer_json'] ?? null);
            $item['reference_answer'] = $this->decodeJsonOrScalar($item['reference_answer_json'] ?? null);
            $item['question_snapshot'] = $this->decodeJson((string) ($item['question_snapshot_json'] ?? ''));
            $item['question_view'] = $this->buildQuestionView($item['question_type'], $item['question_snapshot']);
            $item['review_logs'] = $reviewLogMap[$item['id']] ?? [];
        }
        unset($item);

        return [
            'result' => $this->normalizeResultSummary($result),
            'items' => $items,
            'monitor_logs' => $monitorLogs,
        ];
    }

    public function review(int $resultId, array $reviews, ?AdminUser $reviewer = null): array
    {
        if ($reviews === []) {
            throw new \RuntimeException('至少需要提交一条阅卷记录。');
        }

        $result = Db::name('exam_results')->where('id', $resultId)->find();
        if (!is_array($result)) {
            throw new \RuntimeException('成绩结果不存在。');
        }

        $now = date('Y-m-d H:i:s');

        Db::transaction(function () use ($resultId, $reviews, $now, $reviewer): void {
            foreach ($reviews as $review) {
                if (!is_array($review)) {
                    continue;
                }

                $itemId = (int) ($review['item_id'] ?? 0);
                $earnedScore = (int) ($review['earned_score'] ?? -1);
                $reviewNote = $this->sanitizeReviewNote($review['review_note'] ?? null);

                if ($itemId <= 0 || $earnedScore < 0) {
                    continue;
                }

                $item = Db::name('exam_result_items')
                    ->where('id', $itemId)
                    ->where('result_id', $resultId)
                    ->find();

                if (!is_array($item)) {
                    continue;
                }

                if ((int) ($item['needs_manual_review'] ?? 0) !== 1) {
                    continue;
                }

                $maxScore = (int) ($item['score'] ?? 0);
                if ($earnedScore > $maxScore) {
                    $earnedScore = $maxScore;
                }

                $scoreBefore = (int) ($item['earned_score'] ?? 0);
                $noteBefore = $this->nullableString($item['review_note'] ?? null);
                $statusBefore = (string) ($item['review_status'] ?? '');

                if ($scoreBefore === $earnedScore && $noteBefore === $reviewNote && $statusBefore === ExamResultItem::REVIEW_STATUS_REVIEWED) {
                    continue;
                }

                Db::name('exam_result_items')
                    ->where('id', $itemId)
                    ->update([
                        'earned_score' => $earnedScore,
                        'is_correct' => $earnedScore >= $maxScore && $maxScore > 0 ? 1 : 0,
                        'review_status' => ExamResultItem::REVIEW_STATUS_REVIEWED,
                        'review_note' => $reviewNote,
                        'reviewed_by_admin_id' => $reviewer?->id ? (int) $reviewer->id : null,
                        'reviewed_at' => $now,
                        'scored_at' => $now,
                        'updated_at' => $now,
                    ]);

                Db::name('exam_result_item_reviews')->insert([
                    'result_id' => $resultId,
                    'result_item_id' => $itemId,
                    'session_id' => (int) ($item['session_id'] ?? 0),
                    'question_id' => (int) ($item['question_id'] ?? 0),
                    'reviewer_admin_id' => $reviewer?->id ? (int) $reviewer->id : null,
                    'reviewer_name' => $this->reviewerDisplayName($reviewer),
                    'score_before' => $scoreBefore,
                    'score_after' => $earnedScore,
                    'review_note' => $reviewNote,
                    'created_at' => $now,
                ]);
            }
        });

        return $this->refreshSummary($resultId);
    }

    public function refreshSummary(int $resultId): array
    {
        $result = Db::name('exam_results')->where('id', $resultId)->find();
        if (!is_array($result)) {
            throw new \RuntimeException('成绩结果不存在。');
        }

        $items = Db::name('exam_result_items')
            ->where('result_id', $resultId)
            ->select()
            ->toArray();

        $objectiveScore = 0;
        $subjectiveScore = 0;
        $objectiveTotalScore = 0;
        $subjectiveTotalScore = 0;
        $answeredCount = 0;
        $correctCount = 0;
        $pendingManualCount = 0;

        foreach ($items as $item) {
            $score = (int) ($item['score'] ?? 0);
            $earnedScore = (int) ($item['earned_score'] ?? 0);
            $isAnswered = (int) ($item['is_answered'] ?? 0) === 1;
            $needsManualReview = (int) ($item['needs_manual_review'] ?? 0) === 1;
            $reviewStatus = (string) ($item['review_status'] ?? '');
            $isCorrect = $item['is_correct'];

            if ($isAnswered) {
                $answeredCount++;
            }

            if ($needsManualReview) {
                $subjectiveTotalScore += $score;
                $subjectiveScore += $earnedScore;
                if ($reviewStatus === ExamResultItem::REVIEW_STATUS_PENDING) {
                    $pendingManualCount++;
                }
            } else {
                $objectiveTotalScore += $score;
                $objectiveScore += $earnedScore;
            }

            if ($isCorrect !== null && (int) $isCorrect === 1) {
                $correctCount++;
            }
        }

        $manualReviewStatus = $pendingManualCount > 0 ? ExamResult::REVIEW_STATUS_PENDING : ExamResult::REVIEW_STATUS_COMPLETED;
        $totalScore = $objectiveScore + $subjectiveScore;
        $penaltyScore = (int) ($result['penalty_score'] ?? 0);
        $forceZeroScore = (string) ($result['cheating_status'] ?? '') === 'zero_score';
        $violationCount = (int) ($result['violation_count'] ?? 0);
        $finalScore = $forceZeroScore ? 0 : max($totalScore - $penaltyScore, 0);
        $cheatingStatus = $this->resolveCheatingStatus($violationCount, $forceZeroScore, $penaltyScore);

        Db::name('exam_results')
            ->where('id', $resultId)
            ->update([
                'objective_score' => $objectiveScore,
                'subjective_score' => $subjectiveScore,
                'total_score' => $totalScore,
                'objective_total_score' => $objectiveTotalScore,
                'subjective_total_score' => $subjectiveTotalScore,
                'answered_count' => $answeredCount,
                'correct_count' => $correctCount,
                'pending_manual_count' => $pendingManualCount,
                'manual_review_status' => $manualReviewStatus,
                'final_score' => $finalScore,
                'cheating_status' => $cheatingStatus,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        return [
            'result_id' => $resultId,
            'objective_score' => $objectiveScore,
            'subjective_score' => $subjectiveScore,
            'total_score' => $totalScore,
            'objective_total_score' => $objectiveTotalScore,
            'subjective_total_score' => $subjectiveTotalScore,
            'answered_count' => $answeredCount,
            'correct_count' => $correctCount,
            'pending_manual_count' => $pendingManualCount,
            'manual_review_status' => $manualReviewStatus,
            'penalty_score' => $penaltyScore,
            'final_score' => $finalScore,
            'cheating_status' => $cheatingStatus,
            'violation_count' => $violationCount,
        ];
    }

    protected function normalizeResultSummary(array $result): array
    {
        $result['id'] = (int) ($result['id'] ?? 0);
        $result['session_id'] = (int) ($result['session_id'] ?? 0);
        $result['exam_id'] = (int) ($result['exam_id'] ?? 0);
        $result['paper_id'] = (int) ($result['paper_id'] ?? 0);
        $result['student_id'] = (int) ($result['student_id'] ?? 0);
        $result['attempt_no'] = (int) ($result['attempt_no'] ?? 1);
        $result['objective_score'] = (int) ($result['objective_score'] ?? 0);
        $result['subjective_score'] = (int) ($result['subjective_score'] ?? 0);
        $result['total_score'] = (int) ($result['total_score'] ?? 0);
        $result['objective_total_score'] = (int) ($result['objective_total_score'] ?? 0);
        $result['subjective_total_score'] = (int) ($result['subjective_total_score'] ?? 0);
        $result['answered_count'] = (int) ($result['answered_count'] ?? 0);
        $result['correct_count'] = (int) ($result['correct_count'] ?? 0);
        $result['pending_manual_count'] = (int) ($result['pending_manual_count'] ?? 0);
        $result['penalty_score'] = (int) ($result['penalty_score'] ?? 0);
        $result['final_score'] = (int) ($result['final_score'] ?? 0);
        $result['violation_count'] = (int) ($result['violation_count'] ?? 0);
        $result['cheating_status'] = (string) ($result['cheating_status'] ?? 'none');
        $result['final_score'] = $this->resolvedFinalScore($result);
        $result['cheating_status'] = $this->resolvedCheatingStatusFromRow($result);

        return $result;
    }

    protected function studentVisibleDetail(Exam $exam, array $result): array
    {
        $pendingManualCount = (int) ($result['pending_manual_count'] ?? 0);
        $canViewPaper = (int) ($exam->allow_view_paper ?? 0) === 1;
        $canViewAnalysis = $canViewPaper && (int) ($exam->allow_view_analysis ?? 0) === 1;
        $showQuestionScore = (int) ($exam->show_question_score ?? 1) === 1;
        $showQuestionDifficulty = (int) ($exam->show_question_difficulty ?? 1) === 1;

        $summary = [
            'result_id' => (int) ($result['id'] ?? 0),
            'session_id' => (int) ($result['session_id'] ?? 0),
            'exam_id' => (int) ($result['exam_id'] ?? 0),
            'attempt_no' => (int) ($result['attempt_no'] ?? 1),
            'session_status' => (string) ($result['session_status'] ?? ''),
            'objective_score' => (int) ($result['objective_score'] ?? 0),
            'subjective_score' => (int) ($result['subjective_score'] ?? 0),
            'total_score' => (int) ($result['total_score'] ?? 0),
            'objective_total_score' => (int) ($result['objective_total_score'] ?? 0),
            'subjective_total_score' => (int) ($result['subjective_total_score'] ?? 0),
            'answered_count' => (int) ($result['answered_count'] ?? 0),
            'correct_count' => (int) ($result['correct_count'] ?? 0),
            'pending_manual_count' => $pendingManualCount,
            'manual_review_status' => (string) ($result['manual_review_status'] ?? ''),
            'penalty_score' => (int) ($result['penalty_score'] ?? 0),
            'final_score' => (int) ($result['final_score'] ?? 0),
            'cheating_status' => (string) ($result['cheating_status'] ?? 'none'),
            'violation_count' => (int) ($result['violation_count'] ?? 0),
            'submitted_at' => isset($result['submitted_at']) ? (string) $result['submitted_at'] : null,
            'note' => $pendingManualCount > 0
                ? '当前仅展示客观题自动判分成绩，主观题仍在阅卷中。'
                : '当前仅展示允许公开的客观题自动判分成绩。',
            'allow_view_score' => (int) ($exam->allow_view_score ?? 0),
            'allow_view_paper' => $canViewPaper ? 1 : 0,
            'allow_view_analysis' => $canViewAnalysis ? 1 : 0,
            'show_question_score' => $showQuestionScore ? 1 : 0,
            'show_question_difficulty' => $showQuestionDifficulty ? 1 : 0,
        ];

        $payload = [
            'result' => $summary,
            'visibility' => [
                'allow_view_score' => 1,
                'allow_view_paper' => $canViewPaper ? 1 : 0,
                'allow_view_analysis' => $canViewAnalysis ? 1 : 0,
                'show_question_score' => $showQuestionScore ? 1 : 0,
                'show_question_difficulty' => $showQuestionDifficulty ? 1 : 0,
            ],
            'items' => [],
        ];

        if (!$canViewPaper) {
            return $payload;
        }

        $rows = Db::name('exam_result_items')
            ->where('result_id', (int) ($result['id'] ?? 0))
            ->order('display_order asc, id asc')
            ->select()
            ->toArray();

        foreach ($rows as $row) {
            $questionSnapshot = $this->decodeJson((string) ($row['question_snapshot_json'] ?? ''));
            $questionView = $this->buildQuestionView((string) ($row['question_type'] ?? ''), $questionSnapshot);

            if (!$showQuestionDifficulty) {
                $questionView['difficulty_label'] = '';
            }

            if (!$canViewAnalysis) {
                $questionView['analysis_html'] = '';
                $questionView['blank_answers'] = [];
            }

            $payload['items'][] = [
                'id' => (int) ($row['id'] ?? 0),
                'display_order' => (int) ($row['display_order'] ?? 0),
                'question_type' => (string) ($row['question_type'] ?? ''),
                'score' => $showQuestionScore ? (int) ($row['score'] ?? 0) : null,
                'earned_score' => $showQuestionScore ? (int) ($row['earned_score'] ?? 0) : null,
                'is_answered' => (int) ($row['is_answered'] ?? 0),
                'is_correct' => isset($row['is_correct']) ? ($row['is_correct'] === null ? null : (int) $row['is_correct']) : null,
                'needs_manual_review' => (int) ($row['needs_manual_review'] ?? 0),
                'review_status' => (string) ($row['review_status'] ?? ''),
                'answered_at' => isset($row['answered_at']) ? (string) $row['answered_at'] : null,
                'question_view' => $questionView,
                'answer' => $this->decodeJsonOrScalar($row['answer_json'] ?? null),
                'reference_answer' => $canViewAnalysis ? $this->decodeJsonOrScalar($row['reference_answer_json'] ?? null) : null,
            ];
        }

        return $payload;
    }

    protected function buildQuestionView(string $questionType, array $questionSnapshot): array
    {
        $payload = is_array($questionSnapshot['payload'] ?? null) ? $questionSnapshot['payload'] : [];

        return [
            'title' => (string) ($questionSnapshot['title'] ?? ''),
            'stem_html' => (string) ($questionSnapshot['stem_html'] ?? ''),
            'analysis_html' => (string) ($questionSnapshot['analysis_html'] ?? ''),
            'question_type_label' => (string) ($questionSnapshot['question_type_label'] ?? (Question::TYPE_LABELS[$questionType] ?? $questionType)),
            'difficulty_label' => (string) ($questionSnapshot['difficulty_label'] ?? ''),
            'options' => $this->normalizeChoiceOptions($payload['options'] ?? null),
            'blank_answers' => $this->normalizeStringArrayKeepOrder($payload['answers'] ?? null),
            'operation_requirement' => (string) ($payload['requirement'] ?? ''),
            'operation_package' => is_array($payload['package'] ?? null) ? $payload['package'] : null,
        ];
    }

    protected function normalizeChoiceOptions(mixed $options): array
    {
        if (!is_array($options)) {
            return [];
        }

        $result = [];
        foreach ($options as $option) {
            if (!is_array($option)) {
                continue;
            }

            $key = trim((string) ($option['key'] ?? ''));
            $content = trim((string) ($option['content'] ?? ''));
            if ($key === '' && $content === '') {
                continue;
            }

            $result[] = [
                'key' => $key,
                'content' => $content,
            ];
        }

        return $result;
    }

    protected function normalizeStringArrayKeepOrder(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            $result[] = trim((string) $item);
        }

        return $result;
    }

    protected function reviewerDisplayName(?AdminUser $reviewer): ?string
    {
        if ($reviewer === null) {
            return null;
        }

        $name = trim((string) ($reviewer->name ?? ''));
        if ($name !== '') {
            return $name;
        }

        $username = trim((string) ($reviewer->username ?? ''));

        return $username !== '' ? $username : null;
    }

    protected function resolveReviewerName(array $row): ?string
    {
        foreach (['reviewer_name', 'reviewer_profile_name', 'reviewed_by_name', 'reviewer_username', 'reviewed_by_username'] as $field) {
            $value = trim((string) ($row[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    protected function sanitizeReviewNote(mixed $value): ?string
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

    protected function needsManualReview(string $questionType): bool
    {
        return in_array($questionType, [
            Question::TYPE_SHORT,
            Question::TYPE_OPERATION,
        ], true);
    }

    protected function isAnswerCorrect(string $questionType, mixed $answer, mixed $referenceAnswer): bool
    {
        return match ($questionType) {
            Question::TYPE_SINGLE, Question::TYPE_JUDGE => trim((string) $answer) !== '' && trim((string) $answer) === trim((string) $referenceAnswer),
            Question::TYPE_MULTIPLE => $this->normalizeStringArray($answer) === $this->normalizeStringArray($referenceAnswer),
            Question::TYPE_BLANK => $this->normalizeBlankArray($answer) === $this->normalizeBlankArray($referenceAnswer),
            default => false,
        };
    }

    protected function extractReferenceAnswer(string $questionType, array $questionSnapshot): mixed
    {
        $payload = is_array($questionSnapshot['payload'] ?? null) ? $questionSnapshot['payload'] : [];

        return match ($questionType) {
            Question::TYPE_SINGLE,
            Question::TYPE_MULTIPLE,
            Question::TYPE_JUDGE,
            Question::TYPE_SHORT => $payload['answer'] ?? null,
            Question::TYPE_BLANK => $payload['answers'] ?? null,
            Question::TYPE_OPERATION => $payload['reference_answer'] ?? null,
            default => null,
        };
    }

    protected function isAnswerProvided(mixed $answer): bool
    {
        if (is_array($answer)) {
            foreach ($answer as $item) {
                if (trim((string) $item) !== '') {
                    return true;
                }
            }

            return false;
        }

        return trim((string) $answer) !== '';
    }

    protected function decodeJson(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function decodeJsonOrScalar(mixed $json): mixed
    {
        if (!is_string($json) || trim($json) === '') {
            return null;
        }

        return json_decode($json, true);
    }

    protected function normalizeStringArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            $text = trim((string) $item);
            if ($text === '' || in_array($text, $result, true)) {
                continue;
            }

            $result[] = $text;
        }

        sort($result);

        return $result;
    }

    protected function normalizeBlankArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_map(static fn ($item): string => trim((string) $item), $value);
    }

    protected function resolveCheatingStatus(int $violationCount, bool $forceZeroScore, int $penaltyScore): string
    {
        if ($forceZeroScore) {
            return 'zero_score';
        }

        if ($penaltyScore > 0) {
            return 'deducted';
        }

        if ($violationCount > 0) {
            return 'warning';
        }

        return 'none';
    }

    protected function resolvedFinalScore(array $result): int
    {
        $finalScore = (int) ($result['final_score'] ?? 0);
        $totalScore = (int) ($result['total_score'] ?? 0);
        $penaltyScore = (int) ($result['penalty_score'] ?? 0);
        $violationCount = (int) ($result['violation_count'] ?? 0);
        $cheatingStatus = trim((string) ($result['cheating_status'] ?? 'none'));

        if ($cheatingStatus === 'zero_score') {
            return 0;
        }

        if ($finalScore > 0 || $totalScore === 0) {
            return $finalScore;
        }

        if ($penaltyScore > 0) {
            return max($totalScore - $penaltyScore, 0);
        }

        if ($violationCount === 0 && ($cheatingStatus === '' || $cheatingStatus === 'none')) {
            return $totalScore;
        }

        return $finalScore;
    }

    protected function resolvedCheatingStatusFromRow(array $result): string
    {
        $cheatingStatus = trim((string) ($result['cheating_status'] ?? ''));
        if ($cheatingStatus !== '') {
            return $cheatingStatus;
        }

        return $this->resolveCheatingStatus(
            (int) ($result['violation_count'] ?? 0),
            false,
            (int) ($result['penalty_score'] ?? 0)
        );
    }
}

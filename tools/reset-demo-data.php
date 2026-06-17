<?php

declare(strict_types=1);

require __DIR__ . '/db-bootstrap.php';

$pdo = createPdoFromEnvironment();

$tables = [
    'exam_student_access_tokens',
    'exam_student_group_members',
    'exam_exam_student_groups',
    'exam_exam_student_students',
    'exam_students',
    'exam_student_groups',
    'exam_questions',
    'exam_question_categories',
    'exam_papers',
    'exam_exams',
    'exam_exam_answers',
    'exam_exam_session_questions',
    'exam_exam_sessions',
    'exam_admin_data_scopes',
    'exam_admin_access_tokens',
    'exam_admin_role_permissions',
    'exam_admin_permissions',
    'exam_admin_roles',
    'exam_admin_users',
    'exam_system_settings',
];

try {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

    foreach ($tables as $table) {
        $pdo->exec("TRUNCATE TABLE `{$table}`");
    }

    $pdo->beginTransaction();

    $pdo->exec('DELETE FROM `exam_admin_data_scopes`');

    $seedFiles = [
        __DIR__ . '/../database/schema/admin_rbac.seed.sql',
        __DIR__ . '/../database/schema/admin_users.seed.sql',
        __DIR__ . '/../database/schema/system_settings.seed.sql',
        __DIR__ . '/../database/schema/question_category_demo.seed.sql',
    ];

    foreach ($seedFiles as $file) {
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException('Failed to read seed file: ' . $file);
        }

        $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }

            $pdo->exec($statement);
        }
    }

    $pdo->exec(<<<'SQL'
INSERT INTO `exam_student_groups` (`id`, `code`, `name`, `status`)
VALUES
  (1, 'class-01', '测试一班', 1),
  (2, 'class-02', '测试二班', 1),
  (3, 'class-03', '测试三班', 1),
  (4, 'class-04', '测试四班', 1),
  (5, 'class-05', '测试五班', 1),
  (6, 'class-06', '测试六班', 1),
  (7, 'class-07', '测试七班', 1),
  (8, 'class-08', '测试八班', 1)
ON DUPLICATE KEY UPDATE
  `code` = VALUES(`code`),
  `name` = VALUES(`name`),
  `status` = VALUES(`status`)
SQL);

    $studentPasswordHash = password_hash('student123', PASSWORD_DEFAULT);
    $studentStmt = $pdo->prepare(
        'INSERT INTO `exam_students` (`id`, `username`, `password`, `student_no`, `name`, `status`) VALUES (?, ?, ?, ?, ?, 1)'
    );
    $groupMemberStmt = $pdo->prepare(
        'INSERT INTO `exam_student_group_members` (`student_group_id`, `student_id`) VALUES (?, ?)'
    );

    $studentId = 1;
    for ($groupId = 1; $groupId <= 8; $groupId++) {
        for ($i = 1; $i <= 60; $i++) {
            $username = sprintf('student%03d', $studentId);
            $studentNo = sprintf('S2026%04d', $studentId);
            $name = sprintf('测试学生%03d', $studentId);

            $studentStmt->execute([
                $studentId,
                $username,
                $studentPasswordHash,
                $studentNo,
                $name,
            ]);

            $groupMemberStmt->execute([$groupId, $studentId]);
            $studentId++;
        }
    }

    $questionStmt = $pdo->prepare(
        'INSERT INTO `exam_questions` (`id`, `title`, `question_type`, `category_id`, `difficulty_level`, `stem_html`, `analysis_html`, `payload_json`, `status`, `created_by`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 1)'
    );

    $questionId = 1;
    $questionTypes = ['single', 'multiple', 'judge', 'blank', 'short'];
    $difficulties = ['easy', 'medium', 'hard'];

    foreach ($questionTypes as $questionType) {
        for ($categoryId = 1; $categoryId <= 4; $categoryId++) {
            foreach ($difficulties as $difficulty) {
                for ($i = 1; $i <= 8; $i++) {
                    $title = sprintf('%s 测试题 %d-%d-%s-%02d', strtoupper($questionType), $categoryId, $questionId, $difficulty, $i);
                    $stemHtml = sprintf('<p>%s 第 %d 题，分类 %d，难度 %s。</p>', $title, $questionId, $categoryId, $difficulty);
                    $analysisHtml = sprintf('<p>%s 的解析内容。</p>', $title);
                    $payload = buildQuestionPayload($questionType, $i);

                    $questionStmt->execute([
                        $questionId,
                        $title,
                        $questionType,
                        $categoryId,
                        $difficulty,
                        $stemHtml,
                        $analysisHtml,
                        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]);

                    $questionId++;
                }
            }
        }
    }

    $paperStmt = $pdo->prepare(
        'INSERT INTO `exam_papers` (`id`, `title`, `structure_code`, `client_requirement`, `randomize_questions`, `randomize_options`, `total_score`, `config_json`, `status`, `created_by`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 1)'
    );

    for ($paperId = 1; $paperId <= 12; $paperId++) {
        $isRandom = $paperId % 2 === 0;
        $title = sprintf('批量测试试卷 %02d', $paperId);
        $structureCode = sprintf('bulk-paper-%02d', $paperId);
        $config = $isRandom ? buildRandomPaperConfig() : buildFixedPaperConfig($paperId);
        $totalScore = $isRandom ? 100 : 80;

        $paperStmt->execute([
            $paperId,
            $title,
            $structureCode,
            'unrestricted',
            $isRandom ? 1 : 0,
            $isRandom ? 1 : 0,
            $totalScore,
            json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    $examStmt = $pdo->prepare(
        'INSERT INTO `exam_exams` (`id`, `title`, `paper_id`, `status`, `started_at`, `ended_at`, `attempt_limit`, `deadline_strategy`, `allow_view_score`, `allow_view_paper`, `allow_view_analysis`, `created_by`) VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, 1)'
    );
    $examGroupStmt = $pdo->prepare(
        'INSERT INTO `exam_exam_student_groups` (`exam_id`, `student_group_id`) VALUES (?, ?)'
    );

    for ($examId = 1; $examId <= 16; $examId++) {
        $paperId = (($examId - 1) % 12) + 1;
        $title = sprintf('批量测试考试 %02d', $examId);
        $start = sprintf('2026-06-%02d 09:00:00', (($examId - 1) % 20) + 1);
        $end = sprintf('2026-06-%02d 11:00:00', (($examId - 1) % 20) + 1);
        $attemptLimit = $examId % 3 === 0 ? 2 : 1;
        $deadlineStrategy = $examId % 2 === 0 ? 'continue_until_duration' : 'force_close';
        $allowViewScore = $examId % 2;
        $allowViewPaper = $examId % 3 === 0 ? 1 : 0;
        $allowViewAnalysis = $examId % 4 === 0 ? 1 : 0;

        $examStmt->execute([
            $examId,
            $title,
            $paperId,
            $start,
            $end,
            $attemptLimit,
            $deadlineStrategy,
            $allowViewScore,
            $allowViewPaper,
            $allowViewAnalysis,
        ]);

        $primaryGroup = (($examId - 1) % 8) + 1;
        $secondaryGroup = ($primaryGroup % 8) + 1;

        $examGroupStmt->execute([$examId, $primaryGroup]);
        $examGroupStmt->execute([$examId, $secondaryGroup]);
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $pdo->commit();

    echo "Demo data reset complete\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    throw $e;
}

function buildQuestionPayload(string $questionType, int $seed): array
{
    return match ($questionType) {
        'single' => [
            'options' => [
                ['key' => 'A', 'content' => '选项 A'],
                ['key' => 'B', 'content' => '选项 B'],
                ['key' => 'C', 'content' => '选项 C'],
                ['key' => 'D', 'content' => '选项 D'],
            ],
            'answer' => ['A', 'B', 'C', 'D'][$seed % 4],
        ],
        'multiple' => [
            'options' => [
                ['key' => 'A', 'content' => '条件 A'],
                ['key' => 'B', 'content' => '条件 B'],
                ['key' => 'C', 'content' => '条件 C'],
                ['key' => 'D', 'content' => '条件 D'],
            ],
            'answer' => $seed % 2 === 0 ? ['A', 'C'] : ['B', 'D'],
        ],
        'judge' => [
            'answer' => $seed % 2 === 0 ? 'true' : 'false',
        ],
        'blank' => [
            'answers' => [
                '测试答案一',
                '测试答案二',
            ],
        ],
        'short' => [
            'answer' => '这是简答题参考答案。',
        ],
        default => [],
    };
}

function buildFixedPaperConfig(int $paperId): array
{
    $baseQuestionId = (($paperId - 1) % 10) * 12 + 1;

    return [
        'mode' => 'fixed',
        'duration_minutes' => 90,
        'type_score_rules' => [
            'single' => 2,
            'multiple' => 4,
            'judge' => 2,
            'blank' => 5,
            'short' => 10,
        ],
        'question_items' => [
            ['question_id' => $baseQuestionId, 'score' => 2],
            ['question_id' => $baseQuestionId + 1, 'score' => 2],
            ['question_id' => $baseQuestionId + 48, 'score' => 4],
            ['question_id' => $baseQuestionId + 96, 'score' => 2],
            ['question_id' => $baseQuestionId + 144, 'score' => 5],
            ['question_id' => $baseQuestionId + 192, 'score' => 10],
            ['question_id' => $baseQuestionId + 2, 'score' => 2],
            ['question_id' => $baseQuestionId + 49, 'score' => 4],
            ['question_id' => $baseQuestionId + 97, 'score' => 2],
            ['question_id' => $baseQuestionId + 145, 'score' => 5],
            ['question_id' => $baseQuestionId + 193, 'score' => 10],
            ['question_id' => $baseQuestionId + 3, 'score' => 2],
            ['question_id' => $baseQuestionId + 50, 'score' => 4],
            ['question_id' => $baseQuestionId + 98, 'score' => 2],
            ['question_id' => $baseQuestionId + 146, 'score' => 5],
            ['question_id' => $baseQuestionId + 194, 'score' => 10],
            ['question_id' => $baseQuestionId + 4, 'score' => 2],
            ['question_id' => $baseQuestionId + 51, 'score' => 4],
            ['question_id' => $baseQuestionId + 99, 'score' => 2],
            ['question_id' => $baseQuestionId + 147, 'score' => 5],
        ],
    ];
}

function buildRandomPaperConfig(): array
{
    return [
        'mode' => 'random',
        'duration_minutes' => 120,
        'global_type_scores' => [
            'single' => 2,
            'multiple' => 4,
            'judge' => 2,
            'blank' => 5,
            'short' => 10,
        ],
        'type_rules' => [
            [
                'question_type' => 'single',
                'category_rules' => [
                    ['category_id' => 1, 'easy_count' => 5, 'easy_score' => 2, 'medium_count' => 3, 'medium_score' => 2, 'hard_count' => 2, 'hard_score' => 2],
                ],
            ],
            [
                'question_type' => 'multiple',
                'category_rules' => [
                    ['category_id' => 2, 'easy_count' => 2, 'easy_score' => 4, 'medium_count' => 2, 'medium_score' => 4, 'hard_count' => 1, 'hard_score' => 4],
                ],
            ],
            [
                'question_type' => 'judge',
                'category_rules' => [
                    ['category_id' => 3, 'easy_count' => 3, 'easy_score' => 2, 'medium_count' => 2, 'medium_score' => 2, 'hard_count' => 0, 'hard_score' => 2],
                ],
            ],
            [
                'question_type' => 'blank',
                'category_rules' => [
                    ['category_id' => 4, 'easy_count' => 1, 'easy_score' => 5, 'medium_count' => 1, 'medium_score' => 5, 'hard_count' => 1, 'hard_score' => 5],
                ],
            ],
            [
                'question_type' => 'short',
                'category_rules' => [
                    ['category_id' => 1, 'easy_count' => 1, 'easy_score' => 10, 'medium_count' => 1, 'medium_score' => 10, 'hard_count' => 1, 'hard_score' => 10],
                ],
            ],
        ],
    ];
}

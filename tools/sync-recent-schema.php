<?php

declare(strict_types=1);

require __DIR__ . '/db-bootstrap.php';

$pdo = createPdoFromEnvironment();

function hasTable(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);

    return (int) $stmt->fetchColumn() > 0;
}

function hasColumn(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);

    return (int) $stmt->fetchColumn() > 0;
}

function hasIndex(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $stmt->execute([$table, $index]);

    return (int) $stmt->fetchColumn() > 0;
}

function execSqlFile(PDO $pdo, string $file): void
{
    $sql = file_get_contents($file);
    if ($sql === false) {
        throw new RuntimeException('Failed to read file: ' . $file);
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

if (!hasTable($pdo, 'exam_question_categories')) {
    execSqlFile($pdo, __DIR__ . '/../database/schema/question_categories.sql');
    echo "Created exam_question_categories\n";
}

if (!hasTable($pdo, 'exam_exam_sessions')) {
    execSqlFile($pdo, __DIR__ . '/../database/schema/exam_sessions.sql');
    echo "Created exam_exam_sessions\n";
}

if (!hasTable($pdo, 'exam_exam_session_questions')) {
    execSqlFile($pdo, __DIR__ . '/../database/schema/exam_session_questions.sql');
    echo "Created exam_exam_session_questions\n";
}

if (!hasTable($pdo, 'exam_exam_answers')) {
    execSqlFile($pdo, __DIR__ . '/../database/schema/exam_answers.sql');
    echo "Created exam_exam_answers\n";
}

if (!hasTable($pdo, 'exam_exam_results')) {
    execSqlFile($pdo, __DIR__ . '/../database/schema/exam_results.sql');
    echo "Created exam_exam_results\n";
}

if (!hasTable($pdo, 'exam_exam_result_items')) {
    execSqlFile($pdo, __DIR__ . '/../database/schema/exam_result_items.sql');
    echo "Created exam_exam_result_items\n";
}

if (!hasTable($pdo, 'exam_exam_result_item_reviews')) {
    execSqlFile($pdo, __DIR__ . '/../database/schema/exam_result_item_reviews.sql');
    echo "Created exam_exam_result_item_reviews\n";
}

if (!hasTable($pdo, 'exam_exam_monitor_logs')) {
    execSqlFile($pdo, __DIR__ . '/../database/schema/exam_monitor_logs.sql');
    echo "Created exam_exam_monitor_logs\n";
}

if (!hasTable($pdo, 'exam_admin_audit_logs')) {
    execSqlFile($pdo, __DIR__ . '/../database/schema/admin_audit_logs.sql');
    echo "Created exam_admin_audit_logs\n";
}

if (!hasTable($pdo, 'exam_monitor_access_tokens')) {
    execSqlFile($pdo, __DIR__ . '/../database/schema/monitor_access_tokens.sql');
    echo "Created exam_monitor_access_tokens\n";
}

if (!hasTable($pdo, 'exam_monitor_bridge_tokens')) {
    execSqlFile($pdo, __DIR__ . '/../database/schema/monitor_bridge_tokens.sql');
    echo "Created exam_monitor_bridge_tokens\n";
}

if (!hasTable($pdo, 'exam_exam_student_students')) {
    execSqlFile($pdo, __DIR__ . '/../database/schema/exam_student_students.sql');
    echo "Created exam_exam_student_students\n";
}

$examResultItemColumns = [
    'review_note' => "ALTER TABLE `exam_exam_result_items` ADD COLUMN `review_note` varchar(1000) DEFAULT NULL AFTER `review_status`",
    'reviewed_by_admin_id' => "ALTER TABLE `exam_exam_result_items` ADD COLUMN `reviewed_by_admin_id` bigint unsigned DEFAULT NULL AFTER `review_note`",
    'reviewed_at' => "ALTER TABLE `exam_exam_result_items` ADD COLUMN `reviewed_at` datetime DEFAULT NULL AFTER `reviewed_by_admin_id`",
];

foreach ($examResultItemColumns as $column => $sql) {
    if (!hasColumn($pdo, 'exam_exam_result_items', $column)) {
        $pdo->exec($sql);
        echo "Added exam_exam_result_items.$column\n";
    }
}

$questionColumns = [
    'stem_html' => "ALTER TABLE `exam_questions` ADD COLUMN `stem_html` longtext NULL AFTER `structure_code`",
    'analysis_html' => "ALTER TABLE `exam_questions` ADD COLUMN `analysis_html` longtext NULL AFTER `stem_html`",
    'payload_json' => "ALTER TABLE `exam_questions` ADD COLUMN `payload_json` longtext NULL AFTER `analysis_html`",
    'category_id' => "ALTER TABLE `exam_questions` ADD COLUMN `category_id` bigint unsigned DEFAULT NULL AFTER `question_type`",
    'difficulty_level' => "ALTER TABLE `exam_questions` ADD COLUMN `difficulty_level` varchar(20) NOT NULL DEFAULT 'medium' AFTER `category_id`",
];

foreach ($questionColumns as $column => $sql) {
    if (!hasColumn($pdo, 'exam_questions', $column)) {
        $pdo->exec($sql);
        echo "Added exam_questions.$column\n";
    }
}

$paperColumns = [
    'client_requirement' => "ALTER TABLE `exam_papers` ADD COLUMN `client_requirement` varchar(30) NOT NULL DEFAULT 'unrestricted' AFTER `structure_code`",
    'randomize_questions' => "ALTER TABLE `exam_papers` ADD COLUMN `randomize_questions` tinyint(1) NOT NULL DEFAULT 0 AFTER `client_requirement`",
    'randomize_options' => "ALTER TABLE `exam_papers` ADD COLUMN `randomize_options` tinyint(1) NOT NULL DEFAULT 0 AFTER `randomize_questions`",
    'total_score' => "ALTER TABLE `exam_papers` ADD COLUMN `total_score` int NOT NULL DEFAULT 0 AFTER `randomize_options`",
    'config_json' => "ALTER TABLE `exam_papers` ADD COLUMN `config_json` longtext NULL AFTER `total_score`",
];

foreach ($paperColumns as $column => $sql) {
    if (!hasColumn($pdo, 'exam_papers', $column)) {
        $pdo->exec($sql);
        echo "Added exam_papers.$column\n";
    }
}

$examColumns = [
    'intro_html' => "ALTER TABLE `exam_exams` ADD COLUMN `intro_html` longtext NULL AFTER `title`",
    'enable_notice' => "ALTER TABLE `exam_exams` ADD COLUMN `enable_notice` tinyint(1) NOT NULL DEFAULT 0 AFTER `intro_html`",
    'notice_html' => "ALTER TABLE `exam_exams` ADD COLUMN `notice_html` longtext NULL AFTER `enable_notice`",
    'attempt_limit' => "ALTER TABLE `exam_exams` ADD COLUMN `attempt_limit` int NOT NULL DEFAULT 1 AFTER `ended_at`",
    'deadline_strategy' => "ALTER TABLE `exam_exams` ADD COLUMN `deadline_strategy` varchar(30) NOT NULL DEFAULT 'force_close' AFTER `attempt_limit`",
    'allow_view_score' => "ALTER TABLE `exam_exams` ADD COLUMN `allow_view_score` tinyint(1) NOT NULL DEFAULT 0 AFTER `deadline_strategy`",
    'allow_view_paper' => "ALTER TABLE `exam_exams` ADD COLUMN `allow_view_paper` tinyint(1) NOT NULL DEFAULT 0 AFTER `allow_view_score`",
    'allow_view_analysis' => "ALTER TABLE `exam_exams` ADD COLUMN `allow_view_analysis` tinyint(1) NOT NULL DEFAULT 0 AFTER `allow_view_paper`",
    'show_question_score' => "ALTER TABLE `exam_exams` ADD COLUMN `show_question_score` tinyint(1) NOT NULL DEFAULT 1 AFTER `allow_view_analysis`",
    'show_question_difficulty' => "ALTER TABLE `exam_exams` ADD COLUMN `show_question_difficulty` tinyint(1) NOT NULL DEFAULT 1 AFTER `show_question_score`",
    'auto_fullscreen' => "ALTER TABLE `exam_exams` ADD COLUMN `auto_fullscreen` tinyint(1) NOT NULL DEFAULT 0 AFTER `show_question_difficulty`",
    'enable_focus_monitor' => "ALTER TABLE `exam_exams` ADD COLUMN `enable_focus_monitor` tinyint(1) NOT NULL DEFAULT 0 AFTER `auto_fullscreen`",
    'focus_loss_limit' => "ALTER TABLE `exam_exams` ADD COLUMN `focus_loss_limit` int NOT NULL DEFAULT 0 AFTER `enable_focus_monitor`",
    'focus_loss_action' => "ALTER TABLE `exam_exams` ADD COLUMN `focus_loss_action` varchar(30) NOT NULL DEFAULT 'none' AFTER `focus_loss_limit`",
    'focus_loss_deduct_score' => "ALTER TABLE `exam_exams` ADD COLUMN `focus_loss_deduct_score` int NOT NULL DEFAULT 0 AFTER `focus_loss_action`",
    'exam_code' => "ALTER TABLE `exam_exams` ADD COLUMN `exam_code` varchar(24) DEFAULT NULL AFTER `focus_loss_deduct_score`",
    'monitor_slug' => "ALTER TABLE `exam_exams` ADD COLUMN `monitor_slug` varchar(32) DEFAULT NULL AFTER `exam_code`",
    'monitor_password_hash' => "ALTER TABLE `exam_exams` ADD COLUMN `monitor_password_hash` varchar(255) DEFAULT NULL AFTER `monitor_slug`",
    'monitor_password_ciphertext' => "ALTER TABLE `exam_exams` ADD COLUMN `monitor_password_ciphertext` varchar(255) DEFAULT NULL AFTER `monitor_password_hash`",
    'monitor_password_iv' => "ALTER TABLE `exam_exams` ADD COLUMN `monitor_password_iv` varchar(255) DEFAULT NULL AFTER `monitor_password_ciphertext`",
];

foreach ($examColumns as $column => $sql) {
    if (!hasColumn($pdo, 'exam_exams', $column)) {
        $pdo->exec($sql);
        echo "Added exam_exams.$column\n";
    }
}

$examSessionColumns = [
    'focus_loss_count' => "ALTER TABLE `exam_exam_sessions` ADD COLUMN `focus_loss_count` int NOT NULL DEFAULT 0 AFTER `last_question_id`",
    'last_focus_loss_at' => "ALTER TABLE `exam_exam_sessions` ADD COLUMN `last_focus_loss_at` datetime DEFAULT NULL AFTER `focus_loss_count`",
    'focus_loss_action_applied' => "ALTER TABLE `exam_exam_sessions` ADD COLUMN `focus_loss_action_applied` tinyint(1) NOT NULL DEFAULT 0 AFTER `last_focus_loss_at`",
    'penalty_score' => "ALTER TABLE `exam_exam_sessions` ADD COLUMN `penalty_score` int NOT NULL DEFAULT 0 AFTER `focus_loss_action_applied`",
    'force_zero_score' => "ALTER TABLE `exam_exam_sessions` ADD COLUMN `force_zero_score` tinyint(1) NOT NULL DEFAULT 0 AFTER `penalty_score`",
];

foreach ($examSessionColumns as $column => $sql) {
    if (!hasColumn($pdo, 'exam_exam_sessions', $column)) {
        $pdo->exec($sql);
        echo "Added exam_exam_sessions.$column\n";
    }
}

$examResultColumns = [
    'penalty_score' => "ALTER TABLE `exam_exam_results` ADD COLUMN `penalty_score` int NOT NULL DEFAULT 0 AFTER `manual_review_status`",
    'final_score' => "ALTER TABLE `exam_exam_results` ADD COLUMN `final_score` int NOT NULL DEFAULT 0 AFTER `penalty_score`",
    'cheating_status' => "ALTER TABLE `exam_exam_results` ADD COLUMN `cheating_status` varchar(30) NOT NULL DEFAULT 'none' AFTER `final_score`",
    'violation_count' => "ALTER TABLE `exam_exam_results` ADD COLUMN `violation_count` int NOT NULL DEFAULT 0 AFTER `cheating_status`",
];

foreach ($examResultColumns as $column => $sql) {
    if (!hasColumn($pdo, 'exam_exam_results', $column)) {
        $pdo->exec($sql);
        echo "Added exam_exam_results.$column\n";
    }
}

if (hasTable($pdo, 'exam_student_access_tokens') && !hasIndex($pdo, 'exam_student_access_tokens', 'uk_exam_student_access_tokens_student_id')) {
    $pdo->exec('DELETE t1 FROM `exam_student_access_tokens` t1 INNER JOIN `exam_student_access_tokens` t2 ON t1.student_id = t2.student_id AND t1.id < t2.id');
    $pdo->exec('CREATE UNIQUE INDEX `uk_exam_student_access_tokens_student_id` ON `exam_student_access_tokens` (`student_id`)');
    echo "Applied index: CREATE UNIQUE INDEX `uk_exam_student_access_tokens_student_id` ON `exam_student_access_tokens` (`student_id`)\n";
}

$examIndexes = [
    "CREATE UNIQUE INDEX `uk_exam_exams_exam_code` ON `exam_exams` (`exam_code`)",
    "CREATE UNIQUE INDEX `uk_exam_exams_monitor_slug` ON `exam_exams` (`monitor_slug`)",
];

foreach ($examIndexes as $sql) {
    try {
        $pdo->exec($sql);
        echo "Applied index: $sql\n";
    } catch (\PDOException) {
        // ignore when index already exists
    }
}

execSqlFile($pdo, __DIR__ . '/../database/schema/admin_rbac.seed.sql');
echo "Applied admin_rbac.seed.sql\n";

execSqlFile($pdo, __DIR__ . '/../database/schema/question_category_demo.seed.sql');
echo "Applied question_category_demo.seed.sql\n";

execSqlFile($pdo, __DIR__ . '/../database/schema/question_demo.seed.sql');
echo "Applied question_demo.seed.sql\n";

echo "Schema sync complete\n";

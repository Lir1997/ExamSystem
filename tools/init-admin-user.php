<?php

declare(strict_types=1);

require __DIR__ . '/db-bootstrap.php';

$pdo = createPdoFromEnvironment();

$schemaSql = file_get_contents(__DIR__ . '/../database/schema/admin_users.sql');
$tokenSchemaSql = file_get_contents(__DIR__ . '/../database/schema/admin_access_tokens.sql');
$roleSchemaSql = file_get_contents(__DIR__ . '/../database/schema/admin_roles.sql');
$permissionSchemaSql = file_get_contents(__DIR__ . '/../database/schema/admin_permissions.sql');
$rolePermissionSchemaSql = file_get_contents(__DIR__ . '/../database/schema/admin_role_permissions.sql');
$dataScopeSchemaSql = file_get_contents(__DIR__ . '/../database/schema/admin_data_scopes.sql');
$papersSchemaSql = file_get_contents(__DIR__ . '/../database/schema/papers.sql');
$questionsSchemaSql = file_get_contents(__DIR__ . '/../database/schema/questions.sql');
$examsSchemaSql = file_get_contents(__DIR__ . '/../database/schema/exams.sql');
$studentsSchemaSql = file_get_contents(__DIR__ . '/../database/schema/students.sql');
$studentGroupsSchemaSql = file_get_contents(__DIR__ . '/../database/schema/student_groups.sql');
$studentGroupMembersSchemaSql = file_get_contents(__DIR__ . '/../database/schema/student_group_members.sql');
$examStudentGroupsSchemaSql = file_get_contents(__DIR__ . '/../database/schema/exam_student_groups.sql');
$examStudentStudentsSchemaSql = file_get_contents(__DIR__ . '/../database/schema/exam_student_students.sql');
$systemSettingsSchemaSql = file_get_contents(__DIR__ . '/../database/schema/system_settings.sql');
$studentAccessTokensSchemaSql = file_get_contents(__DIR__ . '/../database/schema/student_access_tokens.sql');
$examSessionsSchemaSql = file_get_contents(__DIR__ . '/../database/schema/exam_sessions.sql');
$examSessionQuestionsSchemaSql = file_get_contents(__DIR__ . '/../database/schema/exam_session_questions.sql');
$examAnswersSchemaSql = file_get_contents(__DIR__ . '/../database/schema/exam_answers.sql');
$seedSql = file_get_contents(__DIR__ . '/../database/schema/admin_users.seed.sql');
$rbacSeedSql = file_get_contents(__DIR__ . '/../database/schema/admin_rbac.seed.sql');
$dataScopeSeedSql = file_get_contents(__DIR__ . '/../database/schema/data_scope_demo.seed.sql');
$questionSeedSql = file_get_contents(__DIR__ . '/../database/schema/question_demo.seed.sql');
$examSeedSql = file_get_contents(__DIR__ . '/../database/schema/exam_demo.seed.sql');
$studentSeedSql = file_get_contents(__DIR__ . '/../database/schema/student_demo.seed.sql');
$examStudentGroupSeedSql = file_get_contents(__DIR__ . '/../database/schema/exam_student_group_demo.seed.sql');
$examStudentStudentSeedSql = file_get_contents(__DIR__ . '/../database/schema/exam_student_student_demo.seed.sql');
$systemSettingsSeedSql = file_get_contents(__DIR__ . '/../database/schema/system_settings.seed.sql');

if (
    $schemaSql === false ||
    $tokenSchemaSql === false ||
    $roleSchemaSql === false ||
    $permissionSchemaSql === false ||
    $rolePermissionSchemaSql === false ||
    $dataScopeSchemaSql === false ||
    $papersSchemaSql === false ||
    $questionsSchemaSql === false ||
    $examsSchemaSql === false ||
    $studentsSchemaSql === false ||
    $studentGroupsSchemaSql === false ||
    $studentGroupMembersSchemaSql === false ||
    $examStudentGroupsSchemaSql === false ||
    $examStudentStudentsSchemaSql === false ||
    $systemSettingsSchemaSql === false ||
    $studentAccessTokensSchemaSql === false ||
    $examSessionsSchemaSql === false ||
    $examSessionQuestionsSchemaSql === false ||
    $examAnswersSchemaSql === false ||
    $seedSql === false ||
    $rbacSeedSql === false ||
    $dataScopeSeedSql === false ||
    $questionSeedSql === false ||
    $examSeedSql === false ||
    $studentSeedSql === false ||
    $examStudentGroupSeedSql === false ||
    $examStudentStudentSeedSql === false ||
    $systemSettingsSeedSql === false
) {
    throw new RuntimeException('无法读取管理员初始化 SQL 文件');
}

$pdo->exec($schemaSql);
$pdo->exec($tokenSchemaSql);
$pdo->exec($roleSchemaSql);
$pdo->exec($permissionSchemaSql);
$pdo->exec($rolePermissionSchemaSql);
$pdo->exec($dataScopeSchemaSql);
$pdo->exec($papersSchemaSql);
$pdo->exec($questionsSchemaSql);
$pdo->exec($examsSchemaSql);
$pdo->exec($studentsSchemaSql);
$pdo->exec($studentGroupsSchemaSql);
$pdo->exec($studentGroupMembersSchemaSql);
$pdo->exec($examStudentGroupsSchemaSql);
$pdo->exec($examStudentStudentsSchemaSql);
$pdo->exec($systemSettingsSchemaSql);
$pdo->exec($studentAccessTokensSchemaSql);
$pdo->exec($examSessionsSchemaSql);
$pdo->exec($examSessionQuestionsSchemaSql);
$pdo->exec($examAnswersSchemaSql);
$pdo->exec($seedSql);
$pdo->exec($rbacSeedSql);
$pdo->exec($dataScopeSeedSql);
$pdo->exec($questionSeedSql);
$pdo->exec($examSeedSql);
$pdo->exec($studentSeedSql);
$pdo->exec($examStudentGroupSeedSql);
$pdo->exec($examStudentStudentSeedSql);
$pdo->exec($systemSettingsSeedSql);

echo "管理员表和默认账号初始化完成。\n";

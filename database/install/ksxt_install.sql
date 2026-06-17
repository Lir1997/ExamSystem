-- 考试系统 V1.0 初始化数据库
-- 导入前请先手动选择目标数据库

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `exam_admin_users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `name` varchar(100) NOT NULL,
  `role_code` varchar(50) NOT NULL DEFAULT 'admin',
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` datetime DEFAULT NULL,
  `last_login_ip` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_exam_admin_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `exam_admin_access_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `admin_user_id` bigint unsigned NOT NULL,
  `token` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `last_used_at` datetime DEFAULT NULL,
  `last_used_ip` varchar(45) DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_exam_admin_access_tokens_token` (`token`),
  KEY `idx_exam_admin_access_tokens_admin_user_id` (`admin_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `exam_admin_roles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_exam_admin_roles_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `exam_admin_permissions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(100) NOT NULL,
  `name` varchar(100) NOT NULL,
  `path` varchar(255) NOT NULL DEFAULT '',
  `icon` varchar(50) NOT NULL DEFAULT '',
  `menu_visible` tinyint(1) NOT NULL DEFAULT 0,
  `sort` int NOT NULL DEFAULT 0,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_exam_admin_permissions_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `exam_admin_role_permissions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `role_id` bigint unsigned NOT NULL,
  `permission_id` bigint unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_exam_admin_role_permission_unique` (`role_id`,`permission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `exam_admin_data_scopes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `admin_user_id` bigint unsigned NOT NULL,
  `scope_type` varchar(50) NOT NULL,
  `scope_value` varchar(100) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_exam_admin_data_scopes_unique` (`admin_user_id`,`scope_type`,`scope_value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `exam_admin_audit_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `admin_user_id` bigint unsigned DEFAULT NULL,
  `admin_name` varchar(100) DEFAULT NULL,
  `role_code` varchar(50) DEFAULT NULL,
  `module` varchar(30) NOT NULL DEFAULT 'admin',
  `action_key` varchar(120) NOT NULL,
  `request_method` varchar(10) NOT NULL,
  `request_path` varchar(255) NOT NULL,
  `request_ip` varchar(45) DEFAULT NULL,
  `request_params_json` longtext NULL,
  `response_code` int NOT NULL DEFAULT 0,
  `response_message` varchar(255) DEFAULT NULL,
  `duration_ms` int NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_exam_admin_audit_logs_module_created_at` (`module`,`created_at`),
  KEY `idx_exam_admin_audit_logs_admin_user_id_created_at` (`admin_user_id`,`created_at`),
  KEY `idx_exam_admin_audit_logs_action_key_created_at` (`action_key`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `exam_question_categories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `code` varchar(50) NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `sort` int NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_exam_question_categories_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `exam_questions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `question_type` varchar(50) NOT NULL,
  `category_id` bigint unsigned DEFAULT NULL,
  `difficulty_level` varchar(20) NOT NULL DEFAULT 'medium',
  `stem_html` longtext,
  `analysis_html` longtext,
  `payload_json` longtext,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `exam_papers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `structure_code` varchar(50) NOT NULL DEFAULT 'default',
  `client_requirement` varchar(30) NOT NULL DEFAULT 'unrestricted',
  `randomize_questions` tinyint(1) NOT NULL DEFAULT 0,
  `randomize_options` tinyint(1) NOT NULL DEFAULT 0,
  `total_score` int NOT NULL DEFAULT 0,
  `config_json` longtext,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `exam_students` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `student_no` varchar(50) NOT NULL,
  `id_card` varchar(30) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_exam_students_username` (`username`),
  UNIQUE KEY `uk_exam_students_student_no` (`student_no`),
  UNIQUE KEY `uk_exam_students_id_card` (`id_card`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `exam_student_groups` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_exam_student_groups_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `exam_student_group_members` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `student_group_id` bigint unsigned NOT NULL,
  `student_id` bigint unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_exam_student_group_members_unique` (`student_group_id`,`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `exam_student_access_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `student_id` bigint unsigned NOT NULL,
  `token` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `last_used_at` datetime DEFAULT NULL,
  `last_used_ip` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_exam_student_access_tokens_token` (`token`),
  UNIQUE KEY `uk_exam_student_access_tokens_student_id` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `exam_system_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_exam_system_settings_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `exam_exams` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `intro_html` longtext NULL,
  `enable_notice` tinyint(1) NOT NULL DEFAULT 0,
  `notice_html` longtext NULL,
  `paper_id` bigint unsigned DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `started_at` datetime DEFAULT NULL,
  `ended_at` datetime DEFAULT NULL,
  `attempt_limit` int NOT NULL DEFAULT 1,
  `deadline_strategy` varchar(30) NOT NULL DEFAULT 'force_close',
  `allow_view_score` tinyint(1) NOT NULL DEFAULT 0,
  `allow_view_paper` tinyint(1) NOT NULL DEFAULT 0,
  `allow_view_analysis` tinyint(1) NOT NULL DEFAULT 0,
  `show_question_score` tinyint(1) NOT NULL DEFAULT 1,
  `show_question_difficulty` tinyint(1) NOT NULL DEFAULT 1,
  `auto_fullscreen` tinyint(1) NOT NULL DEFAULT 0,
  `enable_focus_monitor` tinyint(1) NOT NULL DEFAULT 0,
  `focus_loss_limit` int NOT NULL DEFAULT 0,
  `focus_loss_action` varchar(30) NOT NULL DEFAULT 'none',
  `focus_loss_deduct_score` int NOT NULL DEFAULT 0,
  `exam_code` varchar(24) DEFAULT NULL,
  `monitor_slug` varchar(32) DEFAULT NULL,
  `monitor_password_hash` varchar(255) DEFAULT NULL,
  `monitor_password_ciphertext` varchar(255) DEFAULT NULL,
  `monitor_password_iv` varchar(255) DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_exam_exams_exam_code` (`exam_code`),
  UNIQUE KEY `uk_exam_exams_monitor_slug` (`monitor_slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `exam_exam_student_groups` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `exam_id` bigint unsigned NOT NULL,
  `student_group_id` bigint unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_exam_exam_student_groups_unique` (`exam_id`,`student_group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `exam_exam_student_students` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `exam_id` bigint unsigned NOT NULL,
  `student_id` bigint unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_exam_exam_student_students_unique` (`exam_id`,`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `exam_exam_sessions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `exam_id` bigint unsigned NOT NULL,
  `paper_id` bigint unsigned NOT NULL,
  `student_id` bigint unsigned NOT NULL,
  `attempt_no` int NOT NULL DEFAULT 1,
  `status` varchar(20) NOT NULL DEFAULT 'in_progress',
  `started_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deadline_at` datetime DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `last_saved_at` datetime DEFAULT NULL,
  `last_question_id` bigint unsigned DEFAULT NULL,
  `focus_loss_count` int NOT NULL DEFAULT 0,
  `last_focus_loss_at` datetime DEFAULT NULL,
  `focus_loss_action_applied` tinyint(1) NOT NULL DEFAULT 0,
  `penalty_score` int NOT NULL DEFAULT 0,
  `force_zero_score` tinyint(1) NOT NULL DEFAULT 0,
  `client_ip` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_exam_exam_sessions_exam_student` (`exam_id`, `student_id`),
  KEY `idx_exam_exam_sessions_student_status` (`student_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `exam_exam_session_questions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `session_id` bigint unsigned NOT NULL,
  `question_id` bigint unsigned NOT NULL,
  `display_order` int NOT NULL,
  `question_type` varchar(50) NOT NULL,
  `score` int NOT NULL DEFAULT 0,
  `question_snapshot_json` longtext NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_exam_exam_session_questions_session_question` (`session_id`, `question_id`),
  UNIQUE KEY `uk_exam_exam_session_questions_session_order` (`session_id`, `display_order`),
  KEY `idx_exam_exam_session_questions_session` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `exam_exam_answers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `session_id` bigint unsigned NOT NULL,
  `question_id` bigint unsigned NOT NULL,
  `answer_json` longtext NULL,
  `answered_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_exam_exam_answers_session_question` (`session_id`, `question_id`),
  KEY `idx_exam_exam_answers_session` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `exam_exam_results` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `session_id` bigint unsigned NOT NULL,
  `exam_id` bigint unsigned NOT NULL,
  `paper_id` bigint unsigned NOT NULL,
  `student_id` bigint unsigned NOT NULL,
  `attempt_no` int NOT NULL DEFAULT 1,
  `session_status` varchar(20) NOT NULL DEFAULT 'submitted',
  `objective_score` int NOT NULL DEFAULT 0,
  `subjective_score` int NOT NULL DEFAULT 0,
  `total_score` int NOT NULL DEFAULT 0,
  `objective_total_score` int NOT NULL DEFAULT 0,
  `subjective_total_score` int NOT NULL DEFAULT 0,
  `answered_count` int NOT NULL DEFAULT 0,
  `correct_count` int NOT NULL DEFAULT 0,
  `pending_manual_count` int NOT NULL DEFAULT 0,
  `manual_review_status` varchar(30) NOT NULL DEFAULT 'pending',
  `penalty_score` int NOT NULL DEFAULT 0,
  `final_score` int NOT NULL DEFAULT 0,
  `cheating_status` varchar(30) NOT NULL DEFAULT 'none',
  `violation_count` int NOT NULL DEFAULT 0,
  `submitted_at` datetime DEFAULT NULL,
  `generated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_exam_exam_results_session` (`session_id`),
  KEY `idx_exam_exam_results_exam_student` (`exam_id`, `student_id`),
  KEY `idx_exam_exam_results_manual_review` (`manual_review_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `exam_exam_result_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `result_id` bigint unsigned NOT NULL,
  `session_id` bigint unsigned NOT NULL,
  `question_id` bigint unsigned NOT NULL,
  `display_order` int NOT NULL DEFAULT 0,
  `question_type` varchar(50) NOT NULL,
  `score` int NOT NULL DEFAULT 0,
  `earned_score` int NOT NULL DEFAULT 0,
  `is_answered` tinyint(1) NOT NULL DEFAULT 0,
  `is_correct` tinyint(1) DEFAULT NULL,
  `needs_manual_review` tinyint(1) NOT NULL DEFAULT 0,
  `review_status` varchar(30) NOT NULL DEFAULT 'auto_scored',
  `review_note` varchar(1000) DEFAULT NULL,
  `reviewed_by_admin_id` bigint unsigned DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `answer_json` longtext NULL,
  `reference_answer_json` longtext NULL,
  `question_snapshot_json` longtext NOT NULL,
  `answered_at` datetime DEFAULT NULL,
  `scored_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_exam_exam_result_items_result_question` (`result_id`, `question_id`),
  KEY `idx_exam_exam_result_items_session` (`session_id`),
  KEY `idx_exam_exam_result_items_review_status` (`review_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `exam_exam_result_item_reviews` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `result_id` bigint unsigned NOT NULL,
  `result_item_id` bigint unsigned NOT NULL,
  `session_id` bigint unsigned NOT NULL,
  `question_id` bigint unsigned NOT NULL,
  `reviewer_admin_id` bigint unsigned DEFAULT NULL,
  `reviewer_name` varchar(100) DEFAULT NULL,
  `score_before` int NOT NULL DEFAULT 0,
  `score_after` int NOT NULL DEFAULT 0,
  `review_note` varchar(1000) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_exam_exam_result_item_reviews_result_item` (`result_item_id`),
  KEY `idx_exam_exam_result_item_reviews_result` (`result_id`),
  KEY `idx_exam_exam_result_item_reviews_reviewer` (`reviewer_admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `exam_monitor_access_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `exam_id` bigint unsigned NOT NULL,
  `issued_by_admin_id` bigint unsigned DEFAULT NULL,
  `token` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `last_used_at` datetime DEFAULT NULL,
  `last_used_ip` varchar(45) DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_exam_monitor_access_tokens_token` (`token`),
  KEY `idx_exam_monitor_access_tokens_exam_id` (`exam_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `exam_monitor_bridge_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `exam_id` bigint unsigned NOT NULL,
  `admin_user_id` bigint unsigned DEFAULT NULL,
  `token` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `used_ip` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_exam_monitor_bridge_tokens_token` (`token`),
  KEY `idx_exam_monitor_bridge_tokens_exam_id` (`exam_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `exam_exam_monitor_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `exam_id` bigint unsigned NOT NULL,
  `session_id` bigint unsigned DEFAULT NULL,
  `student_id` bigint unsigned DEFAULT NULL,
  `source` varchar(20) NOT NULL DEFAULT 'system',
  `log_type` varchar(40) NOT NULL,
  `severity` varchar(20) NOT NULL DEFAULT 'info',
  `action_type` varchar(30) DEFAULT NULL,
  `action_value` int NOT NULL DEFAULT 0,
  `note` varchar(1000) DEFAULT NULL,
  `payload_json` longtext NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_exam_exam_monitor_logs_exam` (`exam_id`, `created_at`),
  KEY `idx_exam_exam_monitor_logs_student` (`student_id`, `created_at`),
  KEY `idx_exam_exam_monitor_logs_session` (`session_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `exam_admin_roles` (`code`, `name`, `status`)
VALUES
  ('admin', '系统管理员', 1),
  ('teacher', '教师', 1)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `status` = VALUES(`status`);

INSERT INTO `exam_admin_permissions` (`code`, `name`, `path`, `icon`, `menu_visible`, `sort`, `status`)
VALUES
  ('dashboard.view', '工作台', '/', 'House', 1, 10, 1),
  ('user.manage', '用户管理', '/users', 'UserFilled', 1, 15, 1),
  ('permission.manage', '权限管理', '/permissions', 'Lock', 1, 18, 1),
  ('audit.view', '审计中心', '/audits', 'Notebook', 1, 19, 1),
  ('system.settings.view', '系统设置', '/system/settings', 'Setting', 1, 20, 1),
  ('student.manage', '学生管理', '/students', 'Avatar', 1, 30, 1),
  ('student.group.manage', '学生分组', '/student-groups', 'Files', 1, 40, 1),
  ('paper.manage', '试卷管理', '/papers', 'Document', 1, 50, 1),
  ('question.manage', '试题管理', '/questions', 'Tickets', 1, 60, 1),
  ('question.category.manage', '试题分类管理', '/question-categories', 'Collection', 1, 70, 1),
  ('exam.manage', '考试管理', '/exams', 'Calendar', 1, 80, 1),
  ('marking.manage', '成绩管理', '/marking', 'Checked', 1, 90, 1),
  ('teacher.manage', '教师管理', '/users', 'User', 0, 100, 1)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `path` = VALUES(`path`),
  `icon` = VALUES(`icon`),
  `menu_visible` = VALUES(`menu_visible`),
  `sort` = VALUES(`sort`),
  `status` = VALUES(`status`);

INSERT INTO `exam_admin_role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `exam_admin_roles` r
JOIN `exam_admin_permissions` p
WHERE r.code = 'admin'
ON DUPLICATE KEY UPDATE `permission_id` = VALUES(`permission_id`);

INSERT INTO `exam_admin_role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `exam_admin_roles` r
JOIN `exam_admin_permissions` p
WHERE r.code = 'teacher'
  AND p.code IN ('dashboard.view', 'paper.manage', 'question.manage', 'question.category.manage', 'exam.manage')
ON DUPLICATE KEY UPDATE `permission_id` = VALUES(`permission_id`);

INSERT INTO `exam_admin_users` (`username`, `password`, `name`, `role_code`, `status`)
VALUES (
  'admin',
  '$2y$10$.rG4IARNGr5pFeqqb9XRveYyiRe0RS8aH6/HbtVece9jdmQh9.jVu',
  '系统管理员',
  'admin',
  1
)
ON DUPLICATE KEY UPDATE
  `password` = VALUES(`password`),
  `name` = VALUES(`name`),
  `role_code` = VALUES(`role_code`),
  `status` = VALUES(`status`);

INSERT INTO `exam_system_settings` (`setting_key`, `setting_value`)
VALUES
  ('site_name', '在线考试系统'),
  ('auth_mode', 'bearer-token'),
  ('frontend_admin_path', '/admin#/'),
  ('frontend_exam_path', '/exam#/'),
  ('student_login_mode', 'username_password'),
  ('student_default_password', 'student123'),
  ('exam_visible_before_hours', '0'),
  ('exam_visible_after_hours', '0'),
  ('exam_finish_auto_logout', '0'),
  ('exam_finish_message', '您已完成考试，请根据监考人员要求有序离开考场。'),
  ('exam_timeout_task_token', 'b83928f4ea38ddbf3ecad644723631449430897b3ec89c68')
ON DUPLICATE KEY UPDATE
  `setting_value` = VALUES(`setting_value`);

SET FOREIGN_KEY_CHECKS = 1;

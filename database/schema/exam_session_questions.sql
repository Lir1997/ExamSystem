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

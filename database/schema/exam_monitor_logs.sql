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

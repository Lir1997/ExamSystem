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

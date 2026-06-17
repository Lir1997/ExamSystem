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

CREATE TABLE IF NOT EXISTS `exam_student_group_members` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `student_group_id` bigint unsigned NOT NULL,
  `student_id` bigint unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_exam_student_group_members_unique` (`student_group_id`,`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

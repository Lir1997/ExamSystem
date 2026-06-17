ALTER TABLE `exam_questions`
  ADD COLUMN `category_id` bigint unsigned DEFAULT NULL AFTER `question_type`,
  ADD COLUMN `difficulty_level` varchar(20) NOT NULL DEFAULT 'medium' AFTER `category_id`;

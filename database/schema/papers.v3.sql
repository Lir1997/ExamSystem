ALTER TABLE `exam_papers`
  ADD COLUMN `randomize_questions` tinyint(1) NOT NULL DEFAULT 0 AFTER `client_requirement`,
  ADD COLUMN `randomize_options` tinyint(1) NOT NULL DEFAULT 0 AFTER `randomize_questions`;

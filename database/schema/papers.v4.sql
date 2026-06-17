ALTER TABLE `exam_papers`
  ADD COLUMN `total_score` int NOT NULL DEFAULT 0 AFTER `randomize_options`,
  ADD COLUMN `config_json` longtext NULL AFTER `total_score`;

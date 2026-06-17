ALTER TABLE `exam_questions`
  ADD COLUMN `stem_html` longtext NULL AFTER `structure_code`,
  ADD COLUMN `analysis_html` longtext NULL AFTER `stem_html`,
  ADD COLUMN `payload_json` longtext NULL AFTER `analysis_html`;

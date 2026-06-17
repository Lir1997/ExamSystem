ALTER TABLE `exam_papers`
  ADD COLUMN `client_requirement` varchar(30) NOT NULL DEFAULT 'unrestricted' AFTER `structure_code`;

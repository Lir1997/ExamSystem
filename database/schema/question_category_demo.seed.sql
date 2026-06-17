INSERT INTO `exam_question_categories` (`id`, `name`, `code`, `status`, `sort`)
VALUES
  (1, '基础理论', 'theory-basic', 1, 10),
  (2, '法规知识', 'law', 1, 20),
  (3, '营销实务', 'marketing', 1, 30),
  (4, '操作实训', 'operation', 1, 40)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `code` = VALUES(`code`),
  `status` = VALUES(`status`),
  `sort` = VALUES(`sort`);

INSERT INTO `exam_questions` (`id`, `title`, `question_type`, `category_id`, `difficulty_level`, `stem_html`, `analysis_html`, `payload_json`, `status`, `created_by`)
VALUES
  (1, '房地产品基础单选题示例', 'single', 1, 'easy', '<p>这里是单选题题干，可插入图片或富文本。</p>', '<p>正确答案是 A。</p>', '{"options":[{"key":"A","content":"答案 A"},{"key":"B","content":"答案 B"},{"key":"C","content":"答案 C"},{"key":"D","content":"答案 D"}],"answer":"A"}', 1, 1),
  (2, '房地产品营销多选题示例', 'multiple', 3, 'medium', '<p>这里是多选题题干。</p>', '<p>本题支持多个正确答案。</p>', '{"options":[{"key":"A","content":"条件 1"},{"key":"B","content":"条件 2"},{"key":"C","content":"条件 3"},{"key":"D","content":"条件 4"}],"answer":["A","C"]}', 1, 1),
  (3, '房地产品法规判断题示例', 'judge', 2, 'medium', '<p>请判断下列说法是否正确。</p>', '<p>判断题的正确答案在服务端统一解释。</p>', '{"answer":"true"}', 1, 1),
  (4, '交易流程填空题示例', 'blank', 1, 'hard', '<p>请在空白处填写代码。</p>', '<p>填空题可按空格分解。</p>', '{"answers":["第一空答案","第二空答案"]}', 1, 1),
  (5, '客户接待简答题示例', 'short', 3, 'medium', '<p>简答题可用于回答原理或过程描述。</p>', '<p>后续可统一加入人工阅卷流程。</p>', '{"answer":"简答参考答案"}', 1, 1),
  (6, '办公软件操作题示例', 'operation', 4, 'hard', '<p>操作题可插入任务要求、文件素材和图片。</p>', '<p>操作题的待后续模块作为提示。</p>', '{"requirement":"请完成指定操作并上传结果","reference_answer":"参考操作步骤或评分点","package":null,"client_task":{"download_dir":"","open_file_name":"","auto_upload":true,"result_name":"result.zip"}}', 1, 1)
ON DUPLICATE KEY UPDATE
  `title` = VALUES(`title`),
  `question_type` = VALUES(`question_type`),
  `category_id` = VALUES(`category_id`),
  `difficulty_level` = VALUES(`difficulty_level`),
  `stem_html` = VALUES(`stem_html`),
  `analysis_html` = VALUES(`analysis_html`),
  `payload_json` = VALUES(`payload_json`),
  `status` = VALUES(`status`),
  `created_by` = VALUES(`created_by`);

INSERT INTO `exam_admin_data_scopes` (`admin_user_id`, `scope_type`, `scope_value`)
SELECT u.id, 'question', '3'
FROM `exam_admin_users` u
WHERE u.username = 'teacher01'
ON DUPLICATE KEY UPDATE `scope_value` = VALUES(`scope_value`);

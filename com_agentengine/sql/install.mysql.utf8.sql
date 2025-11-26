CREATE TABLE IF NOT EXISTS `#__agentengine_agents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `provider` varchar(255) NOT NULL,
  `model` varchar(255) NOT NULL,
  `system_prompt` text NOT NULL,
  `tools` text NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

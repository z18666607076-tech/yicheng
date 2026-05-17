-- 创建项目表
CREATE TABLE IF NOT EXISTS `compete_projects` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL COMMENT '项目名称',
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '状态：1=启用，0=禁用',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='数据竞对项目表';

-- 创建平台表
CREATE TABLE IF NOT EXISTS `compete_platforms` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL COMMENT '平台名称',
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '状态：1=启用，0=禁用',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='数据竞对平台表';

-- 创建数据竞对表
CREATE TABLE IF NOT EXISTS `compete_data` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `project_id` INT(11) NOT NULL COMMENT '项目ID',
  `date` DATE NOT NULL COMMENT '日期',
  `platform_id` INT(11) NOT NULL COMMENT '平台ID',
  `visits` INT(11) NOT NULL DEFAULT 0 COMMENT '来访',
  `deals` INT(11) NOT NULL DEFAULT 0 COMMENT '成交',
  `locks` INT(11) NOT NULL DEFAULT 0 COMMENT '锁筹',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `project_date_platform` (`project_id`, `date`, `platform_id`),
  KEY `project_id` (`project_id`),
  KEY `platform_id` (`platform_id`),
  KEY `date` (`date`),
  CONSTRAINT `compete_data_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `compete_projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `compete_data_ibfk_2` FOREIGN KEY (`platform_id`) REFERENCES `compete_platforms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='数据竞对数据表';
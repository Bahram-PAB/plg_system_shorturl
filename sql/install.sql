CREATE TABLE IF NOT EXISTS `#__shorturl` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `article_id` INT(11) UNSIGNED NOT NULL,
    `short_code` VARCHAR(50) NOT NULL,
    `created` DATETIME NOT NULL,
    `modified` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `short_code` (`short_code`),
    KEY `article_id` (`article_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

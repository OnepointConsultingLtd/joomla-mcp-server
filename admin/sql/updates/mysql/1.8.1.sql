CREATE TABLE IF NOT EXISTS `#__mcpserver_credential_request` (
  `id`                 INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`            INT(11) UNSIGNED NOT NULL,
  `client_name`        VARCHAR(150) NOT NULL DEFAULT '',
  `status`             VARCHAR(20) NOT NULL DEFAULT 'requested',
  `requested`          DATETIME NOT NULL,
  `decided`            DATETIME NULL DEFAULT NULL,
  `decided_by`         INT(11) UNSIGNED NULL DEFAULT NULL,
  `credential_expires` DATETIME NULL DEFAULT NULL,
  `claimed`            DATETIME NULL DEFAULT NULL,
  `credential_id`      INT(11) UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_credential_id` (`credential_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__mcpserver_request_event` (
  `id`          INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id`  INT(11) UNSIGNED NOT NULL,
  `event`       VARCHAR(20) NOT NULL DEFAULT '',
  `actor_id`    INT(11) UNSIGNED NULL DEFAULT NULL,
  `created`     DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_request_id` (`request_id`),
  KEY `idx_created` (`created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

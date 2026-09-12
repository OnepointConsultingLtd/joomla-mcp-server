CREATE TABLE IF NOT EXISTS `#__mcpserver_credential` (
  `id`                INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `selector`          VARCHAR(32)  NOT NULL DEFAULT '',
  `user_id`           INT(11) UNSIGNED NOT NULL DEFAULT 0,
  `name`              VARCHAR(150) NOT NULL DEFAULT '',
  `verifier`          VARCHAR(255) NOT NULL DEFAULT '',
  `token_ciphertext`  MEDIUMTEXT   NOT NULL,
  `token_nonce`       VARCHAR(64)  NOT NULL DEFAULT '',
  `token_tag`         VARCHAR(64)  NOT NULL DEFAULT '',
  `key_version`       INT(11) UNSIGNED NOT NULL DEFAULT 1,
  `status`            VARCHAR(20)  NOT NULL DEFAULT 'active',
  `created`           DATETIME NOT NULL,
  `expires`           DATETIME NULL DEFAULT NULL,
  `revoked`           DATETIME NULL DEFAULT NULL,
  `last_used`         DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_selector` (`selector`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_expires` (`expires`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `#__mcpserver_request_log`
  ADD COLUMN `request_id` VARCHAR(64) NULL DEFAULT NULL AFTER `context`,
  ADD COLUMN `credential_id` INT(11) UNSIGNED NULL DEFAULT NULL AFTER `request_id`,
  ADD COLUMN `user_id` INT(11) UNSIGNED NULL DEFAULT NULL AFTER `credential_id`,
  ADD COLUMN `credential_selector` VARCHAR(32) NULL DEFAULT NULL AFTER `user_id`,
  ADD COLUMN `target` VARCHAR(255) NULL DEFAULT NULL AFTER `credential_selector`,
  ADD KEY `idx_request_id` (`request_id`),
  ADD KEY `idx_credential_id` (`credential_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_credential_selector` (`credential_selector`);

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

-- MySQL has no ADD COLUMN IF NOT EXISTS, so each statement below carries
-- Joomla's /** CAN FAIL **/ marker and is applied independently. Without this,
-- any transient failure in a later statement in this file would leave the
-- schema version unrecorded, and the retry would abort on "Duplicate column
-- name 'request_id'" — blocking the upgrade permanently.
ALTER TABLE `#__mcpserver_request_log` ADD COLUMN `request_id` VARCHAR(64) NULL DEFAULT NULL AFTER `context` /** CAN FAIL **/;
ALTER TABLE `#__mcpserver_request_log` ADD COLUMN `credential_id` INT(11) UNSIGNED NULL DEFAULT NULL AFTER `request_id` /** CAN FAIL **/;
ALTER TABLE `#__mcpserver_request_log` ADD COLUMN `user_id` INT(11) UNSIGNED NULL DEFAULT NULL AFTER `credential_id` /** CAN FAIL **/;
ALTER TABLE `#__mcpserver_request_log` ADD COLUMN `credential_selector` VARCHAR(32) NULL DEFAULT NULL AFTER `user_id` /** CAN FAIL **/;
ALTER TABLE `#__mcpserver_request_log` ADD COLUMN `target` VARCHAR(255) NULL DEFAULT NULL AFTER `credential_selector` /** CAN FAIL **/;
ALTER TABLE `#__mcpserver_request_log` ADD KEY `idx_request_id` (`request_id`) /** CAN FAIL **/;
ALTER TABLE `#__mcpserver_request_log` ADD KEY `idx_credential_id` (`credential_id`) /** CAN FAIL **/;
ALTER TABLE `#__mcpserver_request_log` ADD KEY `idx_user_id` (`user_id`) /** CAN FAIL **/;
ALTER TABLE `#__mcpserver_request_log` ADD KEY `idx_credential_selector` (`credential_selector`) /** CAN FAIL **/;

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

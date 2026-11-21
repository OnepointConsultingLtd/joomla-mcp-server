CREATE TABLE IF NOT EXISTS `#__mcpserver_request_log` (
  `id`                  INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `created`             DATETIME NOT NULL,
  `method`              VARCHAR(64)  NOT NULL DEFAULT '',
  `tool_name`           VARCHAR(128) NOT NULL DEFAULT '',
  `status`              VARCHAR(20)  NOT NULL DEFAULT '',
  `error_code`          INT(11)      NULL DEFAULT NULL,
  `http_status`         SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
  `duration_ms`         INT(11) UNSIGNED NOT NULL DEFAULT 0,
  `client_ip`           VARCHAR(45)  NOT NULL DEFAULT '',
  `context`             VARCHAR(10)  NOT NULL DEFAULT '',
  `request_id`          VARCHAR(64)  NULL DEFAULT NULL,
  `credential_id`       INT(11) UNSIGNED NULL DEFAULT NULL,
  `user_id`             INT(11) UNSIGNED NULL DEFAULT NULL,
  `credential_selector` VARCHAR(32)  NULL DEFAULT NULL,
  `target`              VARCHAR(255) NULL DEFAULT NULL,
  `protocol_version`    VARCHAR(20)  NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created`),
  KEY `idx_method` (`method`),
  KEY `idx_tool` (`tool_name`),
  KEY `idx_status` (`status`),
  KEY `idx_request_id` (`request_id`),
  KEY `idx_credential_id` (`credential_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_credential_selector` (`credential_selector`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS `#__mcpserver_oauth_client` (
  `id`                         INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id`                  VARCHAR(191) NOT NULL,
  `client_name`                VARCHAR(150) NOT NULL DEFAULT '',
  `redirect_uris`              TEXT         NOT NULL,
  `token_endpoint_auth_method` VARCHAR(32)  NOT NULL DEFAULT 'none',
  `secret_hash`                CHAR(64)     NULL DEFAULT NULL,
  `created`                    DATETIME     NOT NULL,
  `last_used`                  DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_client_id` (`client_id`),
  KEY `idx_created` (`created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__mcpserver_oauth_grant` (
  `id`            INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id`     VARCHAR(191) NOT NULL,
  `user_id`       INT(11) UNSIGNED NOT NULL,
  `credential_id` INT(11) UNSIGNED NOT NULL,
  `scope`         VARCHAR(255) NOT NULL DEFAULT '',
  `resource`      VARCHAR(2048) NOT NULL DEFAULT '',
  `created`       DATETIME NOT NULL,
  `revoked`       DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_client_id` (`client_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_credential_id` (`credential_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__mcpserver_oauth_code` (
  `id`             INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `code_hash`      CHAR(64)     NOT NULL,
  `grant_id`       INT(11) UNSIGNED NOT NULL,
  `redirect_uri`   VARCHAR(2048) NOT NULL DEFAULT '',
  `code_challenge` VARCHAR(128) NOT NULL DEFAULT '',
  `expires`        DATETIME NOT NULL,
  `used`           DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_code_hash` (`code_hash`),
  KEY `idx_expires` (`expires`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__mcpserver_oauth_token` (
  `id`         INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `token_hash` CHAR(64)    NOT NULL,
  `grant_id`   INT(11) UNSIGNED NOT NULL,
  `type`       VARCHAR(16) NOT NULL,
  `created`    DATETIME NOT NULL,
  `expires`    DATETIME NOT NULL,
  `revoked`    DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_token_hash` (`token_hash`),
  KEY `idx_grant_id` (`grant_id`),
  KEY `idx_expires` (`expires`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

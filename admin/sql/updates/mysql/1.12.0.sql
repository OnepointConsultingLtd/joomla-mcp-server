-- 1.12.0 adds OAuth 2.1 sign-in for MCP clients: registered clients, OAuth
-- grants (each bound to a Governed Mode credential), authorisation codes and
-- access/refresh tokens. Codes and tokens are stored only as SHA-256 hashes.

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

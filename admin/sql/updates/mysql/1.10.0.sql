-- 1.10.0 records the MCP protocol revision each request was served under, so the
-- dashboard can show which clients still speak a legacy revision. NULL means the
-- request did not say (2024-11-05 and 2025-03-26 clients send no version header
-- after initialize), so rows written before this upgrade read the same way.
-- /** CAN FAIL **/ for the reason given in 1.8.0.sql: a retried upgrade must not
-- abort on "Duplicate column name".
ALTER TABLE `#__mcpserver_request_log` ADD COLUMN `protocol_version` VARCHAR(20) NULL DEFAULT NULL AFTER `target` /** CAN FAIL **/;

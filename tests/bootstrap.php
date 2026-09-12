<?php

/**
 * @package     MCP Server for Joomla
 * @copyright   Copyright (C) 2026 Onepoint Consulting Ltd
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

declare(strict_types=1);

if (!defined('_JEXEC')) {
    define('_JEXEC', 1);
}

if (!defined('JPATH_ADMINISTRATOR')) {
    define('JPATH_ADMINISTRATOR', '/tmp/administrator');
}

if (!defined('JPATH_SITE')) {
    define('JPATH_SITE', '/tmp/site');
}

// A real, writable directory: tests that exercise file operations (template
// files, overrides) create and tear down their own trees underneath it.
if (!defined('JPATH_ROOT')) {
    define('JPATH_ROOT', sys_get_temp_dir() . '/com-mcpserver-tests-' . getmypid());
}

require __DIR__ . '/Stubs/joomla.php';

$autoload = dirname(__DIR__) . '/admin/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "Composer autoload not found. Run: composer install --working-dir=admin\n");
    exit(1);
}

require $autoload;

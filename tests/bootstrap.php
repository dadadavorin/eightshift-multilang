<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for Eightshift Multilang unit tests.
 *
 * Unit tests use Brain\Monkey to mock WordPress functions.
 * Integration tests (tests/Integration/) require a full WordPress install
 * and are skipped unless WP_TESTS_DIR is defined.
 */

// Composer autoloader.
$autoloader = dirname(__DIR__) . '/vendor/autoload.php';
if (! file_exists($autoloader)) {
	echo "Run 'composer install' before running tests.\n";
	exit(1);
}

require_once $autoloader;

// Make sodium functions available (they are PHP core in 8.1+).
// No stub needed.

// Define plugin constants so source files can be loaded without WordPress.
if (! defined('ABSPATH')) {
	define('ABSPATH', '/tmp/wordpress/');
}

if (! defined('ESML_PLUGIN_FILE')) {
	define('ESML_PLUGIN_FILE', dirname(__DIR__) . '/eightshift-multilang.php');
}

if (! defined('ESML_PLUGIN_DIR')) {
	define('ESML_PLUGIN_DIR', dirname(__DIR__) . '/');
}

if (! defined('ESML_PLUGIN_VERSION')) {
	define('ESML_PLUGIN_VERSION', '1.0.0');
}

if (! defined('AUTH_KEY')) {
	define('AUTH_KEY', 'unit-test-auth-key-do-not-use-in-production');
}

if (! defined('ARRAY_A')) {
	define('ARRAY_A', 'ARRAY_A');
}

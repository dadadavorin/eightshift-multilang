<?php

/**
 * Plugin Name: Eightshift Multilang
 * Plugin URI: https://eightshift.com
 * Description: Lightweight multilingual plugin with AI-powered translation for Eightshift boilerplate websites.
 * Version: 1.0.0
 * Author: Eightshift
 * Author URI: https://eightshift.com
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: eightshift-multilang
 * Domain Path: /languages
 * Requires at least: 6.4
 * Requires PHP: 8.1
 *
 * @package EightshiftMultilang
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

define('ESML_PLUGIN_FILE', __FILE__);
define('ESML_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ESML_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ESML_PLUGIN_VERSION', '1.0.0');
define('ESML_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Autoloader.
if (file_exists(ESML_PLUGIN_DIR . 'vendor/autoload.php')) {
	require_once ESML_PLUGIN_DIR . 'vendor/autoload.php';
}

use EightshiftMultilang\Main\Main;
use EightshiftMultilang\Main\PluginActivation;

// Activation / deactivation hooks must be registered before plugins_loaded.
register_activation_hook(__FILE__, [PluginActivation::class, 'activate']);
register_deactivation_hook(__FILE__, [PluginActivation::class, 'deactivate']);

// Bootstrap the plugin after all plugins are loaded so dependencies are available.
add_action('plugins_loaded', static function (): void {
	$main = new Main();
	$main->register();
});

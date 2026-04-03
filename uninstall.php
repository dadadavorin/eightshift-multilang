<?php

/**
 * Runs when the plugin is uninstalled (deleted via WP admin).
 * Drops custom tables and removes all plugin options.
 *
 * @package EightshiftMultilang
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

global $wpdb;

// Drop custom tables.
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}es_multilang_translations");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}es_multilang_languages");
// phpcs:enable

// Delete all plugin options.
$optionKeys = [
	'esml_version',
	'esml_db_version',
	'esml_url_mode',
	'esml_translatable_suffixes',
	'esml_ai_provider',
	'esml_ai_api_key_encrypted',
	'esml_ai_custom_prompt',
	'esml_ai_monthly_calls',
	'esml_ai_monthly_limit',
	'esml_translatable_post_types',
	'esml_activation_redirect',
];

foreach ($optionKeys as $key) {
	delete_option($key);
}

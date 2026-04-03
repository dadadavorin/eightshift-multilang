<?php

declare(strict_types=1);

namespace EightshiftMultilang\Main;

use EightshiftMultilang\Languages\LanguageSeeder;

/**
 * Handles plugin activation and deactivation.
 *
 * Called via register_activation_hook() / register_deactivation_hook()
 * before plugins_loaded, so it must not rely on the Main service container.
 * Each required dependency is instantiated directly here.
 */
final class PluginActivation
{
	/**
	 * Run on plugin activation.
	 *
	 * 1. Run DB schema migrations.
	 * 2. Seed languages (no-op if already seeded).
	 * 3. Register default wp_options.
	 * 4. Flush rewrite rules (deferred — flagged for next request).
	 * 5. Set activation redirect flag.
	 */
	public static function activate(): void
	{
		global $wpdb;

		// 1. DB migrations.
		$migrator = new SchemaMigrator($wpdb);
		$migrator->run();

		// 2. Seed language list.
		$seeder = new LanguageSeeder($wpdb);
		$seeder->seed();

		// 3. Register default options (only sets values that don't exist yet).
		self::registerDefaults();

		// 4. Schedule a rewrite rules flush on the next admin request.
		update_option('esml_flush_rewrite_rules', 1, false);

		// 5. Redirect to setup page on first activation.
		if (! get_option('esml_version')) {
			update_option('esml_activation_redirect', 1, false);
		}

		// 6. Record plugin version.
		update_option('esml_version', ESML_PLUGIN_VERSION, true);
	}

	/**
	 * Run on plugin deactivation.
	 * Flushes rewrite rules so language prefix rules are removed immediately.
	 */
	public static function deactivate(): void
	{
		flush_rewrite_rules();
	}

	// ---------------------------------------------------------------------------
	// Private helpers
	// ---------------------------------------------------------------------------

	/**
	 * Register all plugin wp_options with their default values.
	 * Uses add_option() which is a no-op if the option already exists.
	 */
	private static function registerDefaults(): void
	{
		// Autoloaded (small, read on every request).
		add_option('esml_version', '', '', 'yes');
		add_option('esml_url_mode', 'subdirectory', '', 'yes');
		add_option('esml_translatable_suffixes', wp_json_encode(['Content']), '', 'yes');
		add_option('esml_ai_provider', 'claude', '', 'yes');
		add_option('esml_translatable_post_types', wp_json_encode(['post', 'page']), '', 'yes');

		// Not autoloaded (larger / infrequently accessed).
		add_option('esml_ai_api_key_encrypted', '', '', 'no');
		add_option('esml_ai_custom_prompt', '', '', 'no');
		add_option('esml_ai_monthly_calls', wp_json_encode(['month' => '', 'count' => 0]), '', 'no');
		add_option('esml_ai_monthly_limit', 0, '', 'no');
	}
}

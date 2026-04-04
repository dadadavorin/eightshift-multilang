<?php

declare(strict_types=1);

namespace EightshiftMultilang\Main;

/**
 * Versioned database schema migration runner.
 *
 * Migrations run sequentially and are gated by the stored 'esml_db_version'
 * option. Each migration is idempotent — safe to re-run if interrupted.
 */
final class SchemaMigrator
{
	/**
	 * Ordered map of version string → method name.
	 * New migrations are appended to this list; never reorder existing entries.
	 *
	 * @var array<string, string>
	 */
	private const MIGRATIONS = [
		'1.0.0' => 'migration100CreateTables',
		'1.0.1' => 'migration101ResetSeededLanguages',
	];

	public function __construct(
		private readonly \wpdb $db,
	) {
	}

	/**
	 * Execute all pending migrations.
	 * Called on plugin activation and on plugins_loaded (for upgrades).
	 */
	public function run(): void
	{
		$current = (string) get_option('esml_db_version', '0.0.0');

		foreach (self::MIGRATIONS as $version => $method) {
			if (version_compare($current, $version, '<')) {
				$this->$method();
				update_option('esml_db_version', $version, false);
				$current = $version;
			}
		}
	}

	// ---------------------------------------------------------------------------
	// Migrations
	// ---------------------------------------------------------------------------

	/**
	 * 1.0.0 — Create the two core plugin tables.
	 *
	 * Uses dbDelta() for safe, idempotent table creation and column additions.
	 * Indexes and the updated_at column are included here because they are
	 * required by Phase 1 features (SyncDetector uses updated_at).
	 */
	private function migration100CreateTables(): void
	{
		if (! function_exists('dbDelta')) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$charset = $this->db->get_charset_collate();
		$prefix = $this->db->prefix;

		// -----------------------------------------------------------------------
		// Translation groups table
		// -----------------------------------------------------------------------
		$translationsTable = $prefix . 'es_multilang_translations';

		dbDelta("CREATE TABLE {$translationsTable} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            translation_group CHAR(36) NOT NULL,
            post_id BIGINT(20) UNSIGNED NOT NULL,
            language_code VARCHAR(10) NOT NULL,
            is_source TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_post_id (post_id),
            KEY idx_translation_group (translation_group),
            KEY idx_language_code (language_code),
            KEY idx_group_language (translation_group, language_code)
        ) {$charset};");

		// -----------------------------------------------------------------------
		// Languages configuration table
		// -----------------------------------------------------------------------
		$languagesTable = $prefix . 'es_multilang_languages';

		dbDelta("CREATE TABLE {$languagesTable} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(10) NOT NULL,
            locale VARCHAR(20) NOT NULL,
            name VARCHAR(100) NOT NULL,
            native_name VARCHAR(100) NOT NULL,
            flag_code VARCHAR(10) NOT NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            date_format VARCHAR(50) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_code (code),
            UNIQUE KEY uk_locale (locale)
        ) {$charset};");
	}

	/**
	 * 1.0.1 — Reset seeded languages to inactive.
	 *
	 * The 1.0.0 seeder incorrectly set all languages to is_active = 1.
	 * This migration resets them to inactive on fresh installs (no translations yet).
	 * Existing installs with translations are untouched.
	 */
	private function migration101ResetSeededLanguages(): void
	{
		$languagesTable    = $this->db->prefix . 'es_multilang_languages';
		$translationsTable = $this->db->prefix . 'es_multilang_translations';

		// Guard: if translations already exist this is not a fresh install — skip.
		$hasTranslations = (int) $this->db->get_var("SELECT COUNT(*) FROM {$translationsTable}");

		if ($hasTranslations > 0) {
			return;
		}

		// Reset all non-default languages to inactive.
		$this->db->query("UPDATE {$languagesTable} SET is_active = 0 WHERE is_default = 0");
	}
}

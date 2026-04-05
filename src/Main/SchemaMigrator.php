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
		'1.1.0' => 'migration110PerProviderKeys',
		'1.1.1' => 'migration111GeminiModelUpdate',
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
	 * 1.1.1 — Replace the deprecated gemini-2.0-flash model identifier.
	 *
	 * gemini-2.0-flash was discontinued. Any existing installation that still
	 * has it stored is updated to gemini-2.5-flash.
	 */
	private function migration111GeminiModelUpdate(): void
	{
		if ((string) get_option('esml_ai_model_gemini', '') === 'gemini-2.0-flash') {
			update_option('esml_ai_model_gemini', 'gemini-2.5-flash', 'yes');
		}
	}

	/**
	 * 1.1.0 — Migrate Claude API key from the shared option to a per-provider slot.
	 *
	 * Old option: esml_ai_api_key_encrypted (single key, Claude-only)
	 * New options: esml_ai_key_{provider}_encrypted (one per provider)
	 *
	 * The migration writes to the new slot first, verifies the ciphertext can be
	 * decrypted, and only then removes the old option. If decryption fails (e.g.
	 * the AUTH_KEY changed), both options are left intact and an admin notice will
	 * prompt the user to re-enter their key.
	 */
	private function migration110PerProviderKeys(): void
	{
		$old = (string) get_option('esml_ai_api_key_encrypted', '');

		if ($old === '') {
			return; // No existing key — nothing to migrate.
		}

		// Write to the Claude-specific slot.
		update_option('esml_ai_key_claude_encrypted', $old, false);

		// Verify decryption succeeds before removing the old option.
		try {
			\EightshiftMultilang\Helpers\EncryptionHelper::decrypt($old);
			delete_option('esml_ai_api_key_encrypted');
		} catch (\RuntimeException $e) {
			// Decryption failed — roll back the new slot and leave the old option.
			// The admin will be prompted to re-enter the key.
			delete_option('esml_ai_key_claude_encrypted');
		}
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

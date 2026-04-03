<?php

declare(strict_types=1);

namespace EightshiftMultilang\Languages;

use EightshiftMultilang\Exceptions\LanguageException;

/**
 * CRUD operations for the languages table.
 *
 * All write operations fire the 'esml_languages_updated' action so that
 * CacheInvalidator can clear stale cache entries.
 */
final class LanguageManager
{
	/** @var string Full table name including DB prefix. */
	private string $table;

	public function __construct(
		private readonly \wpdb $db,
		private readonly LanguageRepository $repository,
	) {
		$this->table = $this->db->prefix . 'es_multilang_languages';
	}

	/**
	 * Add a new language.
	 *
	 * @param array{
	 *     code: string,
	 *     locale: string,
	 *     name: string,
	 *     native_name: string,
	 *     flag_code: string,
	 *     is_default?: bool,
	 *     is_active?: bool,
	 *     sort_order?: int,
	 *     date_format?: string|null,
	 * } $data Language data.
	 * @return int Inserted language ID.
	 * @throws LanguageException If code already exists.
	 */
	public function add(array $data): int
	{
		if ($this->repository->getByCode($data['code']) !== null) {
			throw new LanguageException(
				sprintf('Language with code "%s" already exists.', $data['code'])
			);
		}

		$isDefault = $data['is_default'] ?? false;

		// If this is the first language ever, auto-set as default.
		if ($this->repository->isEmpty()) {
			$isDefault = true;
		}

		$this->db->insert(
			$this->table,
			[
				'code'        => $data['code'],
				'locale'      => $data['locale'],
				'name'        => $data['name'],
				'native_name' => $data['native_name'],
				'flag_code'   => $data['flag_code'],
				'is_default'  => $isDefault ? 1 : 0,
				'is_active'   => ($data['is_active'] ?? true) ? 1 : 0,
				'sort_order'  => $data['sort_order'] ?? 0,
				'date_format' => $data['date_format'] ?? null,
			],
			['%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s']
		);

		$insertId = (int) $this->db->insert_id;

		if ($insertId === 0) {
			throw new LanguageException('Failed to insert language: ' . $this->db->last_error);
		}

		// If this new language is the default, unset any previous default.
		if ($isDefault) {
			$this->db->query(
				$this->db->prepare(
					"UPDATE {$this->table} SET is_default = 0 WHERE id != %d",
					$insertId
				)
			);
		}

		do_action('esml_languages_updated');

		return $insertId;
	}

	/**
	 * Update an existing language's settings.
	 *
	 * @param int $id Language ID.
	 * @param array<string, mixed> $data Fields to update (any subset of the language columns).
	 * @throws LanguageException If the language does not exist.
	 */
	public function update(int $id, array $data): void
	{
		$allowed = ['locale', 'name', 'native_name', 'flag_code', 'is_active', 'sort_order', 'date_format'];
		$fields = array_intersect_key($data, array_flip($allowed));

		if (empty($fields)) {
			return;
		}

		$result = $this->db->update($this->table, $fields, ['id' => $id]);

		if ($result === false) {
			throw new LanguageException('Failed to update language: ' . $this->db->last_error);
		}

		do_action('esml_languages_updated');
	}

	/**
	 * Set a language as the site default.
	 *
	 * Enforces the invariant that exactly one language has is_default = 1
	 * by running both updates within a transaction.
	 *
	 * @param string $code The language code to set as default.
	 * @throws LanguageException If the language is not found or not active.
	 */
	public function setDefault(string $code): void
	{
		$language = $this->repository->getByCode($code);

		if ($language === null) {
			throw new LanguageException(sprintf('Language "%s" not found.', $code));
		}

		if (! $language->isActive) {
			throw new LanguageException(
				sprintf('Cannot set "%s" as default: language is inactive.', $code)
			);
		}

		$this->db->query('START TRANSACTION');

		$this->db->query("UPDATE {$this->table} SET is_default = 0");
		$this->db->update($this->table, ['is_default' => 1], ['code' => $code]);

		$this->db->query('COMMIT');

		do_action('esml_languages_updated');
	}

	/**
	 * Activate a language.
	 *
	 * @param string $code Language code.
	 * @throws LanguageException If the language is not found.
	 */
	public function activate(string $code): void
	{
		$this->requireExists($code);
		$this->db->update($this->table, ['is_active' => 1], ['code' => $code]);
		do_action('esml_languages_updated');
	}

	/**
	 * Deactivate a language.
	 *
	 * @param string $code Language code.
	 * @throws LanguageException If the language is the current default.
	 */
	public function deactivate(string $code): void
	{
		$language = $this->requireExists($code);

		if ($language->isDefault) {
			throw new LanguageException('Cannot deactivate the default language.');
		}

		$this->db->update($this->table, ['is_active' => 0], ['code' => $code]);
		do_action('esml_languages_updated');
	}

	/**
	 * Remove a language entirely.
	 *
	 * The language must not be the default and must have no linked translations.
	 *
	 * @param string $code Language code.
	 * @throws LanguageException If removal is not permitted.
	 */
	public function remove(string $code): void
	{
		$language = $this->requireExists($code);

		if ($language->isDefault) {
			throw new LanguageException('Cannot remove the default language. Set a new default first.');
		}

		$translationsTable = $this->db->prefix . 'es_multilang_translations';
		$count = (int) $this->db->get_var(
			$this->db->prepare(
				"SELECT COUNT(*) FROM {$translationsTable} WHERE language_code = %s",
				$code
			)
		);

		if ($count > 0) {
			throw new LanguageException(
				sprintf(
					'Cannot remove language "%s": %d translation(s) still linked. Delete or reassign them first.',
					$code,
					$count
				)
			);
		}

		$this->db->delete($this->table, ['code' => $code]);
		do_action('esml_languages_updated');
	}

	/**
	 * Update sort_order for multiple languages at once.
	 *
	 * @param array<string, int> $order Map of language code → new sort_order.
	 */
	public function reorder(array $order): void
	{
		foreach ($order as $code => $sortOrder) {
			$this->db->update($this->table, ['sort_order' => $sortOrder], ['code' => $code]);
		}

		do_action('esml_languages_updated');
	}

	// ---------------------------------------------------------------------------
	// Private helpers
	// ---------------------------------------------------------------------------

	/**
	 * Assert a language exists by code and return it.
	 *
	 * @throws LanguageException If not found.
	 */
	private function requireExists(string $code): Language
	{
		$language = $this->repository->getByCode($code);

		if ($language === null) {
			throw new LanguageException(sprintf('Language "%s" not found.', $code));
		}

		return $language;
	}
}

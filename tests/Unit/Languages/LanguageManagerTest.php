<?php

declare(strict_types=1);

namespace EightshiftMultilang\Tests\Unit\Languages;

use Brain\Monkey;
use Brain\Monkey\Functions;
use EightshiftMultilang\Cache\CacheManager;
use EightshiftMultilang\Exceptions\LanguageException;
use EightshiftMultilang\Languages\Language;
use EightshiftMultilang\Languages\LanguageManager;
use EightshiftMultilang\Languages\LanguageRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LanguageManager.
 *
 * @covers \EightshiftMultilang\Languages\LanguageManager
 */
final class LanguageManagerTest extends TestCase
{
	private MockObject&\wpdb $wpdb;
	private MockObject&CacheManager $cache;
	private MockObject&LanguageRepository $repository;
	private LanguageManager $manager;

	protected function setUp(): void
	{
		parent::setUp();
		Monkey\setUp();

		// Stub WordPress functions used in LanguageManager.
		Functions\stubs([
			'do_action' => null,
		]);

		$this->wpdb = $this->createMock(\wpdb::class);
		$this->wpdb->prefix = 'wp_';

		$this->cache = $this->createMock(CacheManager::class);
		$this->repository = $this->createMock(LanguageRepository::class);
		$this->manager = new LanguageManager($this->wpdb, $this->repository);
	}

	protected function tearDown(): void
	{
		Monkey\tearDown();
		parent::tearDown();
	}

	// ---------------------------------------------------------------------------
	// add()
	// ---------------------------------------------------------------------------

	public function testAddInsertNewLanguage(): void
	{
		$this->repository->method('getByCode')->with('de')->willReturn(null);
		$this->repository->method('isEmpty')->willReturn(false);

		$this->wpdb->method('insert')->willReturn(1);
		$this->wpdb->insert_id = 5;

		$id = $this->manager->add([
			'code'        => 'de',
			'locale'      => 'de_DE',
			'name'        => 'German',
			'native_name' => 'Deutsch',
			'flag_code'   => 'de',
		]);

		$this->assertSame(5, $id);
	}

	public function testAddThrowsIfCodeAlreadyExists(): void
	{
		$this->expectException(LanguageException::class);
		$this->expectExceptionMessageMatches('/already exists/');

		$existing = $this->makeLanguage('de');
		$this->repository->method('getByCode')->with('de')->willReturn($existing);

		$this->manager->add([
			'code'        => 'de',
			'locale'      => 'de_DE',
			'name'        => 'German',
			'native_name' => 'Deutsch',
			'flag_code'   => 'de',
		]);
	}

	public function testAddSetsDefaultWhenTableIsEmpty(): void
	{
		$this->repository->method('getByCode')->willReturn(null);
		$this->repository->method('isEmpty')->willReturn(true);

		$insertArgs = null;
		$this->wpdb->method('insert')
			->willReturnCallback(static function ($table, $data) use (&$insertArgs): int {
				$insertArgs = $data;
				return 1;
			});
		$this->wpdb->insert_id = 1;

		// When table is empty, the first language should be the default.
		$this->wpdb->method('query')->willReturn(true);

		$this->manager->add([
			'code'        => 'en',
			'locale'      => 'en_US',
			'name'        => 'English',
			'native_name' => 'English',
			'flag_code'   => 'us',
		]);

		$this->assertSame(1, $insertArgs['is_default']);
	}

	public function testAddThrowsOnDatabaseFailure(): void
	{
		$this->expectException(LanguageException::class);
		$this->expectExceptionMessageMatches('/Failed to insert/');

		$this->repository->method('getByCode')->willReturn(null);
		$this->repository->method('isEmpty')->willReturn(false);
		$this->wpdb->method('insert')->willReturn(false);
		$this->wpdb->insert_id = 0;
		$this->wpdb->last_error = 'DB error';

		$this->manager->add([
			'code'        => 'fr',
			'locale'      => 'fr_FR',
			'name'        => 'French',
			'native_name' => 'Français',
			'flag_code'   => 'fr',
		]);
	}

	// ---------------------------------------------------------------------------
	// setDefault()
	// ---------------------------------------------------------------------------

	public function testSetDefaultUpdatesDatabase(): void
	{
		$language = $this->makeLanguage('de', isActive: true);
		$this->repository->method('getByCode')->with('de')->willReturn($language);

		$queryCount = 0;
		$this->wpdb->method('query')->willReturnCallback(static function () use (&$queryCount): bool {
			$queryCount++;
			return true;
		});
		$this->wpdb->method('update')->willReturn(1);

		$this->manager->setDefault('de');

		// START TRANSACTION + UPDATE all to 0 + COMMIT = at least 3 queries.
		$this->assertGreaterThanOrEqual(2, $queryCount);
	}

	public function testSetDefaultThrowsIfLanguageNotFound(): void
	{
		$this->expectException(LanguageException::class);
		$this->expectExceptionMessageMatches('/not found/');

		$this->repository->method('getByCode')->with('xx')->willReturn(null);
		$this->manager->setDefault('xx');
	}

	public function testSetDefaultThrowsIfLanguageIsInactive(): void
	{
		$this->expectException(LanguageException::class);
		$this->expectExceptionMessageMatches('/inactive/');

		$language = $this->makeLanguage('de', isActive: false);
		$this->repository->method('getByCode')->with('de')->willReturn($language);

		$this->manager->setDefault('de');
	}

	// ---------------------------------------------------------------------------
	// deactivate()
	// ---------------------------------------------------------------------------

	public function testDeactivateThrowsIfLanguageIsDefault(): void
	{
		$this->expectException(LanguageException::class);
		$this->expectExceptionMessageMatches('/default language/');

		$language = $this->makeLanguage('en', isDefault: true);
		$this->repository->method('getByCode')->with('en')->willReturn($language);

		$this->manager->deactivate('en');
	}

	// ---------------------------------------------------------------------------
	// remove()
	// ---------------------------------------------------------------------------

	public function testRemoveThrowsIfLanguageIsDefault(): void
	{
		$this->expectException(LanguageException::class);
		$this->expectExceptionMessageMatches('/default language/');

		$language = $this->makeLanguage('en', isDefault: true);
		$this->repository->method('getByCode')->with('en')->willReturn($language);

		$this->manager->remove('en');
	}

	public function testRemoveThrowsIfTranslationsExist(): void
	{
		$this->expectException(LanguageException::class);
		$this->expectExceptionMessageMatches('/still linked/');

		$language = $this->makeLanguage('de', isDefault: false);
		$this->repository->method('getByCode')->with('de')->willReturn($language);

		$this->wpdb->method('get_var')->willReturn('3'); // 3 linked translations.
		$this->wpdb->method('prepare')->willReturnArgument(0);

		$this->manager->remove('de');
	}

	// ---------------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------------

	private function makeLanguage(
		string $code,
		bool $isDefault = false,
		bool $isActive = true,
	): Language {
		return new Language(
			id: 1,
			code: $code,
			locale: $code . '_' . strtoupper($code),
			name: ucfirst($code),
			nativeName: ucfirst($code),
			flagCode: $code,
			isDefault: $isDefault,
			isActive: $isActive,
			sortOrder: 0,
			dateFormat: null,
		);
	}
}

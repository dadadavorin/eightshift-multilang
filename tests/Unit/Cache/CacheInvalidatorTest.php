<?php

declare(strict_types=1);

namespace EightshiftMultilang\Tests\Unit\Cache;

use Brain\Monkey;
use Brain\Monkey\Functions;
use EightshiftMultilang\Cache\CacheInvalidator;
use EightshiftMultilang\Cache\CacheManager;
use EightshiftMultilang\Translations\TranslationRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CacheInvalidator.
 *
 * @covers \EightshiftMultilang\Cache\CacheInvalidator
 */
final class CacheInvalidatorTest extends TestCase
{
	private MockObject&CacheManager $cache;
	private MockObject&TranslationRepository $repository;
	private CacheInvalidator $invalidator;

	protected function setUp(): void
	{
		parent::setUp();
		Monkey\setUp();

		Functions\stubs([
			'add_action' => null,
		]);

		$this->cache = $this->createMock(CacheManager::class);
		$this->repository = $this->createMock(TranslationRepository::class);
		$this->invalidator = new CacheInvalidator($this->cache, $this->repository);
	}

	protected function tearDown(): void
	{
		Monkey\tearDown();
		parent::tearDown();
	}

	// ---------------------------------------------------------------------------
	// onPostSave()
	// ---------------------------------------------------------------------------

	public function testOnPostSaveDeletesPostKeysAndGroupKey(): void
	{
		$postId = 42;
		$groupId = 'group-abc';

		$this->repository->method('getGroupId')->with($postId)->willReturn($groupId);
		$this->repository->method('getPostIdsByGroup')->with($groupId)->willReturn([42, 87]);

		// Expect deletions for post keys + group key + hreflang for both posts.
		$this->cache->expects($this->atLeastOnce())->method('delete');
		$this->cache->method('keyTranslations')->willReturnCallback(
			static fn(int $id) => 'translations_' . $id
		);
		$this->cache->method('keyPostLanguage')->willReturnCallback(
			static fn(int $id) => 'post_lang_' . $id
		);
		$this->cache->method('keyHreflang')->willReturnCallback(
			static fn(int $id) => 'hreflang_' . $id
		);
		$this->cache->method('keyGroup')->willReturnCallback(
			static fn(string $g) => 'group_' . $g
		);

		$this->invalidator->onPostSave($postId);
	}

	public function testOnPostSaveNoGroupLookupWhenPostNotLinked(): void
	{
		$postId = 99;

		$this->repository->method('getGroupId')->with($postId)->willReturn(null);
		// getPostIdsByGroup should never be called if there's no group.
		$this->repository->expects($this->never())->method('getPostIdsByGroup');

		$this->cache->method('keyTranslations')->willReturn('t');
		$this->cache->method('keyPostLanguage')->willReturn('pl');
		$this->cache->method('keyHreflang')->willReturn('h');

		$this->invalidator->onPostSave($postId);
	}

	// ---------------------------------------------------------------------------
	// onLanguagesUpdated()
	// ---------------------------------------------------------------------------

	public function testOnLanguagesUpdatedDeletesAllLanguageKeys(): void
	{
		$deleted = [];
		$this->cache->method('delete')->willReturnCallback(static function (string $key) use (&$deleted): void {
			$deleted[] = $key;
		});

		$this->invalidator->onLanguagesUpdated();

		$this->assertContains(CacheManager::KEY_LANGUAGES_ACTIVE, $deleted);
		$this->assertContains(CacheManager::KEY_LANGUAGE_DEFAULT, $deleted);
		$this->assertContains(CacheManager::KEY_LANGUAGES_ALL, $deleted);
	}

	// ---------------------------------------------------------------------------
	// onSettingsSaved()
	// ---------------------------------------------------------------------------

	public function testOnSettingsSavedDeletesSuffixesKey(): void
	{
		$this->cache->expects($this->once())
			->method('delete')
			->with(CacheManager::KEY_SUFFIXES);

		$this->invalidator->onSettingsSaved();
	}

	// ---------------------------------------------------------------------------
	// onTranslationLinked()
	// ---------------------------------------------------------------------------

	public function testOnTranslationLinkedClearsRelevantKeys(): void
	{
		$this->repository->method('getPostIdsByGroup')->with('group-abc')->willReturn([42, 87]);

		$this->cache->method('keyTranslations')->willReturnCallback(
			static fn(int $id) => 'translations_' . $id
		);
		$this->cache->method('keyPostLanguage')->willReturnCallback(
			static fn(int $id) => 'post_lang_' . $id
		);
		$this->cache->method('keyHreflang')->willReturnCallback(
			static fn(int $id) => 'hreflang_' . $id
		);
		$this->cache->method('keyGroup')->willReturnCallback(
			static fn(string $g) => 'group_' . $g
		);

		$this->cache->expects($this->atLeastOnce())->method('delete');

		$this->invalidator->onTranslationLinked(42, 'group-abc', 'de');
	}
}

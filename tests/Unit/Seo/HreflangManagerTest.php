<?php

declare(strict_types=1);

namespace EightshiftMultilang\Tests\Unit\Seo;

use Brain\Monkey;
use Brain\Monkey\Functions;
use EightshiftMultilang\Cache\CacheManager;
use EightshiftMultilang\Languages\Language;
use EightshiftMultilang\Languages\LanguageRepository;
use EightshiftMultilang\Seo\HreflangManager;
use EightshiftMultilang\Translations\Translation;
use EightshiftMultilang\Translations\TranslationRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \EightshiftMultilang\Seo\HreflangManager
 */
final class HreflangManagerTest extends TestCase
{
	private HreflangManager $manager;

	/** @var TranslationRepository&MockObject */
	private TranslationRepository $translationRepo;

	/** @var LanguageRepository&MockObject */
	private LanguageRepository $languageRepo;

	/** @var CacheManager&MockObject */
	private CacheManager $cacheManager;

	protected function setUp(): void
	{
		parent::setUp();
		Monkey\setUp();

		$this->translationRepo = $this->createMock(TranslationRepository::class);
		$this->languageRepo    = $this->createMock(LanguageRepository::class);
		$this->cacheManager    = $this->createMock(CacheManager::class);

		$this->manager = new HreflangManager(
			$this->translationRepo,
			$this->languageRepo,
			$this->cacheManager,
		);
	}

	protected function tearDown(): void
	{
		Monkey\tearDown();
		parent::tearDown();
	}

	// ---------------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------------

	private function makeTranslation(int $postId, string $langCode, bool $isSource): Translation
	{
		return new Translation(
			id: 1,
			translationGroup: 'uuid-1',
			postId: $postId,
			languageCode: $langCode,
			isSource: $isSource,
			createdAt: new \DateTimeImmutable(),
			updatedAt: new \DateTimeImmutable(),
		);
	}

	private function makeLanguage(string $code, string $locale, bool $isActive = true): Language
	{
		return new Language(
			id: 1,
			code: $code,
			locale: $locale,
			name: ucfirst($code),
			nativeName: ucfirst($code),
			flagCode: $code,
			isDefault: $code === 'en',
			isActive: $isActive,
			sortOrder: 0,
			dateFormat: null,
		);
	}

	// ---------------------------------------------------------------------------
	// outputHreflangTags — singular
	// ---------------------------------------------------------------------------

	public function testOutputsSingularHreflangTags(): void
	{
		Functions\stubs([
			'is_singular'         => true,
			'is_home'             => false,
			'is_front_page'       => false,
			'get_queried_object_id' => 42,
			'get_permalink'       => static fn(int $id) => match ($id) {
				42 => 'https://example.com/about-us/',
				99 => 'https://example.com/de/ueber-uns/',
				default => false,
			},
			'trailingslashit'     => static fn(string $url) => rtrim($url, '/') . '/',
			'home_url'            => 'https://example.com',
		]);

		$this->cacheManager->method('keyHreflang')->with(42)->willReturn('esml_hreflang_42');
		$this->cacheManager->method('get')->willReturn(false); // cache miss
		$this->cacheManager->method('set')->willReturn(true);

		$this->translationRepo->method('getGroupId')->with(42)->willReturn('uuid-1');
		$this->translationRepo->method('getByGroup')->willReturn([
			$this->makeTranslation(42, 'en', true),
			$this->makeTranslation(99, 'de', false),
		]);

		$this->languageRepo->method('getDefaultCode')->willReturn('en');
		$this->languageRepo->method('getByCode')->willReturnMap([
			['en', $this->makeLanguage('en', 'en_US')],
			['de', $this->makeLanguage('de', 'de_DE')],
		]);

		ob_start();
		$this->manager->outputHreflangTags();
		$output = ob_get_clean();

		$this->assertStringContainsString('hreflang="en-US"', $output);
		$this->assertStringContainsString('hreflang="de-DE"', $output);
		$this->assertStringContainsString('hreflang="x-default"', $output);
		$this->assertStringContainsString('href="https://example.com/about-us/"', $output);
		$this->assertStringContainsString('href="https://example.com/de/ueber-uns/"', $output);
	}

	public function testSkipsInactiveLanguages(): void
	{
		Functions\stubs([
			'is_singular'           => true,
			'is_home'               => false,
			'is_front_page'         => false,
			'get_queried_object_id' => 42,
			'get_permalink'         => static fn() => 'https://example.com/page/',
		]);

		$this->cacheManager->method('keyHreflang')->willReturn('key');
		$this->cacheManager->method('get')->willReturn(false);
		$this->cacheManager->method('set');

		$this->translationRepo->method('getGroupId')->willReturn('uuid-1');
		$this->translationRepo->method('getByGroup')->willReturn([
			$this->makeTranslation(42, 'en', true),
			$this->makeTranslation(77, 'fr', false),
		]);

		$this->languageRepo->method('getDefaultCode')->willReturn('en');
		$this->languageRepo->method('getByCode')->willReturnMap([
			['en', $this->makeLanguage('en', 'en_US')],
			['fr', $this->makeLanguage('fr', 'fr_FR', isActive: false)],
		]);

		ob_start();
		$this->manager->outputHreflangTags();
		$output = ob_get_clean();

		$this->assertStringNotContainsString('hreflang="fr-FR"', $output);
	}

	public function testReturnsEmptyWhenPostNotInGroup(): void
	{
		Functions\stubs([
			'is_singular'           => true,
			'is_home'               => false,
			'get_queried_object_id' => 55,
		]);

		$this->cacheManager->method('keyHreflang')->willReturn('key');
		$this->cacheManager->method('get')->willReturn(false);
		$this->translationRepo->method('getGroupId')->willReturn(null);

		ob_start();
		$this->manager->outputHreflangTags();
		$output = ob_get_clean();

		$this->assertSame('', $output);
	}

	public function testUsesCacheOnSecondCall(): void
	{
		Functions\stubs([
			'is_singular'           => true,
			'is_home'               => false,
			'get_queried_object_id' => 42,
		]);

		$cachedHtml = '<link rel="alternate" hreflang="en-US" href="https://example.com/">' . "\n";

		$this->cacheManager->method('keyHreflang')->willReturn('key');
		$this->cacheManager->method('get')->willReturn($cachedHtml);
		// set() should NOT be called — we got a cache hit.
		$this->cacheManager->expects($this->never())->method('set');
		$this->translationRepo->expects($this->never())->method('getGroupId');

		ob_start();
		$this->manager->outputHreflangTags();
		$output = ob_get_clean();

		$this->assertSame($cachedHtml, $output);
	}

	// ---------------------------------------------------------------------------
	// Home page
	// ---------------------------------------------------------------------------

	public function testOutputsHomeHreflangTags(): void
	{
		Functions\stubs([
			'is_singular'     => false,
			'is_home'         => true,
			'is_front_page'   => false,
			'home_url'        => 'https://example.com',
			'trailingslashit' => static fn(string $url) => rtrim($url, '/') . '/',
		]);

		$this->languageRepo->method('getActive')->willReturn([
			$this->makeLanguage('en', 'en_US'),
			$this->makeLanguage('de', 'de_DE'),
		]);
		$this->languageRepo->method('getDefaultCode')->willReturn('en');

		ob_start();
		$this->manager->outputHreflangTags();
		$output = ob_get_clean();

		$this->assertStringContainsString('hreflang="en-US"', $output);
		$this->assertStringContainsString('hreflang="de-DE"', $output);
		$this->assertStringContainsString('hreflang="x-default"', $output);
		// Default language home = bare home URL.
		$this->assertStringContainsString('href="https://example.com/"', $output);
		// Non-default language home = prefixed.
		$this->assertStringContainsString('href="https://example.com/de/"', $output);
	}

	// ---------------------------------------------------------------------------
	// Locale conversion
	// ---------------------------------------------------------------------------

	public function testConvertsUnderscoreLocaleToHyphen(): void
	{
		Functions\stubs([
			'is_singular'           => true,
			'is_home'               => false,
			'get_queried_object_id' => 1,
			'get_permalink'         => static fn() => 'https://example.com/p/',
		]);

		$this->cacheManager->method('keyHreflang')->willReturn('k');
		$this->cacheManager->method('get')->willReturn(false);
		$this->cacheManager->method('set');

		$this->translationRepo->method('getGroupId')->willReturn('g');
		$this->translationRepo->method('getByGroup')->willReturn([
			$this->makeTranslation(1, 'zh', true),
		]);

		$this->languageRepo->method('getDefaultCode')->willReturn('zh');
		$this->languageRepo->method('getByCode')->willReturn($this->makeLanguage('zh', 'zh_Hans_CN'));

		ob_start();
		$this->manager->outputHreflangTags();
		$output = ob_get_clean();

		// Underscore-to-hyphen conversion applied.
		$this->assertStringContainsString('hreflang="zh-Hans-CN"', $output);
	}
}

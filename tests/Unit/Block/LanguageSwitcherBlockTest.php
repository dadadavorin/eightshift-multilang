<?php

declare(strict_types=1);

namespace EightshiftMultilang\Tests\Unit\Block;

use Brain\Monkey;
use Brain\Monkey\Functions;
use EightshiftMultilang\Block\LanguageSwitcherBlock;
use EightshiftMultilang\Languages\Language;
use EightshiftMultilang\Languages\LanguageRepository;
use EightshiftMultilang\Router\LanguageDetector;
use EightshiftMultilang\Translations\TranslationRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \EightshiftMultilang\Block\LanguageSwitcherBlock
 */
final class LanguageSwitcherBlockTest extends TestCase
{
	private LanguageSwitcherBlock $block;

	/** @var LanguageRepository&MockObject */
	private LanguageRepository $languageRepo;

	/** @var TranslationRepository&MockObject */
	private TranslationRepository $translationRepo;

	protected function setUp(): void
	{
		parent::setUp();
		Monkey\setUp();

		$this->languageRepo    = $this->createMock(LanguageRepository::class);
		$this->translationRepo = $this->createMock(TranslationRepository::class);

		$this->block = new LanguageSwitcherBlock($this->languageRepo, $this->translationRepo);

		LanguageDetector::reset();
	}

	protected function tearDown(): void
	{
		LanguageDetector::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	// ---------------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------------

	private function makeLanguage(
		string $code,
		string $locale,
		bool $isDefault = false,
		string $flagCode = '',
	): Language {
		return new Language(
			id: 1,
			code: $code,
			locale: $locale,
			name: ucfirst($code),
			nativeName: 'Native ' . ucfirst($code),
			flagCode: $flagCode,
			isDefault: $isDefault,
			isActive: true,
			sortOrder: 0,
			dateFormat: null,
		);
	}

	private function stubFrontendFunctions(): void
	{
		Functions\stubs([
			'is_singular'     => true,
			'get_queried_object_id' => 42,
			'home_url'        => 'https://example.com',
			'trailingslashit' => static fn(string $url) => rtrim($url, '/') . '/',
			'get_permalink'   => static fn(int $id) => match ($id) {
				42 => 'https://example.com/about-us/',
				99 => 'https://example.com/de/ueber-uns/',
				default => false,
			},
		]);
	}

	// ---------------------------------------------------------------------------
	// render() — basic output
	// ---------------------------------------------------------------------------

	public function testRendersListWithLinks(): void
	{
		$this->stubFrontendFunctions();

		$this->languageRepo->method('getActive')->willReturn([
			$this->makeLanguage('en', 'en_US', isDefault: true),
			$this->makeLanguage('de', 'de_DE'),
		]);
		$this->languageRepo->method('getDefaultCode')->willReturn('en');

		$this->translationRepo->method('getTranslatedPostId')->willReturnMap([
			[42, 'en', 42],
			[42, 'de', 99],
		]);

		$output = $this->block->render([]);

		$this->assertStringContainsString('<ul class="esml-language-switcher">', $output);
		$this->assertStringContainsString('hreflang="en-US"', $output);
		$this->assertStringContainsString('hreflang="de-DE"', $output);
		$this->assertStringContainsString('https://example.com/about-us/', $output);
		$this->assertStringContainsString('https://example.com/de/ueber-uns/', $output);
	}

	public function testReturnsEmptyStringWhenNoLanguages(): void
	{
		$this->stubFrontendFunctions();
		$this->languageRepo->method('getActive')->willReturn([]);
		$this->languageRepo->method('getDefaultCode')->willReturn('en');

		$this->assertSame('', $this->block->render([]));
	}

	// ---------------------------------------------------------------------------
	// Active language marking
	// ---------------------------------------------------------------------------

	public function testCurrentLanguageHasActiveClass(): void
	{
		$this->stubFrontendFunctions();

		// Set current language to 'de'.
		$this->languageRepo->method('getActive')->willReturn([
			$this->makeLanguage('en', 'en_US', isDefault: true),
			$this->makeLanguage('de', 'de_DE'),
		]);
		$this->languageRepo->method('getDefaultCode')->willReturn('en');
		$this->translationRepo->method('getTranslatedPostId')->willReturnMap([
			[42, 'en', 42],
			[42, 'de', 99],
		]);

		// Simulate LanguageDetector detecting 'de'.
		$repoMock = $this->createMock(LanguageRepository::class);
		$repoMock->method('getActiveCodes')->willReturn(['en', 'de']);
		$repoMock->method('getDefaultCode')->willReturn('en');

		$detector = new \EightshiftMultilang\Router\LanguageDetector($repoMock);
		$wp = $this->createMock(\WP::class);
		$wp->query_vars = ['esml_language' => 'de'];
		$detector->detectLanguage($wp);

		$output = $this->block->render([]);

		// German item has active class.
		$this->assertMatchesRegularExpression(
			'/esml-switcher__item--active[^>]*>.*?lang="de"/s',
			$output,
		);
		// English item does NOT have active class.
		$this->assertDoesNotMatch(
			'/esml-switcher__item--active[^>]*>.*?lang="en"/s',
			$output,
		);
	}

	// ---------------------------------------------------------------------------
	// Attributes
	// ---------------------------------------------------------------------------

	public function testShowNativeNamesUsesNativeName(): void
	{
		$this->stubFrontendFunctions();
		$this->languageRepo->method('getActive')->willReturn([
			$this->makeLanguage('de', 'de_DE'),
		]);
		$this->languageRepo->method('getDefaultCode')->willReturn('en');
		$this->translationRepo->method('getTranslatedPostId')->willReturn(null);

		$output = $this->block->render(['showNativeNames' => true]);

		$this->assertStringContainsString('Native De', $output);
		$this->assertStringNotContainsString('>De<', $output);
	}

	public function testShowFlagsRendersFlag(): void
	{
		$this->stubFrontendFunctions();
		$this->languageRepo->method('getActive')->willReturn([
			$this->makeLanguage('de', 'de_DE', flagCode: 'de'),
		]);
		$this->languageRepo->method('getDefaultCode')->willReturn('en');
		$this->translationRepo->method('getTranslatedPostId')->willReturn(null);

		$output = $this->block->render(['showFlags' => true]);

		$this->assertStringContainsString('esml-flag--de', $output);
	}

	// ---------------------------------------------------------------------------
	// Fallback URL (no translation found)
	// ---------------------------------------------------------------------------

	public function testFallsBackToLanguageHomeWhenNoTranslation(): void
	{
		$this->stubFrontendFunctions();
		$this->languageRepo->method('getActive')->willReturn([
			$this->makeLanguage('fr', 'fr_FR'),
		]);
		$this->languageRepo->method('getDefaultCode')->willReturn('en');
		$this->translationRepo->method('getTranslatedPostId')->willReturn(null);

		$output = $this->block->render([]);

		$this->assertStringContainsString('https://example.com/fr/', $output);
	}

	// ---------------------------------------------------------------------------
	// Shortcode
	// ---------------------------------------------------------------------------

	public function testShortcodeRendersHtml(): void
	{
		$this->stubFrontendFunctions();
		$this->languageRepo->method('getActive')->willReturn([
			$this->makeLanguage('en', 'en_US', isDefault: true),
		]);
		$this->languageRepo->method('getDefaultCode')->willReturn('en');
		$this->translationRepo->method('getTranslatedPostId')->willReturn(42);

		$output = $this->block->renderShortcode([]);

		$this->assertStringContainsString('esml-language-switcher', $output);
	}

	// ---------------------------------------------------------------------------
	// Helpers — assertDoesNotMatch
	// ---------------------------------------------------------------------------

	private function assertDoesNotMatch(string $pattern, string $string): void
	{
		$this->assertDoesNotMatchRegularExpression($pattern, $string);
	}
}

<?php

declare(strict_types=1);

namespace EightshiftMultilang\Tests\Unit\Router;

use Brain\Monkey;
use Brain\Monkey\Functions;
use EightshiftMultilang\Languages\LanguageRepository;
use EightshiftMultilang\Router\LanguageDetector;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \EightshiftMultilang\Router\LanguageDetector
 */
final class LanguageDetectorTest extends TestCase
{
	private LanguageDetector $detector;

	/** @var LanguageRepository&MockObject */
	private LanguageRepository $repo;

	protected function setUp(): void
	{
		parent::setUp();
		Monkey\setUp();

		$this->repo = $this->createMock(LanguageRepository::class);
		$this->detector = new LanguageDetector($this->repo);

		// Reset static state between tests.
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

	private function makeWp(string $lang = ''): \WP
	{
		$wp = $this->createMock(\WP::class);
		$wp->query_vars = $lang !== '' ? ['esml_language' => $lang] : [];

		return $wp;
	}

	// ---------------------------------------------------------------------------
	// Detection from query var
	// ---------------------------------------------------------------------------

	public function testDetectsLanguageFromQueryVar(): void
	{
		$this->repo->method('getActiveCodes')->willReturn(['en', 'de', 'fr']);
		$this->repo->method('getDefaultCode')->willReturn('en');

		$this->detector->detectLanguage($this->makeWp('de'));

		$this->assertSame('de', LanguageDetector::getCurrentLanguage());
	}

	public function testDetectsNonDefaultLanguage(): void
	{
		$this->repo->method('getActiveCodes')->willReturn(['en', 'de', 'fr']);
		$this->repo->method('getDefaultCode')->willReturn('en');

		$this->detector->detectLanguage($this->makeWp('fr'));

		$this->assertSame('fr', LanguageDetector::getCurrentLanguage());
	}

	// ---------------------------------------------------------------------------
	// Fallback to default
	// ---------------------------------------------------------------------------

	public function testFallsBackToDefaultWhenNoQueryVar(): void
	{
		$this->repo->method('getActiveCodes')->willReturn(['en', 'de']);
		$this->repo->method('getDefaultCode')->willReturn('en');

		$this->detector->detectLanguage($this->makeWp(''));

		$this->assertSame('en', LanguageDetector::getCurrentLanguage());
	}

	public function testFallsBackToDefaultForUnknownLangCode(): void
	{
		$this->repo->method('getActiveCodes')->willReturn(['en', 'de']);
		$this->repo->method('getDefaultCode')->willReturn('en');

		// 'zh' is not in activeCodes.
		$this->detector->detectLanguage($this->makeWp('zh'));

		$this->assertSame('en', LanguageDetector::getCurrentLanguage());
	}

	// ---------------------------------------------------------------------------
	// Static state
	// ---------------------------------------------------------------------------

	public function testGetCurrentLanguageIsNullBeforeDetection(): void
	{
		// reset() was called in setUp; no detectLanguage called yet.
		$this->assertNull(LanguageDetector::getCurrentLanguage());
	}

	public function testResetClearsCurrentLanguage(): void
	{
		$this->repo->method('getActiveCodes')->willReturn(['en', 'de']);
		$this->repo->method('getDefaultCode')->willReturn('en');

		$this->detector->detectLanguage($this->makeWp('de'));

		$this->assertSame('de', LanguageDetector::getCurrentLanguage());

		LanguageDetector::reset();

		$this->assertNull(LanguageDetector::getCurrentLanguage());
	}

	public function testSubsequentCallsOverwritePreviousDetection(): void
	{
		$this->repo->method('getActiveCodes')->willReturn(['en', 'de', 'fr']);
		$this->repo->method('getDefaultCode')->willReturn('en');

		$this->detector->detectLanguage($this->makeWp('de'));
		$this->assertSame('de', LanguageDetector::getCurrentLanguage());

		$this->detector->detectLanguage($this->makeWp('fr'));
		$this->assertSame('fr', LanguageDetector::getCurrentLanguage());
	}

	// ---------------------------------------------------------------------------
	// register() wires hooks
	// ---------------------------------------------------------------------------

	public function testRegisterAddsParseRequestHook(): void
	{
		Functions\stubs(['add_action' => null]);

		$this->detector->register();

		$this->assertSame(1, did_action('esml_detector_registered') + 1); // Monkey tracks stubs.
		// Verify add_action was called with 'parse_request'.
		$this->assertTrue(
			\Brain\Monkey\Actions\has('parse_request', [$this->detector, 'detectLanguage'])
		);
	}
}

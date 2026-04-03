<?php

declare(strict_types=1);

namespace EightshiftMultilang\Tests\Unit\Router;

use Brain\Monkey;
use Brain\Monkey\Functions;
use EightshiftMultilang\Languages\LanguageRepository;
use EightshiftMultilang\Router\PermalinkFilter;
use EightshiftMultilang\Translations\TranslationRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \EightshiftMultilang\Router\PermalinkFilter
 */
final class PermalinkFilterTest extends TestCase
{
	private PermalinkFilter $filter;

	/** @var TranslationRepository&MockObject */
	private TranslationRepository $translationRepo;

	/** @var LanguageRepository&MockObject */
	private LanguageRepository $languageRepo;

	protected function setUp(): void
	{
		parent::setUp();
		Monkey\setUp();

		$this->translationRepo = $this->createMock(TranslationRepository::class);
		$this->languageRepo    = $this->createMock(LanguageRepository::class);

		$this->filter = new PermalinkFilter($this->translationRepo, $this->languageRepo);
	}

	protected function tearDown(): void
	{
		Monkey\tearDown();
		parent::tearDown();
	}

	// ---------------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------------

	private function makePost(int $id): \WP_Post
	{
		$post     = $this->createMock(\WP_Post::class);
		$post->ID = $id;

		return $post;
	}

	// ---------------------------------------------------------------------------
	// Default language — URL unchanged
	// ---------------------------------------------------------------------------

	public function testDefaultLanguageUrlIsUnchanged(): void
	{
		Functions\stubs([
			'home_url'        => 'https://example.com',
			'trailingslashit' => static fn(string $url) => rtrim($url, '/') . '/',
		]);

		$this->translationRepo->method('getLanguageCode')->with(42)->willReturn('en');
		$this->languageRepo->method('getDefaultCode')->willReturn('en');

		$result = $this->filter->filterPermalink('https://example.com/about-us/', $this->makePost(42));

		$this->assertSame('https://example.com/about-us/', $result);
	}

	// ---------------------------------------------------------------------------
	// Non-default language — prefix injected
	// ---------------------------------------------------------------------------

	public function testNonDefaultLanguageGetsPrefixed(): void
	{
		Functions\stubs([
			'home_url'        => 'https://example.com',
			'trailingslashit' => static fn(string $url) => rtrim($url, '/') . '/',
		]);

		$this->translationRepo->method('getLanguageCode')->with(42)->willReturn('de');
		$this->languageRepo->method('getDefaultCode')->willReturn('en');

		$result = $this->filter->filterPermalink('https://example.com/about-us/', $this->makePost(42));

		$this->assertSame('https://example.com/de/about-us/', $result);
	}

	public function testFrenchPrefixInjected(): void
	{
		Functions\stubs([
			'home_url'        => 'https://example.com',
			'trailingslashit' => static fn(string $url) => rtrim($url, '/') . '/',
		]);

		$this->translationRepo->method('getLanguageCode')->with(7)->willReturn('fr');
		$this->languageRepo->method('getDefaultCode')->willReturn('en');

		$result = $this->filter->filterPermalink('https://example.com/contact/', $this->makePost(7));

		$this->assertSame('https://example.com/fr/contact/', $result);
	}

	// ---------------------------------------------------------------------------
	// Post not in any translation group
	// ---------------------------------------------------------------------------

	public function testUnlinkedPostUrlIsUnchanged(): void
	{
		Functions\stubs([
			'home_url'        => 'https://example.com',
			'trailingslashit' => static fn(string $url) => rtrim($url, '/') . '/',
		]);

		$this->translationRepo->method('getLanguageCode')->willReturn(null);

		$result = $this->filter->filterPermalink('https://example.com/some-post/', $this->makePost(99));

		$this->assertSame('https://example.com/some-post/', $result);
	}

	// ---------------------------------------------------------------------------
	// Post ID ≤ 0
	// ---------------------------------------------------------------------------

	public function testInvalidPostIdReturnsUrlUnchanged(): void
	{
		$result = $this->filter->filterPermalink('https://example.com/hello/', $this->makePost(0));

		$this->assertSame('https://example.com/hello/', $result);
	}

	// ---------------------------------------------------------------------------
	// URL outside home — not prefixed (CDN / mapped domain guard)
	// ---------------------------------------------------------------------------

	public function testUrlOutsideHomeIsNotPrefixed(): void
	{
		Functions\stubs([
			'home_url'        => 'https://example.com',
			'trailingslashit' => static fn(string $url) => rtrim($url, '/') . '/',
		]);

		$this->translationRepo->method('getLanguageCode')->willReturn('de');
		$this->languageRepo->method('getDefaultCode')->willReturn('en');

		// URL on a different domain.
		$result = $this->filter->filterPermalink('https://cdn.example.com/about-us/', $this->makePost(42));

		$this->assertSame('https://cdn.example.com/about-us/', $result);
	}

	// ---------------------------------------------------------------------------
	// Integer post ID (page_link passes an int)
	// ---------------------------------------------------------------------------

	public function testAcceptsIntegerPostId(): void
	{
		Functions\stubs([
			'home_url'        => 'https://example.com',
			'trailingslashit' => static fn(string $url) => rtrim($url, '/') . '/',
		]);

		$this->translationRepo->method('getLanguageCode')->with(5)->willReturn('de');
		$this->languageRepo->method('getDefaultCode')->willReturn('en');

		$result = $this->filter->filterPermalink('https://example.com/page/', 5);

		$this->assertSame('https://example.com/de/page/', $result);
	}

	// ---------------------------------------------------------------------------
	// Nested path
	// ---------------------------------------------------------------------------

	public function testNestedPathIsPrefixedCorrectly(): void
	{
		Functions\stubs([
			'home_url'        => 'https://example.com',
			'trailingslashit' => static fn(string $url) => rtrim($url, '/') . '/',
		]);

		$this->translationRepo->method('getLanguageCode')->willReturn('de');
		$this->languageRepo->method('getDefaultCode')->willReturn('en');

		$result = $this->filter->filterPermalink('https://example.com/blog/2024/my-post/', $this->makePost(10));

		$this->assertSame('https://example.com/de/blog/2024/my-post/', $result);
	}
}

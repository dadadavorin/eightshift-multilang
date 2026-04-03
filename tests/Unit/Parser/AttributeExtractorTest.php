<?php

declare(strict_types=1);

namespace EightshiftMultilang\Tests\Unit\Parser;

use Brain\Monkey;
use Brain\Monkey\Filters;
use EightshiftMultilang\Parser\AttributeExtractor;
use PHPUnit\Framework\TestCase;

/**
 * @covers \EightshiftMultilang\Parser\AttributeExtractor
 */
final class AttributeExtractorTest extends TestCase
{
	private AttributeExtractor $extractor;

	protected function setUp(): void
	{
		parent::setUp();
		Monkey\setUp();
		$this->extractor = new AttributeExtractor();
	}

	protected function tearDown(): void
	{
		Monkey\tearDown();
		parent::tearDown();
	}

	// ---------------------------------------------------------------------------
	// Default suffix: Content
	// ---------------------------------------------------------------------------

	public function testExtractsDefaultContentSuffix(): void
	{
		$attrs = ['headingHeadingContent' => 'Hello World', 'headingHeadingSize' => 'h2'];

		$result = $this->extractor->extract($attrs, ['Content']);

		$this->assertSame(['headingHeadingContent' => 'Hello World'], $result);
	}

	public function testIgnoresNonStrings(): void
	{
		$attrs = [
			'someContent'  => 'Text',
			'flagContent'  => true,        // bool
			'countContent' => 42,           // int
			'dataContent'  => ['a', 'b'],  // array
		];

		$result = $this->extractor->extract($attrs, ['Content']);

		$this->assertSame(['someContent' => 'Text'], $result);
	}

	public function testIgnoresEmptyStrings(): void
	{
		$attrs = ['headingContent' => '', 'subContent' => '   '];

		$result = $this->extractor->extract($attrs, ['Content']);

		$this->assertEmpty($result);
	}

	public function testIgnoresStructuralAttributes(): void
	{
		$attrs = [
			'buttonButtonUrl'      => 'https://example.com',
			'buttonButtonIsNewTab' => true,
			'headingPaddingTop'    => 'full',
		];

		$result = $this->extractor->extract($attrs, ['Content']);

		$this->assertEmpty($result);
	}

	// ---------------------------------------------------------------------------
	// Multiple suffixes
	// ---------------------------------------------------------------------------

	public function testExtractsMultipleConfiguredSuffixes(): void
	{
		$attrs = [
			'buttonButtonContent' => 'Click me',
			'inputPlaceholderLabel' => 'Enter name',
			'cardCardSize'        => 'large',
		];

		$result = $this->extractor->extract($attrs, ['Content', 'Label']);

		$this->assertArrayHasKey('buttonButtonContent', $result);
		$this->assertArrayHasKey('inputPlaceholderLabel', $result);
		$this->assertArrayNotHasKey('cardCardSize', $result);
	}

	// ---------------------------------------------------------------------------
	// URL / numeric false positive filtering
	// ---------------------------------------------------------------------------

	public function testFiltersOutUrlValues(): void
	{
		$attrs = ['linkLinkContent' => 'https://example.com/page'];

		$result = $this->extractor->extract($attrs, ['Content']);

		$this->assertEmpty($result);
	}

	public function testFiltersOutNumericValues(): void
	{
		$attrs = ['counterCounterContent' => '42'];

		$result = $this->extractor->extract($attrs, ['Content']);

		$this->assertEmpty($result);
	}

	public function testFiltersOutBooleanStringValues(): void
	{
		$attrs = ['flagContent' => 'true', 'flagContent2' => 'false'];

		$result = $this->extractor->extract($attrs, ['Content']);

		$this->assertEmpty($result);
	}

	// ---------------------------------------------------------------------------
	// Filter override: esml_is_translatable_value
	// ---------------------------------------------------------------------------

	public function testFilterCanForceIncludeValue(): void
	{
		// A URL value would normally be excluded...
		$attrs = ['linkContent' => 'https://example.com'];

		// ...but a filter forces it to be included.
		Filters\expectApplied('esml_is_translatable_value')
			->once()
			->andReturn(true);

		$result = $this->extractor->extract($attrs, ['Content']);

		$this->assertArrayHasKey('linkContent', $result);
	}

	public function testFilterCanForceExcludeValue(): void
	{
		$attrs = ['headingContent' => 'Normal text'];

		Filters\expectApplied('esml_is_translatable_value')
			->once()
			->andReturn(false);

		$result = $this->extractor->extract($attrs, ['Content']);

		$this->assertEmpty($result);
	}

	// ---------------------------------------------------------------------------
	// Edge cases
	// ---------------------------------------------------------------------------

	public function testEmptyAttributeMapReturnsEmpty(): void
	{
		$result = $this->extractor->extract([], ['Content']);

		$this->assertEmpty($result);
	}

	public function testNoMatchingSuffixReturnsEmpty(): void
	{
		$attrs = ['title' => 'Hello', 'size' => 'large'];

		$result = $this->extractor->extract($attrs, ['Content']);

		$this->assertEmpty($result);
	}

	public function testMultipleTranslatableAttrsInOneBlock(): void
	{
		$attrs = [
			'heroTitleContent'    => 'Hero Title',
			'heroSubtitleContent' => 'Hero Subtitle',
			'heroSize'            => 'large',
		];

		$result = $this->extractor->extract($attrs, ['Content']);

		$this->assertCount(2, $result);
		$this->assertSame('Hero Title', $result['heroTitleContent']);
		$this->assertSame('Hero Subtitle', $result['heroSubtitleContent']);
	}
}

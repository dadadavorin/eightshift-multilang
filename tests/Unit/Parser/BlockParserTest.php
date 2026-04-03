<?php

declare(strict_types=1);

namespace EightshiftMultilang\Tests\Unit\Parser;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use EightshiftMultilang\Parser\AttributeExtractor;
use EightshiftMultilang\Parser\BlockParser;
use EightshiftMultilang\Parser\TranslatableString;
use PHPUnit\Framework\TestCase;

/**
 * @covers \EightshiftMultilang\Parser\BlockParser
 */
final class BlockParserTest extends TestCase
{
	private BlockParser $parser;

	protected function setUp(): void
	{
		parent::setUp();
		Monkey\setUp();

		Functions\stubs([
			'apply_filters' => static fn(string $tag, mixed $value) => $value,
		]);

		$this->parser = new BlockParser(new AttributeExtractor());
	}

	protected function tearDown(): void
	{
		Monkey\tearDown();
		parent::tearDown();
	}

	// ---------------------------------------------------------------------------
	// Self-closing blocks
	// ---------------------------------------------------------------------------

	public function testParsesSimpleHeadingBlock(): void
	{
		$parsed = $this->parser->parseContent(BlockFixtures::SIMPLE_HEADING, ['Content']);

		$this->assertCount(1, $parsed->blocks);
		$this->assertCount(1, $parsed->translatableStrings);

		$block = $parsed->blocks[0];
		$this->assertSame('eightshift-boilerplate/heading', $block->blockName);
		$this->assertFalse($block->isWrapper());

		$key = 'block_0_headingHeadingContent';
		$this->assertArrayHasKey($key, $parsed->translatableStrings);
		$this->assertSame('About the Teacher', $parsed->translatableStrings[$key]->value);
		$this->assertSame(TranslatableString::CONTEXT_BLOCK_ATTRIBUTE, $parsed->translatableStrings[$key]->context);
	}

	public function testButtonUrlAndBooleanNotExtracted(): void
	{
		$parsed = $this->parser->parseContent(BlockFixtures::BUTTON_WITH_URL, ['Content']);

		$this->assertCount(1, $parsed->translatableStrings);
		$this->assertSame('Learn More', array_values($parsed->translatableStrings)[0]->value);
	}

	public function testBlockWithNoTranslatableContent(): void
	{
		$parsed = $this->parser->parseContent(BlockFixtures::NO_TRANSLATABLE_CONTENT, ['Content']);

		$this->assertCount(1, $parsed->blocks);
		$this->assertEmpty($parsed->translatableStrings);
		$this->assertFalse($parsed->hasTranslatableContent());
	}

	public function testMultipleContentAttributesInOneBlock(): void
	{
		$parsed = $this->parser->parseContent(BlockFixtures::MULTIPLE_CONTENT_ATTRS, ['Content']);

		$this->assertCount(2, $parsed->translatableStrings);
	}

	// ---------------------------------------------------------------------------
	// Wrapper blocks
	// ---------------------------------------------------------------------------

	public function testParsesWrapperBlockAttributes(): void
	{
		$parsed = $this->parser->parseContent(BlockFixtures::WRAPPER_WITH_INNER, ['Content']);

		// Card (outer) + button (inner) = 2 blocks.
		$this->assertCount(2, $parsed->blocks);

		// Card block.
		$cardBlock = $parsed->blocks[0];
		$this->assertSame('eightshift-boilerplate/card', $cardBlock->blockName);
		$this->assertTrue($cardBlock->isWrapper());
		$this->assertStringContainsString('Our Story', $cardBlock->translatableAttributes['cardCardTitleContent']);

		// Button block (parsed from inner content).
		$buttonBlock = $parsed->blocks[1];
		$this->assertSame('eightshift-boilerplate/button', $buttonBlock->blockName);
		$this->assertFalse($buttonBlock->isWrapper());
	}

	public function testWrapperBlockProducesCorrectStringKeys(): void
	{
		$parsed = $this->parser->parseContent(BlockFixtures::WRAPPER_WITH_INNER, ['Content']);

		$keys = array_keys($parsed->translatableStrings);
		// Card block is index 0, button is index 1.
		$this->assertContains('block_0_cardCardTitleContent', $keys);
		$this->assertContains('block_0_cardCardDescriptionContent', $keys);
		$this->assertContains('block_1_buttonButtonContent', $keys);
	}

	// ---------------------------------------------------------------------------
	// Nesting
	// ---------------------------------------------------------------------------

	public function testNestedWrappersTraversedCorrectly(): void
	{
		$parsed = $this->parser->parseContent(BlockFixtures::NESTED_WRAPPERS, ['Content']);

		// layout + card + button = 3 blocks.
		$this->assertCount(3, $parsed->blocks);
		$this->assertTrue($parsed->hasTranslatableContent());
	}

	public function testSameTypeNestedBlocksCloseCorrectly(): void
	{
		$parsed = $this->parser->parseContent(BlockFixtures::SAME_TYPE_NESTED, ['Content']);

		// Outer group + inner group = 2 blocks.
		$this->assertCount(2, $parsed->blocks);
		$this->assertSame('Outer', $parsed->translatableStrings['block_0_groupGroupTitleContent']->value);
		$this->assertSame('Inner', $parsed->translatableStrings['block_1_groupGroupTitleContent']->value);
	}

	public function testMaxDepthLimitsRecursion(): void
	{
		// With maxDepth=1, we should only process the top-level block and not recurse.
		$parsed = $this->parser->parseContent(BlockFixtures::NESTED_WRAPPERS, ['Content'], maxDepth: 1);

		// Only the outer layout block's attributes; card and button are not traversed.
		$this->assertCount(1, $parsed->blocks);
	}

	// ---------------------------------------------------------------------------
	// Edge cases
	// ---------------------------------------------------------------------------

	public function testEmptyContentReturnsEmptyResult(): void
	{
		$parsed = $this->parser->parseContent(BlockFixtures::EMPTY_CONTENT, ['Content']);

		$this->assertEmpty($parsed->blocks);
		$this->assertEmpty($parsed->translatableStrings);
	}

	public function testMalformedJsonBlockSkipped(): void
	{
		// Should not throw — malformed block is silently skipped.
		$parsed = $this->parser->parseContent(BlockFixtures::MALFORMED_JSON, ['Content']);

		$this->assertEmpty($parsed->blocks);
	}

	public function testMixedCoreAndEightshiftBlocksParsed(): void
	{
		$parsed = $this->parser->parseContent(BlockFixtures::MIXED_CORE_AND_EIGHTSHIFT, ['Content']);

		// Only Eightshift blocks are parsed; core/paragraph is ignored.
		$this->assertCount(2, $parsed->blocks);
		$this->assertCount(2, $parsed->translatableStrings);
	}

	public function testUrlInContentKeyIsFiltered(): void
	{
		$parsed = $this->parser->parseContent(BlockFixtures::URL_IN_CONTENT_KEY, ['Content']);

		$this->assertEmpty($parsed->translatableStrings);
	}

	public function testNumericInContentKeyIsFiltered(): void
	{
		$parsed = $this->parser->parseContent(BlockFixtures::NUMERIC_IN_CONTENT_KEY, ['Content']);

		$this->assertEmpty($parsed->translatableStrings);
	}

	// ---------------------------------------------------------------------------
	// Full page
	// ---------------------------------------------------------------------------

	public function testFullPageExtractsAllTranslatableStrings(): void
	{
		$parsed = $this->parser->parseContent(BlockFixtures::FULL_PAGE, ['Content']);

		// heading1 + paragraph + card (outer) + button (inner) + heading2 = 5 blocks.
		$this->assertCount(5, $parsed->blocks);

		// headingHeadingContent × 2 + paragraphParagraphContent + cardCardTitleContent
		// + cardCardDescriptionContent + buttonButtonContent = 6 strings.
		$this->assertCount(6, $parsed->translatableStrings);
	}

	// ---------------------------------------------------------------------------
	// Content offset tracking
	// ---------------------------------------------------------------------------

	public function testBlockContentOffsetIsCorrect(): void
	{
		$content = BlockFixtures::SIMPLE_HEADING;
		$parsed  = $this->parser->parseContent($content, ['Content']);

		$block = $parsed->blocks[0];

		// The raw markup at the reported offset must match the stored rawMarkup.
		$this->assertSame(
			$block->rawMarkup,
			substr($content, $block->contentOffset, strlen($block->rawMarkup))
		);
	}

	// ---------------------------------------------------------------------------
	// Filter: esml_skip_block_types
	// ---------------------------------------------------------------------------

	public function testSkipBlockTypesFilterExcludesBlock(): void
	{
		Filters\expectApplied('esml_skip_block_types')
			->once()
			->andReturn(['eightshift-boilerplate/heading']);

		$parsed = $this->parser->parseContent(BlockFixtures::SIMPLE_HEADING, ['Content']);

		$this->assertEmpty($parsed->blocks);
		$this->assertEmpty($parsed->translatableStrings);
	}
}

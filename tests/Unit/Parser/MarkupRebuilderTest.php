<?php

declare(strict_types=1);

namespace EightshiftMultilang\Tests\Unit\Parser;

use Brain\Monkey;
use Brain\Monkey\Functions;
use EightshiftMultilang\Parser\AttributeExtractor;
use EightshiftMultilang\Parser\BlockParser;
use EightshiftMultilang\Parser\MarkupRebuilder;
use PHPUnit\Framework\TestCase;

/**
 * @covers \EightshiftMultilang\Parser\MarkupRebuilder
 */
final class MarkupRebuilderTest extends TestCase
{
	private BlockParser $parser;
	private MarkupRebuilder $rebuilder;

	protected function setUp(): void
	{
		parent::setUp();
		Monkey\setUp();

		Functions\stubs([
			'apply_filters' => static fn(string $tag, mixed $value) => $value,
		]);

		$this->parser   = new BlockParser(new AttributeExtractor());
		$this->rebuilder = new MarkupRebuilder();
	}

	protected function tearDown(): void
	{
		Monkey\tearDown();
		parent::tearDown();
	}

	// ---------------------------------------------------------------------------
	// Simple replacements
	// ---------------------------------------------------------------------------

	public function testReplacesSimpleHeadingContent(): void
	{
		$parsed = $this->parser->parseContent(BlockFixtures::SIMPLE_HEADING, ['Content']);
		$translations = ['block_0_headingHeadingContent' => 'Über den Lehrer'];

		$result = $this->rebuilder->rebuild($parsed, $translations);

		$this->assertStringContainsString('Über den Lehrer', $result);
		$this->assertStringNotContainsString('About the Teacher', $result);
		// Structural attribute must survive intact.
		$this->assertStringContainsString('"headingHeadingSize":"h2"', $result);
	}

	public function testPreservesUntranslatedAttributesIntact(): void
	{
		$parsed = $this->parser->parseContent(BlockFixtures::BUTTON_WITH_URL, ['Content']);
		$translations = ['block_0_buttonButtonContent' => 'Mehr erfahren'];

		$result = $this->rebuilder->rebuild($parsed, $translations);

		$this->assertStringContainsString('Mehr erfahren', $result);
		$this->assertStringContainsString('"buttonButtonUrl":"https://example.com"', $result);
		$this->assertStringContainsString('"buttonButtonIsNewTab":true', $result);
	}

	// ---------------------------------------------------------------------------
	// Longer / shorter translations
	// ---------------------------------------------------------------------------

	public function testHandlesTranslationLongerThanOriginal(): void
	{
		$parsed = $this->parser->parseContent(BlockFixtures::SIMPLE_HEADING, ['Content']);
		$translations = [
			'block_0_headingHeadingContent' => 'Eine sehr viel längere deutsche Übersetzung für diesen Text',
		];

		$result = $this->rebuilder->rebuild($parsed, $translations);

		$this->assertStringContainsString('Eine sehr viel längere deutsche Übersetzung für diesen Text', $result);
	}

	public function testHandlesTranslationShorterThanOriginal(): void
	{
		$parsed = $this->parser->parseContent(BlockFixtures::SIMPLE_HEADING, ['Content']);
		$translations = ['block_0_headingHeadingContent' => 'Hi'];

		$result = $this->rebuilder->rebuild($parsed, $translations);

		$this->assertStringContainsString('"headingHeadingContent":"Hi"', $result);
	}

	// ---------------------------------------------------------------------------
	// Special characters and unicode
	// ---------------------------------------------------------------------------

	public function testHandlesSpecialCharactersInTranslation(): void
	{
		$parsed = $this->parser->parseContent(BlockFixtures::SIMPLE_PARAGRAPH, ['Content']);
		$translations = [
			'block_0_paragraphParagraphContent' => 'Ça c\'est "très" bien & "parfait".',
		];

		$result = $this->rebuilder->rebuild($parsed, $translations);

		// The translated value should appear in the output (JSON-encoded).
		$this->assertStringContainsString('Ça c\'est', $result);
	}

	public function testHandlesUnicodeTranslations(): void
	{
		$parsed = $this->parser->parseContent(BlockFixtures::SIMPLE_HEADING, ['Content']);
		$translations = ['block_0_headingHeadingContent' => '日本語のタイトル'];

		$result = $this->rebuilder->rebuild($parsed, $translations);

		$this->assertStringContainsString('日本語のタイトル', $result);
	}

	// ---------------------------------------------------------------------------
	// Multiple blocks
	// ---------------------------------------------------------------------------

	public function testReplacesMultipleBlocksCorrectly(): void
	{
		$parsed = $this->parser->parseContent(BlockFixtures::FULL_PAGE, ['Content']);

		$translations = [];
		foreach ($parsed->translatableStrings as $key => $ts) {
			$translations[$key] = 'TRANSLATED: ' . $ts->value;
		}

		$result = $this->rebuilder->rebuild($parsed, $translations);

		// All original texts should be replaced.
		$this->assertStringNotContainsString('Welcome to Our School', $result);
		$this->assertStringNotContainsString('We have been educating students since 1990.', $result);
		// Replaced versions should be present.
		$this->assertStringContainsString('TRANSLATED: Welcome to Our School', $result);
	}

	public function testRebuildsWrapperBlockCorrectly(): void
	{
		$parsed = $this->parser->parseContent(BlockFixtures::WRAPPER_WITH_INNER, ['Content']);

		$translations = [
			'block_0_cardCardTitleContent'       => 'Unsere Geschichte',
			'block_0_cardCardDescriptionContent' => 'Gegründet 2020.',
			'block_1_buttonButtonContent'        => 'Mehr lesen',
		];

		$result = $this->rebuilder->rebuild($parsed, $translations);

		$this->assertStringContainsString('Unsere Geschichte', $result);
		$this->assertStringContainsString('Gegründet 2020.', $result);
		$this->assertStringContainsString('Mehr lesen', $result);
	}

	// ---------------------------------------------------------------------------
	// Partial translations (missing keys)
	// ---------------------------------------------------------------------------

	public function testPartialTranslationsLeaveUnmatchedBlocksUntouched(): void
	{
		$parsed = $this->parser->parseContent(BlockFixtures::FULL_PAGE, ['Content']);

		// Only translate the first block.
		$translations = ['block_0_headingHeadingContent' => 'Willkommen'];

		$result = $this->rebuilder->rebuild($parsed, $translations);

		$this->assertStringContainsString('Willkommen', $result);
		// Other blocks should remain in the original language.
		$this->assertStringContainsString('We have been educating students since 1990.', $result);
	}

	// ---------------------------------------------------------------------------
	// No-op when no translations provided
	// ---------------------------------------------------------------------------

	public function testEmptyTranslationsReturnsOriginalContent(): void
	{
		$parsed = $this->parser->parseContent(BlockFixtures::SIMPLE_HEADING, ['Content']);

		$result = $this->rebuilder->rebuild($parsed, []);

		$this->assertSame($parsed->rawContent, $result);
	}

	// ---------------------------------------------------------------------------
	// Offset correctness — verify the output is valid Gutenberg markup
	// ---------------------------------------------------------------------------

	public function testRebuiltMarkupPreservesBlockCommentStructure(): void
	{
		$parsed = $this->parser->parseContent(BlockFixtures::SIMPLE_HEADING, ['Content']);
		$translations = ['block_0_headingHeadingContent' => 'Übersetzt'];

		$result = $this->rebuilder->rebuild($parsed, $translations);

		$this->assertStringStartsWith('<!-- wp:eightshift-boilerplate/heading', $result);
		$this->assertStringEndsWith('/-->', $result);
	}
}

<?php

declare(strict_types=1);

namespace EightshiftMultilang\Tests\Unit\AI;

use EightshiftMultilang\AI\ResponseParser;
use EightshiftMultilang\Exceptions\TranslationException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \EightshiftMultilang\AI\ResponseParser
 */
final class ResponseParserTest extends TestCase
{
	private ResponseParser $parser;

	protected function setUp(): void
	{
		parent::setUp();
		$this->parser = new ResponseParser();
	}

	// ---------------------------------------------------------------------------
	// Valid JSON responses
	// ---------------------------------------------------------------------------

	public function testParsesPlainJsonResponse(): void
	{
		$original = ['block_0_headingContent' => 'Hello'];
		$response = '{"block_0_headingContent":"Hallo"}';

		$result = $this->parser->parse($response, $original);

		$this->assertSame(['block_0_headingContent' => 'Hallo'], $result);
	}

	public function testParsesMultipleKeys(): void
	{
		$original = [
			'block_0_titleContent'    => 'Welcome',
			'block_1_paragraphContent' => 'Hello World',
			'__post_slug'             => 'about-us',
		];

		$response = json_encode([
			'block_0_titleContent'    => 'Willkommen',
			'block_1_paragraphContent' => 'Hallo Welt',
			'__post_slug'             => 'ueber-uns',
		]);

		$result = $this->parser->parse($response, $original);

		$this->assertSame('Willkommen', $result['block_0_titleContent']);
		$this->assertSame('Hallo Welt', $result['block_1_paragraphContent']);
		$this->assertSame('ueber-uns', $result['__post_slug']);
	}

	// ---------------------------------------------------------------------------
	// Fenced JSON responses
	// ---------------------------------------------------------------------------

	public function testStripsJsonCodeFence(): void
	{
		$original = ['key' => 'value'];
		$response = "```json\n{\"key\":\"wert\"}\n```";

		$result = $this->parser->parse($response, $original);

		$this->assertSame('wert', $result['key']);
	}

	public function testStripsPlainCodeFence(): void
	{
		$original = ['key' => 'value'];
		$response = "```\n{\"key\":\"wert\"}\n```";

		$result = $this->parser->parse($response, $original);

		$this->assertSame('wert', $result['key']);
	}

	public function testHandlesFenceWithTrailingWhitespace(): void
	{
		$original = ['key' => 'value'];
		$response = "  ```json  \n{\"key\":\"translated\"}  \n```  ";

		$result = $this->parser->parse($response, $original);

		$this->assertSame('translated', $result['key']);
	}

	// ---------------------------------------------------------------------------
	// Extra keys in response (ignored)
	// ---------------------------------------------------------------------------

	public function testExtraKeysInResponseAreIgnored(): void
	{
		$original = ['key' => 'value'];
		$response = '{"key":"übersetzt","extra_key":"should be ignored"}';

		$result = $this->parser->parse($response, $original);

		$this->assertArrayNotHasKey('extra_key', $result);
		$this->assertSame('übersetzt', $result['key']);
	}

	// ---------------------------------------------------------------------------
	// Error cases
	// ---------------------------------------------------------------------------

	public function testThrowsOnNonJsonResponse(): void
	{
		$this->expectException(TranslationException::class);
		$this->expectExceptionMessageMatches('/not valid JSON/');

		$this->parser->parse('This is not JSON at all.', ['key' => 'value']);
	}

	public function testThrowsOnEmptyResponse(): void
	{
		$this->expectException(TranslationException::class);

		$this->parser->parse('', ['key' => 'value']);
	}

	public function testThrowsOnMissingKeys(): void
	{
		$this->expectException(TranslationException::class);
		$this->expectExceptionMessageMatches('/missing translations/');

		$original = ['key1' => 'value1', 'key2' => 'value2'];
		// Response only contains one of the two keys.
		$response = '{"key1":"wert1"}';

		$this->parser->parse($response, $original);
	}

	public function testThrowsWhenValueIsNotString(): void
	{
		$this->expectException(TranslationException::class);
		$this->expectExceptionMessageMatches('/not a string/');

		$original = ['key' => 'value'];
		$response = '{"key":42}';

		$this->parser->parse($response, $original);
	}

	// ---------------------------------------------------------------------------
	// Unicode and special chars
	// ---------------------------------------------------------------------------

	public function testHandlesUnicodeTranslations(): void
	{
		$original = ['key' => 'Hello'];
		$response = '{"key":"こんにちは"}';

		$result = $this->parser->parse($response, $original);

		$this->assertSame('こんにちは', $result['key']);
	}

	public function testHandlesHtmlTagsPreservedInResponse(): void
	{
		$original = ['key' => 'Hello <strong>World</strong>'];
		$response = '{"key":"Hallo <strong>Welt<\/strong>"}';

		$result = $this->parser->parse($response, $original);

		$this->assertStringContainsString('<strong>', $result['key']);
	}
}

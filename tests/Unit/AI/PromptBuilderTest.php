<?php

declare(strict_types=1);

namespace EightshiftMultilang\Tests\Unit\AI;

use Brain\Monkey;
use Brain\Monkey\Functions;
use EightshiftMultilang\AI\PromptBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @covers \EightshiftMultilang\AI\PromptBuilder
 */
final class PromptBuilderTest extends TestCase
{
	private PromptBuilder $builder;

	protected function setUp(): void
	{
		parent::setUp();
		Monkey\setUp();

		$this->builder = new PromptBuilder();
	}

	protected function tearDown(): void
	{
		Monkey\tearDown();
		parent::tearDown();
	}

	// ---------------------------------------------------------------------------
	// Default prompt
	// ---------------------------------------------------------------------------

	public function testDefaultPromptContainsSourceAndTarget(): void
	{
		Functions\stubs([
			'get_option'     => '',
			'apply_filters'  => static fn(string $tag, mixed $value) => $value,
		]);

		$prompt = $this->builder->build('English', 'German');

		$this->assertStringContainsString('English', $prompt);
		$this->assertStringContainsString('German', $prompt);
	}

	public function testDefaultPromptContainsSlugInstruction(): void
	{
		Functions\stubs([
			'get_option'    => '',
			'apply_filters' => static fn(string $tag, mixed $value) => $value,
		]);

		$prompt = $this->builder->build('English', 'German');

		$this->assertStringContainsString('__post_slug', $prompt);
		$this->assertStringContainsString('URL-safe', $prompt);
	}

	public function testDefaultPromptInstructsJsonOnlyResponse(): void
	{
		Functions\stubs([
			'get_option'    => '',
			'apply_filters' => static fn(string $tag, mixed $value) => $value,
		]);

		$prompt = $this->builder->build('English', 'French');

		$this->assertStringContainsString('ONLY a valid JSON object', $prompt);
		$this->assertStringContainsString('No markdown fences', $prompt);
	}

	// ---------------------------------------------------------------------------
	// With glossary
	// ---------------------------------------------------------------------------

	public function testGlossaryEntriesAreIncluded(): void
	{
		Functions\stubs([
			'get_option'    => '',
			'apply_filters' => static fn(string $tag, mixed $value) => $value,
		]);

		$glossary = ['Eightshift' => 'Eightshift', 'Dashboard' => 'Armaturenbrett'];
		$prompt = $this->builder->build('English', 'German', $glossary);

		$this->assertStringContainsString('Glossary:', $prompt);
		$this->assertStringContainsString('"Eightshift"', $prompt);
		$this->assertStringContainsString('"Armaturenbrett"', $prompt);
	}

	public function testEmptyGlossaryOmitsGlossarySection(): void
	{
		Functions\stubs([
			'get_option'    => '',
			'apply_filters' => static fn(string $tag, mixed $value) => $value,
		]);

		$prompt = $this->builder->build('English', 'German', []);

		$this->assertStringNotContainsString('Glossary:', $prompt);
	}

	// ---------------------------------------------------------------------------
	// Custom prompt append
	// ---------------------------------------------------------------------------

	public function testCustomPromptIsAppended(): void
	{
		Functions\stubs([
			'get_option'    => static fn($key) => $key === 'esml_ai_custom_prompt'
				? 'Use formal Sie in German.'
				: '',
			'apply_filters' => static fn(string $tag, mixed $value) => $value,
		]);

		$prompt = $this->builder->build('English', 'German');

		$this->assertStringContainsString('Use formal Sie in German.', $prompt);
		$this->assertStringContainsString('Additional instructions:', $prompt);
	}

	public function testBlankCustomPromptNotAppended(): void
	{
		Functions\stubs([
			'get_option'    => '',
			'apply_filters' => static fn(string $tag, mixed $value) => $value,
		]);

		$prompt = $this->builder->build('English', 'German');

		$this->assertStringNotContainsString('Additional instructions:', $prompt);
	}

	// ---------------------------------------------------------------------------
	// Filter: esml_ai_system_prompt
	// ---------------------------------------------------------------------------

	public function testFilterCanModifyPrompt(): void
	{
		Functions\stubs(['get_option' => '']);

		Functions\stubs([
			'apply_filters' => static fn(string $tag, mixed $value) => $tag === 'esml_ai_system_prompt'
				? 'OVERRIDDEN PROMPT'
				: $value,
		]);

		$prompt = $this->builder->build('English', 'German');

		$this->assertSame('OVERRIDDEN PROMPT', $prompt);
	}
}

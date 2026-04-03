<?php

declare(strict_types=1);

namespace EightshiftMultilang\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use EightshiftMultilang\Admin\PostListManager;
use EightshiftMultilang\Languages\Language;
use EightshiftMultilang\Languages\LanguageRepository;
use EightshiftMultilang\Translations\SyncDetector;
use EightshiftMultilang\Translations\TranslationRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \EightshiftMultilang\Admin\PostListManager
 */
final class PostListManagerTest extends TestCase
{
	private PostListManager $manager;

	/** @var TranslationRepository&MockObject */
	private TranslationRepository $translationRepo;

	/** @var LanguageRepository&MockObject */
	private LanguageRepository $languageRepo;

	/** @var SyncDetector&MockObject */
	private SyncDetector $syncDetector;

	protected function setUp(): void
	{
		parent::setUp();
		Monkey\setUp();

		$this->translationRepo = $this->createMock(TranslationRepository::class);
		$this->languageRepo    = $this->createMock(LanguageRepository::class);
		$this->syncDetector    = $this->createMock(SyncDetector::class);

		$this->manager = new PostListManager(
			$this->translationRepo,
			$this->languageRepo,
			$this->syncDetector,
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

	private function makeLanguage(string $code, bool $isDefault = false): Language
	{
		return new Language(
			id: 1,
			code: $code,
			locale: $code . '_' . strtoupper($code),
			name: ucfirst($code),
			nativeName: ucfirst($code),
			flagCode: $code,
			isDefault: $isDefault,
			isActive: true,
			sortOrder: 0,
			dateFormat: null,
		);
	}

	// ---------------------------------------------------------------------------
	// addLanguageColumn
	// ---------------------------------------------------------------------------

	public function testInsertsColumnAfterTitle(): void
	{
		$columns = ['cb' => '', 'title' => 'Title', 'date' => 'Date'];

		$result = $this->manager->addLanguageColumn($columns);

		$keys = array_keys($result);
		$titleIndex = array_search('title', $keys, true);
		$esmlIndex  = array_search('esml_language', $keys, true);

		$this->assertNotFalse($titleIndex);
		$this->assertNotFalse($esmlIndex);
		$this->assertSame($titleIndex + 1, $esmlIndex);
	}

	public function testPreservesAllExistingColumns(): void
	{
		$columns = ['cb' => '', 'title' => 'Title', 'date' => 'Date'];

		$result = $this->manager->addLanguageColumn($columns);

		$this->assertArrayHasKey('cb', $result);
		$this->assertArrayHasKey('title', $result);
		$this->assertArrayHasKey('date', $result);
		$this->assertArrayHasKey('esml_language', $result);
	}

	public function testDoesNotAddColumnWhenNoTitleColumn(): void
	{
		$columns = ['cb' => '', 'date' => 'Date'];

		$result = $this->manager->addLanguageColumn($columns);

		$this->assertArrayNotHasKey('esml_language', $result);
	}

	// ---------------------------------------------------------------------------
	// renderLanguageColumn
	// ---------------------------------------------------------------------------

	public function testRendersLanguageBadgeForLinkedPost(): void
	{
		Functions\stubs(['esc_html' => static fn(string $s) => $s, 'esc_attr' => static fn(string $s) => $s]);

		$this->translationRepo->method('getLanguageCode')->with(42)->willReturn('de');
		$this->languageRepo->method('getByCode')->willReturn($this->makeLanguage('de'));
		$this->translationRepo->method('getGroupId')->willReturn('uuid');
		$this->syncDetector->method('isOutOfSync')->willReturn(false);

		ob_start();
		$this->manager->renderLanguageColumn('esml_language', 42);
		$output = ob_get_clean();

		$this->assertStringContainsString('esml-badge', $output);
		$this->assertStringContainsString('De', $output);
	}

	public function testRendersDashForUnlinkedPost(): void
	{
		Functions\stubs(['esc_attr__' => static fn(string $s) => $s]);

		$this->translationRepo->method('getLanguageCode')->willReturn(null);

		ob_start();
		$this->manager->renderLanguageColumn('esml_language', 99);
		$output = ob_get_clean();

		$this->assertStringContainsString('—', $output);
		$this->assertStringContainsString('esml-badge--unlinked', $output);
	}

	public function testRendersOutOfSyncIndicator(): void
	{
		Functions\stubs(['esc_html' => static fn(string $s) => $s, 'esc_attr' => static fn(string $s) => $s, 'esc_attr__' => static fn(string $s) => $s]);

		$this->translationRepo->method('getLanguageCode')->willReturn('de');
		$this->languageRepo->method('getByCode')->willReturn($this->makeLanguage('de'));
		$this->translationRepo->method('getGroupId')->willReturn('uuid');
		$this->syncDetector->method('isOutOfSync')->willReturn(true);

		ob_start();
		$this->manager->renderLanguageColumn('esml_language', 55);
		$output = ob_get_clean();

		$this->assertStringContainsString('esml-sync-dot--stale', $output);
	}

	public function testSkipsOtherColumns(): void
	{
		ob_start();
		$this->manager->renderLanguageColumn('title', 42);
		$output = ob_get_clean();

		$this->assertSame('', $output);
	}

	// ---------------------------------------------------------------------------
	// renderLanguageFilterDropdown
	// ---------------------------------------------------------------------------

	public function testRendersDropdownForTranslatablePostType(): void
	{
		Functions\stubs([
			'get_option'  => '["post","page"]',
			'esc_html__'  => static fn(string $s) => $s,
			'esc_attr'    => static fn(string $s) => $s,
			'selected'    => static fn($a, $b) => $a === $b ? ' selected' : '',
		]);

		$this->languageRepo->method('getActive')->willReturn([
			$this->makeLanguage('en', isDefault: true),
			$this->makeLanguage('de'),
		]);

		ob_start();
		$this->manager->renderLanguageFilterDropdown('post');
		$output = ob_get_clean();

		$this->assertStringContainsString('<select', $output);
		$this->assertStringContainsString('esml_language_filter', $output);
		$this->assertStringContainsString('value="en"', $output);
		$this->assertStringContainsString('value="de"', $output);
		$this->assertStringContainsString('value="unlinked"', $output);
	}

	public function testSkipsDropdownForNonTranslatablePostType(): void
	{
		Functions\stubs(['get_option' => '["post","page"]']);

		ob_start();
		$this->manager->renderLanguageFilterDropdown('product');
		$output = ob_get_clean();

		$this->assertSame('', $output);
	}

	// ---------------------------------------------------------------------------
	// postsJoinForFilter + postsWhereForFilter
	// ---------------------------------------------------------------------------

	public function testJoinUsesInnerJoinForLanguageFilter(): void
	{
		// Simulate activeFilter set by applyLanguageFilter.
		$reflection = new \ReflectionProperty(PostListManager::class, 'activeFilter');
		$reflection->setValue($this->manager, 'de');

		global $wpdb;
		$wpdb = $this->createMock(\wpdb::class);
		$wpdb->prefix = 'wp_';
		$wpdb->posts = 'wp_posts';

		$join = $this->manager->postsJoinForFilter('', $this->createMock(\WP_Query::class));

		$this->assertStringContainsString('INNER JOIN', $join);
		$this->assertStringContainsString('es_multilang_translations', $join);
	}

	public function testJoinUsesLeftJoinForUnlinkedFilter(): void
	{
		$reflection = new \ReflectionProperty(PostListManager::class, 'activeFilter');
		$reflection->setValue($this->manager, 'unlinked');

		global $wpdb;
		$wpdb = $this->createMock(\wpdb::class);
		$wpdb->prefix = 'wp_';
		$wpdb->posts = 'wp_posts';

		$join = $this->manager->postsJoinForFilter('', $this->createMock(\WP_Query::class));

		$this->assertStringContainsString('LEFT JOIN', $join);
	}

	public function testWhereClauseAddsNullCheckForUnlinked(): void
	{
		$reflection = new \ReflectionProperty(PostListManager::class, 'activeFilter');
		$reflection->setValue($this->manager, 'unlinked');

		global $wpdb;
		$wpdb = $this->createMock(\wpdb::class);
		$wpdb->prefix = 'wp_';
		$wpdb->method('prepare')->willReturnArgument(0);

		$where = $this->manager->postsWhereForFilter('', $this->createMock(\WP_Query::class));

		$this->assertStringContainsString('IS NULL', $where);
	}
}

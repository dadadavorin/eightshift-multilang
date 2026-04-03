<?php

declare(strict_types=1);

namespace EightshiftMultilang\Tests\Unit\Translations;

use Brain\Monkey;
use Brain\Monkey\Functions;
use EightshiftMultilang\Exceptions\TranslationException;
use EightshiftMultilang\Translations\TranslationManager;
use EightshiftMultilang\Translations\TranslationRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TranslationManager.
 *
 * @covers \EightshiftMultilang\Translations\TranslationManager
 */
final class TranslationManagerTest extends TestCase
{
	private MockObject&\wpdb $wpdb;
	private MockObject&TranslationRepository $repository;
	private TranslationManager $manager;

	protected function setUp(): void
	{
		parent::setUp();
		Monkey\setUp();

		Functions\stubs([
			'do_action'           => null,
			'wp_generate_uuid4'   => static fn() => 'test-uuid-1234',
			'current_time'        => static fn() => '2026-04-03 12:00:00',
		]);

		$this->wpdb = $this->createMock(\wpdb::class);
		$this->wpdb->prefix = 'wp_';

		$this->repository = $this->createMock(TranslationRepository::class);
		$this->manager = new TranslationManager($this->wpdb, $this->repository);
	}

	protected function tearDown(): void
	{
		Monkey\tearDown();
		parent::tearDown();
	}

	// ---------------------------------------------------------------------------
	// createGroup()
	// ---------------------------------------------------------------------------

	public function testCreateGroupReturnsUuid(): void
	{
		$groupId = $this->manager->createGroup();

		$this->assertSame('test-uuid-1234', $groupId);
	}

	// ---------------------------------------------------------------------------
	// linkPost()
	// ---------------------------------------------------------------------------

	public function testLinkPostInsertsRow(): void
	{
		$this->repository->method('getGroupId')->with(42)->willReturn(null);
		$this->wpdb->method('insert')->willReturn(1);
		$this->wpdb->insert_id = 1;

		// Should not throw.
		$this->manager->linkPost(42, 'group-uuid', 'de', false);

		$this->addToAssertionCount(1);
	}

	public function testLinkPostThrowsIfAlreadyLinked(): void
	{
		$this->expectException(TranslationException::class);
		$this->expectExceptionMessageMatches('/already linked/');

		$this->repository->method('getGroupId')->with(42)->willReturn('existing-group');

		$this->manager->linkPost(42, 'group-uuid', 'de', false);
	}

	public function testLinkPostThrowsOnDatabaseFailure(): void
	{
		$this->expectException(TranslationException::class);
		$this->expectExceptionMessageMatches('/Failed to link/');

		$this->repository->method('getGroupId')->with(99)->willReturn(null);
		$this->wpdb->method('insert')->willReturn(false);
		$this->wpdb->last_error = 'DB error';

		$this->manager->linkPost(99, 'group-uuid', 'de', false);
	}

	// ---------------------------------------------------------------------------
	// unlinkPost()
	// ---------------------------------------------------------------------------

	public function testUnlinkPostDeletesRow(): void
	{
		$this->repository->method('getGroupId')->with(42)->willReturn('some-group');
		$this->wpdb->method('delete')->willReturn(1);

		$this->manager->unlinkPost(42);

		$this->addToAssertionCount(1);
	}

	public function testUnlinkPostThrowsIfNotLinked(): void
	{
		$this->expectException(TranslationException::class);
		$this->expectExceptionMessageMatches('/not linked/');

		$this->repository->method('getGroupId')->with(99)->willReturn(null);

		$this->manager->unlinkPost(99);
	}

	// ---------------------------------------------------------------------------
	// cleanupDeletedPost()
	// ---------------------------------------------------------------------------

	public function testCleanupDeletedPostNoopsIfNotLinked(): void
	{
		$this->repository->method('getGroupId')->with(55)->willReturn(null);

		// No delete call expected.
		$this->wpdb->expects($this->never())->method('delete');

		$this->manager->cleanupDeletedPost(55);
	}

	public function testCleanupDeletedPostDeletesRowIfLinked(): void
	{
		$this->repository->method('getGroupId')->with(55)->willReturn('group-abc');
		$this->wpdb->expects($this->once())->method('delete')->willReturn(1);

		$this->manager->cleanupDeletedPost(55);
	}

	// ---------------------------------------------------------------------------
	// setSource()
	// ---------------------------------------------------------------------------

	public function testSetSourceThrowsIfNotLinked(): void
	{
		$this->expectException(TranslationException::class);
		$this->expectExceptionMessageMatches('/not linked/');

		$this->repository->method('getGroupId')->with(42)->willReturn(null);

		$this->manager->setSource(42);
	}

	public function testSetSourceUpdatesFlags(): void
	{
		$this->repository->method('getGroupId')->with(42)->willReturn('group-abc');
		$this->repository->method('getLanguageCode')->with(42)->willReturn('en');

		$updateCount = 0;
		$this->wpdb->method('update')
			->willReturnCallback(static function () use (&$updateCount): int {
				$updateCount++;
				return 1;
			});

		$this->manager->setSource(42);

		// Two updates: clear all + set this one.
		$this->assertSame(2, $updateCount);
	}
}

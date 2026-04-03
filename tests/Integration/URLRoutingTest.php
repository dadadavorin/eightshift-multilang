<?php

declare(strict_types=1);

namespace EightshiftMultilang\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for URL routing.
 *
 * These tests require a live WordPress environment bootstrapped via the
 * integration test bootstrap (tests/bootstrap-integration.php). They are
 * intentionally minimal stubs to validate end-to-end rewrite rule resolution
 * and permalink generation once an integration environment is available.
 *
 * @group integration
 */
final class URLRoutingTest extends TestCase
{
	/**
	 * Verify that the integration bootstrap loaded WordPress correctly.
	 * This is a smoke test — if the suite is run without WordPress, the
	 * test is skipped gracefully.
	 */
	public function testWordPressIsBootstrapped(): void
	{
		if (! function_exists('get_option')) {
			$this->markTestSkipped('Integration suite requires a WordPress environment.');
		}

		$this->assertTrue(function_exists('add_rewrite_rule'));
		$this->assertTrue(function_exists('home_url'));
	}

	/**
	 * Verify that esml_language and esml_path are registered query vars.
	 * Requires WordPress to be loaded and init hook to have run.
	 */
	public function testPluginQueryVarsAreRegistered(): void
	{
		if (! function_exists('get_query_var')) {
			$this->markTestSkipped('Integration suite requires a WordPress environment.');
		}

		global $wp;

		$this->assertContains(
			'esml_language',
			$wp->public_query_vars ?? [],
			'esml_language should be a registered query var after init.',
		);

		$this->assertContains(
			'esml_path',
			$wp->public_query_vars ?? [],
			'esml_path should be a registered query var after init.',
		);
	}

	/**
	 * Smoke test: a post assigned to a non-default language should have a
	 * permalink prefixed with the language code.
	 *
	 * Requires at least one non-default language and one translated post.
	 * Will be skipped when no such data exists in the test environment.
	 */
	public function testNonDefaultLanguagePostHasPrefixedPermalink(): void
	{
		if (! function_exists('get_permalink')) {
			$this->markTestSkipped('Integration suite requires a WordPress environment.');
		}

		// Lookup a translated post from the integration fixtures (to be set up
		// by the integration bootstrap when the environment is available).
		$postId = (int) get_option('esml_integration_test_post_id_de', 0);

		if ($postId === 0) {
			$this->markTestSkipped('No integration fixture post found (esml_integration_test_post_id_de).');
		}

		$permalink = get_permalink($postId);

		$this->assertStringContainsString('/de/', $permalink);
	}
}

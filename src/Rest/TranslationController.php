<?php

declare(strict_types=1);

namespace EightshiftMultilang\Rest;

use EightshiftMultilang\AI\TranslationEngine;
use EightshiftMultilang\Exceptions\RateLimitException;
use EightshiftMultilang\Exceptions\TranslationException;
use EightshiftMultilang\Translations\SyncDetector;
use EightshiftMultilang\Translations\TranslationManager;
use EightshiftMultilang\Translations\TranslationRepository;

/**
 * REST controller for translation group management and AI translation.
 *
 * Routes (namespace: eightshift-multilang/v1):
 *   GET    /translations/(?P<postId>\d+)               — group & links for a post
 *   POST   /translations/(?P<postId>\d+)/translate      — trigger AI translation
 *   DELETE /translations/(?P<postId>\d+)               — unlink post from its group
 *   GET    /translations/(?P<postId>\d+)/sync-status    — is translation out of sync?
 *
 * GET and POST translate require edit_posts (editor-facing).
 * DELETE requires manage_options (admin-only destructive action).
 */
final class TranslationController extends RestController
{
	public function __construct(
		private readonly TranslationRepository $translationRepository,
		private readonly TranslationManager $translationManager,
		private readonly TranslationEngine $translationEngine,
		private readonly SyncDetector $syncDetector,
	) {
	}

	public function register(): void
	{
		add_action('rest_api_init', function (): void {
			register_rest_route(self::REST_NAMESPACE, '/translations/(?P<postId>\d+)', [
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [$this, 'show'],
					'permission_callback' => [$this, 'permissionEditPosts'],
					'args'                => $this->postIdArgs(),
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [$this, 'destroy'],
					'permission_callback' => [$this, 'permissionManageOptions'],
					'args'                => $this->postIdArgs(),
				],
			]);

			register_rest_route(self::REST_NAMESPACE, '/translations/(?P<postId>\d+)/translate', [
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [$this, 'translate'],
				'permission_callback' => [$this, 'permissionEditPosts'],
				'args'                => array_merge($this->postIdArgs(), [
					'target_language' => [
						'required'          => true,
						'type'              => 'string',
						'pattern'           => '^[a-z]{2,10}$',
						'sanitize_callback' => 'sanitize_key',
						'description'       => 'Language code to translate into (e.g. "de").',
					],
				]),
			]);

			register_rest_route(self::REST_NAMESPACE, '/translations/(?P<postId>\d+)/sync-status', [
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [$this, 'syncStatus'],
				'permission_callback' => [$this, 'permissionEditPosts'],
				'args'                => $this->postIdArgs(),
			]);
		});
	}

	// ---------------------------------------------------------------------------
	// Handlers
	// ---------------------------------------------------------------------------

	/**
	 * GET /translations/{postId} — translation group info for a post.
	 */
	public function show(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
	{
		$postId = (int) $request->get_param('postId');
		$post   = get_post($postId);

		if (! $post instanceof \WP_Post) {
			return $this->respondError('post_not_found', 'Post not found.', 404);
		}

		$groupId = $this->translationRepository->getGroupId($postId);

		if ($groupId === null) {
			return $this->respondOk([
				'post_id'  => $postId,
				'group_id' => null,
				'links'    => [],
			]);
		}

		$links = $this->translationRepository->getByGroup($groupId);

		return $this->respondOk([
			'post_id'  => $postId,
			'group_id' => $groupId,
			'links'    => array_map(
				static fn($t) => [
					'post_id'       => $t->postId,
					'language_code' => $t->languageCode,
					'is_source'     => $t->isSource,
					'updated_at'    => $t->updatedAt->format(\DateTimeInterface::ATOM),
				],
				$links,
			),
		]);
	}

	/**
	 * POST /translations/{postId}/translate — trigger AI translation.
	 *
	 * Returns the new translated post ID on success.
	 * This is a synchronous operation — Claude API is called inline.
	 * For large posts this may take several seconds.
	 */
	public function translate(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
	{
		$postId         = (int) $request->get_param('postId');
		$targetLanguage = (string) $request->get_param('target_language');

		$post = get_post($postId);

		if (! $post instanceof \WP_Post) {
			return $this->respondError('post_not_found', 'Post not found.', 404);
		}

		try {
			$newPostId = $this->translationEngine->translatePost($postId, $targetLanguage);
		} catch (RateLimitException $e) {
			return $this->respondError('rate_limit_exceeded', $e->getMessage(), 429);
		} catch (TranslationException $e) {
			return $this->respondError('translation_failed', $e->getMessage(), 422);
		} catch (\InvalidArgumentException $e) {
			return $this->respondError('invalid_request', $e->getMessage());
		} catch (\RuntimeException $e) {
			return $this->respondError('translation_error', $e->getMessage(), 500);
		}

		return new \WP_REST_Response(
			[
				'success' => true,
				'data'    => [
					'source_post_id'    => $postId,
					'translated_post_id' => $newPostId,
					'target_language'   => $targetLanguage,
					'edit_url'          => get_edit_post_link($newPostId, 'raw'),
				],
			],
			201,
		);
	}

	/**
	 * DELETE /translations/{postId} — unlink post from its translation group.
	 */
	public function destroy(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
	{
		$postId = (int) $request->get_param('postId');

		$groupId = $this->translationRepository->getGroupId($postId);

		if ($groupId === null) {
			return $this->respondError('not_linked', 'Post is not part of a translation group.', 404);
		}

		try {
			$this->translationManager->unlinkPost($postId);
		} catch (\RuntimeException $e) {
			return $this->respondError('unlink_error', $e->getMessage(), 500);
		}

		return $this->respondOk(['unlinked_post_id' => $postId]);
	}

	/**
	 * GET /translations/{postId}/sync-status — whether the translation is stale.
	 */
	public function syncStatus(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
	{
		$postId = (int) $request->get_param('postId');

		$post = get_post($postId);

		if (! $post instanceof \WP_Post) {
			return $this->respondError('post_not_found', 'Post not found.', 404);
		}

		$outOfSync = $this->syncDetector->isOutOfSync($postId);

		return $this->respondOk([
			'post_id'      => $postId,
			'out_of_sync'  => $outOfSync,
		]);
	}

	// ---------------------------------------------------------------------------
	// Argument schemas
	// ---------------------------------------------------------------------------

	/**
	 * @return array<string, mixed>
	 */
	private function postIdArgs(): array
	{
		return [
			'postId' => [
				'required'          => true,
				'type'              => 'integer',
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			],
		];
	}
}

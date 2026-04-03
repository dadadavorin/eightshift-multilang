<?php

declare(strict_types=1);

namespace EightshiftMultilang\Cache;

/**
 * Centralised wrapper around WordPress object cache (wp_cache_*).
 *
 * All keys use the 'esml' cache group so the entire plugin cache can be
 * flushed with a single wp_cache_flush_group() call (on hosts that support it).
 *
 * TTL is intentionally 0 (persistent) for all entries — data is deterministic
 * from the database and invalidated surgically on every write via CacheInvalidator.
 */
final class CacheManager
{
	public const GROUP = 'esml';

	// ---------------------------------------------------------------------------
	// Key constants — one constant per logical cache entry type.
	// ---------------------------------------------------------------------------

	public const KEY_LANGUAGES_ACTIVE = 'languages_active';
	public const KEY_LANGUAGE_DEFAULT = 'language_default';
	public const KEY_LANGUAGES_ALL    = 'languages_all';
	public const KEY_SUFFIXES         = 'suffixes';

	// Parameterised key prefixes (append the variable part at call-site).
	public const PREFIX_TRANSLATIONS  = 'translations_';    // + post_id
	public const PREFIX_GROUP         = 'group_';           // + group_uuid
	public const PREFIX_HREFLANG     = 'hreflang_';        // + post_id
	public const PREFIX_POST_LANG    = 'post_lang_';       // + post_id

	// ---------------------------------------------------------------------------
	// Public API
	// ---------------------------------------------------------------------------

	/**
	 * Retrieve a cached value. Returns null on a cache miss.
	 *
	 * @param string $key The cache key (without group prefix).
	 * @return mixed|null Cached value or null on miss.
	 */
	public function get(string $key): mixed
	{
		$value = wp_cache_get($key, self::GROUP);

		return ($value === false) ? null : $value;
	}

	/**
	 * Store a value in the object cache.
	 *
	 * @param string $key   The cache key.
	 * @param mixed  $value The value to store (must be serialisable).
	 */
	public function set(string $key, mixed $value): void
	{
		wp_cache_set($key, $value, self::GROUP);
	}

	/**
	 * Remove a single key from the cache.
	 *
	 * @param string $key The cache key.
	 */
	public function delete(string $key): void
	{
		wp_cache_delete($key, self::GROUP);
	}

	/**
	 * Flush the entire 'esml' group.
	 *
	 * Tries wp_cache_flush_group() (Redis/Memcached with grouping support).
	 * Falls back to wp_cache_flush() for the default object cache.
	 */
	public function flushGroup(): void
	{
		if (function_exists('wp_cache_flush_group')) {
			wp_cache_flush_group(self::GROUP);
		} else {
			// The built-in WP object cache does not support group flushing.
			// A full flush is the only safe fallback.
			wp_cache_flush();
		}
	}

	// ---------------------------------------------------------------------------
	// Helpers for parameterised keys
	// ---------------------------------------------------------------------------

	/** @param int $postId */
	public function keyTranslations(int $postId): string
	{
		return self::PREFIX_TRANSLATIONS . $postId;
	}

	/** @param string $groupId Translation group UUID. */
	public function keyGroup(string $groupId): string
	{
		return self::PREFIX_GROUP . $groupId;
	}

	/** @param int $postId */
	public function keyHreflang(int $postId): string
	{
		return self::PREFIX_HREFLANG . $postId;
	}

	/** @param int $postId */
	public function keyPostLanguage(int $postId): string
	{
		return self::PREFIX_POST_LANG . $postId;
	}
}

<?php

declare(strict_types=1);

namespace EightshiftMultilang\Helpers;

/**
 * Encrypts and decrypts sensitive data (e.g. API keys) using libsodium's
 * authenticated secret-key encryption (XSalsa20-Poly1305).
 *
 * The encryption key is derived from the site's AUTH_KEY constant salted
 * with a plugin-specific string and, in multisite, the current blog ID —
 * so each site in a network has an independent key.
 *
 * Storage format: base64( nonce[24 bytes] + ciphertext )
 */
final class EncryptionHelper
{
	private const KEY_CONTEXT = 'esml_api_key_encryption_v1_blog_';

	/**
	 * Encrypt a plaintext string.
	 *
	 * @param string $plaintext The value to encrypt.
	 * @return string           Base64-encoded nonce + ciphertext.
	 * @throws \RuntimeException If encryption fails.
	 */
	public static function encrypt(string $plaintext): string
	{
		$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		$ciphertext = sodium_crypto_secretbox($plaintext, $nonce, self::getKey());

		// Wipe the plaintext from memory after use.
		sodium_memzero($plaintext);

		return base64_encode($nonce . $ciphertext);
	}

	/**
	 * Decrypt a previously encrypted string.
	 *
	 * @param string $encoded Base64-encoded nonce + ciphertext.
	 * @return string         Original plaintext.
	 * @throws \RuntimeException If the data is corrupted or the key is wrong.
	 */
	public static function decrypt(string $encoded): string
	{
		$decoded = base64_decode($encoded, true);

		if ($decoded === false || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
			throw new \RuntimeException('Invalid encrypted data: base64 decode failed or data is too short.');
		}

		$nonce = mb_substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, '8bit');
		$ciphertext = mb_substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, null, '8bit');

		$plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, self::getKey());

		if ($plaintext === false) {
			throw new \RuntimeException('Decryption failed — key mismatch or corrupted data.');
		}

		return $plaintext;
	}

	/**
	 * Derive the encryption key from AUTH_KEY, salted per site.
	 *
	 * Uses sodium_crypto_generichash (BLAKE2b) to produce a fixed-length key.
	 * The per-blog salt isolates keys across sites in a WordPress multisite network.
	 *
	 * @return string 32-byte binary key.
	 */
	private static function getKey(): string
	{
		$authKey = defined('AUTH_KEY') ? AUTH_KEY : 'fallback-insecure-key-replace-in-production';
		$blogId = function_exists('get_current_blog_id') ? (string) get_current_blog_id() : '1';
		$context = self::KEY_CONTEXT . $blogId;

		return sodium_crypto_generichash(
			$context,
			$authKey,
			SODIUM_CRYPTO_SECRETBOX_KEYBYTES
		);
	}
}

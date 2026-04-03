<?php

declare(strict_types=1);

namespace EightshiftMultilang\Tests\Unit\Helpers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use EightshiftMultilang\Helpers\EncryptionHelper;
use PHPUnit\Framework\TestCase;

/**
 * Tests for EncryptionHelper.
 *
 * @covers \EightshiftMultilang\Helpers\EncryptionHelper
 */
final class EncryptionHelperTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		Monkey\setUp();

		// Mock the WordPress function used for multisite blog ID.
		Functions\stubs([
			'get_current_blog_id' => static fn() => 1,
		]);
	}

	protected function tearDown(): void
	{
		Monkey\tearDown();
		parent::tearDown();
	}

	// ---------------------------------------------------------------------------
	// Happy path
	// ---------------------------------------------------------------------------

	public function testEncryptDecryptRoundTrip(): void
	{
		$plaintext = 'sk-ant-api03-super-secret-key';

		$encrypted = EncryptionHelper::encrypt($plaintext);
		$decrypted = EncryptionHelper::decrypt($encrypted);

		$this->assertSame($plaintext, $decrypted);
	}

	public function testEncryptProducesBase64Output(): void
	{
		$encrypted = EncryptionHelper::encrypt('test-value');

		// Valid base64 string.
		$this->assertNotFalse(base64_decode($encrypted, true));
	}

	public function testEncryptProducesDifferentCiphertextsEachCall(): void
	{
		$plaintext = 'same-input';

		$first = EncryptionHelper::encrypt($plaintext);
		$second = EncryptionHelper::encrypt($plaintext);

		// Nonces are random, so ciphertexts must differ.
		$this->assertNotSame($first, $second);
	}

	public function testDecryptHandlesUnicodeValues(): void
	{
		$plaintext = 'Schlüssel: ñoño 日本語 🔑';

		$encrypted = EncryptionHelper::encrypt($plaintext);
		$decrypted = EncryptionHelper::decrypt($encrypted);

		$this->assertSame($plaintext, $decrypted);
	}

	public function testDecryptHandlesEmptyString(): void
	{
		$plaintext = '';

		$encrypted = EncryptionHelper::encrypt($plaintext);
		$decrypted = EncryptionHelper::decrypt($encrypted);

		$this->assertSame($plaintext, $decrypted);
	}

	// ---------------------------------------------------------------------------
	// Edge cases — corrupted data
	// ---------------------------------------------------------------------------

	public function testDecryptThrowsOnInvalidBase64(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/Invalid encrypted data/');

		EncryptionHelper::decrypt('not-valid-base64!!!');
	}

	public function testDecryptThrowsOnTruncatedData(): void
	{
		$this->expectException(\RuntimeException::class);

		// Valid base64 but too short to contain a nonce + ciphertext.
		EncryptionHelper::decrypt(base64_encode('short'));
	}

	public function testDecryptThrowsOnTamperedCiphertext(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/Decryption failed/');

		$encrypted = EncryptionHelper::encrypt('original value');
		$decoded = base64_decode($encrypted, true);

		// Flip a byte in the ciphertext region (after the 24-byte nonce).
		$tampered = $decoded;
		$tampered[30] = chr(ord($tampered[30]) ^ 0xFF);

		EncryptionHelper::decrypt(base64_encode($tampered));
	}

	// ---------------------------------------------------------------------------
	// Multisite isolation
	// ---------------------------------------------------------------------------

	public function testDifferentBlogIdsProduceDifferentKeys(): void
	{
		// Blog 1.
		Functions\stubs([
			'get_current_blog_id' => static fn() => 1,
		]);

		$plaintext = 'api-key';
		$encryptedBlog1 = EncryptionHelper::encrypt($plaintext);

		// Blog 2 — different ID means different key.
		Functions\stubs([
			'get_current_blog_id' => static fn() => 2,
		]);

		$this->expectException(\RuntimeException::class);
		EncryptionHelper::decrypt($encryptedBlog1);
	}
}

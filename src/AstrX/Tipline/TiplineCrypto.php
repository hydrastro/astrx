<?php
declare(strict_types=1);

namespace AstrX\Tipline;

/**
 * Anonymous tip-line crypto — libsodium sealed boxes (crypto_box_seal).
 *
 * The server only ever holds the operator's PUBLIC key and SEALS submissions to
 * it; the matching secret key lives offline and is the only thing that can open a
 * sealed box. So a full database dump — or a live server compromise — yields
 * nothing but ciphertext. Decryption is deliberately NOT offered here: it belongs
 * offline (see tools/tipline.php, which inlines its own sodium so this class
 * never needs a secret key), because pasting the secret key into the same server
 * the model assumes may be hostile would defeat the entire design.
 */
final class TiplineCrypto
{
    /** Seal $plaintext to the operator's base64 public key. Base64 ciphertext, or null on any failure. */
    public static function seal(string $plaintext, string $pubkeyB64): ?string
    {
        if (!extension_loaded('sodium')) {
            return null;
        }
        $pub = base64_decode(trim($pubkeyB64), true);
        if ($pub === false || strlen($pub) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            return null;
        }
        try {
            $cipher = sodium_crypto_box_seal($plaintext, $pub);
        } catch (\SodiumException) {
            return null;
        }
        return base64_encode($cipher);
    }

    /** True when $pubkeyB64 is base64 of a valid box public key. */
    public static function isValidPubkey(string $pubkeyB64): bool
    {
        if (!extension_loaded('sodium')) {
            return false;
        }
        $pub = base64_decode(trim($pubkeyB64), true);
        return $pub !== false && strlen($pub) === SODIUM_CRYPTO_BOX_PUBLICKEYBYTES;
    }
}

<?php
declare(strict_types=1);

namespace AstrX\Auth;

/**
 * RFC 6238 TOTP + one-time recovery codes — zero dependency (hash_hmac only).
 *
 * Used for optional second-factor auth on interactive password logins. Secrets
 * are base32 (what authenticator apps expect); verification allows a ±1 step
 * (30 s) drift and compares in constant time. Recovery codes are high-entropy
 * random strings shown once and stored only as SHA-256 hashes, verified with
 * hash_equals and consumed on use.
 *
 * Pure/static: no DB, no session — the caller owns persistence.
 */
final class TotpService
{
    private const PERIOD = 30;
    private const DIGITS = 6;
    private const B32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A fresh base32 secret (160-bit → 32 base32 chars). */
    public function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /**
     * Verify a 6-digit code against a base32 secret, allowing ±$window steps of
     * clock drift. Returns false for malformed input rather than throwing.
     */
    public function verifyCode(string $secretB32, string $code, int $window = 1): bool
    {
        $code = trim($code);
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }
        $key = self::base32Decode($secretB32);
        if ($key === '') {
            return false;
        }
        $counter = intdiv(time(), self::PERIOD);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals($this->hotp($key, $counter + $i), $code)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Like verifyCode, but returns the ABSOLUTE time-step the code matched (or null
     * if none), so the login challenge can enforce RFC 6238 §5.2 single-use:
     * accept only a step strictly greater than the last one accepted for this user.
     */
    public function verifyCodeStep(string $secretB32, string $code, int $window = 1): ?int
    {
        $code = trim($code);
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return null;
        }
        $key = self::base32Decode($secretB32);
        if ($key === '') {
            return null;
        }
        $counter = intdiv(time(), self::PERIOD);
        for ($i = -$window; $i <= $window; $i++) {
            $step = $counter + $i;
            if (hash_equals($this->hotp($key, $step), $code)) {
                return $step;
            }
        }
        return null;
    }

    /** The otpauth:// provisioning URI an authenticator imports (QR or manual). */
    public function provisioningUri(string $secretB32, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);
        return 'otpauth://totp/' . $label
            . '?secret=' . rawurlencode($secretB32)
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    /**
     * Generate $n recovery codes. Returns the plaintext (shown once) and the
     * SHA-256 hashes to persist.
     *
     * @return array{plain: list<string>, hashes: list<string>}
     */
    public function generateRecoveryCodes(int $n = 10): array
    {
        $plain = [];
        $hashes = [];
        for ($i = 0; $i < max(1, $n); $i++) {
            // 16 base32 chars (80 bits) grouped 8-8, e.g. "A3F9K2PQ-R7X4M8T2". The
            // dash is cosmetic: hashing is on the normalized form so input with or
            // without it matches. random_bytes(10) encodes to EXACTLY 16 base32
            // chars and ALL are kept. (An earlier version used random_bytes(8) →
            // 13 chars but kept only the first 10 = 50 bits, brute-forceable from a
            // stolen hash table since the codes are single fast-SHA-256 hashes.)
            $raw  = self::base32Encode(random_bytes(10));
            $code = substr($raw, 0, 8) . '-' . substr($raw, 8, 8);
            $plain[]  = $code;
            $hashes[] = hash('sha256', self::normalizeRecovery($code));
        }
        return ['plain' => $plain, 'hashes' => $hashes];
    }

    /**
     * Verify a recovery code against stored hashes. Returns the index of the
     * matched (now-spent) hash so the caller can remove it, or null on no match.
     *
     * @param list<string> $hashes
     */
    public function verifyRecovery(string $code, array $hashes): ?int
    {
        $candidate = hash('sha256', self::normalizeRecovery($code));
        foreach ($hashes as $i => $h) {
            if (hash_equals($h, $candidate)) {
                return $i;
            }
        }
        return null;
    }

    /** Canonicalise a recovery code for hashing: strip non-alphanumerics, uppercase. */
    private static function normalizeRecovery(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    // -------------------------------------------------------------------------

    /** One HOTP value (zero-padded 6-digit string) for a counter. */
    private function hotp(string $key, int $counter): string
    {
        // 8-byte big-endian counter.
        $bin = pack('N*', 0, $counter);
        $hash = hash_hmac('sha1', $bin, $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $part = substr($hash, $offset, 4);
        $val = ((ord($part[0]) & 0x7F) << 24)
             | ((ord($part[1]) & 0xFF) << 16)
             | ((ord($part[2]) & 0xFF) << 8)
             | (ord($part[3]) & 0xFF);
        $otp = $val % (10 ** self::DIGITS);
        return str_pad((string) $otp, self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $data): string
    {
        if ($data === '') {
            return '';
        }
        $bits = '';
        foreach (str_split($data) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $out .= self::B32_ALPHABET[(int) bindec($chunk)];
        }
        return $out;
    }

    private static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(trim($b32));
        if ($b32 === '' || preg_match('/^[A-Z2-7]+$/', $b32) !== 1) {
            return '';
        }
        $bits = '';
        $len = strlen($b32);
        for ($i = 0; $i < $len; $i++) {
            $pos = strpos(self::B32_ALPHABET, $b32[$i]);
            if ($pos === false) {
                return '';
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr((int) bindec($byte));
            }
        }
        return $out;
    }
}

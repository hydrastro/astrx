<?php
declare(strict_types=1);

namespace AstrX\Captcha;

use AstrX\Captcha\CaptchaType;
use AstrX\Result\DiagnosticLevel;

use AstrX\Captcha\Diagnostic\CaptchaExpiredDiagnostic;
use AstrX\Captcha\Diagnostic\CaptchaNotFoundDiagnostic;
use AstrX\Captcha\Diagnostic\CaptchaWrongDiagnostic;
use AstrX\Config\InjectConfig;
use AstrX\Result\Diagnostics;
use AstrX\Result\Result;

/**
 * Captcha orchestration service.
 *
 * Usage — generating a captcha for a form:
 *   $result = $captcha->generate();
 *   if ($result->isOk()) {
 *       ['id' => $id, 'image_b64' => $img] = $result->unwrap();
 *       $ctx->set('captcha_id',    $id);
 *       $ctx->set('captcha_image', $img);
 *   }
 *
 * Usage — verifying on form submission:
 *   $result = $captcha->verify($submittedId, $submittedText);
 *   if (!$result->isOk()) {
 *       $result->drainTo($collector);
 *       // Re-render form with new captcha
 *   }
 *
 * Failure diagnostics:
 *   CaptchaNotFoundDiagnostic — ID not in DB (used, expired+GC'd, or invalid)
 *   CaptchaExpiredDiagnostic  — found but past expiry (not yet GC'd)
 *   CaptchaWrongDiagnostic    — found, valid, but text mismatch
 *   CaptchaDbDiagnostic       — PDO error during any operation
 */
final class CaptchaService
{
    /** Lifetime of a generated captcha token in seconds. Default: 10 minutes. */
    private int $ttl = 600;

    #[InjectConfig('captcha_expiration')]
    public function setTtl(int $ttl): void { $this->ttl = max(1, $ttl); }

    /** Max times a single captcha id may be reloaded before refresh becomes a no-op. */
    private int $maxRegens     = 5;
    /** Seconds that must elapse between two reloads of the same captcha id. */
    private int $cooldownSecs  = 2;

    #[\AstrX\Config\InjectConfig('max_regens')]
    public function setMaxRegens(int $v): void   { $this->maxRegens    = max(1, $v); }
    #[\AstrX\Config\InjectConfig('cooldown_secs')]
    public function setCooldownSecs(int $v): void { $this->cooldownSecs = max(0, $v); }

    /**
     * Whether the iframe reload/refresh control is offered on captcha forms.
     * Opt-in (default off): the reload button lets a user fetch a fresh image
     * without reloading the page, but it adds the CaptchaFrame round-trip and
     * the regen bookkeeping — off keeps a plain single-image captcha.
     */
    private bool $reloadEnabled = false;

    #[\AstrX\Config\InjectConfig('reload_enabled')]
    public function setReloadEnabled(bool $v): void { $this->reloadEnabled = $v; }

    public function reloadEnabled(): bool { return $this->reloadEnabled; }

    public function __construct(
        private readonly CaptchaRepository $repository,
        private readonly CaptchaRenderer   $renderer,
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Generate a new captcha: persist the token and render the image.
     *
     * Opportunistically deletes expired tokens before inserting the new one.
     *
     * @return Result<array{id: string, image_b64: string}>
     */
    public function generate(): Result
    {
        // Clean up expired tokens — non-fatal if it fails
        $this->repository->deleteExpired();

        $id        = bin2hex(random_bytes(16)); // 32-char hex, cryptographically secure
        $text      = $this->renderer->generateText();
        $expiresAt = time() + $this->ttl;

        $storeResult = $this->repository->store($id, $text, $expiresAt);
        if (!$storeResult->isOk()) {
            return Result::err(null, $storeResult->diagnostics());
        }

        return Result::ok([
                              'id'        => $id,
                              'image_b64' => $this->renderer->render($text),
                          ]);
    }

    /**
     * Generate a captcha using a specific difficulty type,
     * overriding the renderer's globally-configured type for this call only.
     *
     * @return Result<array{id: string, image_b64: string}>
     */
    public function generateWithType(CaptchaType $type): Result
    {
        $this->renderer->setCaptchaType($type->value);
        return $this->generate();
    }

    /**
     * Verify a submitted captcha token.
     *
     * On success the token is consumed (deleted) — single-use.
     * On failure the token is NOT deleted so the user can retry.
     *
     * Comparison is case-insensitive to reduce friction.
     *
     * @return Result<bool>
     */
    /**
     * Replace the text and expiry of an existing captcha (keeps the same id).
     * Used by the iframe-reload mechanism: the parent form's hidden cid stays
     * valid, only the visible image+text changes. Returns the new image as
     * base64 data URI so the caller can serve it without an extra DB read.
     *
     * @return Result<string>  the new image as base64 data URI
     */
    public function regenerate(string $id): Result
    {
        $existing = $this->repository->find($id);
        if (!$existing->isOk()) {
            return Result::err('', $existing->diagnostics());
        }
        $row = $existing->unwrap();
        if (!is_array($row)) {
            return Result::err('', \AstrX\Result\Diagnostics::of());
        }

        $newText = $this->renderer->generateText();

        // Repository encodes the rate limit in the WHERE clause: this UPDATE
        // returns rowCount() === 0 (and Result::ok(false)) when the cap or
        // cooldown blocks the attempt. We do NOT treat that as an error —
        // it just means the iframe will keep showing the previous image,
        // which is the right UX for "too many refreshes too fast".
        $regen = $this->repository->regenerate(
            $id,
            $newText,
            $this->maxRegens,
            $this->cooldownSecs,
        );
        if (!$regen->isOk()) {
            return Result::err('', $regen->diagnostics());
        }
        $updated = (bool) $regen->unwrap();
        if (!$updated) {
            // Rate-limited — return the still-current text's image so the
            // caller can render SOMETHING. Reading $row['text'] from the
            // earlier find() is fine: it is stale if a concurrent regen
            // just succeeded, but the rendering is idempotent on the text
            // value so the user still sees a valid captcha image.
            $existingText = $row['text'];
            return Result::ok($this->renderer->render($existingText));
        }

        return Result::ok($this->renderer->render($newText));
    }

    /** @return Result<bool> */
    public function verify(string $id, string $submittedText): Result
    {
        $findResult = $this->repository->find($id);

        if (!$findResult->isOk()) {
            return Result::err(null, $findResult->diagnostics());
        }

        $row = $findResult->unwrap();

        if ($row === null) {
            return Result::err(null, Diagnostics::of(new CaptchaNotFoundDiagnostic(
                                                          'astrx.captcha/not_found', DiagnosticLevel::WARNING,
                                                          $id,
                                                      )));
        }

        if (time() > $row['expires_at']) {
            return Result::err(null, Diagnostics::of(new CaptchaExpiredDiagnostic(
                                                          'astrx.captcha/expired', DiagnosticLevel::NOTICE,
                                                          $id,
                                                          $row['expires_at'],
                                                      )));
        }

        // Stored text is lower-cased plaintext (see CaptchaRepository::store
        // for the rationale). Compare case-insensitively, in constant time to
        // avoid leaking timing information about how many characters matched.
        if (!hash_equals((string) $row['text'], strtolower($submittedText))) {
            return Result::err(null, Diagnostics::of(new CaptchaWrongDiagnostic(
                                                          'astrx.captcha/wrong_text', DiagnosticLevel::NOTICE,
                                                      )));
        }

        // Consume the token ATOMICALLY: only the request whose DELETE removes the
        // row proceeds. Concurrent verifications of one solved captcha all pass
        // the checks above, but only one wins the delete — the losers are
        // rejected here, so a single solved captcha cannot authorise a burst of
        // posts/logins (closes the find()-then-delete() TOCTOU).
        $consumed = $this->repository->consume($id);
        if (!$consumed->isOk() || $consumed->unwrap() !== true) {
            return Result::err(null, Diagnostics::of(new CaptchaWrongDiagnostic(
                                                          'astrx.captcha/wrong_text', DiagnosticLevel::NOTICE,
                                                      )));
        }

        return Result::ok(true);
    }
}

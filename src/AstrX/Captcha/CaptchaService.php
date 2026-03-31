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

        // Stored text is SHA-256(strtolower(plaintext)); apply the same transform.
        if (!hash_equals($row['text'], hash('sha256', strtolower($submittedText)))) {
            return Result::err(null, Diagnostics::of(new CaptchaWrongDiagnostic(
                                                          'astrx.captcha/wrong_text', DiagnosticLevel::NOTICE,
                                                      )));
        }

        // Consume the token — delete by ID, not by text
        $this->repository->delete($id);

        return Result::ok(true);
    }
}

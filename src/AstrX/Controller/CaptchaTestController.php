<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Captcha\CaptchaRenderer;
use AstrX\Captcha\CaptchaRepository;
use AstrX\Captcha\CaptchaService;
use AstrX\Captcha\CaptchaType;
use AstrX\Http\Request;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;
use AstrX\Session\PrgHandler;
use AstrX\Template\DefaultTemplateContext;

/**
 * Temporary captcha test page — DELETE BEFORE PRODUCTION.
 *
 * Shows all three difficulty levels (EASY / MEDIUM / HARD) side by side,
 * each with its own independent submit form. Tests the full generate→verify
 * cycle through the PRG pattern.
 *
 * URL: /en/captcha-test  (page is hidden=1, not in any navbar)
 *
 * GET  → generate one captcha per difficulty, render forms.
 * POST → ContentManager stores POST data and redirects back with ?_prg=token.
 * GET with ?_prg → controller reads submitted data, calls verify(), shows result.
 */
final class CaptchaTestController extends AbstractController
{
    /** Captcha TTL for test tokens — 10 minutes. */
    private const TTL = 600;

    public function __construct(
        DiagnosticsCollector                   $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request               $request,
        private readonly CaptchaRenderer       $renderer,
        private readonly CaptchaRepository     $repository,
        private readonly CaptchaService        $captchaService,
        private readonly PrgHandler            $prg,
        private readonly UrlGenerator          $urlGen,
    ) {
        parent::__construct($collector);
    }

    public function handle(): Result
    {
        // --- PRG: read submitted form data -----------------------------------
        $verificationResult = null;
        $prgToken = $this->request->query()->get($this->prg->tokenQueryKey());

        if (is_string($prgToken) && $prgToken !== '') {
            $posted = $this->prg->pull($prgToken) ?? [];

            $submittedId   = is_string($posted['captcha_id']   ?? null) ? (string) $posted['captcha_id']   : '';
            $submittedText = is_string($posted['captcha_text'] ?? null) ? (string) $posted['captcha_text'] : '';
            $testedLabel   = is_string($posted['type_label']   ?? null) ? (string) $posted['type_label']   : '';

            if ($submittedId !== '' && $submittedText !== '') {
                $result = $this->captchaService->verify($submittedId, $submittedText);
                $result->drainTo($this->collector);

                $verificationResult = [
                    'ok'    => $result->isOk(),
                    'label' => $testedLabel,
                ];
            }
        }

        // --- Generate one captcha per difficulty level -----------------------
        // We use renderer+repository directly instead of CaptchaService::generate()
        // so we can override the type on the shared renderer for each iteration
        // while keeping text/id/image consistent within each entry.
        $this->repository->deleteExpired(); // opportunistic GC

        $pageUrl  = $this->urlGen->toPage('captcha-test');
        $captchas = [];

        foreach ([CaptchaType::EASY, CaptchaType::MEDIUM, CaptchaType::HARD] as $type) {
            $this->renderer->setCaptchaType($type->value);

            $id        = bin2hex(random_bytes(16));
            $text      = $this->renderer->generateText();
            $expiresAt = time() + self::TTL;

            $storeResult = $this->repository->store($id, $text, $expiresAt);
            $storeResult->drainTo($this->collector);

            if (!$storeResult->isOk()) {
                continue;
            }

            $image = $this->renderer->render($text);
            $prgId = $this->prg->createId($pageUrl);

            $captchas[] = [
                'type_label'  => $type->name,
                'captcha_id'  => $id,
                'image_b64'   => $image,
                'prg_id'      => $prgId,
            ];
        }

        // --- Template vars ---------------------------------------------------
        $this->ctx->set('has_captchas', $captchas !== []);
        $this->ctx->set('captchas',     $captchas);
        $this->ctx->set('has_result',   $verificationResult !== null);
        $this->ctx->set('result_ok',    $verificationResult['ok']    ?? false);
        $this->ctx->set('result_fail',  !($verificationResult['ok']  ?? true));
        $this->ctx->set('result_label', $verificationResult['label'] ?? '');

        return $this->ok();
    }
}

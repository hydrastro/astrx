<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Captcha\CaptchaService;
use AstrX\Http\Request;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\CurrentUrl;

/**
 * Serves the iframe content for the reloadable captcha widget.
 *
 * URL: /<locale>/captcha-frame?cid=<id>[&refresh=1]
 *
 * The parent form (login, register, comment, ...) embeds this as:
 *
 *   <iframe src="/{locale}/captcha-frame?cid={captcha_id}"></iframe>
 *
 * Behaviour:
 *   - GET without ?refresh: renders the current image + a "reload" link.
 *   - GET with ?refresh=1: calls CaptchaService::regenerate() on the same
 *     id (new text, new hash, same id), then renders the new image.
 *
 * The reload link\'s target is the iframe itself (default target="_self")
 * so only the iframe navigates; the parent page is untouched. This is the
 * whole point — the user gets a new captcha without losing whatever they
 * typed in the parent form.
 *
 * The parent form\'s `captcha_id` hidden input stays the same regardless
 * of refresh count, so verification on submit always points at the latest
 * answer stored against that id.
 *
 * No template engine involvement — controller writes the HTML directly.
 * The page row has template=0.
 */
final class CaptchaFrameController extends AbstractController
{
    public function __construct(
        DiagnosticsCollector                $collector,
        private readonly Request            $request,
        private readonly CaptchaService     $captchaService,
        private readonly Translator         $t,
        private readonly CurrentUrl         $currentUrl,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        // Load the Captcha translation domain up front so both the error path
        // and the reload label resolve to localized strings. This controller
        // renders its own iframe document and pulls in no other domains.
        $this->t->loadDomain(\AstrX\Support\langDir(), 'Captcha');

        $cid     = self::queryStr($this->request, 'cid', '');
        $refresh = self::queryStr($this->request, 'refresh', '') === '1';

        if ($cid === '' || !preg_match('/\A[0-9a-f]{32}\z/', $cid)) {
            $this->emitError(404);
            return $this->ok();
        }

        if ($refresh) {
            // Generate fresh text+image for the same captcha id. The result is
            // ignored here — the next image fetch by /captcha-image will pick
            // up the new value via the captcha row.
            $this->captchaService->regenerate($cid)->drainTo($this->collector);
        }

        // Build the image and reload URLs. Both use the current locale so the
        // iframe stays inside the locale boundary. A nonce in the image URL
        // forces the browser to actually re-fetch the image after a reload
        // (the Cache-Control: no-store header should be enough, but the nonce
        // is belt-and-braces).
        $lang       = $this->currentUrl->get('lang', 'en');
        $locale     = is_scalar($lang) ? (string) $lang : 'en';
        // Routing slugs live in the shared 'pages' domain (loaded globally — the
        // router already resolved this very slug to reach us), so we look them up
        // by id without a hardcoded English fallback, exactly like the sibling
        // controllers (Login/Comment/Register) do.
        $imageSlug  = $this->t->t('WORDING_CAPTCHA_IMAGE');
        $frameSlug  = $this->t->t('WORDING_CAPTCHA_FRAME');

        $imageUrl   = '/' . $locale . '/' . $imageSlug . '?cid=' . $cid
                    . '&v=' . bin2hex(random_bytes(4));
        $reloadUrl  = '/' . $locale . '/' . $frameSlug . '?cid=' . $cid . '&refresh=1';

        $reloadLabel = $this->t->t('captcha.reload', fallback: 'New captcha');

        // Emit minimal HTML. We use an inline <style> for layout because the
        // iframe runs in its own document context and inheriting the parent
        // stylesheet would pull in the whole site theme — not what we want
        // for a tiny embedded captcha widget.
        http_response_code(200);
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            // Defence-in-depth: block this page from being embedded anywhere
            // except same-origin iframes.
            header('X-Frame-Options: SAMEORIGIN');
        }

        $imgHtml    = htmlspecialchars($imageUrl,  ENT_QUOTES, 'UTF-8');
        $reloadHtml = htmlspecialchars($reloadUrl, ENT_QUOTES, 'UTF-8');
        $labelHtml  = htmlspecialchars($reloadLabel, ENT_QUOTES, 'UTF-8');

        echo <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>captcha</title>
<style>
    body { margin: 0; padding: 0; font: 13px sans-serif; }
    img  { display: block; max-width: 100%; height: auto; }
    a    { display: inline-block; margin-top: 2px; color: inherit; }
</style>
</head>
<body>
<img src="{$imgHtml}" alt="captcha">
<a href="{$reloadHtml}">↻ {$labelHtml}</a>
</body>
</html>
HTML;
        // Hard stop — see CaptchaImageController for rationale.
        exit;
    }

    private function emitError(int $status): void
    {
        http_response_code($status);
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
        }
        $msg = htmlspecialchars(
            $this->t->t('captcha.frame.unavailable', fallback: 'Captcha unavailable'),
            ENT_QUOTES,
            'UTF-8'
        );
        echo '<!DOCTYPE html><body style="font:13px sans-serif">' . $msg . '</body>';
        exit;
    }
}

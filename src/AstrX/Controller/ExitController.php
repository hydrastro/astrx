<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Http\Request;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Template\DefaultTemplateContext;

/**
 * Off-site exit interstitial (/exit?to=<url>).
 *
 * On a hidden service, following an external link is the moment a visitor risks
 * deanonymisation (a clearnet request outside Tor, a leaked Referer, an exit-node
 * observer). This page interposes a conscious step: it shows the destination in
 * full, warns about the anonymity trade-off, and offers a single Continue link
 * carrying rel="noreferrer noopener nofollow" so no referrer is leaked and the
 * opener is severed. Content-page external links are routed here automatically
 * (see ContentService); the page itself is public, template-rendered, no-JS, and
 * NOT indexed (it is a redirector, not content). Only http(s) targets are shown;
 * anything else renders the "invalid destination" state.
 */
final class ExitController extends AbstractController
{
    public function __construct(
        DiagnosticsCollector                    $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request                $request,
        private readonly Translator             $t,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        $this->ctx->set('exit_heading', $this->t->t('exit.heading'));
        $this->ctx->set('btn_back',     $this->t->t('exit.back'));

        $target = self::queryStr($this->request, 'to');
        // Accept an http(s):// destination OR a protocol-relative //host (a valid
        // external navigation the content renderer routes here); reject everything
        // else so the Continue link can never carry a javascript:/data:/same-page
        // scheme (those match neither branch).
        if ($target === '' || preg_match('#^(https?:)?//#i', $target) !== 1) {
            http_response_code(400);
            $this->ctx->set('has_target', false);
            $this->ctx->set('exit_invalid', $this->t->t('exit.invalid'));
            return $this->ok();
        }

        $host = '';
        $parsedHost = parse_url($target, PHP_URL_HOST);
        if (is_string($parsedHost)) {
            $host = $parsedHost;
        }

        $this->ctx->set('has_target',  true);
        $this->ctx->set('exit_warning', $this->t->t('exit.warning'));
        $this->ctx->set('exit_dest_label', $this->t->t('exit.destination'));
        $this->ctx->set('exit_host_label', $this->t->t('exit.host'));
        $this->ctx->set('target_url',  $target);
        $this->ctx->set('target_host', $host);
        $this->ctx->set('has_host',    $host !== '');
        $this->ctx->set('btn_continue', $this->t->t('exit.continue'));

        return $this->ok();
    }
}

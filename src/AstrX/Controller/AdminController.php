<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Admin\AdminService;
use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Http\Response;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\CurrentUrl;
use AstrX\Routing\UrlGenerator;
use AstrX\Template\DefaultTemplateContext;

/**
 * Admin section root — /en/admin
 * Handles sub-path routing to child pages and renders the admin home.
 * Gate: requires ADMIN_ACCESS permission.
 */
final class AdminController extends AbstractController
{
    public function __construct(
        DiagnosticsCollector                   $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Gate                  $gate,
        private readonly UrlGenerator          $urlGen,
        private readonly Translator            $t,
    ) {
        parent::__construct($collector);
    }

    public function handle(): Result
    {
        if ($this->gate->cannot(Permission::ADMIN_ACCESS)) {
            http_response_code(403);
            $this->ctx->set('admin_forbidden', true);
            return $this->ok();
        }

        $this->ctx->set('admin_forbidden', false);
        $this->ctx->set('admin_heading',   $this->t->t('admin.home.heading'));
        $this->ctx->set('admin_welcome',   $this->t->t('admin.home.welcome'));

        // Build section links for the home page
        $sections = [];
        foreach (AdminService::NAV_PAGES as $slug => $labelKey) {
            if ($slug === 'admin') {
                continue;
            }
            $sections[] = [
                'url'   => $this->urlGen->toPage($this->t->t('WORDING_' . strtoupper($slug))),
                'name'  => $this->t->t($labelKey),
                'desc'  => $this->t->t($labelKey . '.desc'),
            ];
        }
        $this->ctx->set('admin_sections', $sections);

        return $this->ok();
    }
}

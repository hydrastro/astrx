<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Api\ContextScope;
use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\GitBrowse\GitBrowseConfig;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Template\DefaultTemplateContext;
use function AstrX\Support\langDir;

/**
 * Git browser link-through (/gitbrowse).
 *
 * gitweb is a standalone, server-rendered HTML app with NO JSON API, so this is
 * deliberately NOT a bridge: there is nothing to fetch, parse or sanitise. The
 * page is a single card that links OUT to the configured gitweb service URL —
 * clearly a hand-off, not an embed. Public: gated by NEWS_VIEW (granted to
 * guests), like the sibling search pages.
 *
 * The page is seeded with file_name 'git_browse' so the reflection router
 * resolves it to THIS class (str_replace('_','',ucwords('git_browse','_')) .
 * 'Controller'); its URL slug is WORDING_GITBROWSE ('gitbrowse'). The only
 * dynamic value is the service URL, which GitBrowseConfig has already forced to
 * an http(s) address and which the template renders through plain `{{ }}`
 * (escaped).
 */
final class GitBrowseController extends AbstractController
{
    public function __construct(
        DiagnosticsCollector                    $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Gate                   $gate,
        private readonly Translator             $t,
        private readonly GitBrowseConfig        $config,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        $this->t->loadDomain(langDir(), 'GitBrowse');

        if ($this->gate->cannot(Permission::NEWS_VIEW)) {
            http_response_code(404);
            exit;
        }

        $this->ctx->set('service_url', $this->config->serviceUrl(), ContextScope::SHARED);

        foreach ([
            'lbl_heading' => 'gitbrowse.heading',
            'lbl_intro'   => 'gitbrowse.intro',
            'lbl_open'    => 'gitbrowse.open',
            'lbl_note'    => 'gitbrowse.note',
        ] as $ctxKey => $tKey) {
            $this->ctx->set($ctxKey, $this->t->t($tKey), ContextScope::SHARED);
        }

        return $this->ok();
    }
}

<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Http\Request;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;
use AstrX\Search\SiteSearchService;
use AstrX\Template\DefaultTemplateContext;
use function AstrX\Support\langDir;

/**
 * Site-wide search — a no-JavaScript GET form over the CMS's public content
 * (news, static pages, comments, imageboard posts; chat is never indexed).
 *
 * The page is seeded with file_name 'site_search' so the reflection router
 * resolves it to THIS class (str_replace('_','',ucwords('site_search','_')) .
 * 'Controller'); its URL slug is WORDING_SEARCH ('search'). The query is a
 * plain <form method="get"> with a `q` field and a `type` dropdown, so results
 * live at a bookmarkable GET URL. All matching / URL building happens in
 * SiteSearchService; this controller only translates, shapes the view model and
 * gates access. Public: gated by NEWS_VIEW (granted to guests).
 */
final class SiteSearchController extends AbstractController
{
    /** Maximum hits returned for one query. */
    private const SEARCH_LIMIT = 50;

    /** Filter values offered in the type dropdown (mirrors the service). */
    private const TYPES = ['all', 'news', 'pages', 'comments', 'board'];

    public function __construct(
        DiagnosticsCollector                    $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request                $request,
        private readonly Gate                   $gate,
        private readonly Translator             $t,
        private readonly UrlGenerator           $urlGen,
        private readonly SiteSearchService      $search,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        $this->t->loadDomain(langDir(), 'Search');

        if ($this->gate->cannot(Permission::NEWS_VIEW)) {
            http_response_code(404);
            exit;
        }

        $q    = self::queryStr($this->request, 'q');
        $type = self::queryStr($this->request, 'type');
        if (!in_array($type, self::TYPES, true)) {
            $type = 'all';
        }

        $this->buildTypeOptions($type);

        $results  = [];
        $searched = $q !== '';
        if ($searched) {
            $sR   = $this->search->search($q, $type, self::SEARCH_LIMIT)->drainTo($this->collector);
            $rows = $sR->isOk() ? $sR->unwrap() : [];
            foreach ($rows as $row) {
                $results[] = [
                    'type_label' => $this->t->t('search.type.' . $row['type']),
                    'title'      => $row['title'],
                    'excerpt'    => $row['excerpt'],
                    'url'        => $row['url'],
                ];
            }
        }

        $this->ctx->set('form_action',  $this->urlGen->toPage($this->t->t('WORDING_SEARCH')));
        $this->ctx->set('query',        $q);
        $this->ctx->set('searched',     $searched);
        // NB: key is 'search_results', NOT 'results' — DefaultTemplateContext
        // reserves 'results' as a legacy alias for the diagnostic message list
        // and overwrites it in finalise(), which runs after this controller. A
        // 'results' key here would be silently clobbered to the (empty) message
        // list, so the rows would vanish while the count still showed.
        $this->ctx->set('search_results', $results);
        $this->ctx->set('has_results',  $results !== []);
        $this->ctx->set('result_count', $this->t->t('search.result_count', ['count' => count($results)]));
        $this->setLabels();

        return $this->ok();
    }

    /**
     * Build the type-filter dropdown, flagging the currently-selected option so
     * the template can pre-select it.
     */
    private function buildTypeOptions(string $selected): void
    {
        $list = [];
        foreach (self::TYPES as $type) {
            $list[] = [
                'value' => $type,
                'label' => $this->t->t('search.type_option.' . $type),
                'sel'   => $type === $selected,
            ];
        }
        $this->ctx->set('types', $list);
    }

    private function setLabels(): void
    {
        foreach ([
            'lbl_heading'    => 'search.heading',
            'lbl_query'      => 'search.query_label',
            'lbl_type'       => 'search.type_label',
            'lbl_submit'     => 'search.submit',
            'lbl_no_results' => 'search.no_results',
        ] as $ctxKey => $tKey) {
            $this->ctx->set($ctxKey, $this->t->t($tKey));
        }
    }
}

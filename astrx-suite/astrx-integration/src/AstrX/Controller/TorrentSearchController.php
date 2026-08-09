<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Api\ContextScope;
use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Http\Request;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;
use AstrX\Template\DefaultTemplateContext;
use AstrX\TorrentSearch\TorrentSearchClient;
use function AstrX\Support\langDir;

/**
 * Torrent search — a no-JavaScript GET form that bridges to the standalone,
 * localhost-only torrentds engine over its JSON API. This is the FOURTH,
 * SEPARATE search page: it sits alongside the internal site search
 * (SiteSearchController), the clear-web search (WebSearchController) and the
 * onion search (OnionSearchController); the four are intentionally NOT merged.
 *
 * The page is seeded with file_name 'torrent_search' so the reflection router
 * resolves it to THIS class (str_replace('_','',ucwords('torrent_search','_')) .
 * 'Controller'); its URL slug is WORDING_TORRENTSEARCH ('torrentsearch'). All
 * HTTP, JSON parsing and sanitisation happen in TorrentSearchClient; this
 * controller only translates, shapes the view model and gates access. Public:
 * gated by NEWS_VIEW (granted to guests), exactly like the internal search.
 *
 * Two views share the one page:
 *   * the results list (torrent names + size + file count + seen count +
 *     magnet + .torrent), and
 *   * a per-torrent detail (`?ih=<hex>`) listing the file paths.
 * Torrent NAMES and FILE PATHS are attacker-controlled (harvested off the DHT);
 * they arrive from TorrentSearchClient already stripped of markup and are
 * rendered through plain `{{ }}` (escaped) in torrent_search.html — never
 * `{{&}}` — so untrusted metadata has zero XSS surface on the AstrX side.
 */
final class TorrentSearchController extends AbstractController
{
    public function __construct(
        DiagnosticsCollector                    $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request                $request,
        private readonly Gate                   $gate,
        private readonly Translator             $t,
        private readonly UrlGenerator           $urlGen,
        private readonly TorrentSearchClient    $client,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        $this->t->loadDomain(langDir(), 'TorrentSearch');

        if ($this->gate->cannot(Permission::NEWS_VIEW)) {
            http_response_code(404);
            exit;
        }

        $slug       = $this->t->t('WORDING_TORRENTSEARCH');
        $formAction = $this->urlGen->toPage($slug);
        $ih         = self::queryStr($this->request, 'ih');

        $this->ctx->set('form_action', $formAction);
        $this->setLabels();

        if ($ih !== '') {
            $this->renderDetail($slug, $ih);
        } else {
            $this->renderSearch($slug);
        }

        return $this->ok();
    }

    private function renderSearch(string $slug): void
    {
        $q    = self::queryStr($this->request, 'q');
        $page = max(1, self::queryInt($this->request, 'page', 1));

        $searched    = $q !== '';
        $unavailable = false;
        $results     = [];
        $total       = 0;
        $hasPrev     = false;
        $hasNext     = false;
        $prevUrl     = '';
        $nextUrl     = '';

        if ($searched) {
            $resp = $this->client->search($q, $page);
            if ($resp['ok']) {
                $total    = $resp['total'];
                $page     = $resp['page'];
                $pageSize = $resp['page_size'];
                foreach ($resp['results'] as $r) {
                    $results[] = [
                        'name'        => $r['name'],
                        'size'        => $r['size'],
                        'file_count'  => $r['file_count'],
                        'seen_count'  => $r['seen_count'],
                        'category'    => $r['category'],
                        'magnet'      => $r['magnet'],
                        'torrent_url' => $r['torrent_url'],
                        'has_torrent' => $r['torrent_url'] !== '',
                        'has_swarm'   => $r['has_swarm'],
                        'seeders'     => $r['seeders'],
                        'leechers'    => $r['leechers'],
                        'swarm'       => $r['swarm'],
                        'detail_url'  => $this->urlGen->toPage($slug, ['ih' => $r['infohash']]),
                    ];
                }
                $last = max(1, intdiv($total + $pageSize - 1, $pageSize));
                if ($page > 1) {
                    $hasPrev = true;
                    $prevUrl = $this->urlGen->toPage($slug, ['q' => $q, 'page' => $page - 1]);
                }
                if ($page < $last) {
                    $hasNext = true;
                    $nextUrl = $this->urlGen->toPage($slug, ['q' => $q, 'page' => $page + 1]);
                }
            } else {
                $unavailable = true;
            }
        }

        // 'torrent_results', NOT 'results' — DefaultTemplateContext reserves
        // 'results' for the diagnostic message list and clobbers it in finalise().
        $this->ctx->setShared('is_detail',           false);
        $this->ctx->setShared('query',               $q);
        $this->ctx->setShared('searched',            $searched);
        $this->ctx->setShared('backend_unavailable', $unavailable);
        $this->ctx->setShared('torrent_results',     $results);
        $this->ctx->setShared('has_results',         $results !== []);
        $this->ctx->setShared('result_count',        $this->t->t('torrentsearch.result_count', ['count' => $total]));
        $this->ctx->setShared('page_num',            $page);

        $this->ctx->set('has_prev', $hasPrev);
        $this->ctx->set('prev_url', $prevUrl);
        $this->ctx->set('has_next', $hasNext);
        $this->ctx->set('next_url', $nextUrl);
    }

    private function renderDetail(string $slug, string $ih): void
    {
        $d           = $this->client->detail($ih);
        $unavailable = !$d['ok'];
        $found       = $d['found'];

        $this->ctx->set('back_url', $this->urlGen->toPage($slug));

        $this->ctx->setShared('is_detail',           true);
        $this->ctx->setShared('searched',            true);
        $this->ctx->setShared('backend_unavailable', $unavailable);
        $this->ctx->setShared('detail_found',        $found);

        if ($found) {
            $this->ctx->setShared('d_name',        $d['name']);
            $this->ctx->setShared('d_infohash',    $d['infohash']);
            $this->ctx->setShared('d_size',        $d['size']);
            $this->ctx->setShared('d_file_count',  $d['file_count']);
            $this->ctx->setShared('d_seen_count',  $d['seen_count']);
            $this->ctx->setShared('d_category',    $d['category']);
            $this->ctx->setShared('d_first_seen',  $d['first_seen']);
            $this->ctx->setShared('d_last_seen',   $d['last_seen']);
            $this->ctx->setShared('d_magnet',      $d['magnet']);
            $this->ctx->setShared('d_torrent_url', $d['torrent_url']);
            $this->ctx->setShared('d_has_torrent', $d['torrent_url'] !== '');
            $this->ctx->setShared('d_files',       $d['files']);
            $this->ctx->setShared('d_has_files',   $d['files'] !== []);
        }
    }

    private function setLabels(): void
    {
        foreach ([
            'lbl_heading'     => 'torrentsearch.heading',
            'lbl_intro'       => 'torrentsearch.intro',
            'lbl_query'       => 'torrentsearch.query_label',
            'lbl_submit'      => 'torrentsearch.submit',
            'lbl_no_results'  => 'torrentsearch.no_results',
            'lbl_unavailable' => 'torrentsearch.unavailable',
            'lbl_prev'        => 'torrentsearch.prev',
            'lbl_next'        => 'torrentsearch.next',
            'lbl_files'       => 'torrentsearch.files',
            'lbl_seen'        => 'torrentsearch.seen',
            'lbl_magnet'      => 'torrentsearch.magnet',
            'lbl_torrent'     => 'torrentsearch.torrent',
            'lbl_details'     => 'torrentsearch.details',
            'lbl_back'        => 'torrentsearch.back',
            'lbl_size'        => 'torrentsearch.size',
            'lbl_category'    => 'torrentsearch.category',
            'lbl_first_seen'  => 'torrentsearch.first_seen',
            'lbl_last_seen'   => 'torrentsearch.last_seen',
            'lbl_swarm'       => 'torrentsearch.swarm',
            'lbl_not_found'   => 'torrentsearch.not_found',
            'lbl_file_list'   => 'torrentsearch.file_list',
        ] as $ctxKey => $tKey) {
            $this->ctx->set($ctxKey, $this->t->t($tKey), ContextScope::SHARED);
        }
    }
}

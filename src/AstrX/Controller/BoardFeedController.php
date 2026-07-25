<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Http\Request;
use AstrX\I18n\Translator;
use AstrX\Imageboard\BoardRepository;
use AstrX\Imageboard\PostRepository;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;

/**
 * Per-board Atom 1.0 feed — no template wrapping (page template=0, raw output).
 *
 * URL: /board-feed?board=<slug>. Gated by BOARD_VIEW (404 on fail, exactly like
 * the board itself). Emits the board's newest posts as an Atom document so a
 * reader can follow a board from any feed client.
 *
 * Tor-safe: every URL is a site-relative path built by UrlGenerator (no host,
 * no external reference is embedded or fetched), and every value is XML-escaped
 * with ENT_XML1. body_html is placed, escaped, inside <content type="html"> so a
 * client un-escapes and renders it. exit() after output so ContentManager cannot
 * stamp a status/template over the raw XML.
 */
final class BoardFeedController extends AbstractController
{
    /** How many of the board's newest posts the feed carries. */
    private const FEED_LIMIT = 40;

    public function __construct(
        DiagnosticsCollector             $collector,
        private readonly Request         $request,
        private readonly Gate            $gate,
        private readonly BoardRepository $boards,
        private readonly PostRepository  $posts,
        private readonly Translator      $t,
        private readonly UrlGenerator    $urlGen,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        if ($this->gate->cannot(Permission::BOARD_VIEW)) {
            http_response_code(404);
            exit;
        }

        $slug  = self::queryStr($this->request, 'board');
        $bR    = $this->boards->bySlug($slug);
        $board = $bR->isOk() ? $bR->unwrap() : null;
        if (!is_array($board)) {
            http_response_code(404);
            exit;
        }

        $slug  = self::mStr($board, 'slug');
        $title = self::mStr($board, 'title');
        $bid   = self::mInt($board, 'id');

        $pR   = $this->posts->newestForBoard($bid, self::FEED_LIMIT);
        $rows = $pR->isOk() ? $pR->unwrap() : [];

        $feedUrl   = $this->urlGen->toPage($this->t->t('WORDING_BOARD_FEED'), ['board' => $slug]);
        $boardBase = $this->urlGen->toPage($this->t->t('WORDING_BOARD'));

        // Feed <updated> is the newest post's time (rows are newest-first); with
        // no posts, fall back to "now" so the document is still valid.
        $newestTs = $rows !== [] ? self::mInt($rows[0], 'created_ts') : time();

        $xml  = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
        $xml .= '<feed xmlns="http://www.w3.org/2005/Atom">' . "\n";
        $xml .= '  <title>' . $this->x($title !== '' ? $title : $slug) . '</title>' . "\n";
        $xml .= '  <id>' . $this->x('urn:astrx:board:' . $slug) . '</id>' . "\n";
        $xml .= '  <link rel="self" href="' . $this->x($feedUrl) . '"/>' . "\n";
        $xml .= '  <updated>' . $this->x(date('c', $newestTs)) . '</updated>' . "\n";

        foreach ($rows as $row) {
            $no      = self::mInt($row, 'no');
            $tid     = self::mInt($row, 'thread_id');
            $subject = self::mStr($row, 'subject');
            $body    = self::mStr($row, 'body_html');
            $ts      = self::mInt($row, 'created_ts');

            // Site-relative thread URL, fragment-anchored to this post.
            $postUrl = $boardBase . '/' . rawurlencode($slug) . '/thread/' . $tid . '#p' . $no;
            $entryId = 'urn:astrx:board:' . $slug . ':' . $no;
            $entryTt = $subject !== '' ? $subject : 'No.' . $no;

            $xml .= '  <entry>' . "\n";
            $xml .= '    <title>' . $this->x($entryTt) . '</title>' . "\n";
            $xml .= '    <id>' . $this->x($entryId) . '</id>' . "\n";
            $xml .= '    <updated>' . $this->x(date('c', $ts)) . '</updated>' . "\n";
            $xml .= '    <link href="' . $this->x($postUrl) . '"/>' . "\n";
            $xml .= '    <content type="html">' . $this->x($body) . '</content>' . "\n";
            $xml .= '  </entry>' . "\n";
        }
        $xml .= '</feed>' . "\n";

        header('Content-Type: application/atom+xml; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        echo $xml;
        exit;
    }

    /** XML-escape a value for inclusion in an Atom document (ENT_XML1). */
    private function x(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }
}

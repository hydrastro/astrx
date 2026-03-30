<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Comment\CommentRepository;
use AstrX\Config\Config;
use AstrX\Config\ConfigWriter;
use AstrX\Csrf\CsrfHandler;
use AstrX\Http\Request;
use AstrX\Http\Response;
use AstrX\I18n\Translator;
use AstrX\Page\Page;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;
use AstrX\Session\FlashBag;
use AstrX\Session\PrgHandler;
use AstrX\Template\DefaultTemplateContext;

/**
 * Admin comments — moderation + comment service configuration on one page.
 *
 * Moderation section (requires ADMIN_COMMENTS):
 *   Filter by page_id / flagged / hidden. Inline edit, hide, flag, delete.
 *
 * Config section (requires ADMIN_CONFIG_COMMENTS):
 *   General settings (per_page, allow_replies, require_email, flood/antispam timing).
 *   Antispam regex table (editable keys, pattern, enabled flag, message).
 *   Writes Comment.config.php atomically.
 *
 * Each section only renders when the current user has the required permission.
 * A user with only one permission sees only that section.
 */
final class AdminCommentsController extends AbstractController
{
    private const FORM = 'admin_comments';

    public function __construct(
        DiagnosticsCollector                   $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request               $request,
        private readonly CommentRepository     $comments,
        private readonly Config                $config,
        private readonly ConfigWriter          $writer,
        private readonly Gate                  $gate,
        private readonly CsrfHandler           $csrf,
        private readonly PrgHandler            $prg,
        private readonly FlashBag              $flash,
        private readonly Page                  $page,
        private readonly UrlGenerator          $urlGen,
        private readonly Translator            $t,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        $canModerate = $this->gate->can(Permission::ADMIN_COMMENTS);
        $canConfig   = $this->gate->can(Permission::ADMIN_CONFIG_COMMENTS);

        if (!$canModerate && !$canConfig) {
            http_response_code(403);
            return $this->ok();
        }

        $resolvedUrlId = $this->page->i18n
            ? $this->t->t($this->page->urlId, fallback: $this->page->urlId)
            : $this->page->urlId;
        $selfUrl = $this->urlGen->toPage($resolvedUrlId);

        $prgToken = $this->request->query()->get($this->prg->tokenQueryKey());
        if (is_string($prgToken) && $prgToken !== '') {
            $qs = $this->processForm($prgToken, $canModerate, $canConfig);
            Response::redirect($selfUrl . $qs)->send()->drainTo($this->collector);
            exit;
        }

        $this->buildContext($selfUrl, $canModerate, $canConfig);
        return $this->ok();
    }

    // =========================================================================

    private function processForm(string $prgToken, bool $canModerate, bool $canConfig): string
    {
        $posted     = $this->prg->pull($prgToken) ?? [];
        $csrfResult = $this->csrf->verify(self::FORM, self::mStr($posted, '_csrf', ''));
        if (!$csrfResult->isOk()) {
            $csrfResult->drainTo($this->collector);
            return '';
        }

        $section = self::mStr($posted, 'section', 'moderation');

        if ($section === 'moderation' && $canModerate) {
            $this->processModeration($posted);
            return $this->buildFilterQs();
        }

        if (in_array($section, ['general', 'antispam'], true) && $canConfig) {
            $r = $section === 'general' ? $this->saveGeneral($posted) : $this->saveAntispam($posted);
            $r->drainTo($this->collector);
            if ($r->isOk()) { $this->flash->set('success', $this->t->t('admin.config.saved')); }
        }

        return '';
    }

    // ── Moderation ────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $posted */
    private function processModeration(array $posted): void
    {
        $action = self::mStr($posted, 'action', '');
        $id     = self::mInt($posted, 'id', 0);

        switch ($action) {
            case 'update':
                $content = trim(self::mStr($posted, 'content', ''));
                $name    = trim(self::mStr($posted, 'name', ''));
                $email   = ($posted['email']    ?? '') !== '' ? trim((is_scalar($posted['email']) ? (string)$posted['email'] : ''))    : null;
                $replyTo = ($posted['reply_to'] ?? '') !== '' ? (is_int($posted['reply_to']) ? $posted['reply_to'] : 0) : null;
                $hidden  = self::mBool($posted, 'hidden');
                $flagged = self::mBool($posted, 'flagged');
                if ($content !== '') {
                    $r = $this->comments->update($id, $content, $name, $email, $replyTo, $hidden, $flagged);
                    $r->drainTo($this->collector);
                    if ($r->isOk()) { $this->flash->set('success', $this->t->t('admin.comments.updated')); }
                }
                break;
            case 'hide':
                $this->comments->setHidden($id, true)->drainTo($this->collector);
                $this->flash->set('success', $this->t->t('admin.comments.hidden'));
                break;
            case 'unhide':
                $this->comments->setHidden($id, false)->drainTo($this->collector);
                $this->flash->set('success', $this->t->t('admin.comments.unhidden'));
                break;
            case 'flag':
                $this->comments->setFlagged($id, true)->drainTo($this->collector);
                break;
            case 'unflag':
                $this->comments->setFlagged($id, false)->drainTo($this->collector);
                break;
            case 'delete':
                $this->comments->delete($id)->drainTo($this->collector);
                $this->flash->set('success', $this->t->t('admin.comments.deleted'));
                break;
        }
    }

    // ── Config savers ─────────────────────────────────────────────────────────

    /** @param array<string, mixed> $p
     * @return Result<mixed>
     */
    private function saveGeneral(array $p): Result
    {
        $current = $this->loadCurrent();
        $current['comments_per_page']  = max(1, self::mInt($p, 'comments_per_page', 20));
        $current['allow_replies']      = self::mBool($p, 'allow_replies');
        $current['require_email']      = self::mBool($p, 'require_email');
        $current['minimum_flood_secs'] = max(0, self::mInt($p, 'minimum_flood_secs', 10));
        $current['antispam_time_secs'] = max(0, self::mInt($p, 'antispam_time_secs', 30));
        return $this->writer->write('Comment', ['CommentService' => $current]);
    }

    /** @param array<string, mixed> $p
     * @return Result<mixed>
     */
    private function saveAntispam(array $p): Result
    {
        $current  = $this->loadCurrent();
        $keys     = (array) ($p['regex_key']     ?? []);
        $regexes  = (array) ($p['regex_pattern'] ?? []);
        $messages = (array) ($p['regex_message'] ?? []);
        $enabled  = (array) ($p['regex_enabled']  ?? []);

        $antispam = [];
        foreach ($keys as $i => $key) {
            $k = is_int($key) ? $key : (is_numeric($key) ? (int)$key : 0);
            if ($k <= 0) { continue; }
            $regexRaw = $regexes[$i] ?? '';
            $pattern = trim(is_scalar($regexRaw) ? (string)$regexRaw : '');
            if ($pattern === '') { continue; }
            $antispam[$k] = [
                'regex'   => $pattern,
                'enabled' => isset($enabled[$i]),
                'message' => trim(is_scalar($messages[$i] ?? null) ? (string)($messages[$i] ?? '') : ''),
            ];
        }
        $current['antispam_regex'] = $antispam;
        return $this->writer->write('Comment', ['CommentService' => $current]);
    }

    // ── Context builder ───────────────────────────────────────────────────────

    private function buildContext(string $selfUrl, bool $canModerate, bool $canConfig): void
    {
        $csrfToken = $this->csrf->generate(self::FORM);
        $prgId     = $this->prg->createId($selfUrl);

        $this->ctx->set('can_moderate', $canModerate);
        $this->ctx->set('can_config',   $canConfig);
        $this->ctx->set('base_url',     $selfUrl);
        $this->ctx->set('csrf_token',   $csrfToken);
        $this->ctx->set('prg_id',       $prgId);

        if ($canModerate) {
            $qPageId  = $this->request->query()->get('page_id');
            $qFlagged = $this->request->query()->get('flagged');
            $qHidden  = $this->request->query()->get('show_hidden');
            $editRaw  = $this->request->query()->get('edit');
        $editId   = is_int($editRaw) ? $editRaw : (is_numeric($editRaw) ? (int)$editRaw : 0);

            $filters = [];
            if ($qPageId !== null) { $filters['page_id'] = is_int($qPageId) ? $qPageId : (is_numeric($qPageId) ? (int)$qPageId : 0); }
            if ($qFlagged === '1')    { $filters['flagged']  = 1; }
            if ($qHidden  === '0')    { $filters['hidden']   = 0; }
            elseif ($qHidden === '1') { $filters['hidden']   = 1; }

            $listResult = $this->comments->fetchAll($filters);
            $listResult->drainTo($this->collector);
            $rawList = $listResult->isOk() ? $listResult->unwrap() : [];

            $commentList = [];
            foreach ($rawList as $row) {
                $row['editing'] = ($editId > 0 && (is_int($row['id']) ? $row['id'] : 0) === $editId) ? [$row] : false;
                $commentList[]  = $row;
            }

            $this->ctx->set('comment_list',       $commentList);
            $this->ctx->set('filter_page_id',           $qPageId  ?? '');
            $this->ctx->set('filter_flagged',           ($qFlagged ?? '') === '1');
            $this->ctx->set('filter_show_hidden',       $qHidden  ?? '');
            // Explicit bool vars so Mustache can mark the correct <option> selected.
            $this->ctx->set('filter_show_hidden_any',     $qHidden === null || $qHidden === '');
            $this->ctx->set('filter_show_hidden_visible', $qHidden === '0');
            $this->ctx->set('filter_show_hidden_hidden',  $qHidden === '1');
        }

        if ($canConfig) {
            $current      = $this->loadCurrent();
            $antispamList = [];
            $antispamRaw = $current['antispam_regex'] ?? [];
            $antispamArr = is_array($antispamRaw) ? $antispamRaw : [];
            /** @var array<int|string, array<string,mixed>> $antispamArr */
            foreach ($antispamArr as $key => $rule) {
                $antispamList[] = [
                    'key'     => $key,
                    'regex'   => self::mStr($rule, 'regex', ''),
                    'enabled' => !empty($rule['enabled']),
                    'message' => self::mStr($rule, 'message', ''),
                ];
            }
            $this->ctx->set('cfg_comments_per_page',   self::mInt($current, 'comments_per_page', 20));
            $this->ctx->set('cfg_allow_replies',       (bool) ($current['allow_replies']      ?? true));
            $this->ctx->set('cfg_require_email',       self::mBool($current, 'require_email'));
            $this->ctx->set('cfg_minimum_flood_secs',  self::mInt($current, 'minimum_flood_secs', 10));
            $this->ctx->set('cfg_antispam_time_secs',  self::mInt($current, 'antispam_time_secs', 30));
            $this->ctx->set('antispam_list',           $antispamList);
            $this->ctx->set('has_antispam',            $antispamList !== []);
        }

        $this->setI18n($canModerate, $canConfig);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function buildFilterQs(): string
    {
        $parts   = [];
        $pageId  = $this->request->query()->get('page_id');
        $flagged = $this->request->query()->get('flagged');
        $hidden  = $this->request->query()->get('show_hidden');
        if ($pageId  !== null) { $parts[] = 'page_id='     . rawurlencode(is_scalar($pageId) ? (string)$pageId : ''); }
        if ($flagged !== null) { $parts[] = 'flagged='     . rawurlencode(is_scalar($flagged) ? (string)$flagged : ''); }
        if ($hidden  !== null) { $parts[] = 'show_hidden=' . rawurlencode(is_scalar($hidden) ? (string)$hidden : ''); }
        return $parts !== [] ? '?' . implode('&', $parts) : '';
    }

    /** @return array<string, mixed> */
    private function loadCurrent(): array
    {
        return [
            'comments_per_page'  => $this->config->getConfig('CommentService', 'comments_per_page',  20),
            'allow_replies'      => $this->config->getConfig('CommentService', 'allow_replies',      true),
            'require_email'      => $this->config->getConfig('CommentService', 'require_email',      false),
            'minimum_flood_secs' => $this->config->getConfig('CommentService', 'minimum_flood_secs', 10),
            'antispam_time_secs' => $this->config->getConfig('CommentService', 'antispam_time_secs', 30),
            'antispam_regex'     => $this->config->getConfig('CommentService', 'antispam_regex',     []),
        ];
    }

    private function setI18n(bool $canModerate, bool $canConfig): void
    {
        $this->ctx->set('admin_comments_heading', $this->t->t('admin.nav.comments'));

        if ($canModerate) {
            $this->ctx->set('label_id',           $this->t->t('admin.field.id'));
            $this->ctx->set('label_page',         $this->t->t('admin.field.page'));
            $this->ctx->set('label_user',         $this->t->t('admin.field.user'));
            $this->ctx->set('label_name',         $this->t->t('admin.field.name'));
            $this->ctx->set('label_email',        $this->t->t('admin.comments.email'));
            $this->ctx->set('label_content',      $this->t->t('admin.field.content'));
            $this->ctx->set('label_date',         $this->t->t('admin.field.date'));
            $this->ctx->set('label_hidden',       $this->t->t('admin.field.hidden'));
            $this->ctx->set('label_flagged',      $this->t->t('admin.field.flagged'));
            $this->ctx->set('label_reply_to',     $this->t->t('admin.comments.reply_to'));
            $this->ctx->set('label_actions',      $this->t->t('admin.field.actions'));
            $this->ctx->set('btn_edit',           $this->t->t('admin.btn.edit'));
            $this->ctx->set('btn_update',         $this->t->t('admin.btn.update'));
            $this->ctx->set('btn_cancel',         $this->t->t('admin.btn.cancel'));
            $this->ctx->set('btn_hide',           $this->t->t('admin.btn.hide'));
            $this->ctx->set('btn_unhide',         $this->t->t('admin.btn.unhide'));
            $this->ctx->set('btn_delete',         $this->t->t('admin.btn.delete'));
            $this->ctx->set('btn_flag',           $this->t->t('admin.btn.flag'));
            $this->ctx->set('btn_unflag',         $this->t->t('admin.btn.unflag'));
            $this->ctx->set('label_filter',       $this->t->t('admin.comments.filter'));
            $this->ctx->set('btn_filter',         $this->t->t('admin.btn.filter'));
            $this->ctx->set('label_show_hidden',  $this->t->t('admin.comments.show_hidden'));
            $this->ctx->set('label_hidden_only',  $this->t->t('admin.comments.hidden_only'));
            $this->ctx->set('label_visible_only', $this->t->t('admin.comments.visible_only'));
        }

        if ($canConfig) {
            $this->ctx->set('section_config_heading',       $this->t->t('admin.config.comments.heading'));
            $this->ctx->set('section_general',              $this->t->t('admin.config.comments.general'));
            $this->ctx->set('section_antispam',             $this->t->t('admin.config.comments.antispam'));
            $this->ctx->set('label_comments_per_page',      $this->t->t('admin.config.field.comments_per_page'));
            $this->ctx->set('label_allow_replies',          $this->t->t('admin.config.field.allow_replies'));
            $this->ctx->set('label_require_email_comment',  $this->t->t('admin.config.field.require_email'));
            $this->ctx->set('label_minimum_flood_secs',     $this->t->t('admin.config.field.minimum_flood_secs'));
            $this->ctx->set('label_antispam_time_secs',     $this->t->t('admin.config.field.antispam_time_secs'));
            $this->ctx->set('label_regex_key',              $this->t->t('admin.config.field.regex_key'));
            $this->ctx->set('label_regex_pattern',          $this->t->t('admin.config.field.regex_pattern'));
            $this->ctx->set('label_regex_enabled',          $this->t->t('admin.config.field.regex_enabled'));
            $this->ctx->set('label_regex_message',          $this->t->t('admin.config.field.regex_message'));
            $this->ctx->set('btn_save',                     $this->t->t('admin.btn.save'));
            $this->ctx->set('btn_add_regex',                $this->t->t('admin.config.comments.add_regex'));
        }
    }
}

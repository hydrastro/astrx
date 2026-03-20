<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
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
 * Admin — Access configuration editor.
 *
 * Two sections on one page:
 *
 * 1. Auth grants — one form per group (GUEST / USER / MOD).
 *    Rendered as a table: rows = permission groups (news.*, comment.*, …),
 *    columns = the three editable roles.  Each cell is a checkbox.
 *    ADMIN always has '*' (full access) and is shown as read-only.
 *
 * 2. Banlist routes — add / edit / delete routes and their rounds.
 *    Routes are the PHP-config penalty schedules (permanent, bad_comment, …).
 *    Rounds are the escalation steps within each route.
 *    Writes Banlist.config.php atomically.
 */
final class AdminConfigAccessController extends AbstractController
{
    private const FORM = 'admin_config_access';

    /** Groups the UI lets you edit (ADMIN is always '*', kept read-only). */
    private const EDITABLE_GROUPS = ['GUEST', 'USER', 'MOD'];

    /** Permission prefix → human section label key. */
    private const PREFIX_LABELS = [
        'news'    => 'admin.config.access.prefix_news',
        'comment' => 'admin.config.access.prefix_comment',
        'user'    => 'admin.config.access.prefix_user',
        'ban'     => 'admin.config.access.prefix_ban',
        'admin'   => 'admin.config.access.prefix_admin',
    ];

    public function __construct(
        DiagnosticsCollector                   $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request               $request,
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

    public function handle(): Result
    {
        if ($this->gate->cannot(Permission::ADMIN_CONFIG_ACCESS)) {
            http_response_code(403);
            return $this->ok();
        }

        $resolvedUrlId = $this->page->i18n
            ? $this->t->t($this->page->urlId, fallback: $this->page->urlId)
            : $this->page->urlId;
        $selfUrl = $this->urlGen->toPage($resolvedUrlId);

        $prgToken = $this->request->query()->get($this->prg->tokenQueryKey());
        if (is_string($prgToken) && $prgToken !== '') {
            $this->processForm($prgToken);
            Response::redirect($selfUrl)->send()->drainTo($this->collector);
            exit;
        }

        $this->buildContext($selfUrl);
        return $this->ok();
    }

    // =========================================================================

    private function processForm(string $prgToken): void
    {
        $posted     = $this->prg->pull($prgToken) ?? [];
        $csrfResult = $this->csrf->verify(self::FORM, (string) ($posted['_csrf'] ?? ''));
        if (!$csrfResult->isOk()) {
            $csrfResult->drainTo($this->collector);
            return;
        }

        $section = (string) ($posted['section'] ?? '');
        $result  = match ($section) {
            'grants'  => $this->saveGrants($posted),
            'routes'  => $this->saveRoutes($posted),
            default   => null,
        };

        if ($result !== null) {
            $result->drainTo($this->collector);
            if ($result->isOk()) {
                $this->flash->set('success', $this->t->t('admin.config.saved'));
            }
        }
    }

    // ── Savers ────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $p */
    private function saveGrants(array $p): Result
    {
        $grants = ['ADMIN' => ['*']];

        foreach (self::EDITABLE_GROUPS as $group) {
            $perms = [];
            foreach (Permission::cases() as $perm) {
                $field = 'perm_' . $group . '_' . str_replace('.', '__', $perm->value);
                if (!empty($p[$field])) {
                    $perms[] = $perm->value;
                }
            }
            $grants[$group] = $perms;
        }

        return $this->writer->write('Auth', ['Gate' => ['grants' => $grants]]);
    }

    /** @param array<string, mixed> $p */
    private function saveRoutes(array $p): Result
    {
        $action   = (string) ($p['route_action'] ?? '');
        $routeKey = trim((string) ($p['route_key'] ?? ''));
        $current  = $this->loadRoutes();

        switch ($action) {
            case 'add_route':
                if ($routeKey !== '' && !isset($current[$routeKey])) {
                    $current[$routeKey] = [];
                }
                break;

            case 'delete_route':
                unset($current[$routeKey]);
                break;

            case 'add_round':
                if ($routeKey !== '' && isset($current[$routeKey])) {
                    $nextIdx = $current[$routeKey] !== [] ? max(array_keys($current[$routeKey])) + 1 : 0;
                    $current[$routeKey][$nextIdx] = [
                        'penalty'    => max(0, (int) ($p['penalty']    ?? 0)),
                        'max_tries'  => max(0, (int) ($p['max_tries']  ?? 0)),
                        'check_time' => max(0, (int) ($p['check_time'] ?? 0)),
                        'enabled'    => !empty($p['enabled']),
                    ];
                }
                break;

            case 'update_round':
                $roundIdx = (int) ($p['round_idx'] ?? -1);
                if ($routeKey !== '' && isset($current[$routeKey][$roundIdx])) {
                    $current[$routeKey][$roundIdx] = [
                        'penalty'    => max(0, (int) ($p['penalty']    ?? 0)),
                        'max_tries'  => max(0, (int) ($p['max_tries']  ?? 0)),
                        'check_time' => max(0, (int) ($p['check_time'] ?? 0)),
                        'enabled'    => !empty($p['enabled']),
                    ];
                }
                break;

            case 'delete_round':
                $roundIdx = (int) ($p['round_idx'] ?? -1);
                if ($routeKey !== '' && isset($current[$routeKey][$roundIdx])) {
                    unset($current[$routeKey][$roundIdx]);
                    $current[$routeKey] = array_values($current[$routeKey]);
                }
                break;
        }

        return $this->writer->write('Banlist', [
            'BanlistRepository' => ['routes' => $current],
        ]);
    }

    // ── Context builder ───────────────────────────────────────────────────────

    private function buildContext(string $selfUrl): void
    {
        $csrfToken = $this->csrf->generate(self::FORM);
        $prgId     = $this->prg->createId($selfUrl);

        $grants  = (array) $this->config->getConfig('Gate', 'grants', []);
        $routes  = $this->loadRoutes();
        $editKey = trim((string) ($this->request->query()->get('route_edit') ?? ''));

        // ── Grants matrix ─────────────────────────────────────────────────────
        // Group permissions by their resource prefix (news, comment, user, ban, admin)
        // so the template can render a sub-table per prefix.
        $prefixSections = [];
        foreach (self::PREFIX_LABELS as $prefix => $labelKey) {
            $rows = [];
            foreach (Permission::cases() as $perm) {
                if (!str_starts_with($perm->value, $prefix . '.')) { continue; }
                $cells = [];
                foreach (self::EDITABLE_GROUPS as $group) {
                    $groupGrants = (array) ($grants[$group] ?? []);
                    $granted     = in_array($perm->value, $groupGrants, true);
                    $field       = 'perm_' . $group . '_' . str_replace('.', '__', $perm->value);
                    $cells[] = ['group' => $group, 'field' => $field, 'granted' => $granted];
                }
                $rows[] = ['perm_value' => $perm->value, 'perm_name' => $perm->name, 'cells' => $cells];
            }
            if ($rows !== []) {
                $prefixSections[] = [
                    'prefix_label' => $this->t->t($labelKey, fallback: $prefix),
                    'rows'         => $rows,
                ];
            }
        }

        // ── Routes ────────────────────────────────────────────────────────────
        $routeList = [];
        foreach ($routes as $key => $rounds) {
            $roundList = [];
            $isEditing = ($editKey !== '' && $editKey === $key);
            foreach ($rounds as $idx => $round) {
                $rd = [
                    'index'          => $idx,
                    'penalty'        => (int)  ($round['penalty']    ?? 0),
                    'max_tries'      => (int)  ($round['max_tries']  ?? 0),
                    'check_time'     => (int)  ($round['check_time'] ?? 0),
                    'enabled'        => (bool) ($round['enabled']    ?? true),
                    'penalty_fmt'    => $this->fmt((int) ($round['penalty']    ?? 0)),
                    'max_tries_fmt'  => ($round['max_tries']  ?? 0) === 0 ? '∞' : (string) (int) $round['max_tries'],
                    'check_time_fmt' => ($round['check_time'] ?? 0) === 0 ? '—' : $this->fmt((int) $round['check_time']),
                    'route_key_val'  => $key,
                    'editing'        => $isEditing ? [['index' => $idx, 'route_key_val' => $key,
                                                       'penalty' => (int) ($round['penalty'] ?? 0),
                                                       'max_tries' => (int) ($round['max_tries'] ?? 0),
                                                       'check_time' => (int) ($round['check_time'] ?? 0),
                                                       'enabled' => (bool) ($round['enabled'] ?? true)]] : false,
                ];
                $roundList[] = $rd;
            }
            $routeList[] = [
                'key'           => $key,
                'rounds'        => $roundList,
                'is_editing'    => $isEditing,
                'edit_url'      => $selfUrl . '?route_edit=' . rawurlencode($key),
                'cancel_url'    => $selfUrl,
            ];
        }

        $this->ctx->set('csrf_token',       $csrfToken);
        $this->ctx->set('prg_id',           $prgId);
        $this->ctx->set('base_url',         $selfUrl);
        $this->ctx->set('editable_groups',  self::EDITABLE_GROUPS);
        $this->ctx->set('prefix_sections',  $prefixSections);
        $this->ctx->set('route_list',       $routeList);
        $this->setI18n();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return array<string, list<array<string,mixed>>> */
    private function loadRoutes(): array
    {
        return (array) $this->config->getConfig('BanlistRepository', 'routes', []);
    }

    private function fmt(int $s): string
    {
        if ($s === 0) { return '0s'; }
        $out = []; $r = $s;
        foreach ([['y',31536000],['mo',2592000],['w',604800],['d',86400],['h',3600],['m',60],['s',1]] as [$u,$d]) {
            if ($r >= $d) { $v = intdiv($r, $d); $out[] = $v . $u; $r -= $v * $d; }
        }
        return implode(' ', array_slice($out, 0, 2));
    }

    private function setI18n(): void
    {
        $this->ctx->set('heading',                  $this->t->t('admin.config.access.heading'));
        $this->ctx->set('section_grants',           $this->t->t('admin.config.access.grants'));
        $this->ctx->set('section_banlist',          $this->t->t('admin.config.access.banlist'));
        $this->ctx->set('label_permission',         $this->t->t('admin.config.access.permission'));
        $this->ctx->set('label_admin_note',         $this->t->t('admin.config.access.admin_note'));
        $this->ctx->set('label_route_key',          $this->t->t('admin.config.access.route_key'));
        $this->ctx->set('label_round',              $this->t->t('admin.banlist.round'));
        $this->ctx->set('label_penalty',            $this->t->t('admin.banlist.penalty'));
        $this->ctx->set('label_max_tries',          $this->t->t('admin.banlist.max_tries'));
        $this->ctx->set('label_check_win',          $this->t->t('admin.banlist.check_win'));
        $this->ctx->set('label_enabled',            $this->t->t('admin.banlist.enabled'));
        $this->ctx->set('label_new_route_key',      $this->t->t('admin.config.access.new_route_key'));
        $this->ctx->set('btn_save',                 $this->t->t('admin.btn.save'));
        $this->ctx->set('btn_add_route',            $this->t->t('admin.config.access.add_route'));
        $this->ctx->set('btn_add_round',            $this->t->t('admin.config.access.add_round'));
        $this->ctx->set('btn_delete',               $this->t->t('admin.btn.delete'));
        $this->ctx->set('btn_edit',                 $this->t->t('admin.btn.edit'));
        $this->ctx->set('btn_update',               $this->t->t('admin.btn.update'));
        $this->ctx->set('btn_cancel',               $this->t->t('admin.btn.cancel'));
        // Prefix section labels (for grant matrix headings)
        foreach (self::PREFIX_LABELS as $prefix => $key) {
            $this->ctx->set('prefix_' . $prefix, $this->t->t($key, fallback: $prefix));
        }
        // Group column headers
        foreach (self::EDITABLE_GROUPS as $g) {
            $this->ctx->set('group_' . strtolower($g), $g);
        }
        $this->ctx->set('group_admin', 'ADMIN');
    }
}
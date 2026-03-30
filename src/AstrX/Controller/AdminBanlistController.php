<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Admin\BanlistRepository;
use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Config\Config;
use AstrX\Config\ConfigWriter;
use AstrX\Csrf\CsrfHandler;
use AstrX\Http\Request;
use AstrX\Http\Response;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Session\FlashBag;
use AstrX\Session\PrgHandler;
use AstrX\Template\DefaultTemplateContext;

/**
 * Admin banlist management.
 *
 * Two sections:
 *   1. Bans    — add / edit (?edit=N) / activate / delete
 *   2. Routes  — add route / delete route / add round / edit round / delete round
 *
 * Route schedules live in BanlistRepository.config.php and are written via ConfigWriter.
 * BanlistRepository provides listRoutes() from the injected config, no DB involved.
 */
final class AdminBanlistController extends AbstractController
{
    private const FORM = 'admin_banlist';

    public function __construct(
        DiagnosticsCollector                   $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request               $request,
        private readonly BanlistRepository     $banlist,
        private readonly Config                $config,
        private readonly ConfigWriter          $writer,
        private readonly Gate                  $gate,
        private readonly CsrfHandler           $csrf,
        private readonly PrgHandler            $prg,
        private readonly FlashBag              $flash,
        private readonly Translator            $t,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        if ($this->gate->cannot(Permission::ADMIN_BANLIST)) {
            http_response_code(403);
            return $this->ok();
        }

        $prgToken = $this->request->query()->get($this->prg->tokenQueryKey());
        if (is_string($prgToken) && $prgToken !== '') {
            $qs = $this->processForm($prgToken);
            Response::redirect($this->request->uri()->path() . $qs)
                ->send()->drainTo($this->collector);
            exit;
        }

        $this->buildContext();
        return $this->ok();
    }

    // =========================================================================

    private function processForm(string $prgToken): string
    {
        $posted     = $this->prg->pull($prgToken) ?? [];
        $csrfResult = $this->csrf->verify(self::FORM, self::mStr($posted, '_csrf', ''));
        if (!$csrfResult->isOk()) {
            $csrfResult->drainTo($this->collector);
            return '';
        }

        $action = self::mStr($posted, 'action', '');

        switch ($action) {
            // ── Bans ──────────────────────────────────────────────────────────
            case 'ban':
                $type   = self::mStr($posted, 'type', '');
                $value  = trim(self::mStr($posted, 'value', ''));
                $reason = trim(self::mStr($posted, 'reason', ''));
                $route  = trim(self::mStr($posted, 'route', ''));
                $end    = ($posted['end'] ?? '') !== '' ? (is_scalar($posted['end']) ? (string)$posted['end'] : '') : null;
                if ($value === '' || $reason === '' || $route === '') { break; }
                $r = match ($type) {
                    'ip'    => $this->banlist->banCidr($value, $reason, $route, $end),
                    'email' => $this->banlist->banEmail($value, $reason, $route, $end),
                    'user'  => $this->banlist->banUser($value, $reason, $route, $end),
                    default => null,
                };
                if ($r !== null) {
                    $r->drainTo($this->collector);
                    if ($r->isOk()) { $this->flash->set('success', $this->t->t('admin.banlist.banned')); }
                }
                break;

            case 'update_ban':
                $banId  = self::mInt($posted, 'ban_id', 0);
                $reason = trim(self::mStr($posted, 'reason', ''));
                $route  = trim(self::mStr($posted, 'route', ''));
                $end    = ($posted['end'] ?? '') !== '' ? (is_scalar($posted['end']) ? (string)$posted['end'] : '') : null;
                $active = self::mBool($posted, 'active');
                if ($banId === 0 || $reason === '' || $route === '') { break; }
                $r = $this->banlist->updateBan($banId, $reason, $route, $end, $active);
                $r->drainTo($this->collector);
                if ($r->isOk()) { $this->flash->set('success', $this->t->t('admin.banlist.updated')); }
                break;

            case 'activate':
                $this->banlist->setActive(self::mInt($posted, 'ban_id', 0), true)
                    ->drainTo($this->collector);
                break;

            case 'deactivate':
                $this->banlist->setActive(self::mInt($posted, 'ban_id', 0), false)
                    ->drainTo($this->collector);
                break;

            case 'delete_ban':
                $this->banlist->delete(self::mInt($posted, 'ban_id', 0))
                    ->drainTo($this->collector);
                $this->flash->set('success', $this->t->t('admin.banlist.deleted'));
                break;

            // ── Routes ────────────────────────────────────────────────────────
            case 'add_route':
                $key = trim(self::mStr($posted, 'route_key', ''));
                if ($key === '') { break; }
                $routes = $this->loadRoutes();
                if (!isset($routes[$key])) {
                    $routes[$key] = [];
                    $this->saveRoutes($routes)->drainTo($this->collector);
                    $this->flash->set('success', $this->t->t('admin.banlist.route_added'));
                }
                break;

            case 'delete_route':
                $key    = trim(self::mStr($posted, 'route_key', ''));
                $routes = $this->loadRoutes();
                unset($routes[$key]);
                $this->saveRoutes($routes)->drainTo($this->collector);
                $this->flash->set('success', $this->t->t('admin.banlist.route_deleted'));
                break;

            case 'add_round':
                $key    = trim(self::mStr($posted, 'route_key', ''));
                $routes = $this->loadRoutes();
                if ($key !== '' && isset($routes[$key])) {
                    $next = $routes[$key] !== [] ? max(array_keys($routes[$key])) + 1 : 0;
                    $routes[$key][$next] = [
                        'penalty'    => max(0, self::mInt($posted, 'penalty', 0)),
                        'max_tries'  => max(0, self::mInt($posted, 'max_tries', 0)),
                        'check_time' => max(0, self::mInt($posted, 'check_time', 0)),
                        'enabled'    => self::mBool($posted, 'enabled'),
                    ];
                    $this->saveRoutes($routes)->drainTo($this->collector);
                    $this->flash->set('success', $this->t->t('admin.banlist.round_added'));
                    return '?route_edit=' . rawurlencode($key);
                }
                break;

            case 'update_round':
                $key      = trim(self::mStr($posted, 'route_key', ''));
                $roundIdxRaw = $posted['round_idx'] ?? -1;
                $roundIdx = is_int($roundIdxRaw) ? $roundIdxRaw : (is_numeric($roundIdxRaw) ? (int)$roundIdxRaw : -1);
                $routes   = $this->loadRoutes();
                if ($key !== '' && isset($routes[$key][$roundIdx])) {
                    $routes[$key][$roundIdx] = [
                        'penalty'    => max(0, self::mInt($posted, 'penalty', 0)),
                        'max_tries'  => max(0, self::mInt($posted, 'max_tries', 0)),
                        'check_time' => max(0, self::mInt($posted, 'check_time', 0)),
                        'enabled'    => self::mBool($posted, 'enabled'),
                    ];
                    $this->saveRoutes($routes)->drainTo($this->collector);
                    $this->flash->set('success', $this->t->t('admin.banlist.round_updated'));
                    return '?route_edit=' . rawurlencode($key);
                }
                break;

            case 'delete_round':
                $key      = trim(self::mStr($posted, 'route_key', ''));
                $roundIdxRaw = $posted['round_idx'] ?? -1;
                $roundIdx = is_int($roundIdxRaw) ? $roundIdxRaw : (is_numeric($roundIdxRaw) ? (int)$roundIdxRaw : -1);
                $routes   = $this->loadRoutes();
                if ($key !== '' && isset($routes[$key][$roundIdx])) {
                    unset($routes[$key][$roundIdx]);
                    $routes[$key] = array_values($routes[$key]);
                    $this->saveRoutes($routes)->drainTo($this->collector);
                    $this->flash->set('success', $this->t->t('admin.banlist.round_deleted'));
                    return '?route_edit=' . rawurlencode($key);
                }
                break;
        }

        return '';
    }

    // ── Context builder ───────────────────────────────────────────────────────

    private function buildContext(): void
    {
        $banEditId   = (is_numeric($vq_edit = $this->request->query()->get('edit')) ? (int)$vq_edit : 0);
        $routeEditId = trim((is_scalar($vroute_edit = $this->request->query()->get('route_edit') ?? '') ? (string)$vroute_edit : ''));

        $listResult = $this->banlist->listAll();
        $listResult->drainTo($this->collector);
        $rawBans   = $listResult->isOk() ? $listResult->unwrap() : [];
        $rawRoutes = $this->banlist->listRoutes();

        $routeOptions = $this->buildRouteOptions($rawRoutes);

        // Decorate bans.
        $banList = [];
        foreach ($rawBans as $ban) {
            $ban['route_name'] = self::mStr($ban, 'ban_route', '');
            $isEditing         = ($banEditId > 0 && (is_int($ban['id']) ? $ban['id'] : 0) === $banEditId);
            if ($isEditing) {
                $editCtx                  = $ban;
                $banRoute = is_scalar($ban['ban_route'] ?? null) ? (string)$ban['ban_route'] : '';
                $editCtx['route_options'] = array_map(
                    /** @param array{key:string,name:string} $o */
                    fn(array $o): array => array_merge($o, ['selected' => $o['key'] === $banRoute]),
                    $routeOptions
                );
                $ban['editing'] = [$editCtx];
            } else {
                $ban['editing'] = false;
            }
            $banList[] = $ban;
        }

        // Decorate routes + rounds.
        $routeList = [];
        foreach ($rawRoutes as $route) {
            $key       = (string) $route['key'];
            $isEditing = ($routeEditId !== '' && $routeEditId === $key);
            $rounds    = [];
            foreach ($route['rounds'] as $idx => $rd) {
                $penalty   = self::mInt($rd, 'penalty', 0);
                $maxTries  = self::mInt($rd, 'max_tries', 0);
                $checkTime = self::mInt($rd, 'check_time', 0);
                $enabled   = (bool) ($rd['enabled']    ?? true);
                $rounds[] = [
                    'index'          => $idx,
                    'penalty'        => $penalty,
                    'max_tries'      => $maxTries,
                    'check_time'     => $checkTime,
                    'enabled'        => $enabled,
                    'penalty_fmt'    => $penalty   === 0 ? '∞' : $this->fmt($penalty),
                    'max_tries_fmt'  => $maxTries  === 0 ? '∞' : (string) $maxTries,
                    'check_time_fmt' => $checkTime === 0 ? '—' : $this->fmt($checkTime),
                    'route_key_val'  => $key,
                    'editing'        => $isEditing
                        ? [['index' => $idx, 'route_key_val' => $key,
                            'penalty' => $penalty, 'max_tries' => $maxTries,
                            'check_time' => $checkTime, 'enabled' => $enabled]]
                        : false,
                ];
            }
            $routeList[] = [
                'key'        => $key,
                'name'       => (string) $route['name'],
                'rounds'     => $rounds,
                'is_editing' => $isEditing,
                'edit_url'   => $this->request->uri()->path() . '?route_edit=' . rawurlencode($key),
                'cancel_url' => $this->request->uri()->path(),
            ];
        }

        $csrfToken = $this->csrf->generate(self::FORM);
        $prgId     = $this->prg->createId($this->request->uri()->path());

        $this->ctx->set('csrf_token',     $csrfToken);
        $this->ctx->set('prg_id',         $prgId);
        $this->ctx->set('ban_list',       $banList);
        $this->ctx->set('ban_routes',     $routeList);
        $this->ctx->set('has_routes',     $routeList !== []);
        $this->ctx->set('add_ban_routes', $routeOptions);
        $this->ctx->set('base_url',       $this->request->uri()->path());
        $this->setI18n();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return array<string, list<array<string,mixed>>> */
    private function loadRoutes(): array
    {
        $raw = $this->config->getConfigArray('BanlistRepository', 'routes');
        /** @var array<string, list<array<string,mixed>>> $typed */
        $typed = $raw;
        return $typed;
    }

    /** @param array<string, mixed> $routes
     * @return Result<mixed>
     */
    private function saveRoutes(array $routes): Result
    {
        return $this->writer->write('BanlistRepository', [
            'BanlistRepository' => ['routes' => $routes],
        ]);
    }

    /** @param list<array<string,mixed>> $routes
     * @return list<array{key:string,name:string}>
     */
    private function buildRouteOptions(array $routes): array
    {
        $options = [];
        foreach ($routes as $r) {
            $options[] = ['key' => is_scalar($r['key'] ?? null) ? (string)$r['key'] : '', 'name' => is_scalar($r['name'] ?? null) ? (string)$r['name'] : ''];
        }
        return $options;
    }

    private function fmt(int $s): string
    {
        if ($s === 0) { return '0s'; }
        $out = []; $r = $s;
        foreach ([['y',31536000],['mo',2592000],['w',604800],['d',86400],
                  ['h',3600],['m',60],['s',1]] as [$u, $d]) {
            if ($r >= $d) { $v = intdiv($r, $d); $out[] = $v . $u; $r -= $v * $d; }
        }
        return implode(' ', array_slice($out, 0, 2));
    }

    private function setI18n(): void
    {
        $this->ctx->set('admin_banlist_heading', $this->t->t('admin.nav.banlist'));
        $this->ctx->set('label_id',          $this->t->t('admin.field.id'));
        $this->ctx->set('label_type',        $this->t->t('admin.field.type'));
        $this->ctx->set('label_value',       $this->t->t('admin.banlist.value'));
        $this->ctx->set('label_reason',      $this->t->t('admin.field.reason'));
        $this->ctx->set('label_route',       $this->t->t('admin.banlist.route'));
        $this->ctx->set('label_end',         $this->t->t('admin.banlist.end'));
        $this->ctx->set('label_active',      $this->t->t('admin.field.active'));
        $this->ctx->set('label_actions',     $this->t->t('admin.field.actions'));
        $this->ctx->set('label_ip_hint',     $this->t->t('admin.banlist.ip_hint'));
        $this->ctx->set('label_round',       $this->t->t('admin.banlist.round'));
        $this->ctx->set('label_penalty',     $this->t->t('admin.banlist.penalty'));
        $this->ctx->set('label_max_tries',   $this->t->t('admin.banlist.max_tries'));
        $this->ctx->set('label_check_win',   $this->t->t('admin.banlist.check_win'));
        $this->ctx->set('label_enabled',     $this->t->t('admin.banlist.enabled'));
        $this->ctx->set('label_name',        $this->t->t('admin.field.name'));
        $this->ctx->set('label_new_route',   $this->t->t('admin.config.access.new_route_key'));
        $this->ctx->set('type_ip',           $this->t->t('admin.banlist.type_ip'));
        $this->ctx->set('type_email',        $this->t->t('admin.banlist.type_email'));
        $this->ctx->set('type_user',         $this->t->t('admin.banlist.type_user'));
        $this->ctx->set('btn_ban',           $this->t->t('admin.btn.ban'));
        $this->ctx->set('btn_mercy',         $this->t->t('admin.btn.mercy'));
        $this->ctx->set('btn_update',        $this->t->t('admin.btn.update'));
        $this->ctx->set('btn_edit',          $this->t->t('admin.btn.edit'));
        $this->ctx->set('btn_add',           $this->t->t('admin.btn.add'));
        $this->ctx->set('btn_delete',        $this->t->t('admin.btn.delete'));
        $this->ctx->set('btn_cancel',        $this->t->t('admin.btn.cancel'));
        $this->ctx->set('btn_activate',      $this->t->t('admin.btn.activate'));
        $this->ctx->set('btn_deactivate',    $this->t->t('admin.btn.deactivate'));
    }
}

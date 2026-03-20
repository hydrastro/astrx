<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Admin\BanlistRepository;
use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
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
 * Three sections:
 *   1. Bans         — add / edit (?edit=N) / activate / delete
 *   2. Routes       — add / edit (?route_edit=N) / delete
 *   3. Rounds       — add / edit (?round_edit=N) / delete (nested inside routes)
 */
final class AdminBanlistController extends AbstractController
{
    private const FORM = 'admin_banlist';

    public function __construct(
        DiagnosticsCollector                   $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request               $request,
        private readonly BanlistRepository     $banlist,
        private readonly Gate                  $gate,
        private readonly CsrfHandler           $csrf,
        private readonly PrgHandler            $prg,
        private readonly FlashBag              $flash,
        private readonly Translator            $t,
    ) {
        parent::__construct($collector);
    }

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

        // ── Query params ───────────────────────────────────────────────────
        $banEditId   = (int) ($this->request->query()->get('edit')       ?? 0);
        $routeEditId = (int) ($this->request->query()->get('route_edit') ?? 0);
        $roundEditId = (int) ($this->request->query()->get('round_edit') ?? 0);

        // ── Data ──────────────────────────────────────────────────────────
        $listResult   = $this->banlist->listAll();
        $rawRoutes = $this->banlist->listRoutes();
        $listResult->drainTo($this->collector);

        $rawBans   = $listResult->isOk()   ? $listResult->unwrap()   : [];

        // ── Decorate bans with inline editing flag ─────────────────────────
        $banList = [];
        foreach ($rawBans as $ban) {
            $isEditing = ($banEditId > 0 && (int) $ban['id'] === $banEditId);
            if ($isEditing) {
                $editCtx = $ban;
                $editCtx['route_options'] = $this->buildRouteOptions($rawRoutes, (int) $ban['ban_route']);
                $ban['editing'] = $editCtx; // nested array → Mustache context
            } else {
                $ban['editing'] = false;
            }
            $banList[] = $ban;
        }

        // ── Decorate routes + rounds with inline editing flags ─────────────
        $fmtRoutes = [];
        foreach ($rawRoutes as $route) {
            $rounds = [];
            foreach ($route['rounds'] as $rd) {
                $rd['penalty_fmt']    = $rd['penalty']    === 0 ? '∞' : $this->fmt((int) $rd['penalty']);
                $rd['max_tries_fmt']  = $rd['max_tries']  === 0 ? '∞' : (string) (int) $rd['max_tries'];
                $rd['check_time_fmt'] = $rd['check_time'] === 0 ? '—' : $this->fmt((int) $rd['check_time']);
                $rd['route_id_val']   = (int) $route['id'];
                $rd['editing'] = ($roundEditId > 0 && (int) $rd['id'] === $roundEditId) ? $rd : false;
                $rounds[] = $rd;
            }
            $route['rounds'] = $rounds;
            $route['editing'] = ($routeEditId > 0 && (int) $route['id'] === $routeEditId) ? $route : false;
            // Re-assign rounds after setting editing on route (route['rounds'] used in template)
            $fmtRoutes[] = $route;
        }

        $csrfToken          = $this->csrf->generate(self::FORM);
        $prgId              = $this->prg->createId($this->request->uri()->path());
        $addBanRouteOptions = $this->buildRouteOptions($rawRoutes, -1);

        $this->ctx->set('csrf_token',     $csrfToken);
        $this->ctx->set('prg_id',         $prgId);
        $this->ctx->set('ban_list',       $banList);
        $this->ctx->set('ban_routes',     $fmtRoutes);
        $this->ctx->set('add_ban_routes', $addBanRouteOptions);
        $this->ctx->set('base_url',       $this->request->uri()->path());
        $this->setI18n();
        return $this->ok();
    }


    // =========================================================================

    /** Returns query string to preserve context on redirect */
    private function processForm(string $prgToken): string
    {
        $posted     = $this->prg->pull($prgToken) ?? [];
        $csrfResult = $this->csrf->verify(self::FORM, (string) ($posted['_csrf'] ?? ''));
        if (!$csrfResult->isOk()) {
            $csrfResult->drainTo($this->collector);
            return '';
        }

        $action = (string) ($posted['action'] ?? '');

        switch ($action) {
            // ── Bans ──────────────────────────────────────────────────────
            case 'ban':
                $type   = (string) ($posted['type']   ?? '');
                $value  = trim((string) ($posted['value']  ?? ''));
                $reason = trim((string) ($posted['reason'] ?? ''));
                $route  = (int)    ($posted['route']   ?? 0);
                $end    = ($posted['end'] ?? '') !== '' ? (string) $posted['end'] : null;
                if ($value === '' || $reason === '') { break; }
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
                $banId  = (int)    ($posted['ban_id']  ?? 0);
                $reason = trim((string) ($posted['reason'] ?? ''));
                $route  = (int)    ($posted['route']   ?? 0);
                $end    = ($posted['end'] ?? '') !== '' ? (string) $posted['end'] : null;
                $active = !empty($posted['active']);
                if ($banId === 0 || $reason === '') { break; }
                $r = $this->banlist->updateBan($banId, $reason, $route, $end, $active);
                $r->drainTo($this->collector);
                if ($r->isOk()) { $this->flash->set('success', $this->t->t('admin.banlist.updated')); }
                break;

            case 'activate':
                $this->banlist->setActive((int) ($posted['ban_id'] ?? 0), true)
                    ->drainTo($this->collector);
                break;

            case 'deactivate':
                $this->banlist->setActive((int) ($posted['ban_id'] ?? 0), false)
                    ->drainTo($this->collector);
                break;

            case 'delete_ban':
                $this->banlist->delete((int) ($posted['ban_id'] ?? 0))
                    ->drainTo($this->collector);
                $this->flash->set('success', $this->t->t('admin.banlist.deleted'));
                break;

            // ── Routes ────────────────────────────────────────────────────
            case 'add_route':
                $name = trim((string) ($posted['name'] ?? ''));
                $desc = trim((string) ($posted['description'] ?? ''));
                if ($name === '') { break; }
                $this->banlist->addRoute($name, $desc)->drainTo($this->collector);
                $this->flash->set('success', $this->t->t('admin.banlist.route_added'));
                break;

            case 'update_route':
                $routeId = (int) ($posted['route_id'] ?? 0);
                $name    = trim((string) ($posted['name'] ?? ''));
                $desc    = trim((string) ($posted['description'] ?? ''));
                if ($routeId === 0 || $name === '') { break; }
                $this->banlist->updateRoute($routeId, $name, $desc)->drainTo($this->collector);
                $this->flash->set('success', $this->t->t('admin.banlist.route_updated'));
                break;

            case 'delete_route':
                $this->banlist->deleteRoute((int) ($posted['route_id'] ?? 0))
                    ->drainTo($this->collector);
                $this->flash->set('success', $this->t->t('admin.banlist.route_deleted'));
                break;

            // ── Rounds ────────────────────────────────────────────────────
            case 'add_round':
                $routeId   = (int) ($posted['route_id']   ?? 0);
                $roundNum  = (int) ($posted['round_num']  ?? 0);
                $penalty   = (int) ($posted['penalty']    ?? 0);
                $maxTries  = (int) ($posted['max_tries']  ?? 0);
                $checkTime = (int) ($posted['check_time'] ?? 0);
                $enabled   = !empty($posted['enabled']);
                if ($routeId === 0) { break; }
                $this->banlist->addRound($routeId, $roundNum, $penalty, $maxTries, $checkTime, $enabled)
                    ->drainTo($this->collector);
                $this->flash->set('success', $this->t->t('admin.banlist.round_added'));
                return '?route_edit=' . $routeId;

            case 'update_round':
                $roundId   = (int) ($posted['round_id']   ?? 0);
                $penalty   = (int) ($posted['penalty']    ?? 0);
                $maxTries  = (int) ($posted['max_tries']  ?? 0);
                $checkTime = (int) ($posted['check_time'] ?? 0);
                $enabled   = !empty($posted['enabled']);
                if ($roundId === 0) { break; }
                $this->banlist->updateRound($roundId, $penalty, $maxTries, $checkTime, $enabled)
                    ->drainTo($this->collector);
                $this->flash->set('success', $this->t->t('admin.banlist.round_updated'));
                break;

            case 'delete_round':
                $this->banlist->deleteRound((int) ($posted['round_id'] ?? 0))
                    ->drainTo($this->collector);
                $this->flash->set('success', $this->t->t('admin.banlist.round_deleted'));
                break;
        }

        return '';
    }

    // =========================================================================

    /**
     * @param list<array<string,mixed>> $routes
     * @return list<array{id:int,name:string,selected:bool}>
     */
    private function buildRouteOptions(array $routes, int $selectedId): array
    {
        $options = [];
        foreach ($routes as $r) {
            $options[] = [
                'id'       => (int) $r['id'],
                'name'     => (string) $r['name'],
                'selected' => ((int) $r['id'] === $selectedId),
            ];
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
        $this->ctx->set('label_type',       $this->t->t('admin.field.type'));
        $this->ctx->set('label_value',      $this->t->t('admin.banlist.value'));
        $this->ctx->set('label_reason',     $this->t->t('admin.field.reason'));
        $this->ctx->set('label_route',      $this->t->t('admin.banlist.route'));
        $this->ctx->set('label_end',        $this->t->t('admin.banlist.end'));
        $this->ctx->set('label_active',     $this->t->t('admin.field.active'));
        $this->ctx->set('label_id',         $this->t->t('admin.field.id'));
        $this->ctx->set('label_name',       $this->t->t('admin.field.name'));
        $this->ctx->set('label_actions',    $this->t->t('admin.field.actions'));
        $this->ctx->set('label_ip_hint',    $this->t->t('admin.banlist.ip_hint'));
        $this->ctx->set('label_round',      $this->t->t('admin.banlist.round'));
        $this->ctx->set('label_penalty',    $this->t->t('admin.banlist.penalty'));
        $this->ctx->set('label_max_tries',  $this->t->t('admin.banlist.max_tries'));
        $this->ctx->set('label_check_win',  $this->t->t('admin.banlist.check_win'));
        $this->ctx->set('label_enabled',    $this->t->t('admin.banlist.enabled'));
        $this->ctx->set('label_desc',       $this->t->t('admin.pages.description'));
        $this->ctx->set('btn_ban',          $this->t->t('admin.btn.ban'));
        $this->ctx->set('btn_mercy',        $this->t->t('admin.btn.mercy'));
        $this->ctx->set('btn_update',       $this->t->t('admin.btn.update'));
        $this->ctx->set('btn_edit',         $this->t->t('admin.btn.edit'));
        $this->ctx->set('btn_add',          $this->t->t('admin.btn.add'));
        $this->ctx->set('btn_delete',       $this->t->t('admin.btn.delete'));
        $this->ctx->set('btn_cancel',       $this->t->t('admin.btn.cancel'));
        $this->ctx->set('btn_activate',     $this->t->t('admin.btn.activate'));
        $this->ctx->set('btn_deactivate',   $this->t->t('admin.btn.deactivate'));
        $this->ctx->set('type_ip',          $this->t->t('admin.banlist.type_ip'));
        $this->ctx->set('type_email',       $this->t->t('admin.banlist.type_email'));
        $this->ctx->set('type_user',        $this->t->t('admin.banlist.type_user'));
    }
}
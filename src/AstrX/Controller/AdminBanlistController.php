<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Admin\BanlistRepository;
use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Config\Config;
use AstrX\Csrf\CsrfHandler;
use AstrX\Http\Request;
use AstrX\Http\Response;
use AstrX\I18n\Translator;
use AstrX\Page\Page;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Routing\UrlGenerator;
use AstrX\Result\Result;
use AstrX\Session\FlashBag;
use AstrX\Session\PrgHandler;
use AstrX\Template\DefaultTemplateContext;

/**
 * Admin banlist management.
 *
 * Three sections:
 *   1. Bans     — add / edit (?edit=N) / activate / delete
 *   2. Routes   — view only (defined in Banlist.config.php, edited via AdminConfigAccessController)
 *   3. (Rounds are part of route config, also edited via AdminConfigAccessController)
 *
 * ban_route is now a VARCHAR(64) string key matching BanlistRepository::ROUTE_* constants.
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
        private readonly Page                  $page,
        private readonly UrlGenerator          $urlGen,
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

        $resolvedUrlId = $this->page->i18n
            ? $this->t->t($this->page->urlId, fallback: $this->page->urlId)
            : $this->page->urlId;
        $selfUrl = $this->urlGen->toPage($resolvedUrlId);

        $prgToken = $this->request->query()->get($this->prg->tokenQueryKey());
        if (is_string($prgToken) && $prgToken !== '') {
            $qs = $this->processForm($prgToken);
            Response::redirect($selfUrl . $qs)
                ->send()->drainTo($this->collector);
            exit;
        }

        $banEditId = (int) ($this->request->query()->get('edit') ?? 0);

        $listResult = $this->banlist->listAll();
        $listResult->drainTo($this->collector);
        $rawBans = $listResult->isOk() ? $listResult->unwrap() : [];

        $routes    = $this->banlist->listRoutes();
        $routeOpts = $this->buildRouteOptions($routes, '');

        $banList = [];
        foreach ($rawBans as $ban) {
            $isEditing = ($banEditId > 0 && (int) $ban['id'] === $banEditId);
            if ($isEditing) {
                $editCtx                = $ban;
                $editCtx['route_options'] = $this->buildRouteOptions($routes, (string) $ban['ban_route']);
                $ban['editing']         = [$editCtx];
            } else {
                $ban['editing'] = false;
            }
            $banList[] = $ban;
        }

        $csrfToken = $this->csrf->generate(self::FORM);
        $prgId     = $this->prg->createId($selfUrl);

        $this->ctx->set('csrf_token',     $csrfToken);
        $this->ctx->set('prg_id',         $prgId);
        $this->ctx->set('ban_list',       $banList);
        $this->ctx->set('ban_routes',     $routes);
        $this->ctx->set('add_ban_routes', $routeOpts);
        $this->ctx->set('base_url',       $selfUrl);
        $this->setI18n();
        return $this->ok();
    }

    // =========================================================================

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
            case 'ban':
                $type   = (string) ($posted['type']   ?? '');
                $value  = trim((string) ($posted['value']  ?? ''));
                $reason = trim((string) ($posted['reason'] ?? ''));
                $route  = (string) ($posted['route']  ?? BanlistRepository::ROUTE_PERMANENT);
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
                $route  = (string) ($posted['route']   ?? BanlistRepository::ROUTE_PERMANENT);
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
        }

        return '';
    }

    // =========================================================================

    /**
     * @param list<array{key:string,name:string,rounds:list<array>}> $routes
     * @return list<array{key:string,name:string,selected:bool}>
     */
    private function buildRouteOptions(array $routes, string $selectedKey): array
    {
        $options = [];
        foreach ($routes as $r) {
            $options[] = [
                'key'      => $r['key'],
                'name'     => $r['name'],
                'selected' => ($r['key'] === $selectedKey),
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
        $this->ctx->set('btn_ban',          $this->t->t('admin.btn.ban'));
        $this->ctx->set('btn_update',       $this->t->t('admin.btn.update'));
        $this->ctx->set('btn_edit',         $this->t->t('admin.btn.edit'));
        $this->ctx->set('btn_delete',       $this->t->t('admin.btn.delete'));
        $this->ctx->set('btn_cancel',       $this->t->t('admin.btn.cancel'));
        $this->ctx->set('btn_activate',     $this->t->t('admin.btn.activate'));
        $this->ctx->set('btn_deactivate',   $this->t->t('admin.btn.deactivate'));
        $this->ctx->set('type_ip',          $this->t->t('admin.banlist.type_ip'));
        $this->ctx->set('type_email',       $this->t->t('admin.banlist.type_email'));
        $this->ctx->set('type_user',        $this->t->t('admin.banlist.type_user'));
    }
}
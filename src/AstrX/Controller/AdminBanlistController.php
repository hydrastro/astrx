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
            $this->processForm($prgToken);
            $editId = $this->request->query()->get('edit');
            $qs     = is_string($editId) ? '?edit=' . $editId : '';
            Response::redirect($this->request->uri()->path() . $qs)
                ->send()->drainTo($this->collector);
            exit;
        }

        // Edit mode: ?edit=<id>
        $editId  = (int) ($this->request->query()->get('edit') ?? 0);
        $editing = false;
        if ($editId > 0) {
            $r = $this->banlist->findById($editId);
            $r->drainTo($this->collector);
            if ($r->isOk() && $r->unwrap() !== null) {
                $row     = $r->unwrap();
                $editing = true;
                $this->ctx->set('e_id',     $row['id']);
                $this->ctx->set('e_reason', $row['reason']);
                $this->ctx->set('e_route',  (int) $row['ban_route']);
                $this->ctx->set('e_end',    $row['end'] ?? '');
                $this->ctx->set('e_active', (bool) $row['active']);
                // Display the ban's target value for context
                $this->ctx->set('e_value',
                                $row['cidr'] ?? $row['email'] ?? ($row['user_id'] ? strtolower(bin2hex($row['user_id'])) : '?')
                );
            }
        }

        $listResult = $this->banlist->listAll();
        $listResult->drainTo($this->collector);

        // Build routes with penalty schedule
        $rawRoutes = $this->banlist->getRoutes();
        $routes    = $this->buildRoutes($rawRoutes);

        $csrfToken = $this->csrf->generate(self::FORM);
        $prgId     = $this->prg->createId($this->request->uri()->path());

        $this->ctx->set('has_editing', $editing);
        $this->ctx->set('csrf_token',  $csrfToken);
        $this->ctx->set('prg_id',      $prgId);
        $this->ctx->set('ban_list',    $listResult->isOk() ? $listResult->unwrap() : []);
        $this->ctx->set('ban_routes',  $routes);
        $this->setI18n();
        return $this->ok();
    }

    // -------------------------------------------------------------------------

    private function processForm(string $prgToken): void
    {
        $posted     = $this->prg->pull($prgToken) ?? [];
        $csrfResult = $this->csrf->verify(self::FORM, (string) ($posted['_csrf'] ?? ''));
        if (!$csrfResult->isOk()) {
            $csrfResult->drainTo($this->collector);
            return;
        }

        $action = (string) ($posted['action']  ?? '');
        $type   = (string) ($posted['type']    ?? '');
        $value  = trim((string) ($posted['value']  ?? ''));
        $reason = trim((string) ($posted['reason'] ?? ''));
        $route  = (int)    ($posted['route']   ?? BanlistRepository::ROUTE_PERMANENT);
        $end    = ($posted['end'] ?? '') !== '' ? (string) $posted['end'] : null;
        $banId  = (int)    ($posted['ban_id']  ?? 0);
        $active = !empty($posted['active']);

        switch ($action) {
            case 'ban':
                if ($value === '' || $reason === '') { return; }
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

            case 'update':
                if ($banId === 0 || $reason === '') { return; }
                $r = $this->banlist->updateBan($banId, $reason, $route, $end, $active);
                $r->drainTo($this->collector);
                if ($r->isOk()) { $this->flash->set('success', $this->t->t('admin.banlist.updated')); }
                break;

            case 'activate':
                $this->banlist->setActive($banId, true)->drainTo($this->collector);
                break;

            case 'deactivate':
                $this->banlist->setActive($banId, false)->drainTo($this->collector);
                break;

            case 'delete':
                $this->banlist->delete($banId)->drainTo($this->collector);
                $this->flash->set('success', $this->t->t('admin.banlist.deleted'));
                break;
        }
    }

    // -------------------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    private function buildRoutes(array $rawRoutes): array
    {
        $routes = [];
        foreach (BanlistRepository::routeNames() as $routeId => $routeName) {
            $label = match ($routeId) {
                BanlistRepository::ROUTE_PERMANENT    => $this->t->t('admin.banlist.route_permanent'),
                BanlistRepository::ROUTE_BAD_COMMENT  => $this->t->t('admin.banlist.route_bad_comment'),
                BanlistRepository::ROUTE_FAILED_LOGIN => $this->t->t('admin.banlist.route_failed_login'),
                default => $routeName,
            };
            $rounds = [];
            foreach ($rawRoutes[$routeId] ?? [] as $round => $cfg) {
                if (empty($cfg['enabled'])) { continue; }
                $rounds[] = [
                    'round'      => $round,
                    'penalty'    => $cfg['penalty'] === 0
                        ? $this->t->t('admin.banlist.permanent')
                        : $this->fmt((int) $cfg['penalty']),
                    'max_tries'  => $cfg['max_tries'] === 0
                        ? '∞'
                        : (string) $cfg['max_tries'],
                    'check_time' => $cfg['check_time'] === 0
                        ? '—'
                        : $this->fmt((int) $cfg['check_time']),
                ];
            }
            $routes[] = ['value' => $routeId, 'label' => $label, 'rounds' => $rounds];
        }
        return $routes;
    }

    private function fmt(int $s): string
    {
        if ($s === 0) { return '0s'; }
        $out = [];
        $r   = $s;
        foreach ([['y',31536000],['mo',2592000],['w',604800],['d',86400],
                  ['h',3600],['m',60],['s',1]] as [$u, $d]) {
            if ($r >= $d) { $v = intdiv($r, $d); $out[] = $v . $u; $r -= $v * $d; }
        }
        return implode(' ', array_slice($out, 0, 2));
    }

    private function setI18n(): void
    {
        $this->ctx->set('admin_banlist_heading', $this->t->t('admin.nav.banlist'));
        $this->ctx->set('label_type',      $this->t->t('admin.field.type'));
        $this->ctx->set('label_value',     $this->t->t('admin.banlist.value'));
        $this->ctx->set('label_reason',    $this->t->t('admin.field.reason'));
        $this->ctx->set('label_route',     $this->t->t('admin.banlist.route'));
        $this->ctx->set('label_end',       $this->t->t('admin.banlist.end'));
        $this->ctx->set('label_active',    $this->t->t('admin.field.active'));
        $this->ctx->set('label_id',        $this->t->t('admin.field.id'));
        $this->ctx->set('label_actions',   $this->t->t('admin.field.actions'));
        $this->ctx->set('label_ip_hint',   $this->t->t('admin.banlist.ip_hint'));
        $this->ctx->set('label_round',     $this->t->t('admin.banlist.round'));
        $this->ctx->set('label_penalty',   $this->t->t('admin.banlist.penalty'));
        $this->ctx->set('label_max_tries', $this->t->t('admin.banlist.max_tries'));
        $this->ctx->set('label_check_win', $this->t->t('admin.banlist.check_win'));
        $this->ctx->set('btn_ban',         $this->t->t('admin.btn.ban'));
        $this->ctx->set('btn_update',      $this->t->t('admin.btn.update'));
        $this->ctx->set('btn_edit',        $this->t->t('admin.btn.edit'));
        $this->ctx->set('btn_delete',      $this->t->t('admin.btn.delete'));
        $this->ctx->set('btn_cancel',      $this->t->t('admin.btn.cancel'));
        $this->ctx->set('btn_activate',    $this->t->t('admin.btn.activate'));
        $this->ctx->set('btn_deactivate',  $this->t->t('admin.btn.deactivate'));
        $this->ctx->set('type_ip',         $this->t->t('admin.banlist.type_ip'));
        $this->ctx->set('type_email',      $this->t->t('admin.banlist.type_email'));
        $this->ctx->set('type_user',       $this->t->t('admin.banlist.type_user'));
    }
}
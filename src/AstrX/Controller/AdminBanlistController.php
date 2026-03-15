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
            Response::redirect($this->request->uri()->path())
                ->send()->drainTo($this->collector);
            exit;
        }

        $listResult = $this->banlist->listAll();
        $listResult->drainTo($this->collector);

        // Build route list with penalty rounds from config
        $rawRoutes = $this->banlist->getRoutes();
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
                    'penalty'    => $cfg['penalty'] === 0 ? 'permanent' : $this->formatSeconds((int) $cfg['penalty']),
                    'max_tries'  => (int) $cfg['max_tries'],
                    'check_time' => $cfg['check_time'] === 0 ? '—' : $this->formatSeconds((int) $cfg['check_time']),
                ];
            }
            $routes[] = ['value' => $routeId, 'label' => $label, 'rounds' => $rounds];
        }

        $csrfToken = $this->csrf->generate(self::FORM);
        $prgId     = $this->prg->createId($this->request->uri()->path());

        $this->ctx->set('csrf_token',  $csrfToken);
        $this->ctx->set('prg_id',      $prgId);
        $this->ctx->set('ban_list',    $listResult->isOk() ? $listResult->unwrap() : []);
        $this->ctx->set('ban_routes',  $routes);
        $this->setI18n();
        return $this->ok();
    }

    private function formatSeconds(int $s): string
    {
        if ($s === 0) { return 'permanent'; }
        $out = []; $r = $s;
        foreach ([['y',31536000],['mo',2592000],['w',604800],['d',86400],['h',3600],['m',60],['s',1]] as [$u,$d]) {
            if ($r >= $d) { $v = intdiv($r,$d); $out[] = $v.$u; $r -= $v*$d; }
        }
        return implode(' ', array_slice($out, 0, 2));
    }

    private function processForm(string $prgToken): void
    {
        $posted = $this->prg->pull($prgToken) ?? [];
        $csrfResult = $this->csrf->verify(self::FORM, (string) ($posted['_csrf'] ?? ''));
        if (!$csrfResult->isOk()) {
            $csrfResult->drainTo($this->collector);
            return;
        }

        $action  = (string) ($posted['action']  ?? '');
        $type    = (string) ($posted['type']    ?? '');
        $value   = trim((string) ($posted['value']  ?? ''));
        $reason  = trim((string) ($posted['reason'] ?? ''));
        $route   = (int)    ($posted['route']   ?? BanlistRepository::ROUTE_PERMANENT);
        $end     = ($posted['end'] ?? '') !== '' ? (string) $posted['end'] : null;
        $banId   = (int)    ($posted['ban_id']  ?? 0);

        switch ($action) {
            case 'ban':
                if ($value === '' || $reason === '') {
                    return;
                }
                $r = match ($type) {
                    'ip'    => $this->banlist->banCidr($value, $reason, $route, $end),
                    'email' => $this->banlist->banEmail($value, $reason, $route, $end),
                    'user'  => $this->banlist->banUser($value, $reason, $route, $end),
                    default => null,
                };
                if ($r !== null) {
                    $r->drainTo($this->collector);
                    if ($r->isOk()) {
                        $this->flash->set('success', $this->t->t('admin.banlist.banned'));
                    }
                }
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

    private function setI18n(): void
    {
        $this->ctx->set('admin_banlist_heading', $this->t->t('admin.nav.banlist'));
        $this->ctx->set('label_type',     $this->t->t('admin.field.type'));
        $this->ctx->set('label_value',    $this->t->t('admin.banlist.value'));
        $this->ctx->set('label_reason',   $this->t->t('admin.field.reason'));
        $this->ctx->set('label_route',    $this->t->t('admin.banlist.route'));
        $this->ctx->set('label_end',      $this->t->t('admin.banlist.end'));
        $this->ctx->set('label_active',   $this->t->t('admin.field.active'));
        $this->ctx->set('label_actions',  $this->t->t('admin.field.actions'));
        $this->ctx->set('label_ip_hint',  $this->t->t('admin.banlist.ip_hint'));
        $this->ctx->set('btn_ban',        $this->t->t('admin.btn.ban'));
        $this->ctx->set('btn_delete',     $this->t->t('admin.btn.delete'));
        $this->ctx->set('btn_activate',   $this->t->t('admin.btn.activate'));
        $this->ctx->set('btn_deactivate', $this->t->t('admin.btn.deactivate'));
        $this->ctx->set('type_ip',        $this->t->t('admin.banlist.type_ip'));
        $this->ctx->set('type_email',     $this->t->t('admin.banlist.type_email'));
        $this->ctx->set('type_user',      $this->t->t('admin.banlist.type_user'));
    }
}
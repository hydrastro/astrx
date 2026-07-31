<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Csrf\CsrfHandler;
use AstrX\Http\Request;
use AstrX\I18n\Translator;
use AstrX\Invite\InviteService;
use AstrX\Page\Page;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;
use AstrX\Session\FlashBag;
use AstrX\Session\PrgHandler;
use AstrX\Template\DefaultTemplateContext;
use AstrX\User\UserSession;
use function AstrX\Support\langDir;

/**
 * Admin — invitations: a no-JavaScript page to mint one-time invite codes for
 * invite-only registration and to revoke unused ones. PRG + CSRF like the other
 * admin editors.
 *
 * Entry is gated on ADMIN_ACCESS (a MOD may view), but minting/revoking codes is
 * re-gated on ADMIN_CONFIG_ACCESS — issuing an invite grants site access, so a
 * view-only moderator must not be able to mint them. Mirrors AdminContent
 * controller's ADMIN_PAGES re-gate under a weaker entry gate.
 */
final class AdminInvitesController extends AbstractController
{
    private const string FORM = 'admin_invites';

    /** Upper bound on codes minted per request; also drives the number input. */
    private const int MAX_BATCH = 50;

    public function __construct(
        DiagnosticsCollector                    $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request                $request,
        private readonly Gate                   $gate,
        private readonly Translator             $t,
        private readonly UrlGenerator           $urlGen,
        private readonly InviteService          $service,
        private readonly CsrfHandler            $csrf,
        private readonly PrgHandler             $prg,
        private readonly FlashBag               $flash,
        private readonly UserSession            $session,
        private readonly Page                   $page,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        $this->t->loadDomain(langDir(), 'Invite');

        // Invite codes ARE the registration access-control on an invite-only
        // deployment, so the whole page (not just mint/revoke) requires
        // ADMIN_CONFIG_ACCESS — a view-only MOD must not be able to read and
        // redistribute unused codes (R8 review MEDIUM-2).
        if ($this->gate->cannot(Permission::ADMIN_CONFIG_ACCESS)) {
            http_response_code(403);
            $this->ctx->set('admin_forbidden', true);
            $this->ctx->set('forbidden_message', $this->t->t('admin.forbidden'));
            return $this->ok();
        }

        $resolvedUrlId = $this->page->i18n
            ? $this->t->t($this->page->urlId, fallback: $this->page->urlId)
            : $this->page->urlId;
        $selfUrl = $this->urlGen->toPage($resolvedUrlId);

        $this->handlePrgPost($this->request, $this->prg, $selfUrl, fn(string $tok): string => $this->processForm($tok));
        $this->buildContext($selfUrl);
        return $this->ok();
    }

    private function processForm(string $prgToken): string
    {
        $posted     = $this->prg->pull($prgToken) ?? [];
        $csrfResult = $this->csrf->verify(self::FORM, self::mStr($posted, '_csrf', ''));
        if (!$csrfResult->isOk()) {
            $csrfResult->drainTo($this->collector);
            return '';
        }

        return match (self::mStr($posted, 'action', '')) {
            'create' => $this->create($posted),
            'revoke' => $this->revoke($posted),
            default  => '',
        };
    }

    /** @param array<string,mixed> $posted */
    private function create(array $posted): string
    {
        // Re-gate: minting an invite hands out site access. Entry is ADMIN_ACCESS
        // (a MOD may see the list), but only ADMIN_CONFIG_ACCESS may issue codes.
        if ($this->gate->cannot(Permission::ADMIN_CONFIG_ACCESS)) {
            $this->flash->set('error', $this->t->t('admin.forbidden'));
            return '';
        }

        $count = self::mInt($posted, 'count', 1);
        $count = max(1, min(self::MAX_BATCH, $count));
        $note  = trim(self::mStr($posted, 'note', ''));
        $admin = $this->session->userId();

        $r = $this->service->generateCodes($count, $note, $admin !== '' ? $admin : null)
            ->drainTo($this->collector);
        if (!$r->isOk()) {
            $this->flash->set('error', $this->t->t('invite.admin.create_failed'));
            return '';
        }
        $this->flash->set('success', $this->t->t('invite.admin.created'));
        return '';
    }

    /** @param array<string,mixed> $posted */
    private function revoke(array $posted): string
    {
        if ($this->gate->cannot(Permission::ADMIN_CONFIG_ACCESS)) {
            $this->flash->set('error', $this->t->t('admin.forbidden'));
            return '';
        }

        $id = self::mInt($posted, 'id', 0);
        if ($id > 0) {
            $r = $this->service->revoke($id)->drainTo($this->collector);
            if ($r->isOk() && $r->unwrap() === true) {
                $this->flash->set('success', $this->t->t('invite.admin.revoked'));
            } else {
                $this->flash->set('error', $this->t->t('invite.admin.revoke_failed'));
            }
        }
        return '';
    }

    private function buildContext(string $selfUrl): void
    {
        $r    = $this->service->list()->drainTo($this->collector);
        $rows = $r->isOk() ? $r->unwrap() : [];
        $list = [];
        foreach ($rows as $row) {
            $used   = $row['status'] === 'used';
            $list[] = [
                'id'           => $row['id'],
                'code'         => $row['code'],
                'note'         => $row['note'],
                'created_at'   => $row['created_at'],
                'used_at'      => $row['used_at'] ?? '',
                'is_used'      => $used,
                'status_label' => $used
                    ? $this->t->t('invite.status.used')
                    : $this->t->t('invite.status.available'),
            ];
        }
        $this->ctx->set('invites',     $list);
        $this->ctx->set('has_invites', $list !== []);
        $this->ctx->set('count_default', 5);
        $this->ctx->set('count_max',     self::MAX_BATCH);

        $this->ctx->set('form_action', $selfUrl);
        $this->ctx->set('csrf_token',  $this->csrf->generate(self::FORM));
        $this->ctx->set('prg_id',      $this->prg->createId($selfUrl));

        $this->setLabels();
    }

    private function setLabels(): void
    {
        foreach ([
            'lbl_heading'       => 'invite.admin.heading',
            'lbl_intro'         => 'invite.admin.intro',
            'lbl_generate'      => 'invite.admin.generate',
            'lbl_count'         => 'invite.admin.count',
            'lbl_count_hint'    => 'invite.admin.count_hint',
            'lbl_note'          => 'invite.admin.note',
            'lbl_note_hint'     => 'invite.admin.note_hint',
            'lbl_create'        => 'invite.admin.create',
            'lbl_existing'      => 'invite.admin.existing',
            'lbl_none'          => 'invite.admin.none',
            'lbl_code'          => 'invite.admin.code',
            'lbl_status'        => 'invite.admin.status',
            'lbl_created'       => 'invite.admin.created_at',
            'lbl_revoke'        => 'invite.admin.revoke',
        ] as $ctxKey => $tKey) {
            $this->ctx->set($ctxKey, $this->t->t($tKey));
        }
    }
}

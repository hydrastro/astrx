<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Admin\AuditLogger;
use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Chat\ChatFilterService;
use AstrX\Csrf\CsrfHandler;
use AstrX\Http\Request;
use AstrX\Http\Response;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;
use AstrX\Session\FlashBag;
use AstrX\Session\PrgHandler;
use AstrX\Template\DefaultTemplateContext;
use function AstrX\Support\langDir;

/**
 * Admin management for the managed word/link filters (page file_name
 * `admin_chat_filters`, template=1). Gated ADMIN_CONFIG_CHAT — the same
 * permission as the chat configuration page it links back to.
 *
 * A deliberately simple CRUD: list the filters, add one (pattern + kind +
 * action + apply-to-mods), delete one. To change a filter, delete and re-add —
 * there is no in-place edit, which keeps the surface small and the intent clear.
 * The same PRG + CSRF flow as every other admin form.
 */
final class AdminChatFiltersController extends AbstractController
{
    private const FORM = 'admin_chat_filters';

    public function __construct(
        DiagnosticsCollector                    $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request                $request,
        private readonly ChatFilterService      $filters,
        private readonly Gate                   $gate,
        private readonly CsrfHandler            $csrf,
        private readonly PrgHandler             $prg,
        private readonly FlashBag               $flash,
        private readonly UrlGenerator           $urlGen,
        private readonly Translator             $t,
        private readonly AuditLogger            $audit,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        $this->t->loadDomain(langDir(), 'Chat');

        if ($this->gate->cannot(Permission::ADMIN_CONFIG_CHAT)) {
            http_response_code(403);
            $this->ctx->set('forbidden',         true);
            $this->ctx->set('forbidden_message', $this->t->t('chat.filter.forbidden'));
            return $this->ok();
        }

        $prgToken = $this->request->query()->get($this->prg->tokenQueryKey());
        if (is_string($prgToken) && $prgToken !== '') {
            $this->processForm($prgToken);
            Response::redirect($this->selfUrl())->send()->drainTo($this->collector);
            exit;
        }

        $this->buildContext();
        return $this->ok();
    }

    // -------------------------------------------------------------------------

    private function processForm(string $prgToken): void
    {
        $posted = $this->prg->pull($prgToken) ?? [];

        $csrf = $this->csrf->verify(self::FORM, self::mStr($posted, '_csrf', ''));
        if (!$csrf->isOk()) {
            $csrf->drainTo($this->collector);
            return;
        }

        switch (self::mStr($posted, 'action', '')) {
            case 'add':
                $pattern = trim(self::mStr($posted, 'pattern', ''));
                if ($pattern === '') {
                    $this->flash->set('error', $this->t->t('chat.filter.err_empty'));
                    break;
                }
                $kind   = match (self::mStr($posted, 'kind', 'word')) {
                    'link'  => ChatFilterService::KIND_LINK,
                    'nick'  => ChatFilterService::KIND_NICK,
                    default => ChatFilterService::KIND_WORD,
                };
                $action = self::mStr($posted, 'act', 'block') === 'kick'
                    ? ChatFilterService::ACTION_KICK : ChatFilterService::ACTION_BLOCK;
                $mods   = self::mBool($posted, 'apply_to_mods');
                $r = $this->filters->add($pattern, $kind, $action, $mods);
                $r->drainTo($this->collector);
                if ($r->isOk()) {
                    $this->flash->set('success', $this->t->t('chat.filter.added'));
                    $this->audit->log('chatfilter.add', 'chatfilter')->drainTo($this->collector);
                }
                break;

            case 'delete':
                $id = self::mInt($posted, 'filter_id', 0);
                $r  = $this->filters->remove($id);
                $r->drainTo($this->collector);
                if ($r->isOk()) {
                    $this->flash->set('success', $this->t->t('chat.filter.deleted'));
                    $this->audit->log('chatfilter.delete', "chatfilter:{$id}")->drainTo($this->collector);
                }
                break;
        }
    }

    private function buildContext(): void
    {
        $this->ctx->set('prg_id',     $this->prg->createId($this->selfUrl()));
        $this->ctx->set('csrf_token', $this->csrf->generate(self::FORM));

        $listResult = $this->filters->all();
        $listResult->drainTo($this->collector);
        $rows = $listResult->isOk() ? $listResult->unwrap() : [];

        $yes  = $this->t->t('chat.filter.yes');
        $no   = $this->t->t('chat.filter.no');
        $items = [];
        foreach ($rows as $row) {
            $id = self::mInt($row, 'id', 0);
            if ($id <= 0) {
                continue;
            }
            $items[] = [
                'id'      => $id,
                'pattern' => self::mStr($row, 'pattern', ''),
                'kind'    => match (self::mInt($row, 'kind', 0)) {
                    ChatFilterService::KIND_LINK => $this->t->t('chat.filter.kind_link'),
                    ChatFilterService::KIND_NICK => $this->t->t('chat.filter.kind_nick'),
                    default                      => $this->t->t('chat.filter.kind_word'),
                },
                'action'  => self::mInt($row, 'action', 0) === ChatFilterService::ACTION_KICK
                    ? $this->t->t('chat.filter.action_kick')
                    : $this->t->t('chat.filter.action_block'),
                'mods'    => self::mInt($row, 'apply_to_mods', 0) === 1 ? $yes : $no,
            ];
        }

        $this->ctx->set('filters',     $items);
        $this->ctx->set('has_filters', $items !== []);
        $this->ctx->set('config_url',  $this->urlGen->toPage($this->t->t('WORDING_ADMIN_CONFIG_CHAT')));

        foreach ([
            'filter_heading'       => 'chat.filter.heading',
            'filter_intro'         => 'chat.filter.intro',
            'filter_none'          => 'chat.filter.none',
            'col_pattern'          => 'chat.filter.col_pattern',
            'col_kind'             => 'chat.filter.col_kind',
            'col_action'           => 'chat.filter.col_action',
            'col_mods'             => 'chat.filter.col_mods',
            'col_manage'           => 'chat.filter.col_manage',
            'filter_delete'        => 'chat.filter.delete',
            'filter_add_heading'   => 'chat.filter.add_heading',
            'filter_pattern_ph'    => 'chat.filter.pattern_ph',
            'filter_kind_word'     => 'chat.filter.kind_word',
            'filter_kind_link'     => 'chat.filter.kind_link',
            'filter_kind_nick'     => 'chat.filter.kind_nick',
            'filter_action_block'  => 'chat.filter.action_block',
            'filter_action_kick'   => 'chat.filter.action_kick',
            'filter_apply_to_mods' => 'chat.filter.apply_to_mods',
            'filter_add'           => 'chat.filter.add',
            'filter_config_link'   => 'chat.filter.config_link',
        ] as $ctxKey => $tKey) {
            $this->ctx->set($ctxKey, $this->t->t($tKey));
        }
    }

    private function selfUrl(): string
    {
        return $this->urlGen->toPage($this->t->t('WORDING_ADMIN_CHAT_FILTERS'));
    }
}

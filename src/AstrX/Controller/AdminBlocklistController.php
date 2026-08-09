<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Admin\AuditLogger;
use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Blocklist\BlocklistClient;
use AstrX\Csrf\CsrfHandler;
use AstrX\Http\Request;
use AstrX\Http\Response;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Session\FlashBag;
use AstrX\Session\PrgHandler;
use AstrX\Template\DefaultTemplateContext;
use function AstrX\Support\langDir;

/**
 * Blocklist editor (/admin-blocklist).
 *
 * An AstrX ADMIN page that lets an admin add abuse-blocklist entries to the two
 * write-capable astrx-suite engines and pushes them over loopback HTTP:
 *
 *   onioncrawler  POST /blocklist   (kind = host | keyword)
 *   torrentds     POST /api/block   (kind = infohash | keyword)
 *
 * Gated with the same Permission::ADMIN_ACCESS the admin section root uses, and
 * every write goes through the same CSRF-protected PRG form the sibling
 * admin-suite page uses. The engine admin TOKENS come from server-side config
 * only ({@see \AstrX\Blocklist\BlocklistConfig}); they are never placed in the
 * template context, rendered or logged. All HTTP, the token auth shapes and the
 * bounded/size-capped transport live in {@see BlocklistClient}; this controller
 * only gates, translates, validates the kind/value, runs the PRG form and reports
 * each target's outcome as a flash. A down engine degrades to a friendly
 * "unreachable" flash — it can never 500 the page.
 *
 * Seeded with file_name 'admin_blocklist' as a child of the admin root, so the
 * reflection router resolves it to THIS class and the template resolves to
 * resources/template/admin/admin_blocklist.html.
 */
final class AdminBlocklistController extends AbstractController
{
    private const FORM = 'admin_blocklist';

    /** onioncrawler /blocklist accepts these kinds. */
    private const array ONION_KINDS = ['host', 'keyword'];

    /** torrentds /api/block accepts these kinds. */
    private const array TORRENT_KINDS = ['infohash', 'keyword'];

    public function __construct(
        DiagnosticsCollector                    $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request                $request,
        private readonly Gate                   $gate,
        private readonly Translator             $t,
        private readonly FlashBag               $flash,
        private readonly CsrfHandler            $csrf,
        private readonly PrgHandler             $prg,
        private readonly AuditLogger            $audit,
        private readonly BlocklistClient        $client,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        $this->t->loadDomain(langDir(), 'Blocklist');

        if ($this->gate->cannot(Permission::ADMIN_ACCESS)) {
            http_response_code(403);
            $this->ctx->set('admin_forbidden', true);
            $this->ctx->set('forbidden_message', $this->t->t('admin.forbidden'));
            return $this->ok();
        }

        $selfUrl  = $this->request->uri()->path();
        $prgToken = $this->request->query()->get($this->prg->tokenQueryKey());
        if (is_string($prgToken) && $prgToken !== '') {
            $this->processPost($this->prg->pull($prgToken) ?? []);
            Response::redirect($selfUrl)->send()->drainTo($this->collector);
            exit;
        }

        $this->ctx->set('onion_kinds',   $this->kindOptions(self::ONION_KINDS));
        $this->ctx->set('torrent_kinds', $this->kindOptions(self::TORRENT_KINDS));
        $this->setLabels();
        $this->ctx->set('prg_id',     $this->prg->createId($selfUrl));
        $this->ctx->set('csrf_token', $this->csrf->generate(self::FORM));

        return $this->ok();
    }

    /** @param array<string,mixed> $posted */
    private function processPost(array $posted): void
    {
        $csrfResult = $this->csrf->verify(self::FORM, self::mStr($posted, '_csrf', ''));
        if (!$csrfResult->isOk()) {
            $csrfResult->drainTo($this->collector);
            return;
        }

        $action = self::mStr($posted, 'action', '');
        $kind   = self::mStr($posted, 'kind', '');
        $value  = self::mNullableTrimmed($posted, 'value');

        if ($action === 'block_onion') {
            $this->doBlock('onion', 'onioncrawler', self::ONION_KINDS, $kind, $value);
        } elseif ($action === 'block_torrent') {
            $this->doBlock('torrent', 'torrentds', self::TORRENT_KINDS, $kind, $value);
        }
    }

    /**
     * Validate, push to one target, and record the outcome as a target-labelled
     * flash + an audit-log entry. The submitted VALUE is never echoed back or
     * logged; only the (enum) kind and the resulting status are audited.
     *
     * @param list<string> $allowedKinds
     */
    private function doBlock(string $target, string $resource, array $allowedKinds, string $kind, ?string $value): void
    {
        $targetLabel = $this->t->t('blocklist.target.' . $target);

        if ($value === null) {
            $this->flash->set('error', $this->t->t('blocklist.empty', ['target' => $targetLabel]));
            return;
        }
        if (!in_array($kind, $allowedKinds, true)) {
            $this->flash->set('error', $this->t->t('blocklist.invalid_kind', ['target' => $targetLabel]));
            return;
        }
        if ($kind === 'infohash' && !self::isInfohash($value)) {
            $this->flash->set('error', $this->t->t('blocklist.invalid_infohash', ['target' => $targetLabel]));
            return;
        }

        $res = $target === 'onion'
            ? $this->client->blockOnion($kind, $value)
            : $this->client->blockTorrent($kind, $value);

        [$type, $key] = match ($res['status']) {
            'added'        => ['success', 'blocklist.added'],
            'duplicate'    => ['info',    'blocklist.duplicate'],
            'forbidden'    => ['error',   'blocklist.forbidden'],
            'invalid'      => ['error',   'blocklist.invalid'],
            'empty'        => ['error',   'blocklist.empty'],
            'unconfigured' => ['error',   'blocklist.unconfigured'],
            'unreachable'  => ['error',   'blocklist.unreachable'],
            default        => ['error',   'blocklist.error'],
        };
        $this->flash->set($type, $this->t->t($key, ['target' => $targetLabel]));
        $this->audit->log('suite.blocklist', $resource, $kind . ':' . $res['status'])
            ->drainTo($this->collector);
    }

    /**
     * Build a kind <select> option list for a target.
     *
     * @param list<string> $kinds
     * @return list<array{value:string,label:string}>
     */
    private function kindOptions(array $kinds): array
    {
        $out = [];
        foreach ($kinds as $kind) {
            $out[] = ['value' => $kind, 'label' => $this->t->t('blocklist.kind.' . $kind)];
        }
        return $out;
    }

    /** A plausible v1 (40-hex) / v2 (64-hex) BitTorrent infohash. */
    private static function isInfohash(string $s): bool
    {
        $s = strtolower(trim($s));
        return preg_match('/^[0-9a-f]{40}$/', $s) === 1 || preg_match('/^[0-9a-f]{64}$/', $s) === 1;
    }

    private function setLabels(): void
    {
        foreach ([
            'blk_heading'       => 'blocklist.heading',
            'blk_intro'         => 'blocklist.intro',
            'onion_heading'     => 'blocklist.onion.heading',
            'onion_intro'       => 'blocklist.onion.intro',
            'torrent_heading'   => 'blocklist.torrent.heading',
            'torrent_intro'     => 'blocklist.torrent.intro',
            'lbl_kind'          => 'blocklist.kind_label',
            'lbl_value'         => 'blocklist.value_label',
            'lbl_submit'        => 'blocklist.submit',
        ] as $ctxKey => $tKey) {
            $this->ctx->set($ctxKey, $this->t->t($tKey));
        }
    }
}

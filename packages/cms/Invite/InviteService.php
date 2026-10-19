<?php
declare(strict_types=1);

namespace AstrX\Invite;

use AstrX\Config\Config;
use AstrX\Result\Result;

/**
 * Invite-module logic on top of {@see InviteRepository}.
 *
 * Minting codes (admin) and listing/revoking them live here; the single-use
 * validation + consumption of a code at registration time is driven by
 * RegisterController (it needs the freshly-created user id for `used_by`), which
 * calls the repository directly.
 *
 * `requireInvite()` reads the `require_invite` flag from the 'Invite' section of
 * User.config.php (the same file that carries `require_email`). It is read via
 * getConfigBool — the register flow always constructs UserService, which loads
 * User.config.php, so the flag is available there; off (false) is the default.
 *
 * @phpstan-type InviteRow array{id:int,code:string,note:string,created_at:string,used_at:?string,status:string}
 */
final class InviteService
{
    /** Hard ceiling on how many codes one request may mint. */
    private const int MAX_BATCH = 50;

    public function __construct(
        private readonly InviteRepository $repo,
        private readonly Config           $config,
    ) {}

    /**
     * Mint $n fresh single-use invite codes (each bin2hex(random_bytes(16)) —
     * 128 bits, unguessable). $n is clamped to 1..MAX_BATCH. Returns the codes
     * that were created.
     *
     * @return Result<list<string>>
     */
    public function generateCodes(int $n, string $note, ?string $adminHexId): Result
    {
        $n     = max(1, min(self::MAX_BATCH, $n));
        $codes = [];
        for ($i = 0; $i < $n; $i++) {
            $code = bin2hex(random_bytes(16));
            $r    = $this->repo->create($code, $note, $adminHexId);
            if (!$r->isOk()) {
                return Result::err($codes, $r->diagnostics());
            }
            $codes[] = $code;
        }
        return Result::ok($codes);
    }

    /**
     * Every invite for the admin list.
     *
     * @return Result<list<InviteRow>>
     */
    public function list(): Result
    {
        return $this->repo->all();
    }

    /** @return Result<bool> */
    public function revoke(int $id): Result
    {
        return $this->repo->revoke($id);
    }

    /** Whether registration is currently gated behind an invite code. */
    public function requireInvite(): bool
    {
        return $this->config->getConfigBool('Invite', 'require_invite', false);
    }
}

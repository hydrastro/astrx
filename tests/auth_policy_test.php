<?php
declare(strict_types=1);

/**
 * Standalone Gate/Policy test — NO AstrX bootstrap, no database.
 *
 * Covers the "UserPolicy never executes" defect and the structure put in to
 * stop it recurring:
 *
 *   1. A user resource is a UserResource, and UserResource::fromRow() fails
 *      CLOSED (unknown group ⇒ ADMIN) instead of open.
 *   2. Gate routes UserResource to UserPolicy, so the "mods cannot edit
 *      admins / peers" rule actually runs.
 *   3. The original defect: a `(object) $userRow` \stdClass resource asking for
 *      a user.* permission no longer sails through as "no opinion" — Gate denies
 *      it, because \stdClass is CommentPolicy's and CommentPolicy does not
 *      govern user.*.
 *   4. USER_PROMOTE — the permission the old match had simply forgotten — is
 *      governed and enforced.
 *   5. The loudness invariant: for every registered policy, every permission in
 *      governs() is handled by evaluate(), and nothing else is ever routed to it.
 *   6. A resource class with no registered policy is denied, not allowed.
 *
 * Run:  php tests/auth_policy_test.php
 */

namespace AstrX\Config {
    // Minimal stub so #[InjectConfig] resolves without the framework.
    if (!\class_exists(InjectConfig::class)) {
        #[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
        final class InjectConfig
        {
            public function __construct(public readonly string $key) {}
        }
    }
}

namespace {

    use AstrX\Auth\Gate;
    use AstrX\Auth\GateBootstrapper;
    use AstrX\Auth\Permission;
    use AstrX\Auth\Policy\ChatPolicy;
    use AstrX\Auth\Policy\CommentPolicy;
    use AstrX\Auth\Policy\NewsPolicy;
    use AstrX\Auth\Policy\UserPolicy;
    use AstrX\Auth\PolicyInterface;
    use AstrX\Auth\PolicyVerdict;
    use AstrX\User\UserGroup;
    use AstrX\User\UserResource;
    use AstrX\User\UserSession;

    $CLASS_DIR = dirname(__DIR__) . '/src/AstrX/';
    spl_autoload_register(static function (string $class) use ($CLASS_DIR): void {
        if (strncmp($class, 'AstrX\\', 6) !== 0) { return; }
        $file = $CLASS_DIR . str_replace('\\', '/', substr($class, 6)) . '.php';
        if (is_file($file)) { require_once $file; }
    });

    $PASS = 0;
    $FAIL = 0;
    function check(string $label, bool $cond): void
    {
        global $PASS, $FAIL;
        if ($cond) { $PASS++; echo "  ok   - $label\n"; }
        else       { $FAIL++; echo "  FAIL - $label\n"; }
    }

    /** Shipped Auth.config.php grants, plus the one-config-change-away MOD set. */
    function gateFor(UserGroup $actor, string $actorId = 'aaaa', bool $modHoldsUserAdmin = false): Gate
    {
        $_SESSION = [
            'logged_in' => true,
            'user'      => [
                'id' => $actorId, 'username' => 'actor', 'display_name' => 'Actor',
                'type' => $actor->value, 'verified' => true, 'avatar' => false,
            ],
        ];

        $gate = new Gate(new UserSession());
        $gate->setGrants([
            'ADMIN' => ['*'],
            'MOD'   => array_merge(
                ['news.view', 'comment.*', 'user.view.public', 'user.edit.own', 'admin.access'],
                // The reviewer's premise: this is ONE admin config change away.
                $modHoldsUserAdmin ? ['user.edit.any', 'user.delete.any', 'user.promote'] : [],
            ),
            'USER'  => ['user.edit.own', 'user.delete.own'],
            'GUEST' => ['news.view'],
        ]);
        (new GateBootstrapper())->registerAll($gate);

        return $gate;
    }

    echo "Gate / policy binding\n";

    // ── 1. UserResource fails closed ─────────────────────────────────────────
    check(
        'fromRow() maps a valid type',
        UserResource::fromRow(['id' => 'AB', 'type' => 2])->type === UserGroup::MOD,
    );
    check(
        'fromRow() lowercases the id',
        UserResource::fromRow(['id' => 'ABCD', 'type' => 0])->id === 'abcd',
    );
    check(
        'fromRow() with a MISSING type is ADMIN (fail closed), not USER',
        UserResource::fromRow(['id' => 'ab'])->type === UserGroup::ADMIN,
    );
    check(
        'fromRow() with a NULL type is ADMIN (fail closed)',
        UserResource::fromRow(['id' => 'ab', 'type' => null])->type === UserGroup::ADMIN,
    );
    check(
        'fromRow() with a garbage type is ADMIN (fail closed)',
        UserResource::fromRow(['id' => 'ab', 'type' => 'banana'])->type === UserGroup::ADMIN,
    );

    // ── 2/3. Routing: typed resource reaches UserPolicy; stdClass does not ───
    $adminRow = ['id' => 'ffff', 'type' => UserGroup::ADMIN->value];
    $userRow  = ['id' => 'bbbb', 'type' => UserGroup::USER->value];

    $modGate = gateFor(UserGroup::MOD, modHoldsUserAdmin: true);
    check(
        'MOD with user.edit.any CANNOT edit an ADMIN (the guard that never ran)',
        $modGate->cannot(Permission::USER_EDIT_ANY, UserResource::fromRow($adminRow)),
    );
    check(
        'MOD with user.edit.any CAN still edit an ordinary USER',
        $modGate->can(Permission::USER_EDIT_ANY, UserResource::fromRow($userRow)),
    );
    check(
        'MOD with user.delete.any CANNOT delete an ADMIN',
        $modGate->cannot(Permission::USER_DELETE_ANY, UserResource::fromRow($adminRow)),
    );
    check(
        'MOD CANNOT promote against an ADMIN target (USER_PROMOTE was unhandled before)',
        $modGate->cannot(Permission::USER_PROMOTE, UserResource::fromRow($adminRow)),
    );
    check(
        'MOD CANNOT edit a peer MOD (equal rank is still a stepping stone)',
        $modGate->cannot(
            Permission::USER_EDIT_ANY,
            UserResource::fromRow(['id' => 'cccc', 'type' => UserGroup::MOD->value]),
        ),
    );

    $selfGate = gateFor(UserGroup::MOD, actorId: 'cccc', modHoldsUserAdmin: true);
    check(
        'MOD CAN act on their own row (self is not escalation)',
        $selfGate->can(
            Permission::USER_EDIT_ANY,
            UserResource::fromRow(['id' => 'cccc', 'type' => UserGroup::MOD->value]),
        ),
    );

    $adminGate = gateFor(UserGroup::ADMIN);
    check(
        'ADMIN CAN edit another ADMIN',
        $adminGate->can(Permission::USER_EDIT_ANY, UserResource::fromRow($adminRow)),
    );

    // The regression itself: the old `(object) $row` shape.
    check(
        'REGRESSION: a \stdClass user row is DENIED for user.edit.any, not silently allowed',
        $modGate->cannot(Permission::USER_EDIT_ANY, (object) $adminRow),
    );
    check(
        'REGRESSION: a \stdClass user row is DENIED for user.promote too',
        $modGate->cannot(Permission::USER_PROMOTE, (object) $adminRow),
    );

    // Role check still comes first: no grant, no access, whatever the policy says.
    $plainMod = gateFor(UserGroup::MOD);
    check(
        'a MOD without the user.edit.any grant is denied at role level',
        $plainMod->cannot(Permission::USER_EDIT_ANY, UserResource::fromRow($userRow)),
    );

    // ── Comment resources keep working through \stdClass ─────────────────────
    $modComments = gateFor(UserGroup::MOD);
    check(
        'MOD can hide a guest comment (user_type NULL)',
        $modComments->can(
            Permission::COMMENT_HIDE_ANY,
            (object) ['user_id' => null, 'user_type' => null],
        ),
    );
    check(
        'MOD cannot hide an ADMIN comment',
        $modComments->cannot(
            Permission::COMMENT_HIDE_ANY,
            (object) ['user_id' => 'ffff', 'user_type' => UserGroup::ADMIN->value],
        ),
    );
    check(
        'a comment resource is DENIED for a permission CommentPolicy does not govern',
        $modComments->cannot(Permission::COMMENT_POST, (object) ['user_id' => null]),
    );

    // ── 6. Unregistered resource class ───────────────────────────────────────
    $anon = new class () {};
    check(
        'a resource class with no registered policy is denied, not allowed',
        $adminGate->cannot(Permission::NEWS_EDIT_ANY, $anon),
    );

    // ── 5. Loudness invariant, for every policy ──────────────────────────────
    echo "\ngoverns()/evaluate() consistency\n";

    $_SESSION = [
        'logged_in' => true,
        'user'      => ['id' => 'aaaa', 'username' => 'a', 'display_name' => 'a',
                        'type' => UserGroup::MOD->value, 'verified' => true, 'avatar' => false],
    ];
    $session = new UserSession();

    /** @var array<string, array{0: PolicyInterface, 1: object}> */
    $policies = [
        'UserPolicy'    => [new UserPolicy(),    UserResource::fromRow(['id' => 'bbbb', 'type' => 0])],
        'CommentPolicy' => [new CommentPolicy(), (object) ['user_id' => 'bbbb', 'user_type' => 0]],
        'ChatPolicy'    => [new ChatPolicy(),    (object) ['user_id' => 'bbbb', 'user_type' => 0]],
        'NewsPolicy'    => [new NewsPolicy(),    (object) []],
    ];

    foreach ($policies as $name => [$policy, $resource]) {
        $governed = $policy->governs();

        $unhandled = [];
        foreach ($governed as $permission) {
            try {
                $verdict = $policy->evaluate($permission, $session, $resource);
                if (!$verdict instanceof PolicyVerdict) {
                    $unhandled[] = $permission->value;
                }
            } catch (\LogicException) {
                // governs() promises a ruling this policy cannot actually make.
                $unhandled[] = $permission->value;
            }
        }
        check(
            "{$name}: every permission it claims in governs() has a rule ("
            . count($governed) . ' claimed)',
            $unhandled === [],
        );

        // And nothing outside governs() is answerable — the arm that used to be
        // `default => null` and silently meant "allow".
        $ungoverned = array_values(array_filter(
            Permission::cases(),
            static fn (Permission $p): bool => !in_array($p, $governed, true),
        ));
        $silent = [];
        foreach ($ungoverned as $permission) {
            try {
                $policy->evaluate($permission, $session, $resource);
                $silent[] = $permission->value;
            } catch (\LogicException) {
                // Correct: loud.
            }
        }
        check(
            "{$name}: no ungoverned permission gets a quiet answer",
            $silent === [],
        );
    }

    echo "\n{$PASS} passed, {$FAIL} failed\n";
    exit($FAIL === 0 ? 0 : 1);
}

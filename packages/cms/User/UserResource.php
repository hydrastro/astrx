<?php
declare(strict_types=1);

namespace AstrX\User;

/**
 * The typed resource a Gate policy sees when a check is scoped to a USER
 * account — the target of user.edit.any / user.delete.any / user.promote.
 *
 * Exists because the previous shape, a bare `(object) $row` cast, was a
 * \stdClass: Gate::findPolicy() resolves by class, \stdClass is registered to
 * CommentPolicy (anonymous comment resources use it), and CommentPolicy has no
 * arm for any user.* permission — so every user-moderation check was answered
 * by the comment policy's "no opinion" and UserPolicy never ran once. A
 * dedicated class makes that mis-binding impossible to express.
 *
 * `type` is a UserGroup, not an int|null. UserPolicy's old
 * `isset($resource->type) && …` guard failed OPEN: a resource without a `type`
 * skipped the whole "mods cannot edit admins" arm and the check was allowed.
 * A non-nullable enum removes the missing-value case from the type system;
 * fromRow() decides once, and decides closed (see below).
 */
final readonly class UserResource
{
    /**
     * @param string    $id   lowercase hex user id ('' when the row had none)
     * @param UserGroup $type the target account's group
     */
    public function __construct(
        public string    $id,
        public UserGroup $type,
    ) {}

    /**
     * Build the resource from a `user` row (UserRepository::findById() shape).
     *
     * @param array<string,mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $idRaw = $row['id'] ?? null;
        $id    = is_scalar($idRaw) ? strtolower((string) $idRaw) : '';

        $typeRaw = $row['type'] ?? null;
        $type    = UserGroup::tryFrom(
            is_int($typeRaw)
                ? $typeRaw
                // -1 is not a UserGroup value, so a non-numeric `type` lands in
                // the fail-closed branch below instead of casting to 0 (= USER).
                : (is_numeric($typeRaw) ? (int) $typeRaw : -1)
        );

        // An absent or unrecognised `type` is treated as ADMIN — the most
        // protected group — NOT as USER. Concretely: if a schema change ever
        // drops `type` from findById()'s SELECT, or the column goes NULL, the
        // old code read isset($resource->type) === false and let UserPolicy
        // abstain, so a MOD holding user.edit.any could POST
        // action=update&user_id=<admin>&password=x&hash_password=1 and take the
        // admin account over. With ADMIN as the fallback, an unreadable target
        // outranks every non-admin actor and the edit is refused instead.
        return new self($id, $type ?? UserGroup::ADMIN);
    }
}

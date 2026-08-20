<?php
declare(strict_types=1);

namespace AstrX\Support;

use AstrX\Auth\Permission;
use Attribute;

/**
 * Declares the permission a controller's PAGE requires to be entered at all.
 *
 * ContentManager reads this off the controller class before it calls
 * handle(), so a page's entry gate lives in the CODE that serves it rather
 * than only in the `page_closure` table.
 *
 * Why this exists: the framework's only admin gate used to be an ancestry walk
 * over page_closure ("is 'admin' one of my ancestors?"). That is data, and data
 * drifts — migrate_zz_content_module.sql and migrate_zz_admin_language.sql each
 * inserted the page's self row and forgot the WORDING_ADMIN parent row, so
 * `GET /en/admin-content` with no session was not seen as an admin page at all:
 * the hidden-page 404 did not fire (hidden = 0), the login redirect was skipped,
 * and dispatch reached AdminContentController::handle(), which rendered the
 * admin page shell under its own in-handler 403. A migration can add or drop a
 * closure row; it cannot add or drop this attribute.
 *
 * It does NOT replace a controller's own finer-grained checks — e.g.
 * AdminConfigSystemController still answers 403 to a MOD who holds ADMIN_ACCESS
 * but not ADMIN_CONFIG_SYSTEM. It only states the coarse "may this visitor open
 * this page at all" gate that the page tree was supposed to express.
 *
 * Lives in Support rather than Auth because Support is loaded in every run mode
 * (bootstrap, tools/*, and the compiled single-file bundle) and carries no
 * dependency of its own beyond the Permission enum.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class RequiresPermission
{
    public function __construct(public readonly Permission $permission) {}
}

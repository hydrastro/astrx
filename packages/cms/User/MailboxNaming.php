<?php
declare(strict_types=1);

namespace AstrX\User;

/**
 * How a new account's mailbox is named, for a site whose accounts come with a
 * mailbox (UserService::requireEmail()).
 *
 * A module extension point (manifest `extensions`, see docs/MODULES.md). The
 * registration form asks every INSTALLED module — mailboxes exist on the mail
 * server whether or not the module that reads them is switched on — and when
 * one says the mailbox is the username, it takes the username and does not ask
 * for a mailbox name. With no module answering, the form asks for one.
 */
interface MailboxNaming
{
    /** True when a new account's mailbox local-part is its username. */
    public function mailboxIsUsername(): bool;
}

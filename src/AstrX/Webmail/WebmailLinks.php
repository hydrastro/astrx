<?php
declare(strict_types=1);

namespace AstrX\Webmail;

use AstrX\Admin\AdminDashboardSection;
use AstrX\User\UserHomeSection;

/** The webmail's links on the user home page (the mailbox) and the admin dashboard (its IMAP settings). */
final class WebmailLinks implements UserHomeSection, AdminDashboardSection
{
    public function userHomeSections(): array
    {
        return ['webmail' => 'webmail.heading'];
    }

    public function adminSections(): array
    {
        return ['admin_config_webmail' => 'admin.nav.config_webmail'];
    }
}

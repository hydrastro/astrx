<?php
declare(strict_types=1);

/**
 * Admin section translations — en locale.
 *
 * Page URL slugs for admin pages (WORDING_ADMIN_*) are defined here
 * alongside their content strings, since this file is loaded via
 * ancestor lang loading whenever any admin page is requested.
 */
return [
    // ---- Page meta ----------------------------------------------------------
    'WORDING_ADMIN.title'            => 'Administration',
    'WORDING_ADMIN.description'      => 'Site administration panel.',
    'WORDING_ADMIN_NEWS.title'       => 'Admin — News',
    'WORDING_ADMIN_NEWS.description' => 'Manage news posts.',
    'WORDING_ADMIN_COMMENTS.title'   => 'Admin — Comments',
    'WORDING_ADMIN_COMMENTS.description' => 'Moderate comments.',
    'WORDING_ADMIN_USERS.title'      => 'Admin — Users',
    'WORDING_ADMIN_USERS.description'=> 'Manage user accounts.',
    'WORDING_ADMIN_BANLIST.title'    => 'Admin — Banlist',
    'WORDING_ADMIN_BANLIST.description' => 'Manage bans.',
    'WORDING_ADMIN_NAVBAR.title'     => 'Admin — Navbar',
    'WORDING_ADMIN_NAVBAR.description' => 'Edit the navigation bar.',
    'WORDING_ADMIN_PAGES.title'      => 'Admin — Pages',
    'WORDING_ADMIN_PAGES.description'=> 'View site pages.',
    'WORDING_ADMIN_NOTES.title'      => 'Admin — Notes',
    'WORDING_ADMIN_NOTES.description'=> 'Personal admin scratchpad.',

    // ---- Nav labels ---------------------------------------------------------
    'admin.nav.home'     => 'Dashboard',
    'admin.nav.news'     => 'News',
    'admin.nav.comments' => 'Comments',
    'admin.nav.users'    => 'Users',
    'admin.nav.banlist'  => 'Banlist',
    'admin.nav.navbar'   => 'Navbar',
    'admin.nav.pages'    => 'Pages',
    'admin.nav.notes'    => 'Notes',

    // ---- Section descriptions for home page --------------------------------
    'admin.nav.news.desc'     => 'Create and manage news posts.',
    'admin.nav.comments.desc' => 'Hide, delete, or flag comments.',
    'admin.nav.users.desc'    => 'View and manage user accounts.',
    'admin.nav.banlist.desc'  => 'Ban IPs, emails, or users.',
    'admin.nav.navbar.desc'   => 'Add or remove public navigation links.',
    'admin.nav.pages.desc'    => 'View the page structure.',
    'admin.nav.notes.desc'    => 'Personal scratchpad visible only to admins.',

    // ---- Home ---------------------------------------------------------------
    'admin.home.heading' => 'Administration',
    'admin.home.welcome' => 'Welcome to the administration panel.',

    // ---- Shared fields ------------------------------------------------------
    'admin.field.id'       => 'ID',
    'admin.field.title'    => 'Title',
    'admin.field.content'  => 'Content',
    'admin.field.hidden'   => 'Hidden',
    'admin.field.date'     => 'Date',
    'admin.field.actions'  => 'Actions',
    'admin.field.type'     => 'Type',
    'admin.field.name'     => 'Name',
    'admin.field.reason'   => 'Reason',
    'admin.field.active'   => 'Active',
    'admin.field.page'     => 'Page',
    'admin.field.user'     => 'User',
    'admin.field.verified' => 'Verified',
    'admin.field.deleted'  => 'Deleted',
    'admin.field.username'   => 'Username',
    'admin.field.flagged'  => 'Flagged',

    // ---- Buttons ------------------------------------------------------------
    'admin.btn.create'     => 'Create',
    'admin.btn.update'     => 'Save changes',
    'admin.btn.delete'     => 'Delete',
    'admin.btn.edit'       => 'Edit',
    'admin.btn.add'        => 'Add',
    'admin.btn.hide'       => 'Hide',
    'admin.btn.unhide'     => 'Unhide',
    'admin.btn.flag'       => 'Flag',
    'admin.btn.ban'        => 'Ban',
    'admin.btn.activate'   => 'Activate',
    'admin.btn.deactivate' => 'Deactivate',
    'admin.btn.promote'    => 'Update role',
    'admin.btn.cancel'        => 'Cancel',
    'admin.btn.save'       => 'Save',
    'admin.btn.clear'      => 'Clear',
    'admin.btn.toggle'     => 'Toggle',
    'admin.btn.filter'     => 'Filter',

    // ---- News ---------------------------------------------------------------
    'admin.news.created' => 'News post created.',
    'admin.news.updated' => 'News post updated.',
    'admin.news.deleted' => 'News post deleted.',

    // ---- Comments -----------------------------------------------------------
    'admin.comments.hidden'   => 'Comment hidden.',
    'admin.comments.unhidden' => 'Comment unhidden.',
    'admin.comments.deleted'  => 'Comment deleted.',
    'admin.comments.filter'   => 'Filter',

    // ---- Users --------------------------------------------------------------
    'admin.users.edit_heading' => 'Edit user',
    'admin.users.updated'          => 'User updated.',
    'admin.users.deleted'          => 'User deleted.',
    'admin.users.permission_denied'=> 'You cannot modify this account.',

    // ---- Banlist ------------------------------------------------------------
    'admin.banlist.banned'           => 'Ban added.',
    'admin.banlist.deleted'          => 'Ban removed.',
    'admin.banlist.value'            => 'IP / CIDR / Email / User ID',
    'admin.banlist.ip_hint'          => 'For IP bans: bare IP (192.168.1.5) or CIDR (10.0.0.0/8, 2001:db8::/32)',
    'admin.banlist.route'            => 'Route',
    'admin.banlist.end'              => 'Expires (YYYY-MM-DD HH:MM:SS, blank = permanent)',
    'admin.banlist.route_permanent'  => 'Permanent',
    'admin.banlist.route_bad_comment'=> 'Bad comment',
    'admin.banlist.route_failed_login' => 'Failed login',
    'admin.banlist.type_ip'          => 'IP / CIDR',
    'admin.banlist.type_email'       => 'Email',
    'admin.banlist.type_user'        => 'User ID',

    // ---- Navbar -------------------------------------------------------------
    'admin.navbar.updated'    => 'Entry updated.',
    'admin.navbar.added'         => 'Entry added.',
    'admin.navbar.deleted'       => 'Entry removed.',
    'admin.navbar.url'           => 'URL',
    'admin.navbar.sort'          => 'Sort order',
    'admin.navbar.type_internal' => 'Internal page',
    'admin.navbar.type_external' => 'External URL',

    // ---- Pages --------------------------------------------------------------
    'admin.pages.url_id'    => 'URL ID',
    'admin.pages.file_name' => 'File name',
    'admin.pages.i18n'      => 'i18n',
    'admin.pages.comments'  => 'Comments',
    'admin.pages.description' => 'Description',
    'admin.pages.note'      => 'Pages are read-only here. Edit directly in the database.',

    // ---- Notes --------------------------------------------------------------
    'admin.notes.label' => 'Notes (visible to admins only)',
    'admin.notes.saved' => 'Notes saved.',
];
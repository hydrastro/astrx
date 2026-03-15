<?php
declare(strict_types=1);

/**
 * Admin section translations — en locale.
 * Complete file — includes all keys added across all sessions.
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
    'WORDING_ADMIN_PAGES.description'=> 'Manage site pages.',
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

    // ---- Section descriptions -----------------------------------------------
    'admin.nav.news.desc'     => 'Create and manage news posts.',
    'admin.nav.comments.desc' => 'Hide, delete, or flag comments.',
    'admin.nav.users.desc'    => 'View and manage user accounts.',
    'admin.nav.banlist.desc'  => 'Ban IPs, emails, or users.',
    'admin.nav.navbar.desc'   => 'Manage all navigation bars and groups.',
    'admin.nav.pages.desc'    => 'Add, edit, or delete site pages.',
    'admin.nav.notes.desc'    => 'Personal scratchpad visible only to admins.',

    // ---- Home ---------------------------------------------------------------
    'admin.home.heading' => 'Administration',
    'admin.home.welcome' => 'Welcome to the administration panel.',

    // ---- Shared fields ------------------------------------------------------
    'admin.field.id'         => 'ID',
    'admin.field.title'      => 'Title',
    'admin.field.content'    => 'Content',
    'admin.field.hidden'     => 'Hidden',
    'admin.field.date'       => 'Date',
    'admin.field.actions'    => 'Actions',
    'admin.field.type'       => 'Type',
    'admin.field.name'       => 'Name',
    'admin.field.reason'     => 'Reason',
    'admin.field.active'     => 'Active',
    'admin.field.page'       => 'Page',
    'admin.field.user'       => 'User',
    'admin.field.username'   => 'Username',
    'admin.field.verified'   => 'Verified',
    'admin.field.deleted'    => 'Deleted',
    'admin.field.flagged'    => 'Flagged',

    // ---- Buttons ------------------------------------------------------------
    'admin.btn.create'     => 'Create',
    'admin.btn.update'     => 'Save changes',
    'admin.btn.delete'     => 'Delete',
    'admin.btn.edit'       => 'Edit',
    'admin.btn.add'        => 'Add',
    'admin.btn.cancel'     => 'Cancel',
    'admin.btn.hide'       => 'Hide',
    'admin.btn.unhide'     => 'Unhide',
    'admin.btn.flag'       => 'Flag',
    'admin.btn.ban'        => 'Ban',
    'admin.btn.activate'   => 'Activate',
    'admin.btn.deactivate' => 'Deactivate',
    'admin.btn.promote'    => 'Update role',
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
    'admin.users.edit_heading'     => 'Edit user',
    'admin.users.updated'          => 'User updated.',
    'admin.users.deleted'          => 'User deleted.',
    'admin.users.permission_denied'=> 'You cannot modify this account.',

    // ---- Banlist ------------------------------------------------------------
    'admin.banlist.banned'             => 'Ban added.',
    'admin.banlist.deleted'            => 'Ban removed.',
    'admin.banlist.value'              => 'IP / CIDR / Email / User ID',
    'admin.banlist.ip_hint'            => 'Bare IP (192.168.1.5) or CIDR (10.0.0.0/8, 2001:db8::/32)',
    'admin.banlist.route'              => 'Route',
    'admin.banlist.end'                => 'Expires (YYYY-MM-DD HH:MM:SS, blank = permanent)',
    'admin.banlist.route_permanent'    => 'Permanent',
    'admin.banlist.route_bad_comment'  => 'Bad comment',
    'admin.banlist.route_failed_login' => 'Failed login',
    'admin.banlist.type_ip'            => 'IP / CIDR',
    'admin.banlist.type_email'         => 'Email',
    'admin.banlist.type_user'          => 'User ID',

    // ---- Navbar -------------------------------------------------------------
    'admin.navbar.added'           => 'Entry added.',
    'admin.navbar.updated'         => 'Entry updated.',
    'admin.navbar.deleted'         => 'Entry removed.',
    'admin.navbar.pin_added'       => 'Group added.',
    'admin.navbar.pin_updated'     => 'Group updated.',
    'admin.navbar.pin_deleted'     => 'Group deleted.',
    'admin.navbar.nb_public'       => 'Public',
    'admin.navbar.nb_user'         => 'User',
    'admin.navbar.nb_admin'        => 'Admin',
    'admin.navbar.url'             => 'URL',
    'admin.navbar.sort'            => 'Sort order',
    'admin.navbar.sort_mode'       => 'Sort mode',
    'admin.navbar.sort_alpha'      => 'Alphabetical',
    'admin.navbar.sort_custom'     => 'Custom order',
    'admin.navbar.type_internal'   => 'Internal page',
    'admin.navbar.type_external'   => 'External URL',
    'admin.navbar.pins'            => 'Groups',
    'admin.navbar.entries'         => 'Entries',
    'admin.navbar.btn_add_pin'     => 'Add group',
    'admin.navbar.btn_add_entry'   => 'Add entry',
    'admin.navbar.i18n'            => 'Translated key',

    // ---- Pages --------------------------------------------------------------
    'admin.pages.url_id'            => 'URL ID',
    'admin.pages.file_name'         => 'File name',
    'admin.pages.description'       => 'Description',
    'admin.pages.i18n'              => 'i18n',
    'admin.pages.template'          => 'Template',
    'admin.pages.controller'        => 'Controller',
    'admin.pages.comments'          => 'Comments',
    'admin.pages.index'             => 'Index',
    'admin.pages.follow'            => 'Follow',
    'admin.pages.parent'            => 'Parent page',
    'admin.pages.note'              => 'Routing-critical fields (url_id, file_name) affect URL resolution.',
    'admin.pages.routing_warning'   => 'Changing url_id or file_name affects routing. Make sure you know what you are doing.',
    'admin.pages.added'             => 'Page added.',
    'admin.pages.updated'           => 'Page updated.',
    'admin.pages.deleted'           => 'Page deleted.',
    'admin.pages.url_file_required' => 'URL ID and file name are required.',

    // ---- Notes --------------------------------------------------------------
    'admin.notes.label' => 'Notes (visible to admins only)',
    'admin.notes.saved' => 'Notes saved.',
];
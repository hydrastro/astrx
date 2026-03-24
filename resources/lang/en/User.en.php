<?php
declare(strict_types=1);

/**
 * User section translations — en locale.
 *
 * Page URL slugs (WORDING_*) are intentionally excluded — they live in
 * pages.en.php which is loaded globally on every request.
 *
 * .title / .description keys must be present here so that
 * DefaultTemplateContext::buildBase() does not emit MissingTranslation notices.
 * Values match the page_meta DB fallbacks; override freely.
 */
return [
    // -------------------------------------------------------------------------
    // Page meta — title and description for each user section page
    // -------------------------------------------------------------------------
    'WORDING_LOGIN.title'         => 'Login',
    'WORDING_LOGIN.description'   => 'Log in to your account.',

    'WORDING_REGISTER.title'      => 'Register',
    'WORDING_REGISTER.description'=> 'Create a new account.',

    'WORDING_RECOVER.title'       => 'Recover account',
    'WORDING_RECOVER.description' => 'Reset your password.',

    'WORDING_SETTINGS.title'      => 'Settings',
    'WORDING_SETTINGS.description'=> 'Manage your account settings.',

    'WORDING_USER_HOME.title'     => 'Home',
    'WORDING_USER_HOME.description'=> 'Your personal home page.',

    'WORDING_LOGOUT.title'         => 'Logout',
    'WORDING_LOGOUT.description'   => 'Sign out of your account.',

    'WORDING_USER.title'          => 'User Area',
    'WORDING_USER.description'    => 'Log in or create your account.',

    // -------------------------------------------------------------------------
    // Shared field labels
    // -------------------------------------------------------------------------
    'user.field.username'        => 'Username',
    'user.field.password'        => 'Password',
    'user.field.old_password'    => 'Current password',
    'user.field.repeat'          => 'Repeat password',
    'user.field.mailbox'         => 'Login email',
    'user.field.email'           => 'Recovery email',
    'user.field.display_name'    => 'Display name',
    'user.field.birth_date'      => 'Date of birth',

    // -------------------------------------------------------------------------
    // Captcha
    // -------------------------------------------------------------------------
    'user.captcha.label'         => 'Enter the captcha text',

    // -------------------------------------------------------------------------
    // Login
    // -------------------------------------------------------------------------
    'user.login.heading'         => 'User Area',
    'user.login.submit'          => 'Log in',
    'user.login.remember_me'     => 'Remember me',
    'user.login.lost_password'   => 'Forgot password?',
    'user.login.need_account'    => 'Need an account?',
    'user.login.register'        => 'Register',

    // -------------------------------------------------------------------------
    // Register
    // -------------------------------------------------------------------------
    'user.register.heading'      => 'Register',
    'user.register.description'  => 'Create a new account.',
    'user.register.submit'       => 'Register',
    'user.register.back_to_login'=> 'Back to login',
    'user.register.closed'       => 'Registrations are currently closed.',

    // -------------------------------------------------------------------------
    // Recover
    // -------------------------------------------------------------------------
    'user.recover.heading'       => 'Recover account',
    'user.recover.description'   => 'Enter your username or recovery email and we will send you a reset link.',
    'user.recover.identifier'    => 'Username or email',
    'user.recover.submit'        => 'Send reset link',
    'user.recover.back_to_login' => 'Back to login',
    'user.recover.unavailable'   => 'Password recovery is not available.',

    // -------------------------------------------------------------------------
    // User home
    // -------------------------------------------------------------------------
    'user.home.heading'          => 'Welcome',
    'user.home.body'             => 'You are logged in.',
    'user.home.profile_heading'  => 'Profile',
    'user.home.profile_text'     => 'View your public profile.',
    'user.home.settings_heading' => 'Settings',
    'user.home.settings_text'    => 'Manage your account.',
    'user.home.logout'           => 'Sign out',
    'user.home.profile_heading.desc'  => 'View and edit your public profile.',
    'user.home.settings_heading.desc' => 'Change your password, avatar, and preferences.',
    'user.home.logout.desc'           => 'End your current session.',

    // -------------------------------------------------------------------------
    // Settings
    // -------------------------------------------------------------------------
    'user.settings.heading'           => 'Account settings',
    'user.settings.current_value'     => 'Current',
    'user.settings.submit'            => 'Save',
    'user.settings.avatar'            => 'Avatar',
    'user.settings.set_avatar'        => 'Upload avatar',
    'user.settings.remove_avatar'     => 'Remove avatar',
    'user.settings.max_size'          => 'Maximum size',
    'user.settings.display_name'      => 'Display name',
    'user.settings.new_display_name'  => 'New display name',
    'user.settings.recovery_email'    => 'Recovery email',
    'user.settings.new_email'         => 'New recovery email',
    'user.settings.username'          => 'Username',
    'user.settings.new_username'      => 'New username',
    'user.settings.password'          => 'Change password',
    'user.settings.verify_email'      => 'Verify email',
    'user.settings.verify_desc'       => 'Your recovery email is not yet verified. Send a verification link.',
    'user.settings.delete'            => 'Delete account',
    'user.settings.delete_confirm'    => 'This action is irreversible. Enter your password to confirm.',


    // -------------------------------------------------------------------------
    // Success / info messages
    // -------------------------------------------------------------------------
    'user.register.success' => 'Registration successful! You can now log in.',
    'user.recover.sent'     => 'If an account with that username or email exists, a reset link has been sent.',

    // -------------------------------------------------------------------------
    // Profile page
    // -------------------------------------------------------------------------
    'user.profile.heading'      => 'Profile',
    'user.profile.not_found'    => 'User not found.',
    'user.profile.joined'       => 'Member since',
    'user.profile.group'        => 'Role',
    'user.profile.verified'     => 'Verified',
    'user.profile.settings_link'=> 'Edit your settings',

    // -------------------------------------------------------------------------
    // User group labels
    // -------------------------------------------------------------------------
    'user.group.admin'  => 'Administrator',
    'user.group.mod'    => 'Moderator',
    'user.group.user'   => 'Member',
    'user.group.guest'  => 'Guest',

    // -------------------------------------------------------------------------
    // Page meta
    // -------------------------------------------------------------------------
    'WORDING_PROFILE.title'        => 'Profile',
    'WORDING_PROFILE.description'  => 'View a user profile.',

    // ---- Webmail -------------------------------------------------------
    'webmail.heading'             => 'Webmail',
    'webmail.login.heading'       => 'Connect to Mailbox',
    'webmail.login.body'          => 'Enter your mailbox password to access your emails.',
    'webmail.login.password'      => 'Mailbox password',
    'webmail.login.btn'           => 'Connect',
    'webmail.login.error'         => 'Could not connect. Please check your password and try again.',
    'webmail.compose.heading'     => 'Compose',
    'webmail.compose.from'        => 'From',
    'webmail.compose.to'          => 'To',
    'webmail.compose.subject'     => 'Subject',
    'webmail.compose.body'        => 'Message',
    'webmail.compose.send'        => 'Send',
    'webmail.compose.cancel'      => 'Cancel',
    'webmail.folders'             => 'Folders',
    'webmail.inbox'               => 'Inbox',
    'webmail.from'                => 'From',
    'webmail.subject'             => 'Subject',
    'webmail.date'                => 'Date',
    'webmail.actions'             => 'Actions',
    'webmail.no_messages'         => 'No messages in this folder.',
    'webmail.btn_compose'         => 'Compose',
    'webmail.btn_reply'           => 'Reply',
    'webmail.btn_delete'          => 'Delete',
    'webmail.btn_move'            => 'Move',
    'webmail.btn_mark_read'       => 'Mark read',
    'webmail.btn_mark_unread'     => 'Mark unread',
    'webmail.btn_back'            => '← Back',
    'webmail.btn_disconnect'      => 'Disconnect',
    'webmail.move_to'             => 'Move to',
    'webmail.prev'                => '← Prev',
    'webmail.next'                => 'Next →',
    'webmail.page'                => 'Page',
    'webmail.of'                  => 'of',
    'webmail.total'               => 'Total',
    'webmail.message_deleted'     => 'Message deleted.',
    'webmail.message_moved'       => 'Message moved.',
    'webmail.message_sent'        => 'Message sent.',
    'webmail.compose_fields_required' => 'To, Subject and body are required.',

];
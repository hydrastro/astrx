<?php

declare(strict_types = 1);

namespace AstrX\User\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/**
 * Business-logic failure from the user subsystem.
 * The `operation` field is a machine-readable slug used by the lang file
 * to render a locale-specific message. This keeps the diagnostic class count
 * low while still producing rich, translatable user-facing output.
 * Known operation slugs:
 *   login_failed           — wrong username or password (intentionally vague)
 *   login_restricted       — user group not allowed to log in
 *   not_verified           — login requires email verification
 *   registration_closed    — registrations are disabled
 *   username_taken         — username already exists
 *   email_taken            — recovery email already exists
 *   mailbox_taken          — mailbox (local address part) already exists
 *   invalid_username       — username fails regex validation
 *   invalid_password       — password fails regex validation
 *   passwords_mismatch     — password != repeat
 *   invalid_date           — birth date is not a valid calendar date
 *   too_young              — user does not meet minimum age requirement
 *   empty_fields           — required fields missing
 *   wrong_password         — wrong current password (settings actions)
 *   token_not_found        — token ID not found or already used
 *   token_expired          — token found but past expiry
 *   token_already_sent     — a valid token was already issued recently
 *   user_not_found         — no user with the given identifier
 *   avatar_size            — uploaded file exceeds size limit
 *   avatar_extension       — file extension not allowed
 *   avatar_invalid         — file fails image type check
 *   avatar_upload_error    — PHP upload error code != UPLOAD_ERR_OK
 *   avatar_move_failed     — move_uploaded_file() returned false
 */
final class UserDiagnostic extends AbstractDiagnostic
{
    public const string ID = 'astrx.user/operation';
    public const DiagnosticLevel LEVEL = DiagnosticLevel::NOTICE;

    public function __construct(
        string $id,
        DiagnosticLevel $level,
        private readonly string $operation,
        private readonly string $detail = '',
    ) {
        parent::__construct($id, $level);
    }

    public function operation()
    : string
    {
        return $this->operation;
    }

    public function detail()
    : string
    {
        return $this->detail;
    }

    public function vars()
    : array
    {
        return ['operation' => $this->operation, 'detail' => $this->detail];
    }
}
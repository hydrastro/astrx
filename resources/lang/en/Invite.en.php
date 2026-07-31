<?php
declare(strict_types=1);

/**
 * Invite module (invite-only registration) — en locale.
 * Loaded via loadDomain(langDir(), 'Invite') by AdminInvitesController.
 * Keys mirror the it counterpart 1:1.
 */
return [
    // ── Admin: generate / list / revoke invites ──────────────────────────────
    'invite.admin.heading'       => 'Invitations',
    'invite.admin.intro'         => 'Mint one-time invite codes for invite-only registration. Each code works for a single sign-up and cannot be reused. Give a code to the person you want to invite; revoke any code that has not been used yet.',
    'invite.admin.generate'      => 'Generate invites',
    'invite.admin.count'         => 'How many',
    'invite.admin.count_hint'    => 'Between 1 and 50 codes per batch.',
    'invite.admin.note'          => 'Note',
    'invite.admin.note_hint'     => 'Optional — a reminder of who this batch is for.',
    'invite.admin.create'        => 'Generate',
    'invite.admin.existing'      => 'Existing invites',
    'invite.admin.none'          => 'No invites yet.',
    'invite.admin.code'          => 'Code',
    'invite.admin.status'        => 'Status',
    'invite.admin.created_at'    => 'Created',
    'invite.admin.created'       => 'Invite codes generated.',
    'invite.admin.create_failed' => 'Could not generate the invite codes.',
    'invite.admin.revoke'        => 'Revoke',
    'invite.admin.revoked'       => 'Invite revoked.',
    'invite.admin.revoke_failed' => 'Could not revoke that invite — it may already have been used.',

    // ── Status labels ────────────────────────────────────────────────────────
    'invite.status.available'    => 'Available',
    'invite.status.used'         => 'Used',
];

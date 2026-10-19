<?php
declare(strict_types=1);

/**
 * Modulo Invite (registrazione solo su invito) — locale it.
 * Le chiavi corrispondono 1:1 alla controparte en.
 */
return [
    // ── Admin: genera / elenca / revoca inviti ───────────────────────────────
    'invite.admin.heading'       => 'Inviti',
    'invite.admin.intro'         => 'Genera codici di invito monouso per la registrazione solo su invito. Ogni codice vale per una sola iscrizione e non può essere riutilizzato. Consegna un codice alla persona che vuoi invitare; revoca qualsiasi codice non ancora utilizzato.',
    'invite.admin.generate'      => 'Genera inviti',
    'invite.admin.count'         => 'Quanti',
    'invite.admin.count_hint'    => 'Da 1 a 50 codici per lotto.',
    'invite.admin.note'          => 'Nota',
    'invite.admin.note_hint'     => 'Facoltativa — un promemoria su chi è destinato questo lotto.',
    'invite.admin.create'        => 'Genera',
    'invite.admin.existing'      => 'Inviti esistenti',
    'invite.admin.none'          => 'Ancora nessun invito.',
    'invite.admin.code'          => 'Codice',
    'invite.admin.status'        => 'Stato',
    'invite.admin.created_at'    => 'Creato',
    'invite.admin.created'       => 'Codici di invito generati.',
    'invite.admin.create_failed' => 'Impossibile generare i codici di invito.',
    'invite.admin.revoke'        => 'Revoca',
    'invite.admin.revoked'       => 'Invito revocato.',
    'invite.admin.revoke_failed' => 'Impossibile revocare questo invito — potrebbe essere già stato utilizzato.',

    // ── Etichette di stato ───────────────────────────────────────────────────
    'invite.status.available'    => 'Disponibile',
    'invite.status.used'         => 'Utilizzato',
];

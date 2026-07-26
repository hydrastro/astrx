<?php
declare(strict_types=1);

return [
    // ── Intestazioni ─────────────────────────────────────────────────────────
    'comment.heading'        => 'Commenti',
    'comment.none'           => 'Nessun commento ancora. Sii il primo!',
    'comment.submit_heading' => 'Lascia un commento',
    'comment.anonymous'      => 'Anonimo',

    // ── Etichette del modulo ─────────────────────────────────────────────────
    'comment.label.name'         => 'Nome',
    'comment.label.email'        => 'Email',
    'comment.label.content'      => 'Commento',
    'comment.label.reply'        => 'In risposta a',
    'comment.label.captcha'      => 'Inserisci il codice mostrato',
    'comment.label.show'         => 'Mostra',
    'comment.label.order'        => 'Ordinato per data',
    'comment.label.order_asc'    => 'Crescente',
    'comment.label.order_desc'   => 'Decrescente',
    'comment.label.indent'       => 'Raggruppamento',
    'comment.label.indent_nest'  => 'Annidato',
    'comment.label.indent_flat'  => 'Piatto',

    // ── Pulsanti ──────────────────────────────────────────────────────────────
    'comment.btn.submit'        => 'Pubblica commento',
    'comment.btn.filter'        => 'Applica',
    'comment.btn.reply'         => 'Rispondi',
    'comment.btn.cancel_reply'  => 'Annulla risposta',
    'comment.btn.hide'          => 'Nascondi',
    'comment.btn.unhide'        => 'Mostra',
    'comment.btn.delete'        => 'Elimina',

    // ── Parole ───────────────────────────────────────────────────────────────
    'comment.word.older' => 'Commenti:',
    'comment.word.first' => '<<',
    'comment.word.last'  => '>>',
    'comment.word.prev'  => '<',
    'comment.word.next'  => '>',

    // ── Feedback di pubblicazione (mostrato via flash dopo il redirect PRG) ────
    'comment.posted'                 => 'Commento pubblicato.',
    'comment.error.csrf'             => 'La sessione è scaduta. Riprova.',
    'comment.error.captcha'          => 'Il captcha non è corretto. Riprova.',
    'comment.error.generic'          => 'Impossibile pubblicare il commento.',
    'comment.error.flood'            => 'Stai pubblicando troppo velocemente — attendi un momento e riprova.',
    'comment.error.antispam'         => 'Il tuo commento è stato bloccato da un filtro antispam.',
    'comment.error.muted'            => 'Sei silenziato e non puoi pubblicare.',
    'comment.error.not_allowed'      => 'Non puoi pubblicare commenti qui.',
    'comment.error.empty'            => 'Il commento è vuoto.',
    'comment.error.invalid_email'    => 'È richiesto un indirizzo email valido.',
    'comment.error.reply_not_found'  => 'Il commento a cui stai rispondendo non esiste più.',
    'comment.error.reply_wrong_page' => 'Quella risposta non appartiene a questa pagina.',
    'comment.error.gate_denied'      => 'Non puoi eseguire questa azione.',
    'comment.error.not_found'        => 'Commento non trovato.',

    // ── Messaggi antispam — scegline uno come messaggio regola in Admin → Commenti
    'comment.antispam.blocked'        => 'Il tuo commento sembra spam ed è stato bloccato.',
    'comment.antispam.too_many_lines' => 'Il tuo commento ha troppe interruzioni di riga.',
    'comment.antispam.too_long'       => 'Il tuo commento è troppo lungo.',
    'comment.antispam.no_links'       => 'I link non sono consentiti nei commenti.',
];

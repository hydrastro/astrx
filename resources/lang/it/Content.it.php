<?php
declare(strict_types=1);

/**
 * Modulo Content (pagine Markdown ispirate a W/wcms) — locale it.
 * Le chiavi corrispondono 1:1 alla controparte en.
 */
return [
    // ── Pagine di contenuto pubbliche ────────────────────────────────────────
    'content.index_heading' => 'Pagine',
    'content.graph_heading' => 'Grafo delle pagine',
    'content.graph_link'    => 'Vedi il grafo delle pagine',
    'content.index_link'    => 'Tutte le pagine',
    'content.empty'         => 'Ancora nessuna pagina.',
    'content.graph_empty'   => 'Ancora nessuna pagina da rappresentare.',
    'content.backlinks'     => 'Cosa rimanda qui',
    'content.not_found'     => 'Pagina non trovata',
    'content.not_found_msg' => 'Non esiste alcuna pagina di contenuto a questo indirizzo.',
    'content.updated'       => 'Aggiornata:',
    'content.unlisted'      => 'Non elencata — visibile solo agli amministratori.',

    // ── Editor amministrativo + verifica dei link rotti ─────────────────────
    'content.admin.heading'         => 'Pagine di contenuto',
    'content.admin.intro'           => 'Scrivi pagine in Markdown che si collegano tra loro con i link wiki [[slug]]. I backlink, il grafo delle pagine e la verifica dei link rotti si aggiornano al salvataggio.',
    'content.admin.new'             => 'Nuova pagina',
    'content.admin.edit'            => 'Modifica pagina',
    'content.admin.slug'            => 'Slug',
    'content.admin.slug_hint'       => 'Id URL, es. "about" → /pages/about. Lettere minuscole, cifre e trattini.',
    'content.admin.title'           => 'Titolo',
    'content.admin.body'            => 'Corpo (Markdown)',
    'content.admin.body_hint'       => 'Markdown: # titolo, **grassetto**, *corsivo*, `codice`, - elenchi, > citazioni, [testo](url) e [[slug]] per collegare un\'altra pagina.',
    'content.admin.visible'         => 'Elencata (visibile a tutti)',
    'content.admin.save'            => 'Salva',
    'content.admin.delete'          => 'Elimina',
    'content.admin.view'            => 'Vedi',
    'content.admin.pages'           => 'Tutte le pagine',
    'content.admin.none'            => 'Ancora nessuna pagina di contenuto.',
    'content.admin.unlisted'        => 'non elencata',
    'content.admin.broken'          => 'Link rotti',
    'content.admin.broken_none'     => 'Nessun link rotto.',
    'content.admin.broken_links_to' => 'rimanda alla pagina mancante',
    'content.admin.slug_required'   => 'Lo slug è obbligatorio.',
    'content.admin.saved'           => 'Pagina salvata.',
    'content.admin.save_failed'     => 'Impossibile salvare la pagina — lo slug è già in uso?',
    'content.admin.deleted'         => 'Pagina eliminata.',
];

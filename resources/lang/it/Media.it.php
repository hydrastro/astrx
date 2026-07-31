<?php
declare(strict_types=1);

/**
 * Modulo libreria media — locale it.
 * Caricato tramite loadDomain(langDir(), 'Media') da AdminMediaController.
 * Le chiavi corrispondono 1:1 alla controparte en.
 */
return [
    // ── Gestore media (admin) ────────────────────────────────────────────────
    'media.admin.heading'        => 'Libreria media',
    'media.admin.intro'          => 'Carica le immagini una volta e riutilizzale in tutte le pagine di contenuto. Ogni caricamento viene ricodificato per rimuovere i metadati (EXIF/GPS) e validato dal contenuto, mai dal nome del file.',
    'media.admin.upload_heading' => 'Carica media',
    'media.admin.upload_file'    => 'File immagine',
    'media.admin.upload_hint'    => 'Accettati: JPG, PNG, GIF, WebP. Ricodificati al caricamento; GIF/WebP diventano PNG statici.',
    'media.admin.upload_btn'     => 'Carica',
    'media.admin.list_heading'   => 'Media caricati',
    'media.admin.none'           => 'Nessun media caricato ancora.',
    'media.admin.col_preview'    => 'Anteprima',
    'media.admin.col_name'       => 'Nome',
    'media.admin.col_size'       => 'Dimensione',
    'media.admin.col_dims'       => 'Dimensioni',
    'media.admin.col_embed'      => 'Incorpora',
    'media.admin.col_actions'    => 'Azioni',
    'media.admin.embed_hint'     => 'Copia per incorporare in una pagina di contenuto:',
    'media.admin.rename'         => 'Rinomina in',
    'media.admin.rename_btn'     => 'Rinomina',
    'media.admin.delete'         => 'Elimina',
    'media.admin.view'           => 'Vedi',

    // ── Esiti flash ──────────────────────────────────────────────────────────
    'media.admin.uploaded'       => 'Media caricato.',
    'media.admin.upload_no_file' => 'Nessuna immagine valida caricata.',
    'media.admin.upload_failed'  => 'Caricamento non riuscito — il file non era un\'immagine supportata, era troppo grande o non è stato possibile salvarlo.',
    'media.admin.renamed'        => 'Media rinominato.',
    'media.admin.rename_taken'   => 'Quel nome è già in uso oppure non è valido.',
    'media.admin.rename_failed'  => 'Impossibile rinominare il media.',
    'media.admin.deleted'        => 'Media eliminato.',
    'media.admin.delete_failed'  => 'Impossibile eliminare il media.',
];

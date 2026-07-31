<?php
declare(strict_types=1);

/**
 * Media library module — en locale.
 * Loaded via loadDomain(langDir(), 'Media') by AdminMediaController.
 * Keys mirror the it counterpart 1:1.
 */
return [
    // ── Admin media manager ──────────────────────────────────────────────────
    'media.admin.heading'        => 'Media library',
    'media.admin.intro'          => 'Upload images once and re-use them across content pages. Every upload is re-encoded to strip metadata (EXIF/GPS) and validated by content, never by file name.',
    'media.admin.upload_heading' => 'Upload media',
    'media.admin.upload_file'    => 'Image file',
    'media.admin.upload_hint'    => 'Accepted: JPG, PNG, GIF, WebP. Re-encoded on upload; GIF/WebP become static PNG.',
    'media.admin.upload_btn'     => 'Upload',
    'media.admin.list_heading'   => 'Uploaded media',
    'media.admin.none'           => 'No media uploaded yet.',
    'media.admin.col_preview'    => 'Preview',
    'media.admin.col_name'       => 'Name',
    'media.admin.col_size'       => 'Size',
    'media.admin.col_dims'       => 'Dimensions',
    'media.admin.col_embed'      => 'Embed',
    'media.admin.col_actions'    => 'Actions',
    'media.admin.embed_hint'     => 'Copy to embed in a content page:',
    'media.admin.rename'         => 'Rename to',
    'media.admin.rename_btn'     => 'Rename',
    'media.admin.delete'         => 'Delete',
    'media.admin.view'           => 'View',

    // ── Flash outcomes ───────────────────────────────────────────────────────
    'media.admin.uploaded'       => 'Media uploaded.',
    'media.admin.upload_no_file' => 'No valid image was uploaded.',
    'media.admin.upload_failed'  => 'Upload failed — the file was not a supported image, was too large, or could not be stored.',
    'media.admin.renamed'        => 'Media renamed.',
    'media.admin.rename_taken'   => 'That name is already taken, or is not valid.',
    'media.admin.rename_failed'  => 'Could not rename the media.',
    'media.admin.deleted'        => 'Media deleted.',
    'media.admin.delete_failed'  => 'Could not delete the media.',
];

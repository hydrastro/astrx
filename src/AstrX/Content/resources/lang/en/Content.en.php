<?php
declare(strict_types=1);

/**
 * Content module (W/wcms-inspired Markdown pages) — en locale.
 * Loaded via loadDomain(langDir(), 'Content') by ContentController and
 * AdminContentController. Keys mirror the it counterpart 1:1.
 */
return [
    // ── Public content pages ─────────────────────────────────────────────────
    'content.index_heading' => 'Pages',
    'content.graph_heading' => 'Page graph',
    'content.graph_link'    => 'View the page graph',
    'content.index_link'    => 'All pages',
    'content.empty'         => 'No pages yet.',
    'content.graph_empty'   => 'No pages to graph yet.',
    'content.backlinks'     => 'What links here',
    'content.not_found'     => 'Page not found',
    'content.not_found_msg' => 'There is no content page at this address.',
    'content.updated'       => 'Updated:',
    'content.unlisted'      => 'Unlisted — visible to admins only.',

    // ── Admin editor + broken-link checker ──────────────────────────────────
    'content.admin.heading'         => 'Content pages',
    'content.admin.intro'           => 'Author Markdown pages that link to each other with [[slug]] wiki links. Backlinks, the page graph and the broken-link checker all update on save.',
    'content.admin.new'             => 'New page',
    'content.admin.edit'            => 'Edit page',
    'content.admin.slug'            => 'Slug',
    'content.admin.slug_hint'       => 'URL id, e.g. "about" → /pages/about. Lowercase letters, digits and hyphens.',
    'content.admin.title'           => 'Title',
    'content.admin.body'            => 'Body (Markdown)',
    'content.admin.body_hint'       => 'Markdown: # heading, **bold**, *italic*, `code`, - lists, > quotes, [text](url), and [[slug]] to link another page.',
    'content.admin.visible'         => 'Published (uncheck to keep as a draft)',
    'content.admin.save'            => 'Save',
    'content.admin.delete'          => 'Delete',
    'content.admin.view'            => 'View',
    'content.admin.pages'           => 'All pages',
    'content.admin.none'            => 'No content pages yet.',
    'content.admin.unlisted'        => 'unlisted',
    'content.admin.broken'          => 'Broken links',
    'content.admin.broken_none'     => 'No broken links.',
    'content.admin.broken_links_to' => 'links to the missing page',
    'content.admin.slug_required'   => 'A slug is required.',
    'content.admin.saved'           => 'Page saved.',
    'content.admin.save_failed'     => 'Could not save the page — is the slug already taken?',
    'content.admin.deleted'         => 'Page deleted.',

    // ── Visibility states + scheduling (R8) ─────────────────────────────────
    'content.admin.visibility'      => 'Visibility',
    'content.admin.publish_at'      => 'Publish at',
    'content.admin.expire_at'       => 'Expire at',
    'content.admin.schedule_hint'   => 'Optional. Blank = live now / never expires. Times use the server timezone.',
    'content.admin.state'           => 'State',
    'content.state.public'          => 'Public',
    'content.state.unlisted'        => 'Unlisted',
    'content.state.private'         => 'Private',
    'content.state.draft'           => 'Draft',
    'content.state.scheduled'       => 'Scheduled',
    'content.state.expired'         => 'Expired',
];

<?php
declare(strict_types=1);

namespace AstrX\Imageboard;

use AstrX\I18n\Translator;

/**
 * Renders the dynamic imageboard content (threads, posts, catalog) to HTML in
 * PHP — the same choice the chat makes for its message stream. AstrX's zero-dep
 * template engine resolves only top-level and current-list-item context, so
 * deeply-nested per-post markup is built here instead of in the template.
 *
 * Everything is escaped; `body_html` arrives already sanitized from PostRenderer
 * (greentext/quote-links/spoilers) and is the only value emitted raw.
 */
final class BoardView
{
    public function __construct(private readonly Translator $t) {}

    /** @param list<array<string,mixed>> $threads */
    public function index(array $threads): string
    {
        $h = '';
        foreach ($threads as $thread) {
            $threadUrl = $this->str($thread['thread_url'] ?? null);
            $tags = '';
            if (!empty($thread['sticky'])) { $tags .= '<span class="tag">' . $this->e($this->t->t('board.sticky')) . '</span>'; }
            if (!empty($thread['locked'])) { $tags .= '<span class="tag">' . $this->e($this->t->t('board.locked')) . '</span>'; }

            $h .= '<div class="thread">';
            if ($tags !== '') { $h .= '<p>' . $tags . '</p>'; }
            $h .= $this->post($this->arr($thread['op'] ?? null), $threadUrl);

            $omitted = $this->int($thread['omitted'] ?? 0);
            if ($omitted > 0) {
                $h .= '<p class="omitted">' . $omitted . ' ' . $this->e($this->t->t('board.replies_omitted')) . '</p>';
            }
            foreach ($this->list($thread['replies'] ?? null) as $r) {
                $h .= $this->post($r, $threadUrl);
            }
            $h .= '</div>';
        }
        return $h;
    }

    /** @param list<array<string,mixed>> $posts */
    public function thread(array $posts): string
    {
        $h = '';
        foreach ($posts as $p) {
            $h .= $this->post($p, null);
        }
        return $h;
    }

    /** @param list<array<string,mixed>> $cells */
    public function catalog(array $cells): string
    {
        $h = '<div class="catalog">';
        foreach ($cells as $c) {
            $h .= '<a class="cat-cell" href="' . $this->e($this->str($c['thread_url'] ?? null)) . '">';
            $thumb = $this->str($c['thumb_url'] ?? null);
            if ($thumb !== '') {
                $h .= '<img src="' . $this->e($thumb) . '" alt="" loading="lazy" referrerpolicy="no-referrer">';
            }
            $h .= '<span class="cat-stats">' . $this->e($this->t->t('board.replies')) . ' ' . $this->int($c['reply_count'] ?? 0)
                . ' / ' . $this->e($this->t->t('board.images')) . ' ' . $this->int($c['image_count'] ?? 0) . '</span>';
            $subject = $this->str($c['subject'] ?? null);
            if ($subject !== '') { $h .= '<strong>' . $this->e($subject) . '</strong>'; }
            $h .= '<span class="cat-ex">' . $this->e($this->str($c['excerpt'] ?? null)) . '</span></a>';
        }
        return $h . '</div>';
    }

    /** @param array<string,mixed> $post */
    public function post(array $post, ?string $threadUrl): string
    {
        $isOp = !empty($post['is_op']);
        $h = '<div class="post ' . ($isOp ? 'op' : 'reply') . '" id="' . $this->e($this->str($post['post_id'] ?? null)) . '">';

        $images = $this->list($post['images'] ?? null);
        if ($images !== []) {
            $h .= '<div class="post-files">';
            foreach ($images as $im) {
                $spoiler = !empty($im['spoiler']) ? ' spoiler' : '';
                $orig    = $this->e($this->str($im['orig'] ?? null));
                $h .= '<label class="post-file' . $spoiler . '">'
                    . '<input type="checkbox" class="xpand" aria-label="' . $this->e($this->t->t('board.view_full')) . '">'
                    . '<img class="thumb" src="' . $this->e($this->str($im['thumb_url'] ?? null)) . '" width="' . $this->int($im['tw'] ?? 0) . '" height="' . $this->int($im['th'] ?? 0) . '" alt="' . $orig . '" loading="lazy" referrerpolicy="no-referrer">'
                    . '<img class="full" src="' . $this->e($this->str($im['full_url'] ?? null)) . '" alt="' . $orig . '" loading="lazy" referrerpolicy="no-referrer"></label>';
            }
            $h .= '</div>';
        }

        $h .= '<p class="post-head">';
        $subject = $this->str($post['subject'] ?? null);
        if ($subject !== '') { $h .= '<span class="subject">' . $this->e($subject) . '</span> '; }
        // A post made under an account carries a profile_url; render its name as a
        // link to that profile. Anonymous/guest posts have none and stay plain.
        $name       = $this->str($post['name'] ?? null);
        $profileUrl = $this->str($post['profile_url'] ?? null);
        $nameInner  = $this->e($name);
        if ($profileUrl !== '') {
            $nameInner = '<a class="name-link" href="' . $this->e($profileUrl) . '">' . $nameInner . '</a>';
        }
        $h .= '<span class="name">' . $nameInner . '</span> '
            . '<span class="time">' . $this->e($this->str($post['time'] ?? null)) . '</span> '
            . '<span class="no">No.' . $this->int($post['no'] ?? 0) . '</span>';
        if ($threadUrl !== null && $threadUrl !== '') {
            $h .= ' <a class="reply-link" href="' . $this->e($threadUrl) . '">[' . $this->e($this->t->t('board.reply')) . ']</a>';
        }
        $h .= '</p>';

        // body_html is already sanitized by PostRenderer — emitted raw.
        $h .= '<div class="post-body">' . $this->str($post['body_html'] ?? null) . '</div>';
        return $h . '</div>';
    }

    /**
     * @param mixed $v
     * @return array<string,mixed>
     */
    private function arr(mixed $v): array
    {
        $out = [];
        if (is_array($v)) {
            foreach ($v as $k => $val) {
                if (is_string($k)) { $out[$k] = $val; }
            }
        }
        return $out;
    }

    /**
     * @param mixed $v
     * @return list<array<string,mixed>>
     */
    private function list(mixed $v): array
    {
        $out = [];
        if (is_array($v)) {
            foreach ($v as $item) {
                if (is_array($item)) { $out[] = $this->arr($item); }
            }
        }
        return $out;
    }

    private function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5);
    }

    private function str(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : '';
    }

    private function int(mixed $v): int
    {
        return is_int($v) ? $v : (is_numeric($v) ? (int) $v : 0);
    }
}

<?php

declare(strict_types = 1);

namespace AstrX\Session;

/**
 * Session-backed single-request flash messages.
 * Messages survive exactly one redirect: set() stores in session,
 * pull() reads and removes them all at once.
 * Usage — setting a message (e.g. after successful registration):
 *   $this->flash->set('success', $this->t->t('user.register.success'));
 * Usage — reading in DefaultTemplateContext::finalise():
 *   $messages = $this->flash->pull();
 *   // $messages = [['type' => 'success', 'text' => '...']]
 * Session layout:
 *   $_SESSION['_flash'] = [['type' => 'success', 'text' => 'Registration successful.'], ...]
 */
final class FlashBag
{
    private const SESSION_KEY = '_flash';

    /**
     * Store a flash message that will be displayed on the next page load.
     *
     * @param string $type Semantic type: 'success' | 'error' | 'info' | 'warning'
     * @param string $text The message text (already translated).
     */
    public function set(string $type, string $text)
    : void {
        if (!isset($_SESSION[self::SESSION_KEY]) ||
            !is_array($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = [];
        }
        $_SESSION[self::SESSION_KEY][] = ['type' => $type, 'text' => $text];
    }

    /**
     * Read all flash messages and remove them from the session.
     * @return list<array{type: string, text: string}>
     */
    public function pull()
    : array
    {
        $messages = $_SESSION[self::SESSION_KEY]??[];
        unset($_SESSION[self::SESSION_KEY]);

        return is_array($messages) ? array_values($messages) : [];
    }

    /**
     * Check if any flash messages are pending without consuming them.
     */
    public function has()
    : bool
    {
        return !empty($_SESSION[self::SESSION_KEY]);
    }
}
<?php

declare(strict_types = 1);

namespace AstrX\Controller;

use AstrX\Http\HttpStatus;
use AstrX\Http\Request;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\CurrentUrl;
use AstrX\Routing\UrlGenerator;
use AstrX\Template\DefaultTemplateContext;
use AstrX\User\AvatarService;
use AstrX\User\UserGroup;
use AstrX\User\UserRepository;
use AstrX\User\UserSession;

/**
 * Public user profile page.
 * URL resolution (in priority order):
 *   1. Rewrite tail:  /en/profile/username   → tail[0] = 'username'
 *   2. Query param:   ?uid=<hexId>            → look up by id
 *   3. Logged-in:     no param               → show own profile
 * The profile URL for the "my profile" link in the user nav is generated
 * in DefaultTemplateContext using the logged-in user's hex ID as a query param:
 *   /en/profile?uid=<hexId>
 * This makes profile URLs predictable and shareable:
 *   /en/profile/alice  — public profile for user 'alice'
 *   /en/profile?uid=<hexId>  — same but by ID (used internally)
 */
final class ProfileController extends AbstractController
{
    public function __construct(
        DiagnosticsCollector $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request $request,
        private readonly CurrentUrl $currentUrl,
        private readonly UserRepository $userRepo,
        private readonly AvatarService $avatarService,
        private readonly UserSession $session,
        private readonly Translator $t,
        private readonly UrlGenerator $urlGen,
    ) {
        parent::__construct($collector);
    }

    public function handle()
    : Result
    {
        $userData = $this->resolveUser();

        if ($userData === null) {
            http_response_code(HttpStatus::NOT_FOUND->value);
            $this->ctx->set('profile_not_found', true);
            $this->ctx->set(
                'profile_not_found_msg',
                $this->t->t('user.profile.not_found')
            );
            $this->setI18n();

            return $this->ok();
        }

        $hexId = (is_scalar($userData['id']) ? (string)$userData['id'] : '');
        $hasAvatar = (bool)$userData['avatar'];
        $avatarSrc = $this->resolveAvatarUrl($hexId, $hasAvatar);

        $groupLabel = $this->resolveGroupLabel((is_int($userData['type']) ? $userData['type'] : 0));
        $isOwnProfile = $this->session->isLoggedIn() &&
                        $this->session->userId() === $hexId;

        $this->ctx->set('profile_not_found', false);
        $this->ctx->set('profile_id', $hexId);
        $this->ctx->set('profile_username', (is_scalar($userData['username']) ? (string)$userData['username'] : ''));
        $this->ctx->set(
            'profile_display_name',
            (is_scalar($userData['display_name'] ?? null) ? (string)$userData['display_name'] : (is_scalar($userData['username'] ?? null) ? (string)$userData['username'] : ''))
        );
        $this->ctx->set('profile_group', $groupLabel);
        $this->ctx->set('profile_verified', (bool)$userData['verified']);
        $this->ctx->set('profile_avatar_src', $avatarSrc);
        $this->ctx->set('profile_has_avatar', $avatarSrc !== '');
        $this->ctx->set(
            'profile_joined',
            self::mStr($userData, 'created_at', '')
        );
        $this->ctx->set('profile_is_own', $isOwnProfile);

        if ($isOwnProfile) {
            $this->ctx->set(
                'profile_settings_url',
                $this->t->t('WORDING_SETTINGS', fallback: 'settings')
            );
        }

        $this->setI18n();

        return $this->ok();
    }

    // -------------------------------------------------------------------------

    /**
     * Try to identify the target user from (in order):
     *   1. URL tail segment (username)
     *   2. ?uid= query param (hex id)
     *   3. Logged-in user's own session
     * @return array<string,mixed>|null
     */
    private function resolveUser()
    : ?array
    {
        // 1. Rewrite tail: /en/profile/alice
        $tailUsername = $this->currentUrl->tailSegment(0);
        if ($tailUsername !== null && $tailUsername !== '') {
            $result = $this->userRepo->findPublicByUsername($tailUsername);
            $result->drainTo($this->collector);

            return $result->isOk() ? $result->unwrap() : null;
        }

        // 2. ?uid=<hexId>
        $uid = $this->request->query()->get('uid');
        if (is_string($uid) &&
            $uid !== '' &&
            ctype_xdigit($uid) &&
            strlen($uid) === 32) {
            $result = $this->userRepo->findPublicById($uid);
            $result->drainTo($this->collector);

            return $result->isOk() ? $result->unwrap() : null;
        }

        // 3. Own profile (must be logged in)
        if ($this->session->isLoggedIn()) {
            $result = $this->userRepo->findPublicById($this->session->userId());
            $result->drainTo($this->collector);

            return $result->isOk() ? $result->unwrap() : null;
        }

        return null;
    }

    /**
     * Build the avatar img src as a URL pointing to the avatar endpoint.
     * For uploaded avatars: /avatar?uid=<hexId>
     * For identicons:       /avatar?uid=<hexId>  (AvatarController generates it)
     * Returns '' if neither avatar nor identicons are enabled.
     *
     * Using URLs instead of data URIs:
     *   - Avoids embedding large base64 blobs in the page HTML.
     *   - Allows the browser to cache the image independently.
     *   - Keeps the HTML payload small.
     */
    private function resolveAvatarUrl(string $hexId, bool $hasAvatar): string
    {
        if (!$hasAvatar && !$this->avatarService->useIdenticons()) {
            return '';
        }
        return $this->urlGen->toPage('avatar', ['uid' => $hexId]);
    }

    private function resolveGroupLabel(int $type)
    : string {
        $group = UserGroup::tryFrom($type)??UserGroup::GUEST;

        return match ($group) {
            UserGroup::ADMIN => $this->t->t(
                          'user.group.admin',
                fallback: 'Admin'
            ),
            UserGroup::MOD => $this->t->t(
                          'user.group.mod',
                fallback: 'Moderator'
            ),
            UserGroup::USER => $this->t->t(
                          'user.group.user',
                fallback: 'Member'
            ),
            UserGroup::GUEST => $this->t->t(
                          'user.group.guest',
                fallback: 'Guest'
            ),
        };
    }

    private function setI18n()
    : void
    {
        $this->ctx->set('profile_heading', $this->t->t('user.profile.heading'));
        $this->ctx->set(
            'profile_label_joined',
            $this->t->t('user.profile.joined')
        );
        $this->ctx->set(
            'profile_label_group',
            $this->t->t('user.profile.group')
        );
        $this->ctx->set(
            'profile_label_verified',
            $this->t->t('user.profile.verified')
        );
        $this->ctx->set(
            'profile_label_settings',
            $this->t->t('user.profile.settings_link')
        );
    }
}

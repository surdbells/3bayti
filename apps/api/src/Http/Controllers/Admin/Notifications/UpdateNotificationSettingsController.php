<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Notifications;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Setting\NotificationSettings;
use Bayti\Api\Domain\Setting\SettingsService;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /v3/admin/settings/notifications   (settings.edit)
 *
 * Set the customer-notification toggles. Takes effect on the next notification
 * (the sender reads this live via a cached SettingsService lookup) — no
 * redeploy. Audited.
 *
 * Body: { "suppress_vendor_item_status": true|false }
 */
final class UpdateNotificationSettingsController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly SettingsService $settings,
        private readonly AuditEmitter $audit,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$actor instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        $body = $request->getParsedBody();
        if (!is_array($body) || !array_key_exists('suppress_vendor_item_status', $body)) {
            throw HttpException::badRequest('Expected "suppress_vendor_item_status" (boolean).');
        }
        if (!is_bool($body['suppress_vendor_item_status'])) {
            throw HttpException::validation([
                'suppress_vendor_item_status' => ['Must be a boolean.'],
            ]);
        }

        $before = NotificationSettings::fromArray($this->settings->get(NotificationSettings::KEY));
        $after = NotificationSettings::fromArray([
            'suppress_vendor_item_status' => $body['suppress_vendor_item_status'],
        ]);

        $this->settings->set(NotificationSettings::KEY, $after->toArray());

        $this->audit->recordSettingChange(
            request: $request,
            actor: $actor,
            settingKey: NotificationSettings::KEY,
            before: $before->toArray(),
            after: $after->toArray(),
        );

        return $this->ok($after->toArray());
    }
}

<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Notifications;

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
 * GET /v3/admin/settings/notifications   (settings.view)
 *
 * The admin-editable customer-notification toggles. Currently one switch:
 * suppress the per-item status updates a vendor triggers to the customer.
 * Returns a safe default (not suppressed) when nothing is stored.
 */
final class GetNotificationSettingsController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly SettingsService $settings,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        $settings = NotificationSettings::fromArray($this->settings->get(NotificationSettings::KEY));

        return $this->ok($settings->toArray());
    }
}

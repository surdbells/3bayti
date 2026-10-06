<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Otp;

use Bayti\Api\Domain\Setting\OtpProviderSettings;
use Bayti\Api\Domain\Setting\SettingsService;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\OtpProviderSettingsSerializer;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/admin/otp/providers   (settings.view)
 *
 * The current OTP-provider routing config: which providers are enabled and in
 * what priority order the router tries them (first success wins, automatic
 * failover to the next on error). Returns a safe default when unconfigured.
 */
final class GetOtpProvidersController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly SettingsService $settings,
        private readonly OtpProviderSettingsSerializer $serializer,
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

        $settings = OtpProviderSettings::fromArray($this->settings->get(OtpProviderSettings::KEY));

        return $this->ok($this->serializer->shape($settings));
    }
}

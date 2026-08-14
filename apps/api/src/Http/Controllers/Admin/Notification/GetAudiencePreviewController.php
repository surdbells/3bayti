<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Notification;

use Bayti\Api\Domain\Notification\DeviceToken;
use Bayti\Api\Domain\Notification\DeviceTokenRepository;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/admin/notifications/audience-preview?audience=all|customers|vendors|admins
 *
 * Live compose-time audience summary: active-device count split by platform
 * (Android / iOS). Backs the "1,245 recipients · 890 Android · 355 iOS"
 * preview before send/schedule.
 */
final class GetAudiencePreviewController
{
    use Responder;

    private const AUDIENCES = ['all', 'customers', 'vendors', 'admins'];

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $_r, array $args): ResponseInterface
    {
        /** @var array<string, mixed> $q */
        $q = $request->getQueryParams();
        $audience = (string) ($q['audience'] ?? 'all');
        if (!in_array($audience, self::AUDIENCES, true)) {
            throw HttpException::badRequest('audience must be one of: ' . implode(', ', self::AUDIENCES) . '.');
        }

        /** @var DeviceTokenRepository $deviceRepo */
        $deviceRepo = $this->em->getRepository(DeviceToken::class);
        $totals = $deviceRepo->countActiveForAudienceByPlatform($audience);

        return $this->ok(['data' => [
            'audience' => $audience,
            'total' => $totals['total'],
            'android' => $totals['android'],
            'ios' => $totals['ios'],
        ]]);
    }
}

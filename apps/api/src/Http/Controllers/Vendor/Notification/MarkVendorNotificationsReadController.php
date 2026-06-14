<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Vendor\Notification;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\Notification\NotificationLog;
use Bayti\Api\Domain\Notification\NotificationLogRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/vendor/notifications/mark-read
 *
 * Marks the vendor's notifications read. Body may include
 * { "ids": [1,2,3] } to mark specific ones; omit to mark all unread.
 * Replaces the legacy /vendors/common/mark_notifications call.
 */
final class MarkVendorNotificationsReadController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
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

        /** @var VendorRepository $vendorRepo */
        $vendorRepo = $this->em->getRepository(Vendor::class);
        $emails = [];
        foreach ($vendorRepo->findByOwnerUser($user) as $vendor) {
            try {
                $email = $vendor->getContactEmail();
            } catch (\Error) {
                continue;
            }
            if ($email !== '') {
                $emails[strtolower($email)] = $email;
            }
        }
        $emails = array_values($emails);

        $body = (array) ($request->getParsedBody() ?? []);
        $ids = null;
        if (isset($body['ids']) && is_array($body['ids'])) {
            $ids = array_values(array_filter(array_map('intval', $body['ids']), static fn (int $i): bool => $i > 0));
        }

        /** @var NotificationLogRepository $repo */
        $repo = $this->em->getRepository(NotificationLog::class);
        $marked = $repo->markFeedRead($emails, $ids, '%.vendor');

        return $this->ok(['data' => ['marked' => $marked]]);
    }
}

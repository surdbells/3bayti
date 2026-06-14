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
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/vendor/notifications
 *
 * The vendor's in-app notification feed (top-bar bell). Surfaces
 * successfully-sent, vendor-targeted notifications addressed to the
 * vendor's contact email(s) from the notification_logs audit, with
 * read-state. Replaces the legacy /vendors/common/notifications call.
 */
final class ListVendorNotificationsController
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

        $recipients = $this->vendorEmails($user);

        $query = $request->getQueryParams();
        $limit = max(1, min(100, (int) ($query['limit'] ?? 30)));
        $offset = max(0, (int) ($query['offset'] ?? 0));

        /** @var NotificationLogRepository $repo */
        $repo = $this->em->getRepository(NotificationLog::class);
        $result = $repo->findFeed($recipients, $limit, $offset, '%.vendor');

        $envelope = PaginatedEnvelope::build(
            array_map([$this, 'shape'], $result['items']),
            $result['total'],
            $limit,
            $offset,
        );
        $envelope['meta']['unread'] = $result['unread'];

        return $this->ok($envelope);
    }

    /**
     * Contact emails for every store the user owns — the feed is scoped to
     * notifications addressed to these.
     *
     * @return list<string>
     */
    private function vendorEmails(User $user): array
    {
        /** @var VendorRepository $vendorRepo */
        $vendorRepo = $this->em->getRepository(Vendor::class);
        $emails = [];
        foreach ($vendorRepo->findByOwnerUser($user) as $vendor) {
            try {
                $email = $vendor->getContactEmail();
            } catch (\Error) {
                continue; // contactEmail uninitialised — skip
            }
            if ($email !== '') {
                $emails[strtolower($email)] = $email;
            }
        }
        return array_values($emails);
    }

    /** @return array<string, mixed> */
    private function shape(NotificationLog $log): array
    {
        return [
            'id'       => $log->getId(),
            'message'  => $this->message($log),
            'is_read'  => $log->isRead(),
            'order_id' => $log->getOrderId(),
            'template' => $log->getTemplate(),
            'sent_at'  => $log->getSentAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /** Human-readable feed message from the template (+ order ref). */
    private function message(NotificationLog $log): string
    {
        $orderId = $log->getOrderId();
        $orderSuffix = $orderId !== null ? " (Order #{$orderId})" : '';

        return match ($log->getTemplate()) {
            'order.placed.vendor'     => "You have a new sale{$orderSuffix}.",
            'order.cancelled.vendor'  => "An order was cancelled{$orderSuffix}.",
            'return.submitted.vendor' => "A return was requested{$orderSuffix}.",
            'compliance.approved.vendor' => 'Your compliance documents were approved.',
            'compliance.rejected.vendor' => 'Your compliance submission was rejected — please re-upload.',
            default => 'You have a new notification' . $orderSuffix . '.',
        };
    }
}

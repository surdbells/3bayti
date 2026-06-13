<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Vendor;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorMessage;
use Bayti\Api\Domain\Catalog\VendorMessageRepository;
use Bayti\Api\Domain\Catalog\VendorRepository;
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
 * Vendor message inbox — the vendor read-side of admin→vendor direct
 * messages.
 *
 *   GET  /v3/vendor/messages              list (paginated, + unread count)
 *   POST /v3/vendor/messages/{id}/read    mark one read
 *
 * Scoped to the authenticated vendor (VendorAuthMiddleware + owner
 * resolution). The admin send-side persists via SendVendorMessageController.
 */
final class VendorMessagesController
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

    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $vendor = $this->resolveVendor($request);
        $q = $request->getQueryParams();
        $limit = max(1, min(100, (int) ($q['limit'] ?? 20)));
        $offset = max(0, (int) ($q['offset'] ?? 0));

        /** @var VendorMessageRepository $repo */
        $repo = $this->em->getRepository(VendorMessage::class);
        $page = $repo->findForVendorPaginated((int) $vendor->getId(), $limit, $offset);

        $envelope = PaginatedEnvelope::build(
            array_map([$this, 'shape'], $page['items']),
            $page['total'],
            $limit,
            $offset,
        );
        $envelope['meta']['unread'] = $repo->countUnreadForVendor((int) $vendor->getId());

        return $this->ok($envelope);
    }

    public function markRead(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vendor = $this->resolveVendor($request);
        $id = (int) ($args['id'] ?? 0);

        /** @var VendorMessageRepository $repo */
        $repo = $this->em->getRepository(VendorMessage::class);
        $message = $repo->findOneForVendor($id, (int) $vendor->getId());
        if ($message === null) {
            throw HttpException::notFound('Message not found.');
        }
        $message->markRead();
        $this->em->flush();

        return $this->ok(['data' => $this->shape($message)]);
    }

    private function resolveVendor(ServerRequestInterface $request): Vendor
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }
        /** @var VendorRepository $vendorRepo */
        $vendorRepo = $this->em->getRepository(Vendor::class);
        $vendors = $vendorRepo->findByOwnerUser($user);
        if ($vendors === []) {
            throw HttpException::forbidden('No approved vendor account found.');
        }
        return $vendors[0];
    }

    /** @return array<string, mixed> */
    private function shape(VendorMessage $m): array
    {
        return [
            'id'         => $m->getId(),
            'subject'    => $m->getSubject(),
            'message'    => $m->getBody(),
            'body'       => $m->getBody(),
            'is_read'    => $m->isRead(),
            'read_at'    => $m->getReadAt()?->format(\DateTimeInterface::ATOM),
            'created'    => $m->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'created_at' => $m->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}

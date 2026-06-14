<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Chat\Vendor;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\Chat\Conversation;
use Bayti\Api\Domain\Chat\ConversationRepository;
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
 * GET /v3/vendor/chat/unread-count
 *
 * Total unread chat messages for the authenticated vendor across all stores
 * they own. Cheap aggregate for badge polling.
 */
final class GetUnreadCountController
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

        /** @var VendorRepository $vendors */
        $vendors = $this->em->getRepository(Vendor::class);
        $vendorIds = $vendors->findIdsByOwnerUser($user);

        /** @var ConversationRepository $conversations */
        $conversations = $this->em->getRepository(Conversation::class);

        return $this->ok([
            'unread_count' => $conversations->unreadCountForVendor($vendorIds),
        ]);
    }
}

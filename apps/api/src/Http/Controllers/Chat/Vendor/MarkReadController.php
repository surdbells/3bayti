<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Chat\Vendor;

use Bayti\Api\Domain\Chat\Conversation;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Chat\ResolvesChatConversation;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/vendor/chat/conversations/{uuid}/read
 *
 * Resets the vendor-side unread counter for one of the vendor's
 * conversations.
 */
final class MarkReadController
{
    use Responder;
    use ResolvesChatConversation;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        $conversation = $this->vendorConversation($this->em, (string) ($args['uuid'] ?? ''), $user);
        $conversation->markReadFor(Conversation::PARTY_VENDOR);
        $this->em->flush();

        return $this->ok([
            'uuid'         => $conversation->getUuid(),
            'unread_count' => 0,
        ]);
    }
}

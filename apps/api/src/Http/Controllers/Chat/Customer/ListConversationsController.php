<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Chat\Customer;

use Bayti\Api\Domain\Chat\Conversation;
use Bayti\Api\Domain\Chat\ConversationRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\ChatSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/chat/conversations?limit=20&offset=0
 *
 * The authenticated customer's order chat threads, newest activity first,
 * with the customer-side unread total for the inbox badge.
 */
final class ListConversationsController
{
    use Responder;

    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 50;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly ChatSerializer $serializer,
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

        $query = $request->getQueryParams();
        $limit = $this->clampLimit($query['limit'] ?? null);
        $offset = $this->clampOffset($query['offset'] ?? null);

        /** @var ConversationRepository $conversations */
        $conversations = $this->em->getRepository(Conversation::class);
        $result = $conversations->findForCustomer((int) $user->getId(), $limit, $offset);

        $items = array_map(
            fn (Conversation $c): array => $this->serializer->conversationShape($c, Conversation::PARTY_CUSTOMER),
            $result['items'],
        );

        return $this->ok([
            'conversations' => $items,
            'unread_total'  => $result['unread'],
            'pagination'    => [
                'limit'  => $limit,
                'offset' => $offset,
                'count'  => count($items),
                'total'  => $result['total'],
            ],
        ]);
    }

    private function clampLimit(mixed $raw): int
    {
        if ($raw === null || $raw === '') {
            return self::DEFAULT_LIMIT;
        }
        $n = (int) $raw;
        if ($n < 1) {
            return self::DEFAULT_LIMIT;
        }
        return min($n, self::MAX_LIMIT);
    }

    private function clampOffset(mixed $raw): int
    {
        if ($raw === null || $raw === '') {
            return 0;
        }
        return max(0, (int) $raw);
    }
}

<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Chat\Vendor;

use Bayti\Api\Domain\Chat\Conversation;
use Bayti\Api\Domain\Chat\Message;
use Bayti\Api\Domain\Chat\MessageRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Chat\ResolvesChatConversation;
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
 * GET /v3/vendor/chat/conversations/{uuid}/messages?limit=30&after_id=&before_id=
 *
 * Messages for one of the vendor's conversations + conversation meta.
 * Authorization is scoped to the stores the vendor owns.
 */
final class GetMessagesController
{
    use Responder;
    use ResolvesChatConversation;

    private const DEFAULT_LIMIT = 30;
    private const MAX_LIMIT = 100;

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

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        $conversation = $this->vendorConversation($this->em, (string) ($args['uuid'] ?? ''), $user);

        $query = $request->getQueryParams();
        $limit = $this->clampLimit($query['limit'] ?? null);
        $afterId = $this->nullableId($query['after_id'] ?? null);
        $beforeId = $this->nullableId($query['before_id'] ?? null);

        /** @var MessageRepository $messages */
        $messages = $this->em->getRepository(Message::class);
        $list = $messages->findForConversation(
            (int) $conversation->getId(),
            $limit,
            $beforeId,
            $afterId,
            Conversation::PARTY_VENDOR,
        );

        return $this->ok([
            'conversation' => $this->serializer->conversationShape($conversation, Conversation::PARTY_VENDOR),
            'messages'     => array_map(fn (Message $m): array => $this->serializer->messageShape($m), $list),
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

    private function nullableId(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $n = (int) $raw;
        return $n > 0 ? $n : null;
    }
}

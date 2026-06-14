<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Chat;

use Bayti\Api\Domain\Chat\Conversation;
use Bayti\Api\Domain\Chat\ConversationRepository;
use Bayti\Api\Domain\Chat\Message;
use Bayti\Api\Domain\Chat\MessageRepository;
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
use Psr\Log\LoggerInterface;

/**
 * GET /v3/admin/chat/conversations/{uuid}?limit=100
 *
 * Full thread of any conversation for moderation investigation — both
 * parties named and ALL messages including the ones that were blocked, so
 * an admin can see the context around a flagged attempt. Read-only; admins
 * never post into a thread.
 */
final class GetConversationController
{
    use Responder;

    private const DEFAULT_LIMIT = 100;
    private const MAX_LIMIT = 200;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly ChatSerializer $serializer,
        private readonly LoggerInterface $logger,
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

        $uuid = (string) ($args['uuid'] ?? '');

        /** @var ConversationRepository $conversations */
        $conversations = $this->em->getRepository(Conversation::class);
        $conversation = $uuid !== '' ? $conversations->findByUuid($uuid) : null;
        if ($conversation === null) {
            throw HttpException::notFound('Conversation not found.');
        }

        $limit = $this->clampLimit($request->getQueryParams()['limit'] ?? null);

        /** @var MessageRepository $messages */
        $messages = $this->em->getRepository(Message::class);
        $list = $messages->findAllForConversation((int) $conversation->getId(), $limit);

        $this->logger->info('admin.chat.conversation_viewed', [
            'actor_user_id'     => $user->getId(),
            'conversation_uuid' => $conversation->getUuid(),
        ]);

        return $this->ok([
            'conversation' => $this->serializer->adminConversationShape($conversation),
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
}

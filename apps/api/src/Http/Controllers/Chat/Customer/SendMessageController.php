<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Chat\Customer;

use Bayti\Api\Domain\Chat\ChatMessageSender;
use Bayti\Api\Domain\Chat\Conversation;
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
 * POST /v3/chat/conversations/{uuid}/messages   body: { "content": "..." }
 *
 * Sends a message from the customer to the vendor. The content is screened
 * for personal contact details; if it trips the moderator the message is
 * withheld and the response is 422 CHAT_MESSAGE_BLOCKED naming the offending
 * categories, so the app can warn the sender rather than fail silently.
 */
final class SendMessageController
{
    use Responder;
    use ResolvesChatConversation;

    private const MAX_LENGTH = 4000;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly ChatMessageSender $sender,
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

        $conversation = $this->customerConversation($this->em, (string) ($args['uuid'] ?? ''), $user);
        if (!$conversation->isActive()) {
            throw HttpException::badRequest('This conversation is closed.');
        }

        $body = (array) $request->getParsedBody();
        $content = trim((string) ($body['content'] ?? ''));
        if ($content === '') {
            throw HttpException::validation(['content' => 'Message content is required.']);
        }
        if (mb_strlen($content) > self::MAX_LENGTH) {
            throw HttpException::validation(['content' => 'Message is too long (max ' . self::MAX_LENGTH . ' characters).']);
        }

        $result = $this->sender->send($conversation, $user, Conversation::PARTY_CUSTOMER, $content);
        if (!$result->delivered) {
            $moderation = $result->moderation;
            throw HttpException::chatBlocked(
                $moderation?->flagTypes ?? [],
                'Your message wasn\'t sent because it looks like it contains a ' . $moderation?->labels()
                . '. To stay protected, please keep contact details out of chat.',
            );
        }

        return $this->created(['message' => $this->serializer->messageShape($result->message)]);
    }
}

<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Chat;

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
 * GET /v3/admin/chat/flagged?limit=20&offset=0&flag_type=phone
 *
 * Moderation feed: every chat message the PII detector withheld, newest
 * first, with the attempted content and full context (order, customer,
 * vendor) so admins can spot vendors/customers repeatedly trying to take
 * deals off-platform. `flag_type` narrows to one category
 * (phone|email|social|address); a stored combination matches either.
 */
final class ListFlaggedMessagesController
{
    use Responder;

    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;
    private const ALLOWED_FLAGS = ['phone', 'email', 'social', 'address'];

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

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        $query = $request->getQueryParams();
        $limit = $this->clampLimit($query['limit'] ?? null);
        $offset = $this->clampOffset($query['offset'] ?? null);
        $flagType = $this->parseFlagType($query['flag_type'] ?? null);

        /** @var MessageRepository $messages */
        $messages = $this->em->getRepository(Message::class);
        $result = $messages->findFlagged($limit, $offset, $flagType);

        $items = array_map(
            fn (Message $m): array => $this->serializer->flaggedMessageShape($m),
            $result['items'],
        );

        $this->logger->info('admin.chat.flagged_listed', [
            'actor_user_id' => $user->getId(),
            'flag_type'     => $flagType,
            'returned'      => count($items),
        ]);

        return $this->ok([
            'messages'   => $items,
            'pagination' => [
                'limit'  => $limit,
                'offset' => $offset,
                'count'  => count($items),
                'total'  => $result['total'],
            ],
        ]);
    }

    private function parseFlagType(mixed $raw): ?string
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $raw = strtolower(trim($raw));
        return in_array($raw, self::ALLOWED_FLAGS, true) ? $raw : null;
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

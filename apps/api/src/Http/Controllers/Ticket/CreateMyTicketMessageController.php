<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Ticket;

use Bayti\Api\Domain\Support\Ticket;
use Bayti\Api\Domain\Support\TicketMessage;
use Bayti\Api\Domain\Support\TicketRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\TicketSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/me/tickets/{id}/messages — reply on one of the authenticated
 * user's own tickets. Always a customer (non-admin) message. Re-opens a
 * resolved/closed ticket so staff see the new activity. 404 if the
 * ticket isn't theirs (no existence leak).
 */
final class CreateMyTicketMessageController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly TicketSerializer $serializer,
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

        $id = (int) $request->getAttribute('id');
        /** @var TicketRepository $repo */
        $repo   = $this->em->getRepository(Ticket::class);
        $ticket = $repo->find($id);
        if ($ticket === null || $ticket->getUserId() !== $user->getId()) {
            throw HttpException::notFound('Ticket not found.');
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $text = trim((string) ($body['message'] ?? $body['body'] ?? ''));
        if ($text === '') {
            throw HttpException::badRequest('message body is required.');
        }

        $authorName = trim($user->getFirstName() . ' ' . $user->getLastName());
        $message    = new TicketMessage(
            ticket:       $ticket,
            body:         $text,
            userId:       $user->getId(),
            isAdminReply: false,
            authorName:   $authorName !== '' ? $authorName : null,
        );
        $ticket->getMessages()->add($message);

        // A customer reply re-opens a resolved/closed ticket so it
        // resurfaces for staff.
        if (in_array($ticket->getStatus(), [Ticket::STATUS_RESOLVED, Ticket::STATUS_CLOSED], true)) {
            $ticket->setStatus(Ticket::STATUS_OPEN);
        }

        $repo->save($ticket);

        return $this->created(PaginatedEnvelope::single($this->serializer->messageShape($message)));
    }
}

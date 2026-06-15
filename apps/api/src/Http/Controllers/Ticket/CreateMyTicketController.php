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
 * POST /v3/me/tickets — open a support ticket as the authenticated user.
 *
 * Body: { subject, body|message, vendor_id? }. The opening message is
 * stored both as the ticket body and as the first (non-admin) thread
 * message so the conversation reads naturally. Owner is always the
 * authed user (never trusted from the body).
 */
final class CreateMyTicketController
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

        $body    = (array) ($request->getParsedBody() ?? []);
        $subject = trim((string) ($body['subject'] ?? ''));
        $text    = trim((string) ($body['body'] ?? $body['message'] ?? ''));
        if ($subject === '') {
            throw HttpException::badRequest('subject is required.');
        }
        if ($text === '') {
            throw HttpException::badRequest('message body is required.');
        }

        $vendorId = isset($body['vendor_id']) && $body['vendor_id'] !== '' ? (int) $body['vendor_id'] : null;

        $ticket = new Ticket(
            subject:  $subject,
            body:     $text,
            vendorId: $vendorId,
            userId:   $user->getId(),
        );

        // Seed the thread with the opening message (author = the customer).
        $authorName = trim($user->getFirstName() . ' ' . $user->getLastName());
        $ticket->getMessages()->add(new TicketMessage(
            ticket:       $ticket,
            body:         $text,
            userId:       $user->getId(),
            isAdminReply: false,
            authorName:   $authorName !== '' ? $authorName : null,
        ));

        /** @var TicketRepository $repo */
        $repo = $this->em->getRepository(Ticket::class);
        $repo->save($ticket);

        return $this->created(PaginatedEnvelope::single($this->serializer->detailShape($ticket)));
    }
}

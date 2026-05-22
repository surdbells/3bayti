<?php declare(strict_types=1);
namespace Bayti\Api\Http\Controllers\Admin\Ticket;

use Bayti\Api\Domain\Support\Ticket;
use Bayti\Api\Domain\Support\TicketMessage;
use Bayti\Api\Domain\Support\TicketRepository;
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

/** POST /v3/admin/tickets/{id}/messages */
final class CreateTicketMessageController
{
    use Responder;
    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
    ) {}
    protected function getResponseFactory(): ResponseFactoryInterface { return $this->responseFactory; }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        $id   = (int) $request->getAttribute('id');
        /** @var TicketRepository $repo */
        $repo   = $this->em->getRepository(Ticket::class);
        $ticket = $repo->find($id);
        if ($ticket === null) throw HttpException::notFound('Ticket not found.');

        $body = (array) ($request->getParsedBody() ?? []);
        $text = trim((string) ($body['message'] ?? $body['body'] ?? ''));
        if ($text === '') throw HttpException::badRequest('message body is required.');

        $authorName = $user->getFirstName() . ' ' . $user->getLastName();
        $message    = new TicketMessage(
            ticket:       $ticket,
            body:         $text,
            userId:       $user->getId(),
            isAdminReply: true,
            authorName:   trim($authorName) ?: 'Admin',
        );
        $ticket->getMessages()->add($message);
        $repo->save($ticket);

        return $this->created(PaginatedEnvelope::single([
            'id'            => $message->getId(),
            'body'          => $message->getBody(),
            'author_name'   => $message->getAuthorName(),
            'is_admin_reply'=> $message->isAdminReply(),
            'created_at'    => $message->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ]));
    }
}

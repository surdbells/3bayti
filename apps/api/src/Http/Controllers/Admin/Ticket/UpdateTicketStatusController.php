<?php declare(strict_types=1);
namespace Bayti\Api\Http\Controllers\Admin\Ticket;

use Bayti\Api\Domain\Support\Ticket;
use Bayti\Api\Domain\Support\TicketRepository;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** PATCH /v3/admin/tickets/{id}/status */
final class UpdateTicketStatusController
{
    use Responder;
    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
    ) {}
    protected function getResponseFactory(): ResponseFactoryInterface { return $this->responseFactory; }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $id   = (int) $request->getAttribute('id');
        /** @var TicketRepository $repo */
        $repo   = $this->em->getRepository(Ticket::class);
        $ticket = $repo->find($id);
        if ($ticket === null) throw HttpException::notFound('Ticket not found.');

        $body   = (array) ($request->getParsedBody() ?? []);
        $status = trim((string) ($body['status'] ?? ''));
        $valid  = [Ticket::STATUS_OPEN, Ticket::STATUS_PENDING, Ticket::STATUS_RESOLVED, Ticket::STATUS_CLOSED];
        if (!in_array($status, $valid, true)) {
            throw HttpException::badRequest('status must be one of: ' . implode(', ', $valid));
        }

        $ticket->setStatus($status);
        $repo->save($ticket);

        return $this->ok(PaginatedEnvelope::single([
            'id'     => $ticket->getId(),
            'status' => $ticket->getStatus(),
        ]));
    }
}

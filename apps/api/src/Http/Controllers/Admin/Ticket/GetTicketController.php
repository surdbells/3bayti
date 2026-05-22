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

/** GET /v3/admin/tickets/{id} */
final class GetTicketController
{
    use Responder;
    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
    ) {}
    protected function getResponseFactory(): ResponseFactoryInterface { return $this->responseFactory; }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $id = (int) $request->getAttribute('id');
        /** @var TicketRepository $repo */
        $repo   = $this->em->getRepository(Ticket::class);
        $ticket = $repo->find($id);
        if ($ticket === null) throw HttpException::notFound('Ticket not found.');

        return $this->ok(PaginatedEnvelope::single($this->shape($ticket)));
    }

    /** @return array<string,mixed> */
    private function shape(Ticket $t): array
    {
        $msgs = [];
        foreach ($t->getMessages() as $m) {
            $msgs[] = [
                'id'           => $m->getId(),
                'body'         => $m->getBody(),
                'author_name'  => $m->getAuthorName(),
                'is_admin_reply'=> $m->isAdminReply(),
                'created_at'   => $m->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ];
        }
        return [
            'id'        => $t->getId(),
            'subject'   => $t->getSubject(),
            'body'      => $t->getBody(),
            'status'    => $t->getStatus(),
            'priority'  => $t->getPriority(),
            'vendor_id' => $t->getVendorId(),
            'messages'  => $msgs,
            'created_at'=> $t->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at'=> $t->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}

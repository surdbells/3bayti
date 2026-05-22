<?php declare(strict_types=1);
namespace Bayti\Api\Http\Controllers\Admin\Ticket;

use Bayti\Api\Domain\Support\Ticket;
use Bayti\Api\Domain\Support\TicketRepository;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /v3/admin/tickets/{id}/messages */
final class ListTicketMessagesController
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

        $msgs = [];
        foreach ($ticket->getMessages() as $m) {
            $msgs[] = [
                'id'            => $m->getId(),
                'body'          => $m->getBody(),
                'author_name'   => $m->getAuthorName(),
                'is_admin_reply'=> $m->isAdminReply(),
                'created_at'    => $m->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ];
        }
        return $this->ok(['data' => $msgs, 'meta' => ['total' => count($msgs)]]);
    }
}

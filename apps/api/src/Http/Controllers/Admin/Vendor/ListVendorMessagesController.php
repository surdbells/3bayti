<?php declare(strict_types=1);
namespace Bayti\Api\Http\Controllers\Admin\Vendor;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorMessage;
use Bayti\Api\Domain\Catalog\VendorMessageRepository;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/admin/vendors/{id}/messages[?limit=&offset=]
 *
 * The admin-to-vendor message thread for one vendor (newest first), so the
 * portal's store-messages view can render it. Mirrors the vendor-facing inbox
 * (GET /v3/vendor/messages) but scoped by an explicit vendor id — the
 * counterpart read for SendVendorMessageController's POST on the same path.
 */
final class ListVendorMessagesController
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

        /** @var VendorRepository $vendors */
        $vendors = $this->em->getRepository(Vendor::class);
        if ($vendors->find($id) === null) {
            throw HttpException::notFound('Vendor not found.');
        }

        $q      = $request->getQueryParams();
        $limit  = max(1, min(100, (int) ($q['limit'] ?? 20)));
        $offset = max(0, (int) ($q['offset'] ?? 0));

        /** @var VendorMessageRepository $repo */
        $repo   = $this->em->getRepository(VendorMessage::class);
        $result = $repo->findForVendorPaginated($id, $limit, $offset);

        return $this->ok([
            'data' => array_map([$this, 'shape'], $result['items']),
            'meta' => ['total' => $result['total'], 'limit' => $limit, 'offset' => $offset],
        ]);
    }

    /** @return array<string,mixed> */
    private function shape(VendorMessage $m): array
    {
        return [
            'id'         => $m->getId(),
            'subject'    => $m->getSubject(),
            'body'       => $m->getBody(),
            'is_read'    => $m->isRead(),
            'created_at' => $m->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}

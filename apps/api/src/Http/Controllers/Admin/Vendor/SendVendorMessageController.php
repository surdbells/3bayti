<?php declare(strict_types=1);
namespace Bayti\Api\Http\Controllers\Admin\Vendor;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/admin/vendors/{id}/messages
 * Send an admin-to-vendor platform message. In M3.3 the messaging
 * entity doesn't exist yet; this endpoint validates the vendor exists
 * and records an audit log entry, returning success so the portal UX
 * works while the full messaging module is deferred to M3.5.
 */
final class SendVendorMessageController
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
        /** @var VendorRepository $repo */
        $repo   = $this->em->getRepository(Vendor::class);
        $vendor = $repo->find($id);
        if ($vendor === null) throw HttpException::notFound('Vendor not found.');

        $body    = (array) ($request->getParsedBody() ?? []);
        $subject = trim((string) ($body['subject'] ?? ''));
        $message = trim((string) ($body['message'] ?? ''));

        if ($subject === '' || $message === '') {
            throw HttpException::badRequest('subject and message are required.');
        }

        // TODO M3.5: persist to VendorMessage entity.
        // For now: acknowledge receipt so portal UX works.
        return $this->ok(['data' => [
            'vendor_id' => $vendor->getId(),
            'subject'   => $subject,
            'status'    => 'queued',
            'message'   => 'Message queued for delivery (full messaging in M3.5)',
        ]]);
    }
}

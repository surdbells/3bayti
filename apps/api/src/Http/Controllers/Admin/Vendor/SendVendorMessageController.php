<?php declare(strict_types=1);
namespace Bayti\Api\Http\Controllers\Admin\Vendor;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorMessage;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/admin/vendors/{id}/messages
 * Send an admin-to-vendor platform message. Persists a VendorMessage to
 * the vendor's inbox (read via GET /v3/vendor/messages). The sender is
 * the authenticated admin (nullable on the row if unresolved).
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

        $sender = $request->getAttribute(AuthMiddleware::ATTR_USER);
        $senderUser = $sender instanceof User ? $sender : null;

        $vm = new VendorMessage($vendor, $senderUser, $message, $subject);
        $this->em->persist($vm);
        $this->em->flush();

        return $this->created(['data' => [
            'id'        => $vm->getId(),
            'vendor_id' => $vendor->getId(),
            'subject'   => $subject,
            'status'    => 'sent',
        ]]);
    }
}

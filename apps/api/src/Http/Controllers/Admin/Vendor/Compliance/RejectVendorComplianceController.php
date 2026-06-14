<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Vendor\Compliance;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\Compliance\ComplianceNotificationService;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/admin/vendors/{id}/compliance/reject
 *
 * Reject a vendor's KYC submission with an optional reason. Records the
 * reviewing admin + time; the vendor can then re-submit.
 */
final class RejectVendorComplianceController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly ComplianceNotificationService $notifier,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    /** @param array<string, string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        $idRaw = $args['id'] ?? '';
        if (!ctype_digit((string) $idRaw)) {
            throw HttpException::notFound('Vendor not found.');
        }

        /** @var VendorRepository $repo */
        $repo = $this->em->getRepository(Vendor::class);
        $vendor = $repo->find((int) $idRaw);
        if ($vendor === null) {
            throw HttpException::notFound('Vendor not found.');
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $note = isset($body['note']) && is_string($body['note']) ? trim($body['note']) : null;
        if ($note === '') {
            $note = null;
        }
        if ($note !== null && strlen($note) > 1000) {
            $note = substr($note, 0, 1000);
        }

        $vendor->rejectCompliance((int) $user->getId(), $note);
        $this->em->flush();

        $this->notifier->rejected($vendor, $note);

        return $this->ok(['data' => [
            'vendor_id'         => (int) $vendor->getId(),
            'compliance_status' => $vendor->getComplianceStatus(),
            'reviewed_at'       => $vendor->getComplianceReviewedAt()?->format(\DateTimeInterface::ATOM),
            'review_note'       => $vendor->getComplianceReviewNote(),
        ]]);
    }
}

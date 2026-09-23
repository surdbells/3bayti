<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Vendor\Customization;

use Bayti\Api\Domain\Customization\CustomizationRequest;
use Bayti\Api\Domain\Customization\CustomizationRequestRepository;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\CustomizationRequestSerializer;
use Bayti\Api\Notification\CustomizationNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * POST /v3/vendor/customization-requests/{id}/complete
 *
 * Vendor marks the (paid) customization work fulfilled — terminal
 * 'completed'. Allowed only from 'paid'.
 */
final class MarkCompletedController
{
    use Responder;
    use ResolvesVendorCustomization;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly CustomizationRequestSerializer $serializer,
        private readonly CustomizationNotificationService $notifications,
        private readonly LoggerInterface $logger,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $_response,
        array $args,
    ): ResponseInterface {
        $customization = $this->resolveForVendor($request, $args, $this->em);

        try {
            $customization->markCompleted();
        } catch (\DomainException $e) {
            throw new HttpException(
                status: 422,
                errorCode: 'CUSTOMIZATION_CANNOT_COMPLETE',
                publicMessage: $e->getMessage(),
            );
        }

        /** @var CustomizationRequestRepository $repo */
        $repo = $this->em->getRepository(CustomizationRequest::class);
        $repo->save($customization);

        // Tell the customer their customization is ready.
        try {
            $this->notifications->customizationCompleted($customization);
        } catch (\Throwable $e) {
            $this->logger->error('customization.notification.completed_failed', [
                'customization_id' => $customization->getId(),
                'error' => $e->getMessage(),
            ]);
        }

        return $this->ok([
            'data' => $this->serializer->vendorShape($customization),
        ]);
    }
}

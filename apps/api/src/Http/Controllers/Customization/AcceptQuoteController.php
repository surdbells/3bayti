<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Customization;

use Bayti\Api\Domain\Customization\CustomizationRequest;
use Bayti\Api\Domain\Customization\CustomizationRequestRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\CustomizationRequestSerializer;
use Bayti\Api\Notification\CustomizationNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * POST /v3/me/customization-requests/{id}/accept
 *
 * Customer accepts the vendor's quote. This does NOT charge anything —
 * it moves the request to 'accepted'; the client then initiates checkout
 * (POST /v3/checkout/initiate {customization_request_id}) to pay.
 * Allowed only from 'quoted'.
 */
final class AcceptQuoteController
{
    use Responder;

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
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            throw HttpException::notFound('Customization request not found.');
        }

        /** @var CustomizationRequestRepository $repo */
        $repo = $this->em->getRepository(CustomizationRequest::class);
        $customization = $repo->findById($id);
        if ($customization === null || $customization->getCustomer()->getId() !== $user->getId()) {
            throw HttpException::notFound('Customization request not found.');
        }

        try {
            $customization->acceptQuote();
        } catch (\DomainException $e) {
            throw new HttpException(
                status: 422,
                errorCode: 'CUSTOMIZATION_CANNOT_ACCEPT',
                publicMessage: $e->getMessage(),
            );
        }

        $repo->save($customization);

        // Notify the vendor the customer accepted (payment now in progress).
        try {
            $this->notifications->customizationAccepted($customization);
        } catch (\Throwable $e) {
            $this->logger->error('customization.notification.accepted_failed', [
                'customization_id' => $customization->getId(),
                'error' => $e->getMessage(),
            ]);
        }

        return $this->ok([
            'data' => $this->serializer->customerShape($customization),
        ]);
    }
}

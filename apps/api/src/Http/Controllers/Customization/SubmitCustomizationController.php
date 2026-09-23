<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Customization;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Customization\CustomizationRequest;
use Bayti\Api\Domain\Customization\CustomizationRequestRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Customization\Dto\SubmitCustomizationInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\CustomizationRequestSerializer;
use Bayti\Api\Http\Validator\RequestValidator;
use Bayti\Api\Notification\CustomizationNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * POST /v3/me/customization-requests
 *
 * Customer opens a bespoke-customization request on a specific product.
 * The product is resolved by slug (never a legacy id) and must be
 * orderable — but need NOT be in stock, since customization is
 * made-to-order. A customer can hold only one in-flight request per
 * product (dup guard → 409).
 *
 * 201 with the customer-shape of the new request (status 'pending').
 */
final class SubmitCustomizationController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
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

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        $input = $this->validator->parse($request, SubmitCustomizationInput::class);

        /** @var ProductRepository $productRepo */
        $productRepo = $this->em->getRepository(Product::class);
        $product = $productRepo->findBySlug($input->product_slug);
        // Anti-hallucination: only a live, sellable product from an approved
        // vendor can be customized. Stock is intentionally NOT required —
        // bespoke work is made-to-order.
        if ($product === null || !$product->isOrderable()) {
            throw HttpException::notFound('Product not found.');
        }

        $productId = $product->getId();
        $userId = $user->getId();
        /** @var CustomizationRequestRepository $repo */
        $repo = $this->em->getRepository(CustomizationRequest::class);
        if ($productId !== null && $userId !== null
            && $repo->hasActiveForProductAndCustomer($productId, $userId)) {
            throw HttpException::conflict(
                'CUSTOMIZATION_ALREADY_REQUESTED',
                'You already have an active customization request for this product.',
            );
        }

        $customization = new CustomizationRequest(
            product: $product,
            vendor: $product->getVendor(),
            customer: $user,
            customerNotes: $input->description,
            measurementSnapshot: $input->measurement_snapshot,
        );
        $repo->save($customization);

        // Notify the vendor there's a request to quote. Never block the
        // response on an email failure.
        try {
            $this->notifications->customizationSubmitted($customization);
        } catch (\Throwable $e) {
            $this->logger->error('customization.notification.submitted_failed', [
                'customization_id' => $customization->getId(),
                'error' => $e->getMessage(),
            ]);
        }

        return $this->created([
            'data' => $this->serializer->customerShape($customization),
        ]);
    }
}

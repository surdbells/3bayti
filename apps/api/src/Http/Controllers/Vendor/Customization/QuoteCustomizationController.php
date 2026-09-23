<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Vendor\Customization;

use Bayti\Api\Domain\Customization\CustomizationRequestRepository;
use Bayti\Api\Http\Controllers\Customization\Dto\QuoteCustomizationInput;
use Bayti\Api\Http\Errors\HttpException;
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
 * POST /v3/vendor/customization-requests/{id}/quote
 *
 * Vendor prices the work (amount + currency + optional lead time + notes),
 * moving the request 'pending' → 'quoted'. Allowed only from 'pending'.
 */
final class QuoteCustomizationController
{
    use Responder;
    use ResolvesVendorCustomization;

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

    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $_response,
        array $args,
    ): ResponseInterface {
        $customization = $this->resolveForVendor($request, $args, $this->em);
        $input = $this->validator->parse($request, QuoteCustomizationInput::class);

        try {
            $customization->quote(
                $input->amount,
                $input->currency,
                $input->lead_time_days,
                $input->vendor_notes,
            );
        } catch (\DomainException $e) {
            throw new HttpException(
                status: 422,
                errorCode: 'CUSTOMIZATION_CANNOT_QUOTE',
                publicMessage: $e->getMessage(),
            );
        } catch (\InvalidArgumentException $e) {
            throw HttpException::validation(['amount' => [$e->getMessage()]]);
        }

        /** @var CustomizationRequestRepository $repo */
        $repo = $this->em->getRepository(\Bayti\Api\Domain\Customization\CustomizationRequest::class);
        $repo->save($customization);

        // Notify the customer their quote is ready to accept/decline.
        try {
            $this->notifications->customizationQuoted($customization);
        } catch (\Throwable $e) {
            $this->logger->error('customization.notification.quoted_failed', [
                'customization_id' => $customization->getId(),
                'error' => $e->getMessage(),
            ]);
        }

        return $this->ok([
            'data' => $this->serializer->vendorShape($customization),
        ]);
    }
}

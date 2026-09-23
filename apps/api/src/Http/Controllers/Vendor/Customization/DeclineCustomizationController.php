<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Vendor\Customization;

use Bayti\Api\Domain\Customization\CustomizationRequest;
use Bayti\Api\Domain\Customization\CustomizationRequestRepository;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\CustomizationRequestSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/vendor/customization-requests/{id}/decline
 *
 * Vendor declines to take on the work (terminal 'declined'), optionally
 * with a reason (vendor_notes in the JSON body). Allowed only from
 * 'pending'.
 */
final class DeclineCustomizationController
{
    use Responder;
    use ResolvesVendorCustomization;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly CustomizationRequestSerializer $serializer,
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

        $body = $request->getParsedBody();
        $vendorNotes = null;
        if (is_array($body) && isset($body['vendor_notes']) && is_string($body['vendor_notes'])) {
            $vendorNotes = $body['vendor_notes'];
        }

        try {
            $customization->declineByVendor($vendorNotes);
        } catch (\DomainException $e) {
            throw new HttpException(
                status: 422,
                errorCode: 'CUSTOMIZATION_CANNOT_DECLINE',
                publicMessage: $e->getMessage(),
            );
        }

        /** @var CustomizationRequestRepository $repo */
        $repo = $this->em->getRepository(CustomizationRequest::class);
        $repo->save($customization);

        return $this->ok([
            'data' => $this->serializer->vendorShape($customization),
        ]);
    }
}

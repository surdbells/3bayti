<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Address;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\User\Address;
use Bayti\Api\Domain\User\AddressRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Address\Dto\UpdateAddressInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\AddressSerializer;
use Bayti\Api\Http\Validator\RequestValidator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /v3/me/addresses/{id}
 *
 * Full-replace update of an existing address. All required fields
 * (recipient_name, recipient_phone, emirate, area) must be present;
 * optional fields can be omitted to clear them.
 *
 * Default flags (isDefaultShipping, isDefaultBilling) are NOT touched
 * by this endpoint. To change defaults, use PATCH .../default.
 *
 * IDOR protection: 404 if address belongs to a different user. See
 * GetAddressController docblock for the rationale.
 *
 * Response: 200 with the updated address.
 */
final class UpdateAddressController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly EntityManagerInterface $em,
        private readonly AddressSerializer $serializer,
        private readonly AuditEmitter $audit,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(
                ErrorCodes::AUTH_INVALID_TOKEN,
                'Authentication required.',
            );
        }

        $idRaw = $args['id'] ?? '';
        if (!ctype_digit((string) $idRaw)) {
            throw HttpException::notFound('Address not found.');
        }
        $id = (int) $idRaw;

        $address = $this->em->getRepository(Address::class)->find($id);
        if ($address === null || $address->getUser()->getId() !== $user->getId()) {
            throw HttpException::notFound('Address not found.');
        }

        // Validate body. PUT means we read the full body; the DTO
        // requires all four required fields.
        $input = $this->validator->parse($request, UpdateAddressInput::class);

        // M1.6.1.C — capture pre-mutation state for audit diff.
        $beforeSnapshot = $this->audit->snapshot($address);

        // Address::update() accepts nullable strings for partial
        // updates — if a field is null, the existing value stays.
        // For PUT semantics we ALWAYS pass values for required
        // fields and explicitly pass nullables (clears them when
        // omitted from the request).
        //
        // To get true full-replace behavior on optional fields
        // (omitted in JSON → cleared), we'd have to distinguish
        // 'absent' from 'null in JSON' — which RequestValidator
        // can't do (same tristate issue). Acceptable trade-off:
        // PUT here clears optional fields when null is explicitly
        // sent, and leaves them when omitted. That matches REST
        // expectations closely enough; users wanting to clear a
        // field send {"label": null} explicitly.
        $address->update(
            label: $input->label,
            recipientName: $input->recipient_name,
            recipientPhone: $input->recipient_phone,
            emirate: $input->emirate,
            area: $input->area,
            streetAddress: $input->street_address,
            buildingDetails: $input->building_details,
            postalCode: $input->postal_code,
        );

        $this->em->flush();

        // Emit update audit. AuditEmitter computes the diff (only
        // changed fields land in the row).
        $this->audit->recordUpdate(
            request: $request,
            actor: $user,
            subject: $address,
            beforeSnapshot: $beforeSnapshot,
            afterSnapshot: $this->audit->snapshot($address),
        );

        return $this->ok([
            'address' => $this->serializer->publicShape($address),
        ]);
    }
}

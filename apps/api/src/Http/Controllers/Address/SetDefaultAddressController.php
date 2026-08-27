<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Address;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\User\Address;
use Bayti\Api\Domain\User\AddressRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Address\Dto\SetDefaultInput;
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
 * PATCH /v3/me/addresses/{id}/default
 *
 * Set or unset this address's role-specific default flags.
 *
 * Request body
 * ------------
 *   { "shipping": true,  "billing": true  }   set both
 *   { "shipping": true,  "billing": false }   set shipping, clear billing
 *   { "shipping": false }                     clear shipping (no auto-promote)
 *   { "billing":  true  }                     set billing only
 *   { } or {"shipping": null, "billing": null} → 422 (no operation)
 *
 * Setting a flag to `true` triggers the "unset on all others, set on
 * this" workflow via AddressRepository::setAsDefaultShipping /
 * setAsDefaultBilling. Both methods open their own transaction.
 *
 * Setting a flag to `false` clears it on this address only, no
 * other address auto-promotes. The user can have zero defaults
 * after this call (acceptable; checkout will prompt).
 *
 * Sending null for a flag (or omitting it) means "leave that flag
 * alone."
 *
 * Response: 200 with the updated address.
 *
 * IDOR: 404 if not yours.
 */
final class SetDefaultAddressController
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

        /** @var AddressRepository $addresses */
        $addresses = $this->em->getRepository(Address::class);
        $address = $addresses->find($id);

        if ($address === null || $address->getUser()->getId() !== $user->getId()) {
            throw HttpException::notFound('Address not found.');
        }

        $input = $this->validator->parse($request, SetDefaultInput::class);

        // Track which flags actually changed (for audit). We compare
        // the post-mutation state against the pre-mutation state.
        $beforeShipping = $address->isDefaultShipping();
        $beforeBilling = $address->isDefaultBilling();

        // Apply each flag if its field was provided. Setting true
        // goes through the repo (transactional unset-others-set-this).
        // Setting false just clears on this address, no other side
        // effects.
        if ($input->shipping === true) {
            $addresses->setAsDefaultShipping($address);
        } elseif ($input->shipping === false) {
            $address->setDefaultShipping(false);
            $this->em->flush();
        }

        if ($input->billing === true) {
            $addresses->setAsDefaultBilling($address);
        } elseif ($input->billing === false) {
            $address->setDefaultBilling(false);
            $this->em->flush();
        }

        // Emit a 'default' audit event with the actual flag changes.
        // Only fields that actually changed end up in the changes map
        //, if user PATCH'd shipping=true on an already-default
        // address, no change happened, no audit row.
        $changes = [];
        if ($beforeShipping !== $address->isDefaultShipping()) {
            $changes['is_default_shipping'] = [
                'before' => $beforeShipping,
                'after' => $address->isDefaultShipping(),
            ];
        }
        if ($beforeBilling !== $address->isDefaultBilling()) {
            $changes['is_default_billing'] = [
                'before' => $beforeBilling,
                'after' => $address->isDefaultBilling(),
            ];
        }
        if (!empty($changes)) {
            $this->audit->recordDefault(
                request: $request,
                actor: $user,
                subject: $address,
                changes: $changes,
            );
        }

        return $this->ok([
            'address' => $this->serializer->publicShape($address),
        ]);
    }
}

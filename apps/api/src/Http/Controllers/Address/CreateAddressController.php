<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Address;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\User\Address;
use Bayti\Api\Domain\User\AddressRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Address\Dto\CreateAddressInput;
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
 * POST /v3/me/addresses
 *
 * Create a new address for the authenticated user.
 *
 * Request body
 * ------------
 *   {
 *     "recipient_name": "Alice",        REQUIRED
 *     "recipient_phone": "+971501234567", REQUIRED
 *     "emirate": "Dubai",                REQUIRED
 *     "area": "Jumeirah",                REQUIRED
 *     "street_address": "Beach Road",   optional
 *     "building_details": "Villa 12",   optional
 *     "postal_code": "12345",           optional
 *     "label": "Home",                  optional
 *     "is_default": true                optional, default false
 *   }
 *
 * Response
 * --------
 *   201 Created
 *   { "address": { "id": 123, "recipient_name": "Alice", ..., "is_default": true } }
 *
 * Auto-default behaviour
 * ----------------------
 * If the user has NO addresses yet, the first one we create becomes
 * the default automatically, regardless of the `is_default` flag in
 * the request. This avoids the "user has 1 address but no default"
 * UX glitch where checkout has to prompt to pick a default.
 *
 * If the user has addresses already and provides is_default=true,
 * we set this address as default AND unset it on whichever was the
 * previous default.
 *
 * If is_default=false (or absent), the new address is just appended
 * without disrupting existing defaults.
 *
 * Address count limit
 * -------------------
 * 50 addresses per user. Anything above starts to suggest abuse
 * (testing, scraping). 50 is generous, most users have 1-3,
 * corporate users have ~10. The limit is checked here, not at the
 * DB level.
 */
final class CreateAddressController
{
    use Responder;

    /** Hard cap on addresses per user. See class docblock. */
    private const MAX_ADDRESSES_PER_USER = 50;

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

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(
                ErrorCodes::AUTH_INVALID_TOKEN,
                'Authentication required.',
            );
        }

        $input = $this->validator->parse($request, CreateAddressInput::class);

        /** @var AddressRepository $addresses */
        $addresses = $this->em->getRepository(Address::class);

        // Enforce the address-count cap before doing any writes.
        $existing = $addresses->findAllForUser($user);
        if (count($existing) >= self::MAX_ADDRESSES_PER_USER) {
            throw HttpException::validation([
                '_root' => [
                    sprintf(
                        'Cannot create more than %d addresses per account.',
                        self::MAX_ADDRESSES_PER_USER,
                    ),
                ],
            ]);
        }

        $isFirstAddress = count($existing) === 0;

        $address = new Address(
            user: $user,
            recipientName: $input->recipient_name,
            recipientPhone: $input->recipient_phone,
            emirate: $input->emirate,
            area: $input->area,
            streetAddress: $input->street_address,
            buildingDetails: $input->building_details,
            postalCode: $input->postal_code,
            label: $input->label,
        );

        // Default-flag handling, three cases:
        //
        //   1. First address ever: auto-default (set both flags).
        //   2. User explicitly set is_default=true: unset previous
        //      default, set this one as default for both shipping
        //      and billing. (M1.7.2 uses the combined concept.)
        //   3. is_default=false (or absent): just append.
        //
        if ($isFirstAddress) {
            $address->setDefaultShipping(true);
            $address->setDefaultBilling(true);
            $addresses->save($address);
        } elseif ($input->is_default) {
            // Persist first so it has an id, then promote.
            $addresses->save($address, flush: false);
            $this->em->flush();
            // setAsDefaultShipping / setAsDefaultBilling each open
            // their own transaction; that's fine here.
            $addresses->setAsDefaultShipping($address);
            $addresses->setAsDefaultBilling($address);
        } else {
            $addresses->save($address);
        }

        // M1.6.1.C, record the create in audit log. Snapshot post-save
        // so we capture the final defaults state too.
        $this->audit->recordCreate(
            request: $request,
            actor: $user,
            subject: $address,
            afterSnapshot: $this->audit->snapshot($address),
        );

        return $this->created([
            'address' => $this->serializer->publicShape($address),
        ]);
    }
}

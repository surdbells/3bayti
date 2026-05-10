<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Address;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\User\Address;
use Bayti\Api\Domain\User\AddressRepository;
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
 * DELETE /v3/me/addresses/{id}
 *
 * Hard-delete the address. Addresses are not soft-deleted because:
 *   - The legacy schema doesn't have a deleted_at column
 *   - Orders that referenced the address store the snapshot at
 *     order time (M3 design), so deleting the address record
 *     doesn't break order history
 *   - Soft-delete adds query-filter complexity for marginal benefit
 *
 * Auto-promotion on default deletion
 * ----------------------------------
 * If the deleted address was the default for shipping or billing
 * (or both), the OLDEST remaining address of the user is auto-
 * promoted into that role. Three reasons:
 *
 *   1. UX: avoids leaving the user with no default, which forces
 *      checkout to prompt every time
 *   2. "Oldest" is deterministic — the address the user has had
 *      longest is most likely their actual home address
 *   3. The same logic could be triggered by deleting the LAST
 *      address (no promotion possible — that's fine, no defaults
 *      to set)
 *
 * If no other addresses remain, no promotion happens and the user
 * has zero addresses.
 *
 * Response: 204 No Content.
 *
 * IDOR: 404 if not yours.
 */
final class DeleteAddressController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
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

        // Capture default-flag state BEFORE deletion so we know what
        // to promote afterwards.
        $wasDefaultShipping = $address->isDefaultShipping();
        $wasDefaultBilling = $address->isDefaultBilling();

        // M1.6.1.C — snapshot pre-delete state for audit. Must capture
        // BEFORE the remove() — afterwards the entity is detached and
        // its id may be reset by Doctrine.
        $beforeSnapshot = $this->audit->snapshot($address);
        $deletedAddressId = $address->getId();

        // Delete the address. Doctrine cascades nothing here — there
        // are no FK references from other tables to address.id yet
        // (orders snapshot the address at order time per M3 design).
        $addresses->remove($address);

        // Auto-promotion: if the deleted address was a default and
        // others remain, promote the oldest. We refresh the list
        // AFTER the delete since findAllForUser would otherwise
        // include the just-removed address.
        if ($wasDefaultShipping || $wasDefaultBilling) {
            $remaining = $addresses->findAllForUser($user);
            // findAllForUser orders default-shipping first then
            // created_at DESC. We want the OLDEST (longest-held),
            // so reverse to get oldest first... actually that's
            // not quite right either. Let me sort by createdAt
            // ascending explicitly.
            usort(
                $remaining,
                fn (Address $a, Address $b) =>
                    $a->getCreatedAt() <=> $b->getCreatedAt()
            );

            if (count($remaining) > 0) {
                $promoted = $remaining[0];
                if ($wasDefaultShipping) {
                    // setAsDefaultShipping unsets the flag on all
                    // OTHER addresses (none of which had it now —
                    // we just deleted the one that did) and sets it
                    // on this one.
                    $addresses->setAsDefaultShipping($promoted);
                }
                if ($wasDefaultBilling) {
                    $addresses->setAsDefaultBilling($promoted);
                }
            }
        }

        // Emit delete audit. We pass the address INSTANCE (still in
        // memory, even if detached) for type/id resolution; the actual
        // state captured is the pre-delete snapshot.
        // Note: subjectId is read from $address->getId() which Doctrine
        // may have nulled — handle defensively by using the captured id
        // when present.
        if ($deletedAddressId !== null) {
            // Build the audit row manually here because the standard
            // recordDelete path resolves the id from the entity, which
            // may now be null after remove(). Workaround: temporarily
            // restore the id property via reflection so AuditEmitter
            // can read it.
            $ref = new \ReflectionProperty(Address::class, 'id');
            $ref->setAccessible(true);
            $ref->setValue($address, $deletedAddressId);
        }

        $this->audit->recordDelete(
            request: $request,
            actor: $user,
            subject: $address,
            beforeSnapshot: $beforeSnapshot,
        );

        return $this->noContent();
    }
}

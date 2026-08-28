<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\User;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Admin\User\Dto\UpdateUserInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\UserSerializer;
use Bayti\Api\Http\Validator\RequestValidator;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /v3/admin/users/{id}   (users.edit)
 *
 * Admin support-edit of a user's CONTACT details, name, email, and phone,
 * so support staff can correct a customer's information on their behalf.
 *
 * Scope is deliberately narrow: this touches only contact fields. Roles,
 * flags, password, and active status are changed through their own
 * dedicated endpoints, so a contact edit can never escalate privileges.
 *
 * Email / phone uniqueness is checked pre-flight for a friendly 409, with
 * the DB UNIQUE constraint as the race-safe backstop. Changing either value
 * resets its verified flag (the new value is not yet proven owned).
 *
 * Failure modes:
 *   - Non-numeric id → 404 (route pattern already constrains to digits)
 *   - Id not found → 404
 *   - Email/phone already taken by another account → 409
 *   - Missing permission → 403 (PermissionMiddleware)
 */
final class UpdateUserController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly EntityManagerInterface $em,
        private readonly UserSerializer $serializer,
        private readonly AuditEmitter $audit,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$actor instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        $id = (int) $request->getAttribute('id');

        /** @var UserRepository $users */
        $users = $this->em->getRepository(User::class);
        $user = $users->findById($id);
        if ($user === null) {
            throw HttpException::notFound('User not found.');
        }

        $input = $this->validator->parse($request, UpdateUserInput::class);

        $before = $this->audit->snapshot($user);

        // Name is always (re)applied; the portal form pre-fills the current
        // values, so an unchanged submit is a no-op.
        $user->setName($input->first_name, $input->last_name);

        // Email: only touch it (and reset verification) when it actually
        // changes, so an unchanged submit doesn't spuriously unverify.
        $emailChanged = $input->email !== $user->getEmail();
        if ($emailChanged) {
            if (!$users->isEmailAvailable($input->email)) {
                throw HttpException::conflict(
                    ErrorCodes::CONFLICT_EMAIL_TAKEN,
                    'That email address is already registered to another account.',
                );
            }
            $user->setEmail($input->email);
        }

        // Phone: same "only on change" rule. A non-null new value must be
        // free; a null value clears the phone.
        $phoneChanged = $input->phone !== $user->getPhone();
        if ($phoneChanged) {
            if ($input->phone !== null && !$users->isPhoneAvailable($input->phone)) {
                throw HttpException::conflict(
                    ErrorCodes::CONFLICT_PHONE_TAKEN,
                    'That phone number is already registered to another account.',
                );
            }
            $user->setPhone($input->phone);
        }

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Race backstop: another writer claimed the email/phone between
            // our pre-flight check and flush. Report the field that changed.
            throw HttpException::conflict(
                $emailChanged ? ErrorCodes::CONFLICT_EMAIL_TAKEN : ErrorCodes::CONFLICT_PHONE_TAKEN,
                'That email address or phone number is already registered to another account.',
            );
        }

        $this->audit->recordUpdate(
            request: $request,
            actor: $actor,
            subject: $user,
            beforeSnapshot: $before,
            afterSnapshot: $this->audit->snapshot($user),
        );

        return $this->ok(PaginatedEnvelope::single($this->serializer->publicProfile($user)));
    }
}

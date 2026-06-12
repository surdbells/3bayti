<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\User;

use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Admin\User\Dto\CreateUserInput;
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
use Psr\Log\LoggerInterface;

/**
 * POST /v3/admin/users
 *
 * Admin-initiated creation of a back-office staff account (finance /
 * support / sub-admin). Separate from public self-registration:
 *
 *  - Created ACTIVE and email-verified (an admin vouches for the account;
 *    there is no OTP/phone step for staff).
 *  - Roles assigned explicitly from the request; customer/vendor flags are
 *    never set by this path, so a staff account can't accidentally gain
 *    storefront access.
 *  - Email uniqueness checked pre-flight for a friendly 409, with the DB
 *    UNIQUE constraint as the race-safe backstop.
 *
 * Guarded by AdminAuthMiddleware (admin-tier only) + AuthMiddleware.
 * The acting admin is recorded in the audit log for traceability.
 */
final class CreateUserController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly EntityManagerInterface $em,
        private readonly UserSerializer $serializer,
        private readonly LoggerInterface $logger,
    ) {}

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $input = $this->validator->parse($request, CreateUserInput::class);

        /** @var UserRepository $users */
        $users = $this->em->getRepository(User::class);

        // Friendly pre-flight; the DB UNIQUE constraint is the real guard.
        if (!$users->isEmailAvailable($input->email)) {
            throw HttpException::conflict(
                ErrorCodes::CONFLICT_EMAIL_TAKEN,
                'That email address is already registered.',
            );
        }

        // Staff accounts have no phone; pass null. Created active.
        $user = new User(
            email: $input->email,
            phone: null,
            passwordHash: password_hash($input->password, PASSWORD_BCRYPT),
        );
        $user->setName($input->first_name, $input->last_name);
        $user->markEmailVerified();

        // Explicit role assignment. Never customer/vendor via this path.
        // sub_admin implies admin-tier console access; finance/support are
        // scoped roles. We set the requested flags and leave the rest false.
        $user->setRoles(
            customer: false,
            vendor: false,
            admin: false,
            finance: $input->is_finance,
            support: $input->is_support,
            subAdmin: $input->is_sub_admin,
        );

        try {
            $users->save($user);
        } catch (UniqueConstraintViolationException) {
            // Race winner already inserted the email.
            throw HttpException::conflict(
                ErrorCodes::CONFLICT_EMAIL_TAKEN,
                'That email address is already registered.',
            );
        }

        $actingAdmin = $request->getAttribute(AuthMiddleware::ATTR_USER);
        $this->logger->info('admin.user.created', [
            'created_user_id' => $user->getId(),
            'created_email'   => $user->getEmail(),
            'roles'           => [
                'finance'   => $input->is_finance,
                'support'   => $input->is_support,
                'sub_admin' => $input->is_sub_admin,
            ],
            'by_admin_id'     => $actingAdmin instanceof User ? $actingAdmin->getId() : null,
        ]);

        return $this->created(
            PaginatedEnvelope::single($this->serializer->publicProfile($user)),
        );
    }
}

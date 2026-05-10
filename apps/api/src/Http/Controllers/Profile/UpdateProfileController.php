<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Profile;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\User\Gender;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Profile\Dto\UpdateProfileInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\UserSerializer;
use Bayti\Api\Http\Validator\RequestValidator;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PATCH /v3/me/profile
 *
 * Update the authenticated user's profile. JSON Merge Patch semantics:
 * provided fields are updated, missing fields stay unchanged.
 *
 * Editable fields:
 *   - first_name, last_name (strings, max 100 chars)
 *   - gender (one of: male, female, other, prefer_not_to_say)
 *   - dob (ISO 8601 date)
 *   - locale (en, ar, en-AE, ar-AE)
 *   - timezone (IANA timezone identifier)
 *
 * Not editable here:
 *   - email, phone, password — separate flows with re-verification
 *   - country_code — set at registration
 *   - role flags — admin-only
 *
 * Empty body (no fields provided)
 * --------------------------------
 * Returns 200 with the current profile (no-op). Mirrors what HTTP
 * does with idempotent operations and avoids forcing the caller to
 * branch on "did I have changes to send?". The only cost of an empty
 * PATCH is a request round-trip and a serialization — no DB write
 * happens.
 *
 * Response shape
 * --------------
 *   200 OK
 *   { "user": { ...full profile... } }
 *
 * Failure modes
 * -------------
 *   - 401 if AuthMiddleware didn't attach a user (token issue)
 *   - 422 with field-level errors if any provided field fails validation
 *   - 400 if the body isn't a JSON object (RequestValidator handles this)
 *
 * Audit log
 * ---------
 * Per M1.6.1.C, profile updates emit a structured audit_log row with:
 *   - actor user_id (the user updating their own profile)
 *   - subject_type='User', subject_id={user_id}
 *   - action='updated'
 *   - changes={before:{changed_fields},after:{changed_fields}}
 *   - ip_address, user_agent, request_id
 *
 * Sensitive fields (none on User profile yet) would be redacted.
 * No audit row is written if nothing actually changed.
 *
 * Why no notification
 * -------------------
 * In M3+ we may want to email/SMS the user "Your profile was updated"
 * as a security signal (helpful if their account was hijacked).
 * That's M3+ work — needs ZeptoMail integration and template support.
 */
final class UpdateProfileController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly UserSerializer $userSerializer,
        private readonly EntityManagerInterface $em,
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

        $input = $this->validator->parse($request, UpdateProfileInput::class);

        // Empty PATCH → 200 no-op, return current profile.
        if (!$input->hasAnyField()) {
            return $this->ok([
                'user' => $this->userSerializer->publicProfile($user),
            ]);
        }

        // M1.6.1.C — capture pre-mutation state for audit diff. Must
        // happen BEFORE applyChanges or we'd compare a mutated entity
        // against itself.
        $beforeSnapshot = $this->audit->snapshot($user);

        // Apply changes to the entity. Each field is gated on
        // null-check so we only touch fields the user actually sent.
        // The User entity setters do their own normalization (trim,
        // setTime to midnight, etc).
        $this->applyChanges($user, $input);

        // One flush per request — Doctrine batches the UPDATE.
        $this->em->flush();

        // Emit audit AFTER flush so we know the change actually
        // landed. AuditEmitter will compute the diff (only changed
        // fields end up in the row).
        $afterSnapshot = $this->audit->snapshot($user);
        $this->audit->recordUpdate(
            request: $request,
            actor: $user,
            subject: $user,
            beforeSnapshot: $beforeSnapshot,
            afterSnapshot: $afterSnapshot,
        );

        return $this->ok([
            'user' => $this->userSerializer->publicProfile($user),
        ]);
    }

    /**
     * Apply the DTO's non-null fields to the User entity. Pulled out
     * for testability — easier to assert "after applyChanges, user
     * has these fields" without reaching for the EM.
     */
    private function applyChanges(User $user, UpdateProfileInput $input): void
    {
        // Names: setName takes both, but we only want to touch the
        // ones provided. Read existing values for the other.
        if ($input->first_name !== null || $input->last_name !== null) {
            $user->setName(
                $input->first_name ?? $user->getFirstName(),
                $input->last_name ?? $user->getLastName(),
            );
        }

        if ($input->gender !== null) {
            // We already validated via Assert\Choice, but use
            // Gender::tryFrom (via fromStringOrNull) to convert string
            // to enum. The setter takes the enum, not the raw string.
            $user->setGender(Gender::fromStringOrNull($input->gender));
        }

        if ($input->dob !== null) {
            // Already validated as YYYY-MM-DD — createFromFormat will
            // succeed. setDob normalizes to midnight.
            $dob = DateTimeImmutable::createFromFormat('Y-m-d', $input->dob);
            if ($dob !== false) {
                $user->setDob($dob);
            }
        }

        if ($input->locale !== null) {
            $user->setLocale($input->locale);
        }

        if ($input->timezone !== null) {
            $user->setTimezone($input->timezone);
        }
    }
}

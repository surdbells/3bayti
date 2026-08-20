<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Vendor;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\User\RefreshToken;
use Bayti\Api\Domain\User\RefreshTokenRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\RequestContext;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\UserSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * POST /v3/admin/vendors/{id}/impersonate
 *
 * Gated on `vendors.impersonate`. Mints a full session token pair for the
 * vendor's owner account so the admin can act as that vendor ("log in as").
 * The token carries an `imp_by` claim (this admin's id) so the app shows an
 * impersonation banner and every downstream request is attributable to the
 * admin behind it. The impersonation session refreshes normally (imp_by is
 * preserved across rotation), and is exited client-side by restoring the
 * admin's own session.
 *
 * Guards: the vendor must have an owner account, it can't be the admin's own
 * account, and staff accounts (admin/finance/support) can't be impersonated —
 * only sellers.
 */
final class ImpersonateVendorController
{
    use Responder;
    use RequestContext;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly JwtService $jwt,
        private readonly UserSerializer $userSerializer,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $admin = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$admin instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        $vendorId = (int) $request->getAttribute('id');
        $vendor = $this->em->getRepository(Vendor::class)->find($vendorId);
        if (!$vendor instanceof Vendor) {
            throw HttpException::notFound('Vendor not found.');
        }

        $owner = $vendor->getOwnerUser();
        if (!$owner instanceof User) {
            throw HttpException::businessRuleViolation(
                message: 'This vendor has no owner account to sign in as.',
            );
        }
        if ($owner->getId() === $admin->getId()) {
            throw HttpException::businessRuleViolation(message: 'You cannot impersonate your own account.');
        }
        if ($owner->isAdmin() || $owner->isFinance() || $owner->isSupport() || $owner->isSubAdmin()) {
            throw HttpException::forbidden('Staff accounts cannot be impersonated.');
        }
        if (!$owner->isActive()) {
            throw HttpException::businessRuleViolation(message: 'This account is inactive and cannot be impersonated.');
        }

        // Mint a session for the owner, tagged with the impersonating admin.
        $pair = $this->jwt->issueTokenPair($owner, $admin->getId());

        $this->em->wrapInTransaction(function () use ($owner, $pair, $request): void {
            /** @var RefreshTokenRepository $refreshRepo */
            $refreshRepo = $this->em->getRepository(RefreshToken::class);
            $refreshRepo->save(new RefreshToken(
                user: $owner,
                jti: $pair->refreshTokenJti,
                tokenHash: $pair->refreshTokenHash(),
                expiresAt: $pair->refreshTokenExpiresAt,
                issuedIp: $this->extractIp($request),
                userAgent: $request->getHeaderLine('User-Agent') ?: null,
            ), flush: false);
            $this->em->flush();
        });

        // Audit trail — who impersonated whom.
        $this->logger->warning('admin.impersonate_vendor', [
            'admin_id' => $admin->getId(),
            'admin_email' => $admin->getEmail(),
            'vendor_id' => $vendor->getId(),
            'owner_user_id' => $owner->getId(),
            'ip' => $this->extractIp($request),
        ]);

        return $this->ok([
            'access_token' => $pair->accessToken,
            'access_token_expires_at' => $pair->accessTokenExpiresAt->format(\DateTimeInterface::ATOM),
            'refresh_token' => $pair->refreshToken,
            'refresh_token_expires_at' => $pair->refreshTokenExpiresAt->format(\DateTimeInterface::ATOM),
            'user' => $this->userSerializer->publicProfile($owner),
            'impersonation' => [
                'vendor_id' => $vendor->getId(),
                'store_name' => $vendor->getName(),
                'user_name' => trim(($owner->getFirstName() ?? '') . ' ' . ($owner->getLastName() ?? '')),
                'impersonator_id' => $admin->getId(),
            ],
        ]);
    }
}

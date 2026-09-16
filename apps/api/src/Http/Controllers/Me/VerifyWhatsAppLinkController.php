<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Me;

use Bayti\Api\Domain\User\OtpAttempt;
use Bayti\Api\Domain\User\OtpService;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Domain\User\VerifyResult;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/me/whatsapp/link/verify  (AuthMiddleware)
 *
 * Confirm the OTP from POST /v3/me/whatsapp/link and persist the WhatsApp number
 * on the current account. Guards (purpose === WHATSAPP_LINK, attempt bound to
 * THIS caller) prevent a code minted for another flow / another user being
 * replayed here. All OTP/guard failures collapse to one 401
 * OTP_VERIFICATION_FAILED (no state leak).
 *
 * Response: 200 { whatsapp_phone } (masked).
 */
final class VerifyWhatsAppLinkController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly OtpService $otp,
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
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $verificationId = isset($body['verification_id']) && is_string($body['verification_id'])
            ? trim($body['verification_id']) : '';
        $code = isset($body['code']) && is_scalar($body['code']) ? trim((string) $body['code']) : '';
        if ($verificationId === '' || $code === '') {
            throw HttpException::badRequest('verification_id and code are required.');
        }

        $attempt = $this->otp->findAttempt($verificationId);
        $bound = $attempt !== null
            && $attempt->getPurpose() === OtpAttempt::PURPOSE_WHATSAPP_LINK
            && $attempt->getUser()?->getId() === $user->getId();
        if (!$bound) {
            throw HttpException::unauthorized(ErrorCodes::OTP_VERIFICATION_FAILED, 'Verification failed.');
        }

        if ($this->otp->verify($verificationId, $code) !== VerifyResult::Success) {
            throw HttpException::unauthorized(ErrorCodes::OTP_VERIFICATION_FAILED, 'Verification failed.');
        }

        /** @var OtpAttempt $attempt (non-null, guarded above) */
        $phone = $attempt->getPhone();

        // A WhatsApp number links to at most one account (the webhook resolves a
        // sender deterministically). Reject if it's already on another account.
        $repo = $this->em->getRepository(User::class);
        if ($repo instanceof UserRepository) {
            $existing = $repo->findByWhatsAppPhone($phone);
            if ($existing !== null && $existing->getId() !== $user->getId()) {
                throw HttpException::conflict(
                    ErrorCodes::CONFLICT_PHONE_TAKEN,
                    'That WhatsApp number is already linked to another account.',
                );
            }
        }

        $user->setWhatsappPhone($phone);
        $this->em->flush();

        return $this->ok(['whatsapp_phone' => $this->mask($phone)]);
    }

    private function mask(string $phone): string
    {
        $len = strlen($phone);
        return $len <= 4 ? '***' : substr($phone, 0, 4) . str_repeat('*', max(0, $len - 6)) . substr($phone, -2);
    }
}

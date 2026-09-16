<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Me;

use Bayti\Api\Domain\User\OtpAttempt;
use Bayti\Api\Domain\User\OtpService;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\RequestContext;
use Bayti\Api\Http\Responder;
use Bayti\Api\Infrastructure\Otp\OtpProviderException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/me/whatsapp/link  (AuthMiddleware)
 *
 * Start linking a WhatsApp number to the CURRENT account (for the WhatsApp
 * Commerce channel). Sends an OTP to the given number so the customer proves
 * control of it; POST /v3/me/whatsapp/link/verify confirms and persists it.
 *
 * OTP purpose is WHATSAPP_LINK (never ACCOUNT_LINK/PHONE_CHANGE) so this code
 * can't be replayed against the account-merge or phone-change endpoints.
 *
 * Response: 200 { verification_id }.
 */
final class LinkWhatsAppController
{
    use Responder;
    use RequestContext;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
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
        $phone = $this->normalisePhone($body['phone'] ?? null);

        try {
            $verificationId = $this->otp->send(
                to: $phone,
                purpose: OtpAttempt::PURPOSE_WHATSAPP_LINK,
                channel: OtpAttempt::CHANNEL_SMS,
                user: $user,
                requestedIp: $this->extractIp($request),
            );
        } catch (OtpProviderException $e) {
            throw HttpException::upstreamFailure(
                ErrorCodes::OTP_PROVIDER_ERROR,
                'Could not send verification code. Please try again in a moment.',
                $e,
            );
        }

        return $this->ok(['verification_id' => $verificationId]);
    }

    private function normalisePhone(mixed $raw): string
    {
        $phone = is_string($raw) ? trim($raw) : '';
        if ($phone !== '' && $phone[0] !== '+') {
            $phone = '+' . $phone;
        }
        if (preg_match('/^\+[1-9]\d{6,14}$/', $phone) !== 1) {
            throw HttpException::badRequest('A valid WhatsApp phone number is required (E.164, e.g. +971501234567).');
        }
        return $phone;
    }
}

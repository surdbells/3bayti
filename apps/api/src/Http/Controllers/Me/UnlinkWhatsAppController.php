<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Me;

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
 * DELETE /v3/me/whatsapp/link  (AuthMiddleware)
 *
 * Unlink the WhatsApp number from the current account. Idempotent — returns
 * 200 whether or not one was linked. The number then frees up for another
 * account to link (the partial-unique index only covers non-null values).
 *
 * Response: 200 { whatsapp_phone: null }.
 */
final class UnlinkWhatsAppController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
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

        if ($user->hasWhatsAppLinked()) {
            $user->setWhatsappPhone(null);
            $this->em->flush();
        }

        return $this->ok(['whatsapp_phone' => null]);
    }
}

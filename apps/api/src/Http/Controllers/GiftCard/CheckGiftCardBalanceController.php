<?php declare(strict_types=1);
namespace Bayti\Api\Http\Controllers\GiftCard;

use Bayti\Api\Domain\GiftCard\GiftCard;
use Bayti\Api\Domain\GiftCard\GiftCardRepository;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/gift-cards/balance?code=XXXX-XXXX-XXXX-XXXX
 *
 * Public balance check, returns balance and status without auth.
 * Deliberately minimal response (no buyer name, no transactions) to
 * prevent information leakage if a code is guessed.
 */
final class CheckGiftCardBalanceController
{
    use Responder;
    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
    ) {}
    protected function getResponseFactory(): ResponseFactoryInterface { return $this->responseFactory; }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $code = trim((string) ($request->getQueryParams()['code'] ?? ''));
        if ($code === '') throw HttpException::badRequest('code query parameter is required.');

        /** @var GiftCardRepository $repo */
        $repo = $this->em->getRepository(GiftCard::class);
        $card = $repo->findByCode($code);
        if ($card === null) throw HttpException::notFound('Gift card not found.');

        return $this->ok(['data' => [
            'code'        => $card->formattedCode(),
            'theme'       => $card->getTheme(),
            'status'      => $card->getStatus(),
            'balance'     => $card->getBalance(),
            'currency'    => $card->getCurrency(),
            'is_spendable'=> $card->isSpendable(),
            'expires_at'  => $card->getExpiresAt()?->format(\DateTimeInterface::ATOM),
        ]]);
    }
}

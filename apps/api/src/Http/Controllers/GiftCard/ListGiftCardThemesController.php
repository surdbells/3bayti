<?php declare(strict_types=1);
namespace Bayti\Api\Http\Controllers\GiftCard;

use Bayti\Api\Http\Responder;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/gift-cards/themes
 *
 * Public endpoint, no auth required. Returns all 6 themes with
 * design metadata (colours, labels, photo support flag) and the
 * denomination presets. The mobile app calls this on the gift card
 * browse screen to build the theme selector UI.
 */
final class ListGiftCardThemesController
{
    use Responder;
    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
    ) {}
    protected function getResponseFactory(): ResponseFactoryInterface { return $this->responseFactory; }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return $this->ok(['data' => GiftCardSerializer::allThemes()]);
    }
}

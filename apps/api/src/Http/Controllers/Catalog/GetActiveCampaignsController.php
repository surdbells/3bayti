<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Catalog;

use Bayti\Api\Domain\Catalog\Campaign;
use Bayti\Api\Domain\Catalog\CampaignRepository;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\CampaignSerializer;
use Bayti\Api\Http\Serializers\ProductSerializer;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/campaigns/active
 *
 * Returns the single live anniversary campaign and the single live flash
 * campaign (highest priority of each type whose [starts_at, ends_at]
 * window contains "now"), each with their products. Either may be null.
 *
 * `server_now` is included so the client can run countdowns against the
 * server clock rather than the (possibly skewed) device clock.
 *
 * Public, no auth. Display currency honoured via CurrencyContextMiddleware.
 *
 * Shape:
 *   { data: { server_now: ISO8601,
 *             anniversary: Campaign | null,
 *             flash: Campaign | null } }
 */
final class GetActiveCampaignsController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly ProductSerializer $products,
        private readonly CampaignSerializer $campaigns,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $now = new DateTimeImmutable();
        $ps  = $this->products->configureFromRequest($request);

        /** @var CampaignRepository $repo */
        $repo = $this->em->getRepository(Campaign::class);

        $anniversary = $repo->findActiveByType(Campaign::TYPE_ANNIVERSARY, $now);
        $flash       = $repo->findActiveByType(Campaign::TYPE_FLASH, $now);

        return $this->ok([
            'data' => [
                'server_now'  => $now->format(DateTimeInterface::ATOM),
                'anniversary' => $anniversary !== null ? $this->campaigns->shape($anniversary, $ps) : null,
                'flash'       => $flash !== null ? $this->campaigns->shape($flash, $ps) : null,
            ],
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Catalog;

use Bayti\Api\Domain\Catalog\Campaign;
use Bayti\Api\Domain\Catalog\CampaignRepository;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\CampaignSerializer;
use Bayti\Api\Http\Serializers\ProductSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/campaigns/{slug}
 *
 * A single campaign by slug with all its products, backs the storefront
 * "view all" page for a deals / flash-sale campaign. Public, no auth.
 * 404 when the slug is unknown.
 *
 * Registered AFTER /v3/campaigns/active so the literal "active" path is
 * not captured by {slug}.
 */
final class GetCampaignController
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
        $slug = trim((string) $request->getAttribute('slug'));

        /** @var CampaignRepository $repo */
        $repo     = $this->em->getRepository(Campaign::class);
        $campaign = $slug !== '' ? $repo->findBySlugWithItems($slug) : null;

        if ($campaign === null) {
            throw HttpException::notFound('Campaign not found.');
        }

        $ps = $this->products->configureFromRequest($request);

        return $this->ok(['data' => $this->campaigns->shape($campaign, $ps)]);
    }
}

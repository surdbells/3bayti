<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Chat\Vendor;

use Bayti\Api\Domain\Chat\ChatPromptCategory;
use Bayti\Api\Domain\Chat\ChatPromptCategoryRepository;
use Bayti\Api\Domain\Chat\PromptCatalog;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\ChatSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/vendor/chat/prompts
 *
 * The vendor-audience canned reply templates (P1). Same shape as the
 * customer catalog; VendorAuthMiddleware-gated (from the group).
 */
final class GetPromptsController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly ChatSerializer $serializer,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /** @var ChatPromptCategoryRepository $repo */
        $repo = $this->em->getRepository(ChatPromptCategory::class);
        $categories = $repo->findActiveForAudience(PromptCatalog::AUDIENCE_VENDOR);

        return $this->ok(['categories' => $this->serializer->promptCatalogShape($categories)]);
    }
}

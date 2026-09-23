<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Chat\Customer;

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
 * GET /v3/chat/prompts
 *
 * The customer-audience quick-start prompt catalog (P1): categories with
 * nested canned questions, bilingual. Drives the prompt-picker shown
 * alongside the free-text composer. Auth-only (from the group), but not
 * user-specific.
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
        $categories = $repo->findActiveForAudience(PromptCatalog::AUDIENCE_CUSTOMER);

        return $this->ok(['categories' => $this->serializer->promptCatalogShape($categories)]);
    }
}

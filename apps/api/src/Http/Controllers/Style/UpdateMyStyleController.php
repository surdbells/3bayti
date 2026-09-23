<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Style;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Style;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT|PATCH /v3/me/styles/{id}, edit one of the authenticated user's own
 * saved looks: rename it and/or replace its product set (still up to 4).
 *
 * Full-replace semantics: name + products are re-validated exactly as on
 * create (CreateStyleController), the products collection is cleared and
 * rebuilt from the request body, and total_price is recomputed. The slug
 * is intentionally kept STABLE so any already-shared /styles/{slug} link
 * keeps resolving after an edit.
 *
 * Ownership: 404 (not 403) when the style doesn't exist, is inactive
 * (soft-deleted), or isn't the caller's, so we never leak the existence
 * of another user's style. Editorial/community styles the caller didn't
 * create are therefore not editable here.
 */
final class UpdateMyStyleController
{
    use Responder;

    private const MAX_PRODUCTS = 4;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        $id = (int) ($args['id'] ?? 0);
        $style = $id > 0 ? $this->em->find(Style::class, $id) : null;
        if (
            !$style instanceof Style
            || !$style->isActive()
            || $style->getCreatedByUser()?->getId() !== $user->getId()
        ) {
            throw HttpException::notFound('Style not found.');
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            throw HttpException::badRequest('name is required.');
        }

        $productIds = $this->parseProductIds($body['products'] ?? '');
        if (count($productIds) === 0) {
            throw HttpException::badRequest('At least one product is required.');
        }
        if (count($productIds) > self::MAX_PRODUCTS) {
            throw HttpException::badRequest('A style may contain at most ' . self::MAX_PRODUCTS . ' products.');
        }

        // Resolve + validate the whole new product set BEFORE mutating the
        // style, so a bad product id can't leave a half-updated look.
        $products = [];
        $total = '0.00';
        foreach ($productIds as $pid) {
            $product = $this->em->find(Product::class, $pid);
            if (!$product instanceof Product || !$product->isActive()) {
                throw HttpException::badRequest("Product {$pid} is not available.");
            }
            $products[] = $product;
            $total = $this->addMoney($total, $product->getSalePrice() ?? $product->getPrice());
        }

        $style->setName($name);
        $style->getProducts()->clear();
        foreach ($products as $product) {
            $style->getProducts()->add($product);
        }
        $style->setTotalPrice($total);
        // Slug intentionally NOT regenerated on rename: keep shared links stable.

        $this->em->flush();

        return $this->ok(PaginatedEnvelope::single([
            'id'            => $style->getId(),
            'slug'          => $style->getSlug(),
            'name'          => $style->getName(),
            'total_price'   => $style->getTotalPrice(),
            'product_count' => $style->getProducts()->count(),
            'is_active'     => $style->isActive(),
            'source'        => $style->getSource(),
        ]));
    }

    /**
     * Parse a products value into a de-duplicated list of positive ints.
     * Accepts a CSV string ("12,34") or an array. Mirrors CreateStyleController.
     *
     * @return list<int>
     */
    private function parseProductIds(mixed $raw): array
    {
        $parts = is_array($raw) ? $raw : explode(',', (string) $raw);
        $ids = [];
        foreach ($parts as $part) {
            $id = (int) trim((string) $part);
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** Add two decimal-money strings, returning a 2dp string. */
    private function addMoney(string $a, string $b): string
    {
        return number_format(((float) $a) + ((float) $b), 2, '.', '');
    }
}

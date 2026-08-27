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
 * POST /v3/me/styles, a customer submits a style (a curated look of up
 * to 4 products). Replaces legacy /customer/create_style.
 *
 * The style is created as TYPE_COMMUNITY, owned by the authenticated
 * user (created_by_user) and active (matching legacy behaviour, where
 * customer styles published immediately), but now attributable, so it
 * can be moderated/removed on abuse. total_price is the sum of the
 * products' effective prices. The legacy `category` field has no
 * equivalent in the v3 Style model and is ignored.
 */
final class CreateStyleController
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

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
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

        $style = new Style($this->makeSlug($name), $name, Style::TYPE_COMMUNITY);
        $style->setCreatedByUser($user);
        $style->setActive(true);

        $total = '0.00';
        foreach ($productIds as $pid) {
            $product = $this->em->find(Product::class, $pid);
            if (!$product instanceof Product || !$product->isActive()) {
                throw HttpException::badRequest("Product {$pid} is not available.");
            }
            $style->getProducts()->add($product);
            $total = $this->addMoney($total, $product->getSalePrice() ?? $product->getPrice());
        }
        $style->setTotalPrice($total);

        $this->em->persist($style);
        $this->em->flush();

        return $this->created(PaginatedEnvelope::single([
            'id'            => $style->getId(),
            'slug'          => $style->getSlug(),
            'name'          => $style->getName(),
            'total_price'   => $style->getTotalPrice(),
            'product_count' => $style->getProducts()->count(),
            'is_active'     => true,
        ]));
    }

    /**
     * Parse a products value into a de-duplicated list of positive ints.
     * Accepts a CSV string ("12,34") or an array.
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

    /** URL-safe slug from the name + a short unique suffix (slug is unique). */
    private function makeSlug(string $name): string
    {
        $base = strtolower($name);
        $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?? '';
        $base = trim($base, '-');
        if ($base === '') {
            $base = 'style';
        }
        $base = substr($base, 0, 120);

        return $base . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
    }

    /** Add two decimal-money strings, returning a 2dp string. */
    private function addMoney(string $a, string $b): string
    {
        return number_format(((float) $a) + ((float) $b), 2, '.', '');
    }
}

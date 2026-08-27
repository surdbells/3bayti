<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Chat\Customer;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Chat\Conversation;
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
 * GET /v3/chat/conversation-stores, the store-grouped chat inbox for a
 * customer. Replaces legacy /customer/read-messages.
 *
 * v3 chat is order-scoped (one Conversation per order item), but the
 * legacy mobile inbox lists the distinct STORES a customer has chatted
 * with. So this collapses the customer's conversations to their distinct
 * vendors and returns, per store, the fields the inbox renders:
 *   { store, store_name, store_address, total_products }
 *
 * The response is shaped as { data: [...] } so the mobile envelope
 * adapter unwraps it directly into the page's `response.data` array -
 * no client-side transform required.
 */
final class ListConversationStoresController
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

        /** @var \Bayti\Api\Domain\Chat\ConversationRepository $conversations */
        $conversations = $this->em->getRepository(Conversation::class);
        /** @var list<Vendor> $vendors */
        $vendors = $conversations->findDistinctVendorsForCustomer((int) $user->getId());

        $productCounts = $this->countActiveProductsByVendor($vendors);

        $data = [];
        foreach ($vendors as $vendor) {
            $vendorId = (int) $vendor->getId();
            $data[] = [
                'store'          => $vendorId,
                'store_name'     => $vendor->getName(),
                'store_address'  => $vendor->getStoreAddress() ?? '',
                'total_products' => $productCounts[$vendorId] ?? 0,
            ];
        }

        return $this->ok(['data' => $data]);
    }

    /**
     * Active-product counts keyed by vendor id, in one grouped query (no
     * N+1 over the vendor list).
     *
     * @param list<Vendor> $vendors
     * @return array<int, int>
     */
    private function countActiveProductsByVendor(array $vendors): array
    {
        if ($vendors === []) {
            return [];
        }

        $rows = $this->em->getRepository(Product::class)->createQueryBuilder('p')
            ->select('IDENTITY(p.vendor) AS vid, COUNT(p.id) AS cnt')
            ->where('p.vendor IN (:vendors)')->setParameter('vendors', $vendors)
            ->andWhere('p.isActive = true')
            ->groupBy('p.vendor')
            ->getQuery()->getResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['vid']] = (int) $row['cnt'];
        }

        return $counts;
    }
}

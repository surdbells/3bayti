<?php declare(strict_types=1);
namespace Bayti\Api\Http\Controllers\Vendor\Review;

use Bayti\Api\Domain\Catalog\ProductReview;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /v3/vendor/reviews[?limit&offset] — reviews for the vendor's products. */
final class ListVendorReviewsController
{
    use Responder;
    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
    ) {}
    protected function getResponseFactory(): ResponseFactoryInterface { return $this->responseFactory; }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');

        /** @var VendorRepository $vendorRepo */
        $vendorRepo = $this->em->getRepository(Vendor::class);
        $vendors    = $vendorRepo->findByOwnerUser($user);
        if (empty($vendors)) throw HttpException::forbidden('No approved vendor account found.');
        $vendorId = $vendors[0]->getId();

        $q      = $request->getQueryParams();
        $limit  = max(1, min(100, (int) ($q['limit']  ?? 20)));
        $offset = max(0, (int) ($q['offset'] ?? 0));

        $qb = $this->em->createQueryBuilder()
            ->select('r')
            ->from(ProductReview::class, 'r')
            ->where('r.vendor = :vid')
            ->setParameter('vid', $vendorId)
            ->orderBy('r.id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        /** @var list<ProductReview> $items */
        $items = $qb->getQuery()->getResult();

        $countQb = clone $qb;
        $total   = (int) $countQb->select('COUNT(r.id)')->setMaxResults(null)->setFirstResult(0)->getQuery()->getSingleScalarResult();

        $data = array_map(static fn(ProductReview $r) => [
            'id'           => $r->getId(),
            'product_name' => $r->getProductNameSnapshot() ?? $r->getProduct()?->getName(),
            'reviewer'     => $r->getReviewerName(),
            'star'         => $r->getStar(),
            'title'        => $r->getTitle(),
            'comment'      => $r->getComment(),
            'vendor_reply' => $r->getVendorReply(),
            'created_at'   => $r->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], $items);

        return $this->ok(['data' => $data, 'meta' => ['total' => $total, 'limit' => $limit, 'offset' => $offset]]);
    }
}

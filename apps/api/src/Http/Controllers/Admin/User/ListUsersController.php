<?php declare(strict_types=1);
namespace Bayti\Api\Http\Controllers\Admin\User;

use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AdminAuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\UserSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /v3/admin/users[?role=&search=&limit=&offset=] */
final class ListUsersController
{
    use Responder;
    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly UserSerializer $serializer,
    ) {}
    protected function getResponseFactory(): ResponseFactoryInterface { return $this->responseFactory; }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $q      = $request->getQueryParams();
        $limit  = max(1, min(100, (int) ($q['limit']  ?? 20)));
        $offset = max(0, (int) ($q['offset'] ?? 0));
        $role   = is_string($q['role'] ?? null) ? $q['role'] : null;

        // Two listings share this endpoint:
        //  - The Users admin page asks for staff (admins + anyone holding an
        //    RBAC role) and renders the staff() shape.
        //  - The Customers admin page asks for role=customer and needs the
        //    real customer set with phone/verification/status fields, so it
        //    gets the customer() shape instead.
        $isCustomerListing = $role === 'customer';

        /** @var UserRepository $repo */
        $repo = $this->em->getRepository(User::class);

        // Customers and staff use different repository paths + response shapes.
        if ($isCustomerListing) {
            $filters = [
                'search'         => $q['search'] ?? null,
                'status'         => $this->stringOrNull($q['status'] ?? null),
                'email_verified' => $this->boolOrNull($q['email_verified'] ?? null),
                'phone_verified' => $this->boolOrNull($q['phone_verified'] ?? null),
                'created_from'   => $this->dateFrom($q['created_from'] ?? null),
                'created_to'     => $this->dateTo($q['created_to'] ?? null),
                'min_orders'     => $this->intOrNull($q['min_orders'] ?? null),
                'limit'          => $limit,
                'offset'         => $offset,
            ];

            $result = $repo->findCustomersPaginated($filters);

            $data = array_map(
                fn (array $row): array => $this->serializer->customer($row['user'], $row['orders_count']),
                $result['items'],
            );

            return $this->ok([
                'data' => $data,
                'meta' => ['total' => $result['total'], 'limit' => $limit, 'offset' => $offset],
            ]);
        }

        $result = $repo->findPaginated([
            'staff'  => true,
            'search' => $q['search'] ?? null,
            'limit'  => $limit,
            'offset' => $offset,
        ]);

        return $this->ok([
            'data' => array_map([$this->serializer, 'staff'], $result['items']),
            'meta' => ['total' => $result['total'], 'limit' => $limit, 'offset' => $offset],
        ]);
    }

    private function stringOrNull(mixed $v): ?string
    {
        return is_string($v) && $v !== '' ? $v : null;
    }

    private function intOrNull(mixed $v): ?int
    {
        if ($v === null || $v === '' || !is_numeric($v)) {
            return null;
        }
        $n = (int) $v;
        return $n > 0 ? $n : null;
    }

    /**
     * Parse a truthy/falsy query param into a nullable bool. Returns null when
     * the param is absent or empty so the filter is simply not applied.
     */
    private function boolOrNull(mixed $v): ?bool
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_bool($v)) {
            return $v;
        }
        $s = strtolower((string) $v);
        if (in_array($s, ['1', 'true', 'yes'], true)) {
            return true;
        }
        if (in_array($s, ['0', 'false', 'no'], true)) {
            return false;
        }
        return null;
    }

    /** Start of the given calendar day (YYYY-MM-DD), inclusive. */
    private function dateFrom(mixed $v): ?\DateTimeImmutable
    {
        if (!is_string($v) || $v === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        return $d === false ? null : $d;
    }

    /** End of the given calendar day (YYYY-MM-DD), inclusive (23:59:59). */
    private function dateTo(mixed $v): ?\DateTimeImmutable
    {
        if (!is_string($v) || $v === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        return $d === false ? null : $d->setTime(23, 59, 59);
    }
}

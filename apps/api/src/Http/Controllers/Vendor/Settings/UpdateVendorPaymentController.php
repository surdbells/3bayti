<?php declare(strict_types=1);
namespace Bayti\Api\Http\Controllers\Vendor\Settings;

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

/** PATCH /v3/vendor/store/payment */
final class UpdateVendorPaymentController
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

        /** @var VendorRepository $repo */
        $repo    = $this->em->getRepository(Vendor::class);
        $vendors = $repo->findByOwnerUser($user);
        if (empty($vendors)) throw HttpException::notFound('No vendor account found.');
        $v    = $vendors[0];
        $body = (array) ($request->getParsedBody() ?? []);

        if (isset($body['store_bank_name']))      $v->setStoreBankName((string) $body['store_bank_name']);
        if (isset($body['store_account_name']))   $v->setStoreBankAccountName((string) $body['store_account_name']);
        if (isset($body['store_account_number'])) $v->setStoreBankAccountNumber((string) $body['store_account_number']);

        $repo->save($v);

        return $this->ok(['data' => [
            'store_bank_name'      => $v->getStoreBankName(),
            'store_account_name'   => $v->getStoreBankAccountName(),
            'store_account_number' => $v->getStoreBankAccountNumber(),
        ]]);
    }
}

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

/** PATCH /v3/vendor/store/notifications */
final class UpdateVendorNotificationsController
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
        $repo = $this->em->getRepository(Vendor::class);
        $vendors = $repo->findByOwnerUser($user);
        if (empty($vendors)) throw HttpException::notFound('No vendor account found.');
        $vendor = $vendors[0];

        $body  = (array) ($request->getParsedBody() ?? []);
        $prefs = $vendor->getNotificationPrefs();
        $known = [
            'store_notify_order_received', 'store_notify_order_cancelled',
            'store_notify_order_return_refund_initiated', 'store_notify_product_listing_approved_rejected',
            'store_notify_payment_scheduled', 'store_notify_payment_completed',
            'store_notify_negative_review', 'store_notify_monthly_performance',
            'store_notify_weekly_performance', 'store_notify_low_engagement',
            'store_notify_custom_order',
        ];
        foreach ($known as $key) {
            if (array_key_exists($key, $body)) {
                $prefs[$key] = (bool) $body[$key];
            }
        }
        $vendor->setNotificationPrefs($prefs);
        $repo->save($vendor);

        return $this->ok(['data' => $prefs]);
    }
}

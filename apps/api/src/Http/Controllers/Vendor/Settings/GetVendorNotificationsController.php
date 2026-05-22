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

/** GET /v3/vendor/store/notifications */
final class GetVendorNotificationsController
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

        $defaults = [
            'store_notify_order_received' => true,
            'store_notify_order_cancelled' => true,
            'store_notify_order_return_refund_initiated' => true,
            'store_notify_product_listing_approved_rejected' => true,
            'store_notify_payment_scheduled' => true,
            'store_notify_payment_completed' => true,
            'store_notify_negative_review' => true,
            'store_notify_monthly_performance' => true,
            'store_notify_weekly_performance' => true,
            'store_notify_low_engagement' => false,
            'store_notify_custom_order' => false,
        ];
        $prefs = array_merge($defaults, $vendors[0]->getNotificationPrefs());

        return $this->ok(['data' => $prefs]);
    }
}

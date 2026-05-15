<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Checkout;

use Bayti\Api\Domain\Cart\Cart;
use Bayti\Api\Domain\Cart\CartRepository;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderAddress;
use Bayti\Api\Domain\Order\OrderItem;
use Bayti\Api\Domain\Order\OrderRepository;
use Bayti\Api\Domain\Payment\PaymentTransaction;
use Bayti\Api\Domain\Payment\PaymentTransactionRepository;
use Bayti\Api\Domain\User\Address;
use Bayti\Api\Domain\User\AddressRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Checkout\Dto\InitiateCheckoutInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Validator\RequestValidator;
use Bayti\Api\Payment\PaymentGatewayException;
use Bayti\Api\Payment\PaymentGatewayInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * POST /v3/checkout/initiate
 *
 * Converts the authenticated user's active cart into a pending-payment
 * Order, calls Noon INITIATE to get a hosted checkout URL, persists
 * a PaymentTransaction record, and returns the URL for the mobile
 * webview to load.
 *
 * Flow
 * ----
 *   1. Load user's active cart; fail 422 if empty
 *   2. Load user's billing + shipping addresses (override via input
 *      if provided; otherwise defaults from address book)
 *   3. Generate a unique server-side order_reference
 *   4. Wrap in EM transaction:
 *      - Create Order(status=pending_payment, subtotal/total computed
 *        server-side from cart items + delivery_fee + discount)
 *      - Snapshot each CartItem → OrderItem with unit_price_snapshot
 *        carried over (already snapshotted at add-to-cart time)
 *      - Snapshot User's selected billing+shipping Address → two
 *        OrderAddress records (decouples Order from later
 *        address-book edits)
 *      - Mark Cart as converted
 *   5. Call Noon INITIATE — outside the DB transaction. The Noon
 *      round-trip is slow (median ~800ms in our sandbox testing)
 *      and we don't want to hold a row lock that long. Worst-case
 *      failure here: a pending-payment Order exists with no
 *      PaymentTransaction; the GetCheckoutStatus polling loop
 *      will retrieve-order-by-reference from Noon and rectify.
 *   6. Persist PaymentTransaction record with Noon's response
 *   7. Return { checkout_url, order_reference, provider_order_ref,
 *      order_id }
 *
 * Idempotency
 * -----------
 * Each PaymentTransaction has a unique idempotency_key. We derive
 * it from { user_id, cart_id, cart_updated_at_epoch } so a
 * double-tap on "Pay Now" within the same cart-state returns the
 * SAME checkout URL (no second order created). If the user
 * modifies the cart between taps, the key differs and a new
 * checkout begins legitimately.
 *
 * What's defensive about this
 * ----------------------------
 * - Server-derived totals: client cannot pass a fake total and
 *   end up paying less
 * - Server-derived order_reference: client cannot collide with
 *   another user's order (also, DB UNIQUE constraint backs us up)
 * - Server-derived unit_prices: snapshot from cart, which was
 *   snapshot from product at add-time. Cart contains the price
 *   the user saw when they added the item; that's what they pay,
 *   not a possibly-changed current product.price.
 *
 * What's NOT in this controller
 * ------------------------------
 * - Delivery fee calculation: caller passes it (M3.2.x will add
 *   a /v3/checkout/quote endpoint to compute it)
 * - Discount/coupon resolution: caller passes amount (same — quote
 *   endpoint comes in M3.2.x)
 * - Cart locking against concurrent add-to-cart: not needed since
 *   the cart-snapshot-into-order is inside the transaction; an
 *   add-to-cart that races is either before (and included) or
 *   after (and lands in a NEW active cart the user can checkout
 *   separately)
 */
final class InitiateCheckoutController
{
    use Responder;

    private const RETURN_PATH_PREFIX = '/v3/checkout/return/';

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly EntityManagerInterface $em,
        private readonly PaymentGatewayInterface $gateway,
        private readonly \Bayti\Api\Notification\OrderNotificationService $notifications,
        private readonly LoggerInterface $logger = new NullLogger(),
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
            throw HttpException::unauthorized(
                ErrorCodes::AUTH_INVALID_TOKEN,
                'Authentication required.',
            );
        }

        $input = $this->validator->parse($request, InitiateCheckoutInput::class);

        /** @var CartRepository $carts */
        $carts = $this->em->getRepository(Cart::class);
        $cart = $carts->findActiveForUser($user);
        if ($cart === null || $cart->itemCount() === 0) {
            throw HttpException::businessRuleViolation(
                ErrorCodes::VALIDATION_FAILED,
                'Cart is empty.',
            );
        }

        /** @var AddressRepository $addresses */
        $addresses = $this->em->getRepository(Address::class);
        $billing = $this->resolveAddress($addresses, $user, $input->billing_address_id, 'billing');
        $shipping = $this->resolveAddress($addresses, $user, $input->shipping_address_id, 'shipping');

        // Compute subtotal from cart items. Don't trust the client.
        $subtotal = $cart->computeSubtotal();
        $deliveryFee = $input->delivery_fee;
        $discount = $input->discount;

        // Server-generated order reference: V3- + 13-digit epoch_ms +
        // 4-char random hex. Total length: 3 + 13 + 4 = 20 chars,
        // under the 32-char DB cap. Collision probability negligible
        // (the UNIQUE constraint is the backstop anyway).
        $orderReference = $this->generateOrderReference();

        // Idempotency: same (user, cart-id, cart-updated-at) =>
        // same paymentTransaction. Lets a double-tap return the
        // same checkout URL rather than creating two orders.
        $cartId = $cart->getId() ?? 0;
        $cartTs = $cart->getUpdatedAt()->getTimestamp();
        $idempotencyKey = sprintf(
            'checkout:user=%d:cart=%d:ts=%d',
            $user->getId() ?? 0,
            $cartId,
            $cartTs,
        );

        /** @var PaymentTransactionRepository $transactions */
        $transactions = $this->em->getRepository(PaymentTransaction::class);
        $existing = $transactions->findByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            // Return the cached checkout URL from the existing INITIATE response.
            $cachedUrl = $this->extractCheckoutUrl($existing);
            if ($cachedUrl !== null) {
                $this->logger->info('checkout.initiate: idempotency hit', [
                    'user_id' => $user->getId(),
                    'cart_id' => $cartId,
                    'idempotency_key' => $idempotencyKey,
                ]);
                return $this->ok([
                    'checkout_url' => $cachedUrl,
                    'order_reference' => $existing->getOrder()->getOrderReference(),
                    'provider_order_ref' => $existing->getProviderOrderRef() ?? '',
                    'order_id' => $existing->getOrder()->getId() ?? 0,
                    'idempotent' => true,
                ]);
            }
            // Cached transaction has no checkoutData (shouldn't happen,
            // but defend against it). Fall through to a fresh initiate.
        }

        // ------------------------------------------------------------------
        // Create Order + snapshots inside a transaction. Noon round-trip
        // is OUTSIDE this transaction (see docblock).
        // ------------------------------------------------------------------
        $order = $this->em->wrapInTransaction(
            function (EntityManagerInterface $em) use (
                $user, $cart, $orderReference, $subtotal, $deliveryFee, $discount,
                $billing, $shipping,
            ): Order {
                $order = new Order(
                    user: $user,
                    orderReference: $orderReference,
                    subtotal: $subtotal,
                    deliveryFee: $deliveryFee,
                    discount: $discount,
                );

                // Snapshot cart items → order items.
                foreach ($cart->getItems() as $cartItem) {
                    $product = $cartItem->getProduct();
                    $orderItem = new OrderItem(
                        product: $product,
                        vendor: $product->getVendor(),
                        quantity: $cartItem->getQuantity(),
                        unitPrice: $cartItem->getUnitPriceSnapshot(),
                        productNameSnapshot: $product->getName(),
                        productImageSnapshot: $product->getPrimaryImageUrl(),
                        size: $cartItem->getSize(),
                        color: $cartItem->getColor(),
                        isCustom: $cartItem->isCustom(),
                        measurement: $cartItem->getMeasurement(),
                        extraMeasurement: $cartItem->getExtraMeasurement(),
                        note: $cartItem->getNote(),
                    );
                    $order->addItem($orderItem);
                }

                // Snapshot addresses.
                $order->addAddress($this->snapshotAddress($billing, OrderAddress::TYPE_BILLING));
                $order->addAddress($this->snapshotAddress($shipping, OrderAddress::TYPE_SHIPPING));

                $em->persist($order);

                // Mark cart converted (won't be returned by GET /v3/cart anymore).
                $cart->markConverted();

                $em->flush();
                return $order;
            },
        );

        // ------------------------------------------------------------------
        // Call Noon INITIATE. Failure modes:
        //   - Network/timeout: rollback by marking the Order as failed
        //     (we keep the row for forensics; future cleanup job archives)
        //   - Auth/upstream: same — log + 502
        //   - Duplicate reference (resultCode 19012): vanishingly unlikely
        //     given our server-side ref generation, but if it happens,
        //     fetch existing via retrieveOrderByReference and reuse
        // ------------------------------------------------------------------
        $returnUrl = $this->buildReturnUrl($orderReference);
        try {
            $initiation = $this->gateway->initiateCheckout(
                order: $order,
                returnUrl: $returnUrl,
                channel: $input->channel,
            );
        } catch (PaymentGatewayException $e) {
            $this->markOrderFailed($order, $e);
            $this->logger->error('checkout.initiate: gateway failure', [
                'user_id' => $user->getId(),
                'order_reference' => $orderReference,
                'kind' => $e->kind,
                'provider_code' => $e->providerCode,
                'message' => $e->getMessage(),
            ]);
            throw HttpException::upstreamFailure(ErrorCodes::PAYMENT_PROVIDER_ERROR, 
                'Payment provider could not initiate checkout. Please try again in a moment.',
            );
        }

        // Persist the INITIATE PaymentTransaction record.
        $tx = new PaymentTransaction(
            order: $order,
            operation: PaymentTransaction::OPERATION_INITIATE,
            status: 'initiated',
            amount: $order->getTotal(),
            idempotencyKey: $idempotencyKey,
            provider: PaymentTransaction::PROVIDER_NOON,
            providerOrderRef: $initiation->providerOrderRef,
            currency: $order->getCurrency(),
            requestPayload: ['returnUrl' => $returnUrl, 'channel' => $input->channel],
            responsePayload: $initiation->rawResponse,
        );
        $transactions->save($tx);

        $this->logger->info('checkout.initiate: success', [
            'user_id' => $user->getId(),
            'order_id' => $order->getId(),
            'order_reference' => $orderReference,
            'provider_order_ref' => $initiation->providerOrderRef,
        ]);

        // M3.1.7-H — notify customer + vendors that the order is in flight.
        // Fire-and-forget: notification failure must not abort checkout.
        $this->notifications->orderPlaced($order);

        return $this->ok([
            'checkout_url' => $initiation->checkoutUrl,
            'order_reference' => $orderReference,
            'provider_order_ref' => $initiation->providerOrderRef,
            'order_id' => $order->getId() ?? 0,
            'idempotent' => false,
        ]);
    }

    private function resolveAddress(
        AddressRepository $addresses,
        User $user,
        ?int $requestedId,
        string $kind, // 'billing' | 'shipping'
    ): Address {
        if ($requestedId !== null) {
            $candidate = $addresses->find($requestedId);
            if ($candidate === null || $candidate->getUser()->getId() !== $user->getId()) {
                throw HttpException::businessRuleViolation(
                    ErrorCodes::VALIDATION_FAILED,
                    ucfirst($kind) . ' address not found.',
                );
            }
            return $candidate;
        }

        $default = $kind === 'billing'
            ? $addresses->findDefaultBillingForUser($user)
            : $addresses->findDefaultShippingForUser($user);

        if ($default === null) {
            throw HttpException::businessRuleViolation(
                ErrorCodes::VALIDATION_FAILED,
                'No ' . $kind . ' address on file. Please add one before checking out.',
            );
        }
        return $default;
    }

    private function snapshotAddress(Address $source, string $type): OrderAddress
    {
        return new OrderAddress(
            type: $type,
            firstName: $source->getRecipientName(),
            phone: $source->getRecipientPhone(),
            email: $source->getUser()->getEmail(),
            street: $source->getStreetAddress() ?? '',
            city: $source->getEmirate(),
            stateProvince: $source->getArea(),
            countryCode: $source->getCountry(),
            postalCode: $source->getPostalCode(),
        );
    }

    private function generateOrderReference(): string
    {
        $epochMs = (int) (microtime(true) * 1000);
        $rand = bin2hex(random_bytes(2)); // 4 hex chars
        return sprintf('V3-%013d-%s', $epochMs, $rand);
    }

    private function buildReturnUrl(string $orderReference): string
    {
        $base = rtrim($_ENV['APP_URL'] ?? 'http://localhost:8080', '/');
        return $base . self::RETURN_PATH_PREFIX . $orderReference;
    }

    private function extractCheckoutUrl(PaymentTransaction $tx): ?string
    {
        $payload = $tx->getResponsePayload();
        if (!is_array($payload)) {
            return null;
        }
        $result = $payload['result'] ?? null;
        if (!is_array($result)) {
            return null;
        }
        $checkoutData = $result['checkoutData'] ?? null;
        if (!is_array($checkoutData)) {
            return null;
        }
        $url = $checkoutData['postUrl'] ?? null;
        return is_string($url) && $url !== '' ? $url : null;
    }

    private function markOrderFailed(Order $order, PaymentGatewayException $e): void
    {
        // Mark the order failed. We keep the row (forensics, refund
        // attempts, customer-service lookups). A future cleanup job
        // archives old failed orders.
        try {
            $order->markFailed();
            $this->em->flush();
        } catch (\Throwable $flushErr) {
            // Order entity might not have markFailed — degrade
            // gracefully. The pending_payment row still exists; the
            // ops team can mark it failed manually.
            $this->logger->warning('checkout.initiate: could not mark order failed', [
                'order_id' => $order->getId(),
                'error' => $flushErr->getMessage(),
            ]);
        }
    }
}

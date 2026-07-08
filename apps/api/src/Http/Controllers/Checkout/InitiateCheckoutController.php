<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Checkout;

use Bayti\Api\Domain\Cart\Cart;
use Bayti\Api\Domain\Cart\CartRepository;
use Bayti\Api\Domain\GiftCard\GiftCard;
use Bayti\Api\Domain\GiftCard\GiftCardRepository;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderAddress;
use Bayti\Api\Domain\Order\OrderItem;
use Bayti\Api\Domain\Order\OrderRepository;
use Bayti\Api\Domain\Payment\PaymentTransaction;
use Bayti\Api\Domain\Payment\PaymentTransactionRepository;
use Bayti\Api\Domain\Promo\Exception\PromoNotApplicableException;
use Bayti\Api\Domain\Promo\PromoCodeResolverService;
use Bayti\Api\Domain\Promo\PromoResolution;
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
        private readonly \Bayti\Api\Notification\Push\PushNotificationService $pushNotifications,
        private readonly PromoCodeResolverService $promoResolver,
        private readonly \Bayti\Api\Domain\Chat\OrderChatProvisioner $chatProvisioner,
        private readonly \Bayti\Api\Domain\Catalog\FlashCampaignStockReducer $flashStock,
        private readonly \Bayti\Api\Domain\Cart\DeliveryFeeCalculator $delivery,
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

        // NOTE: The former pre-checkout phone-verification gate (throwing
        // AUTH_PHONE_NOT_VERIFIED when !$user->isPhoneVerified()) was removed.
        // All customers — migrated and new — already have a verified phone from
        // onboarding, so the gate was redundant and blocked legitimate
        // checkouts. Phone collection/storage and the social phone-link flow
        // are unaffected; only the checkout-blocking check is gone.

        $input = $this->validator->parse($request, InitiateCheckoutInput::class);

        // ------------------------------------------------------------------
        // M3.5 Phase 4 — Gift card PURCHASE flow (no cart involved).
        //
        // When gift_card_purchase_id is supplied the buyer is paying for a
        // gift card they just created (status=pending_payment). We bypass
        // the cart entirely: create a synthetic single-item order whose
        // total equals the card denomination, then call Noon INITIATE.
        //
        // On Noon webhook paid → NoonWebhookController calls activateGiftCard()
        // which finds the card by purchase_order_reference and activates it.
        //
        // This is a completely separate code path from the normal cart
        // checkout. After the early return below the rest of __invoke()
        // (cart-based flow) is not executed.
        // ------------------------------------------------------------------
        if ($input->gift_card_purchase_id !== null) {
            return $this->initiateGiftCardPurchase($request, $user, $input);
        }

        /** @var CartRepository $carts */
        $carts = $this->em->getRepository(Cart::class);
        $cart = $carts->findActiveForUser($user);
        if ($cart === null || $cart->itemCount() === 0) {
            throw HttpException::businessRuleViolation(
                ErrorCodes::VALIDATION_FAILED,
                'Cart is empty.',
            );
        }

        // Extra-measurement backstop: every line whose product requires the
        // vendor's extra measurement must carry it. AddCartItemController blocks
        // these at add time; this guards carts that predate that rule (or were
        // tampered with) so a vendor never receives an un-fulfillable line.
        foreach ($cart->getItems() as $cartItem) {
            $itemProduct = $cartItem->getProduct();
            if ($itemProduct->requiresExtraMsmt() && trim((string) $cartItem->getExtraMeasurement()) === '') {
                throw HttpException::businessRuleViolation(
                    ErrorCodes::VALIDATION_FAILED,
                    sprintf(
                        '"%s" needs an extra measurement before you can check out.',
                        $itemProduct->getName(),
                    ),
                );
            }
            // CUSTOM size must carry the body-measurement snapshot — unless the
            // category makes size optional (bags/accessories/kaftans/mukhawars).
            if (strtoupper((string) $cartItem->getSize()) === 'CUSTOM'
                && !$this->isSizeOptionalCategory($itemProduct)
                && trim((string) $cartItem->getMeasurement()) === ''
            ) {
                throw HttpException::businessRuleViolation(
                    ErrorCodes::VALIDATION_FAILED,
                    sprintf(
                        '"%s" is a custom size — add your measurements before checking out.',
                        $itemProduct->getName(),
                    ),
                );
            }
        }

        /** @var AddressRepository $addresses */
        $addresses = $this->em->getRepository(Address::class);
        $billing = $this->resolveAddress($addresses, $user, $input->billing_address_id, 'billing');
        $shipping = $this->resolveAddress($addresses, $user, $input->shipping_address_id, 'shipping');

        // Compute subtotal from cart items. Don't trust the client.
        $subtotal = $cart->computeSubtotal();
        // Server-authoritative delivery fee: distinct vendors (stores) in the
        // cart, 20 + 15×(stores−1). The client-supplied $input->delivery_fee
        // is intentionally ignored (kept on the DTO for back-compat) so it
        // can't be spoofed.
        $deliveryFee = $this->delivery->forCart($cart);

        // ------------------------------------------------------------------
        // M3.2.X.8-D — Promo code resolution
        //
        // If the caller supplied promo_code, resolve it server-side via
        // PromoCodeResolverService BEFORE the transaction. Resolution
        // failure → 422 with a structured PROMO_* error code, no order
        // ever created.
        //
        // The resolution OVERRIDES any client-supplied discount field.
        // The legacy raw-discount path is preserved (with a deprecation
        // header) for backwards compatibility with the live mobile build
        // until its next release ships the promo_code field.
        // ------------------------------------------------------------------
        $resolution = $this->resolvePromoOrThrow($cart, $user, $input);
        $usedLegacyDiscount = false;
        if ($resolution !== null) {
            $discount = $resolution->discountAmount;
        } elseif ($input->promo_code === null && bccomp($input->discount, '0.00', 2) > 0) {
            // Legacy path: caller supplied a raw discount but no promo
            // code. Honor it; emit deprecation header on the response.
            $discount = $input->discount;
            $usedLegacyDiscount = true;
        } else {
            $discount = '0.00';
        }

        // ------------------------------------------------------------------
        // M3.5 — Gift card resolution (pre-transaction, like promo)
        //
        // If the caller supplied gift_card_code, load and validate the card
        // before entering the EM transaction. Validation failure → 422 with
        // a clear message. No debit happens here — debit is inside the
        // transaction so it's atomic with the order creation.
        // ------------------------------------------------------------------
        $giftCard = null;
        $giftCardAmount = '0.00';
        if ($input->gift_card_code !== null) {
            /** @var GiftCardRepository $gcRepo */
            $gcRepo = $this->em->getRepository(GiftCard::class);
            $giftCard = $gcRepo->findByCode($input->gift_card_code);

            if ($giftCard === null) {
                throw HttpException::badRequest('Gift card not found.');
            }
            // Must be owned by or assigned to this user
            $gcBuyerId     = $giftCard->getBuyerUser()->getId();
            $gcRecipientId = $giftCard->getRecipientUser()?->getId();
            $uid           = $user->getId();
            if ($gcBuyerId !== $uid && $gcRecipientId !== $uid) {
                throw HttpException::forbidden('This gift card is not associated with your account.');
            }
            if (!$giftCard->isSpendable()) {
                throw HttpException::badRequest(
                    'This gift card is not spendable (status: ' . $giftCard->getStatus() . ').'
                );
            }
            // Compute how much the card can cover (may be < total)
            // We pass a tentative total here (subtotal + deliveryFee - discount).
            // The exact order total is computed inside the transaction — we use
            // the same arithmetic as Order::computeTotal() for consistency.
            $tentativeTotal = bcsub(
                bcadd($subtotal, $deliveryFee, 2),
                $discount,
                2
            );
            $tentativeTotal = bccomp($tentativeTotal, '0.00', 2) < 0 ? '0.00' : $tentativeTotal;
            $giftCardAmount = $giftCard->applyableAmount($tentativeTotal);
        }

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
                $billing, $shipping, $resolution, $giftCard, $giftCardAmount,
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

                // M3.2.X.8-D — Promo redemption (unchanged).
                if ($resolution !== null) {
                    $redemption = $this->promoResolver->recordRedemption(
                        promoCode: $resolution->promoCode,
                        user: $user,
                        order: $order,
                        discountAmount: $resolution->discountAmount,
                    );
                    $order->setPromoRedemption($redemption);
                    $em->flush();
                }

                // M3.5 — Gift card debit (atomic with order creation).
                // Debit the card inside the transaction so a gateway failure
                // after this point rolls back both the order AND the debit
                // (they're in the same EM transaction; gateway call is outside).
                if ($giftCard !== null && bccomp($giftCardAmount, '0.00', 2) > 0) {
                    // Re-check spendability under the write lock (order row
                    // now exists; gift card row is about to be mutated).
                    $em->refresh($giftCard);
                    if (!$giftCard->isSpendable()) {
                        throw new \LogicException(
                            'Gift card became non-spendable between validation and debit.'
                        );
                    }
                    $giftCard->debit(
                        amount: $giftCardAmount,
                        orderReference: $orderReference,
                        orderId: $order->getId(),
                    );
                    $order->applyGiftCard($giftCardAmount, $giftCard->getCode());
                    $em->flush();
                }

                return $order;
            },
        );

        // ------------------------------------------------------------------
        // M3.5 — Gateway skip: if the gift card covers the full order total,
        // skip the Noon INITIATE call entirely and mark the order paid now.
        // The mobile app receives { checkout_url: null, gateway_skipped: true }
        // and navigates directly to /success.
        // ------------------------------------------------------------------
        if (bccomp($order->gatewayChargeAmount(), '0.00', 2) === 0) {
            // Mark order paid immediately (no gateway involved).
            /** @var OrderRepository $orderRepo */
            $orderRepo = $this->em->getRepository(Order::class);
            if ($order->markPaid()) {
                // First (and only) paid transition for this zero-value
                // order — decrement flash-campaign stock before the save
                // below persists everything in one flush.
                $this->flashStock->reduceForPaidOrder($order);
            }
            $orderRepo->save($order);

            // Confirmed-at-creation path (no online payment step): the
            // gift-card credit fully covered the total, so the order is
            // PAID the instant it's created — there is no gateway round-
            // trip and no webhook will ever arrive for it. This is the
            // ONLY order-creation path that legitimately confirms without
            // a payment gateway (the moral equivalent of a COD / zero-
            // amount order). The single payment confirmation therefore
            // fires HERE, at creation, and nowhere else for this order.
            // markPaid() above returned true exactly once, so this block
            // runs once per order:
            //   - orderPaid(): the customer's payment-confirmed email/push.
            //   - orderPaidVendors(): the vendor "new order to prepare"
            //     email (vendors are notified only once payment is real).
            // We deliberately do NOT call orderPlaced() here — its
            // customer copy says "await payment confirmation", which is
            // wrong for an order that is already paid.
            $this->notifications->orderPaid($order);
            $this->notifications->orderPaidVendors($order);
            $this->pushNotifications->orderPaid($order);

            // Order is paid (no gateway) — provision the per-item chats now.
            // Fire-and-forget: must not abort the response.
            try {
                $this->chatProvisioner->provisionForOrder($order);
            } catch (\Throwable $e) {
                $this->logger->error('order_chat.provision_failed', [
                    'order_reference' => $orderReference,
                    'error'           => $e->getMessage(),
                ]);
            }

            $this->logger->info('checkout.initiate: gift-card full-cover — gateway skipped', [
                'user_id'         => $user->getId(),
                'order_id'        => $order->getId(),
                'order_reference' => $orderReference,
                'gift_card_amount'=> $order->getGiftCardAmount(),
            ]);

            return $this->ok([
                'checkout_url'      => null,
                'gateway_skipped'   => true,
                'order_reference'   => $orderReference,
                'provider_order_ref'=> '',
                'order_id'          => $order->getId() ?? 0,
                'idempotent'        => false,
                'gift_card_amount'  => $order->getGiftCardAmount(),
            ]);
        }

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
            $this->markOrderFailed($order, $e, $cart);
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
            amount: $order->gatewayChargeAmount(),
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

        // NO order-confirmation notification fires here. This is the
        // PRE-payment path: we have only just handed the customer a Noon
        // checkout URL — they have not paid yet. Firing orderPlaced()/
        // orderPaid() here would confirm an order the customer might
        // abandon. The single confirmation fires later, on the PAID
        // transition, in NoonWebhookController::__invoke() (gated on the
        // order actually transitioning into 'paid' via markPaid()), which
        // sends orderPaid() to the customer AND the vendor "new order to
        // prepare" email. The status-poll endpoint is read-only and never
        // sends, so there is exactly one confirmation per order.
        $response = $this->ok([
            'checkout_url' => $initiation->checkoutUrl,
            'order_reference' => $orderReference,
            'provider_order_ref' => $initiation->providerOrderRef,
            'order_id' => $order->getId() ?? 0,
            'idempotent' => false,
            'gift_card_amount' => $order->getGiftCardAmount(),
        ]);

        // M3.2.X.8-D — Deprecation header on legacy raw-discount path.
        // Signal to clients that the `discount` request field is being
        // phased out in favor of `promo_code`. Header is RFC 8594-style
        // free-form; consumers can grep for the leading prefix to detect.
        if ($usedLegacyDiscount) {
            $response = $response->withHeader(
                'X-Bayti-Deprecation',
                'client-supplied discount accepted as-is; pass promo_code instead',
            );
        }

        return $response;
    }

    /**
     * Gift card purchase flow — creates a synthetic order for the card
     * denomination, calls Noon INITIATE, and returns the checkout URL.
     * The cart is not touched. Called when gift_card_purchase_id is set.
     */
    private function initiateGiftCardPurchase(
        \Psr\Http\Message\ServerRequestInterface $request,
        User $user,
        InitiateCheckoutInput $input,
    ): \Psr\Http\Message\ResponseInterface {
        /** @var GiftCardRepository $gcRepo */
        $gcRepo = $this->em->getRepository(GiftCard::class);

        $card = $gcRepo->find($input->gift_card_purchase_id);
        if ($card === null) {
            throw HttpException::notFound('Gift card not found.');
        }
        if ($card->getBuyerUser()->getId() !== $user->getId()) {
            throw HttpException::forbidden('This gift card does not belong to your account.');
        }
        if ($card->getStatus() !== GiftCard::STATUS_PENDING_PAYMENT) {
            throw HttpException::badRequest(
                'This gift card is not awaiting payment (status: ' . $card->getStatus() . ').'
            );
        }

        // A gift card is a DIGITAL product — it needs no deliverable
        // shipping address. Noon only needs (a) a payer NAME on the billing
        // OrderAddress and (b) a shipping OrderAddress to merely EXIST (the
        // gateway never receives the shipping content). So — unlike the cart
        // path — we do NOT run assertAddressComplete here: we resolve the
        // buyer's billing address if they happen to have one (for a nicer
        // payer name) but never hard-fail on a missing/incomplete one, and we
        // snapshot the SAME resolved address as both the billing and shipping
        // OrderAddress so both rows exist without forcing the buyer to own a
        // deliverable address.
        /** @var \Bayti\Api\Domain\User\AddressRepository $addresses */
        $addresses    = $this->em->getRepository(\Bayti\Api\Domain\User\Address::class);
        $payerAddress = $this->resolveGiftCardPayerAddress($addresses, $user, $input->billing_address_id);

        $denomination   = $card->getDenomination();
        $orderReference = $this->generateOrderReference();

        // Idempotency: same (user, card) pair returns existing checkout URL.
        $idempotencyKey = sprintf('gc-purchase:user=%d:card=%d', $user->getId() ?? 0, $card->getId() ?? 0);

        /** @var \Bayti\Api\Domain\Payment\PaymentTransactionRepository $transactions */
        $transactions = $this->em->getRepository(\Bayti\Api\Domain\Payment\PaymentTransaction::class);
        $existing = $transactions->findByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            $cachedUrl = $this->extractCheckoutUrl($existing);
            if ($cachedUrl !== null) {
                return $this->ok([
                    'checkout_url'       => $cachedUrl,
                    'order_reference'    => $existing->getOrder()->getOrderReference(),
                    'provider_order_ref' => $existing->getProviderOrderRef() ?? '',
                    'order_id'           => $existing->getOrder()->getId() ?? 0,
                    'idempotent'         => true,
                    'gift_card_id'       => $card->getId(),
                ]);
            }
        }

        // Create the synthetic order (no cart items — just the denomination).
        $order = $this->em->wrapInTransaction(
            function (\Doctrine\ORM\EntityManagerInterface $em) use (
                $user, $orderReference, $denomination, $payerAddress,
            ): Order {
                $order = new Order(
                    user: $user,
                    orderReference: $orderReference,
                    subtotal: $denomination,
                    deliveryFee: '0.00',
                    discount: '0.00',
                );
                // Same resolved (or synthesized) address as BOTH billing +
                // shipping — the shipping row only has to exist for Noon.
                $order->addAddress($this->snapshotGiftCardAddress($payerAddress, $user, OrderAddress::TYPE_BILLING));
                $order->addAddress($this->snapshotGiftCardAddress($payerAddress, $user, OrderAddress::TYPE_SHIPPING));
                $em->persist($order);
                $em->flush();
                return $order;
            }
        );

        // Store the reference on the card so the webhook can find it.
        $card->setPurchaseOrderReferenceForPayment($orderReference);
        $this->em->flush();

        // Call Noon INITIATE.
        $returnUrl = $this->buildReturnUrl($orderReference);
        try {
            $initiation = $this->gateway->initiateCheckout(
                order: $order,
                returnUrl: $returnUrl,
                channel: $input->channel,
            );
        } catch (\Bayti\Api\Payment\PaymentGatewayException $e) {
            $this->markOrderFailed($order, $e);
            $this->logger->error('checkout.initiate: gc-purchase gateway failure', [
                'user_id' => $user->getId(), 'card_id' => $card->getId(), 'message' => $e->getMessage(),
            ]);
            throw HttpException::upstreamFailure(
                ErrorCodes::PAYMENT_PROVIDER_ERROR,
                'Payment provider could not initiate checkout. Please try again.',
            );
        }

        $tx = new \Bayti\Api\Domain\Payment\PaymentTransaction(
            order: $order,
            operation: \Bayti\Api\Domain\Payment\PaymentTransaction::OPERATION_INITIATE,
            status: 'initiated',
            amount: $order->gatewayChargeAmount(),
            idempotencyKey: $idempotencyKey,
            provider: \Bayti\Api\Domain\Payment\PaymentTransaction::PROVIDER_NOON,
            providerOrderRef: $initiation->providerOrderRef,
            currency: $order->getCurrency(),
            requestPayload: ['returnUrl' => $returnUrl, 'channel' => $input->channel],
            responsePayload: $initiation->rawResponse,
        );
        $transactions->save($tx);

        $this->logger->info('checkout.initiate: gc-purchase success', [
            'user_id' => $user->getId(), 'order_id' => $order->getId(),
            'card_id' => $card->getId(), 'denomination' => $denomination,
        ]);

        return $this->ok([
            'checkout_url'       => $initiation->checkoutUrl,
            'order_reference'    => $orderReference,
            'provider_order_ref' => $initiation->providerOrderRef,
            'order_id'           => $order->getId() ?? 0,
            'idempotent'         => false,
            'gift_card_id'       => $card->getId(),
        ]);
    }

    /**
     * Resolve the supplied promo code (if any) via the resolver
     * service. Returns null when:
     *   - no promo_code supplied (legacy / no-promo paths)
     *   - no resolver injected (back-compat for callers that built
     *     this controller without the optional dependency)
     *
     * Throws HttpException 422 (via PromoNotApplicableException
     * mapping) when a promo code was supplied but didn't apply.
     * Same shape as POST /v3/cart/quote — clients can use the same
     * error-handling code path.
     */
    private function resolvePromoOrThrow(
        Cart $cart,
        User $user,
        InitiateCheckoutInput $input,
    ): ?PromoResolution {
        if ($input->promo_code === null) {
            return null;
        }
        try {
            return $this->promoResolver->resolveForCart($cart, $user, $input->promo_code);
        } catch (PromoNotApplicableException $e) {
            throw $e->toHttpException();
        }
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
            $this->assertAddressComplete($candidate, $kind);
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
        $this->assertAddressComplete($default, $kind);
        return $default;
    }

    /**
     * Guard a resolved Address against the fields that OrderAddress (the
     * immutable per-order snapshot) requires before it will construct.
     *
     * A saved Address can legitimately carry empty/null optional columns
     * — street_address in particular is nullable — but OrderAddress
     * mandates a recipient name, phone, email, street and city. Without
     * this guard an incomplete (e.g. street-less) address flows into
     * snapshotAddress() → OrderAddress::__construct(), which throws a raw
     * \InvalidArgumentException that the error middleware can only render
     * as an opaque 500 ("An unexpected error occurred"). That left buyers
     * with a created-but-unpayable gift card and no idea why.
     *
     * Validating here — before any order is created and before the
     * gateway is called — converts that 500 into an actionable 422 that
     * names the address (billing/shipping) and the missing field(s), for
     * BOTH the cart and gift-card checkout flows (they share this method).
     */
    private function assertAddressComplete(Address $address, string $kind): void
    {
        $missing = [];
        if (trim($address->getRecipientName()) === '') {
            $missing[] = 'recipient name';
        }
        if (trim($address->getRecipientPhone()) === '') {
            $missing[] = 'phone number';
        }
        if (trim((string) $address->getUser()->getEmail()) === '') {
            $missing[] = 'email';
        }
        if (trim((string) ($address->getStreetAddress() ?? '')) === '') {
            $missing[] = 'street address';
        }
        if (trim($address->getEmirate()) === '') {
            $missing[] = 'emirate';
        }

        if ($missing !== []) {
            throw HttpException::businessRuleViolation(
                ErrorCodes::VALIDATION_FAILED,
                sprintf(
                    'Your %s address is incomplete (missing: %s). Please update it before checking out.',
                    $kind,
                    implode(', ', $missing),
                ),
            );
        }
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

    /**
     * Resolve the billing address to use for a GIFT-CARD purchase WITHOUT
     * asserting completeness. Unlike resolveAddress() (used by the cart
     * path), this never throws: a gift card is digital, so a missing or
     * incomplete address is fine — the only thing we want the address for
     * is a nicer payer name.
     *
     *   - If a specific id was requested and it belongs to the user, use it.
     *   - Otherwise fall back to the user's default billing address.
     *   - May return null (buyer has no address at all); the caller then
     *     synthesizes a minimal snapshot from the user profile.
     */
    private function resolveGiftCardPayerAddress(
        AddressRepository $addresses,
        User $user,
        ?int $requestedId,
    ): ?Address {
        if ($requestedId !== null) {
            $candidate = $addresses->find($requestedId);
            if ($candidate !== null && $candidate->getUser()->getId() === $user->getId()) {
                return $candidate;
            }
            // Foreign/unknown id: don't hard-fail a digital purchase — fall
            // through to the default-billing lookup below.
        }
        return $addresses->findDefaultBillingForUser($user);
    }

    /**
     * Build an OrderAddress snapshot for a gift-card purchase. Tolerant of a
     * null/incomplete source Address: OrderAddress mandates a non-empty
     * name/phone/email/street/city, so we fall back to the user profile for
     * name/phone/email and to a placeholder for the delivery-only fields
     * (street/city) that Noon never receives for a digital gift card. This
     * lets both the billing and shipping rows exist without forcing the
     * buyer to have a deliverable address on file.
     */
    private function snapshotGiftCardAddress(?Address $source, User $user, string $type): OrderAddress
    {
        // Payer name: saved recipient name → user's own name → email → literal.
        $name = $source !== null ? trim($source->getRecipientName()) : '';
        if ($name === '') {
            $name = trim(trim((string) $user->getFirstName()) . ' ' . trim((string) $user->getLastName()));
        }
        if ($name === '') {
            $name = trim((string) $user->getEmail());
        }
        if ($name === '') {
            $name = 'Customer';
        }

        // Phone: saved recipient phone → user phone → placeholder (no courier
        // for a digital card, but OrderAddress requires a non-empty value).
        $phone = $source !== null ? trim($source->getRecipientPhone()) : '';
        if ($phone === '') {
            $phone = trim((string) $user->getPhone());
        }
        if ($phone === '') {
            $phone = 'N/A';
        }

        // Email: user email (guaranteed present in practice); placeholder is a
        // last-resort guard so a blank can never surface as an opaque 500.
        $email = trim((string) $user->getEmail());
        if ($email === '') {
            $email = 'no-reply@3bayti.ae';
        }

        // Delivery-only fields — irrelevant for a digital gift card (never sent
        // to Noon) but non-empty is required by OrderAddress.
        $street = $source !== null ? trim((string) ($source->getStreetAddress() ?? '')) : '';
        if ($street === '') {
            $street = 'N/A';
        }
        $city = $source !== null ? trim($source->getEmirate()) : '';
        if ($city === '') {
            $city = 'N/A';
        }

        return new OrderAddress(
            type: $type,
            firstName: $name,
            phone: $phone,
            email: $email,
            street: $street,
            city: $city,
            stateProvince: $source?->getArea(),
            countryCode: $source?->getCountry() ?? 'AE',
            postalCode: $source?->getPostalCode(),
        );
    }

    private function generateOrderReference(): string
    {
        $epochMs = (int) (microtime(true) * 1000);
        $rand = bin2hex(random_bytes(2)); // 4 hex chars
        return sprintf('V3-%013d-%s', $epochMs, $rand);
    }

    /**
     * Categories where size selection (incl. CUSTOM) is optional, so a CUSTOM
     * line isn't forced to carry a measurement. Keyed on the bare category
     * name (the slug's trailing "-<id>" stripped).
     */
    private function isSizeOptionalCategory(\Bayti\Api\Domain\Catalog\Product $product): bool
    {
        $slug = $product->getCategory()?->getSlug() ?? '';
        $key = strtolower((string) preg_replace('/-\d+$/', '', $slug));
        return in_array($key, ['bags', 'accessories', 'kaftans', 'mukhawars'], true);
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

    private function markOrderFailed(Order $order, PaymentGatewayException $e, ?Cart $cart = null): void
    {
        // Mark the order failed. We keep the row (forensics, refund
        // attempts, customer-service lookups). A future cleanup job
        // archives old failed orders.
        try {
            $order->markFailed();
            // The cart was marked converted inside the order-creation
            // transaction (committed before the gateway call). Payment
            // never started, so restore it to active — the customer keeps
            // their cart and can retry. The bumped updated_at gives the
            // retry a fresh idempotency key. (Gift-card purchases pass no
            // cart; nothing to restore there.)
            if ($cart !== null && !$cart->isActive()) {
                $cart->reactivate();
            }
            $this->em->flush();
        } catch (\Throwable $flushErr) {
            // Order entity might not have markFailed — degrade
            // gracefully. The pending_payment row still exists; the
            // ops team can mark it failed manually.
            $this->logger->warning('checkout.initiate: could not mark order failed / restore cart', [
                'order_id' => $order->getId(),
                'cart_id' => $cart?->getId(),
                'error' => $flushErr->getMessage(),
            ]);
        }
    }
}

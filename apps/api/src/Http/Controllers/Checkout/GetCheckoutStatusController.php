<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Checkout;

use Bayti\Api\Domain\GiftCard\GiftCard;
use Bayti\Api\Domain\GiftCard\GiftCardRepository;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderRepository;
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
 * GET /v3/checkout/status/{order_reference}
 *
 * Returns the current state of an order whose checkout the user
 * just completed (or abandoned, or is still waiting on a webhook
 * for). The mobile webview lands on the path-based return URL
 * after Noon's hosted checkout finishes; mobile then polls this
 * endpoint to learn the authoritative outcome.
 *
 * IMPORTANT: This endpoint reads ONLY v3's local Order state.
 * It does NOT call Noon's GET_ORDER, because:
 *
 *   1. Noon explicitly bans IPs that poll their GET_ORDER endpoint
 *      aggressively (docs.noonpayments.com/test/evaluating-api).
 *      Mobile may poll us 1-2x/second for ~30 seconds; that would
 *      be 60 hits to Noon per checkout — a guaranteed IP ban.
 *   2. The webhook receiver already calls Noon's GET_ORDER as
 *      the authoritative state-of-record, so v3's local state
 *      is correct by the time mobile polls.
 *   3. If the webhook hasn't arrived yet, the response says
 *      `status: 'pending_payment'` and `terminal: false`, telling
 *      mobile to keep polling. Mobile's polling stays cheap (DB
 *      read), Noon's rate limit stays unblown.
 *
 * Authorization: order must belong to the authenticated user.
 * Cross-tenant access returns 404 to avoid leaking existence.
 *
 * Lookup key is `order_reference` (the server-generated 3B-...
 * string) not the numeric order_id, because the mobile webview's
 * return URL is path-based on reference (mobile may not know the
 * numeric id, which is created server-side at initiate time).
 */
final class GetCheckoutStatusController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly \Bayti\Api\Notification\GiftCardDeliveryService $giftCardDelivery,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(
                ErrorCodes::AUTH_INVALID_TOKEN,
                'Authentication required.',
            );
        }

        $reference = (string) ($args['order_reference'] ?? '');
        if ($reference === '' || strlen($reference) > 32) {
            throw HttpException::notFound('Order not found.');
        }

        /** @var OrderRepository $orders */
        $orders = $this->em->getRepository(Order::class);
        $order = $orders->findByOrderReference($reference);

        // Cross-tenant check: order exists but belongs to someone else.
        // Return 404 (not 403) to avoid leaking that the reference is
        // valid — protects against an attacker enumerating references.
        if ($order === null || $order->getUser()->getId() !== $user->getId()) {
            throw HttpException::notFound('Order not found.');
        }

        $status = $order->getStatus();
        $terminal = $this->isTerminalStatus($status);
        $paid = $status === Order::STATUS_PAID
            || $status === Order::STATUS_FULFILLING
            || $status === Order::STATUS_SHIPPED
            || $status === Order::STATUS_DELIVERED;

        // M3.5 Phase 4 — activate gift card on status poll if webhook was delayed.
        // Idempotent: already-active cards are skipped inside activateIfPending().
        if ($paid) {
            try {
                /** @var GiftCardRepository $gcRepo */
                $gcRepo = $this->em->getRepository(GiftCard::class);
                $card   = $gcRepo->findByPurchaseOrderReference($reference);
                if ($card !== null && $card->getStatus() === GiftCard::STATUS_PENDING_PAYMENT) {
                    $card->activate($reference);
                    $gcRepo->save($card);

                    // Webhook was delayed; deliver to the recipient now that
                    // this poll has activated the card (no-op if future-scheduled).
                    $this->giftCardDelivery->deliverIfDue($card);
                }
            } catch (\Throwable) {
                // Never fail the status poll due to activation error.
            }
        }

        $giftCardId = null;
        if ($paid) {
            try {
                /** @var GiftCardRepository $gcr */
                $gcr  = $this->em->getRepository(GiftCard::class);
                $card = $gcr->findByPurchaseOrderReference($reference);
                $giftCardId = $card?->getId();
            } catch (\Throwable) { /* silent */ }
        }

        return $this->ok([
            'order_reference' => $reference,
            'order_id'        => $order->getId() ?? 0,
            'status'          => $status,
            'terminal'        => $terminal,
            'paid'            => $paid,
            'total'           => $order->getTotal(),
            'currency'        => $order->getCurrency(),
            'paid_at'         => $order->getPaidAt()?->format(\DateTimeInterface::ATOM),
            'gift_card_id'    => $giftCardId,
        ]);
    }

    private function isTerminalStatus(string $status): bool
    {
        return in_array($status, [
            Order::STATUS_PAID,
            Order::STATUS_FULFILLING,
            Order::STATUS_SHIPPED,
            Order::STATUS_DELIVERED,
            Order::STATUS_CANCELLED,
            Order::STATUS_REFUNDED,
            Order::STATUS_FAILED,
        ], true);
    }
}

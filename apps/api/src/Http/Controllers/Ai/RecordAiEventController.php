<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Ai;

use Bayti\Api\Ai\Analytics\AiEventLogger;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/ai/events — client-side Ain analytics.
 *
 * The app/web posts UI events (opened, product clicked, added to cart, vendor
 * clicked, …) with the interaction_id returned by the concierge, so the funnel
 * — and later, revenue attribution — can be measured. Only a whitelist of
 * client events is accepted; server-side events are logged by the services.
 */
final class RecordAiEventController
{
    use Responder;

    /** Events the client is allowed to post (server emits the query_* ones itself). */
    private const CLIENT_EVENTS = [
        'ai_opened',
        'ai_product_clicked',
        'ai_product_added_to_cart',
        'ai_vendor_clicked',
        'ai_gift_started',
        'ai_gift_card_recommended',
        'complete_look_viewed',
        'complete_look_item_added',
        'complete_look_added',
        'style_ai_used',
        'style_saved',
        'style_shared',
        'gift_reminder_created',
        'gift_reminder_clicked',
        // Personal Style Profile: the net-new product-view signal that feeds the
        // profile builder, plus the For-You rail impression/click events.
        'product_viewed',
        'for_you_shown',
        'for_you_product_clicked',
    ];

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly AiEventLogger $events,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);

        $event = isset($body['event']) && is_scalar($body['event']) ? (string) $body['event'] : '';
        if (!in_array($event, self::CLIENT_EVENTS, true)) {
            throw HttpException::validation(['event' => ['Unknown or unsupported event.']]);
        }

        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        $userId = $user instanceof User ? $user->getId() : null;

        $this->events->recordEvent(
            $event,
            $this->intOrNull($body['interaction_id'] ?? null),
            $userId,
            isset($body['session_id']) && is_scalar($body['session_id']) ? mb_substr((string) $body['session_id'], 0, 64) : null,
            $this->intOrNull($body['product_id'] ?? null),
            $this->intOrNull($body['vendor_id'] ?? null),
            isset($body['metadata']) && is_array($body['metadata']) ? $body['metadata'] : null,
        );

        return $this->ok(['recorded' => true]);
    }

    private function intOrNull(mixed $v): ?int
    {
        return is_numeric($v) ? (int) $v : null;
    }
}

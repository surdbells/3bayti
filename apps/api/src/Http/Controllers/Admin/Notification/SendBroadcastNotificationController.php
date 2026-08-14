<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Notification;

use Bayti\Api\Domain\Notification\BroadcastSender;
use Bayti\Api\Domain\Notification\DeviceToken;
use Bayti\Api\Domain\Notification\DeviceTokenRepository;
use Bayti\Api\Domain\Notification\NotificationBroadcast;
use Bayti\Api\Domain\Notification\NotificationTemplate;
use Bayti\Api\Domain\Notification\TemplateVariableResolver;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\NotificationBroadcastSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/admin/notifications
 *
 * Compose "Send Now": create a broadcast and either send it inline (small
 * audiences → instant summary) or queue it for the background dispatcher
 * (larger audiences → never blocks the request, survives without the admin
 * keeping the tab open). Either way the broadcast is recorded in history
 * with per-recipient tracking + device breakdown.
 *
 * Body:
 *   title     string  required
 *   body      string  required
 *   audience  string  optional  — all | customers | vendors | admins (default all)
 *   image_url string  optional
 *   deep_link string  optional
 *   data      object  optional  — extra string key/values forwarded to the client
 *
 * Backward compatible: the response still carries { recipients, sent, failed }
 * for a synchronously-sent (small) broadcast; queued sends return status
 * 'queued' + broadcast_id so the UI can follow it in history.
 */
final class SendBroadcastNotificationController
{
    use Responder;

    private const AUDIENCES = ['all', 'customers', 'vendors', 'admins'];

    /** Audiences at/under this size send inline; larger ones queue. */
    private const INLINE_THRESHOLD = 200;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly BroadcastSender $sender,
        private readonly TemplateVariableResolver $variables,
        private readonly NotificationBroadcastSerializer $serializer,
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

        $body = (array) ($request->getParsedBody() ?? []);

        // Optional template: fills any field the request doesn't override.
        $templateId = isset($body['template_id']) && $body['template_id'] !== '' ? (int) $body['template_id'] : null;
        $template = null;
        if ($templateId !== null) {
            $template = $this->em->getRepository(NotificationTemplate::class)->find($templateId);
            if (!$template instanceof NotificationTemplate) {
                throw HttpException::badRequest('Template not found.');
            }
            if (!$template->isActive()) {
                throw HttpException::badRequest('That template is inactive.');
            }
        }

        $title = trim((string) ($body['title'] ?? ($template?->getTitle() ?? '')));
        $message = trim((string) ($body['body'] ?? ($template?->getBody() ?? '')));
        if ($title === '') {
            throw HttpException::badRequest('title is required.');
        }
        if ($message === '') {
            throw HttpException::badRequest('body is required.');
        }

        // Message may carry {{variables}} (resolved per recipient at send).
        $unknown = $this->variables->unknownVariables($title, $message);
        if ($unknown !== []) {
            throw HttpException::badRequest(
                'Unknown variable(s): ' . implode(', ', array_map(static fn (string $v): string => '{{' . $v . '}}', $unknown))
                . '. Supported: ' . implode(', ', TemplateVariableResolver::knownKeys()) . '.'
            );
        }

        $audience = (string) ($body['audience'] ?? 'all');
        if (!in_array($audience, self::AUDIENCES, true)) {
            throw HttpException::badRequest('audience must be one of: ' . implode(', ', self::AUDIENCES) . '.');
        }

        $imageUrl = $this->nullableStr($body['image_url'] ?? null, 1000) ?? $template?->getImageUrl();
        $deepLink = $this->nullableStr($body['deep_link'] ?? null, 1000) ?? $template?->getDeepLink();

        $data = [];
        if (isset($body['data']) && is_array($body['data'])) {
            foreach ($body['data'] as $k => $v) {
                // FCM data values must be strings.
                $data[(string) $k] = is_scalar($v) ? (string) $v : (string) json_encode($v);
            }
        }

        /** @var DeviceTokenRepository $deviceRepo */
        $deviceRepo = $this->em->getRepository(DeviceToken::class);
        $totals = $deviceRepo->countActiveForAudienceByPlatform($audience);
        if ($totals['total'] === 0) {
            throw HttpException::badRequest('No active devices in that audience — nothing to send.');
        }

        $broadcast = new NotificationBroadcast(
            title: $title,
            body: $message,
            audience: ['type' => $audience],
            sentByUserId: $user->getId(),
            imageUrl: $imageUrl,
            deepLink: $deepLink,
            data: $data === [] ? null : $data,
        );
        $broadcast->setTemplateId($templateId);

        $names = [(int) $user->getId() => $this->displayName($user)];

        if ($totals['total'] <= self::INLINE_THRESHOLD) {
            // Small audience — send now and return the finished summary.
            $broadcast->markProcessing();
            $this->em->persist($broadcast);
            $this->em->flush();
            $this->sender->process($broadcast);

            return $this->ok(['data' => array_merge(
                $this->serializer->detailShape($broadcast, $names),
                // Legacy-compatible flat fields for the existing compose UI.
                [
                    'recipients' => $broadcast->getRecipientsTotal(),
                    'sent' => $broadcast->getSentCount(),
                    'failed' => $broadcast->getFailedCount(),
                    'queued' => false,
                ],
            )]);
        }

        // Larger audience — queue for the background dispatcher.
        $this->em->persist($broadcast);
        $this->em->flush();

        return $this->ok(['data' => array_merge(
            $this->serializer->detailShape($broadcast, $names),
            [
                'recipients' => $totals['total'],
                'sent' => 0,
                'failed' => 0,
                'queued' => true,
            ],
        )]);
    }

    private function nullableStr(mixed $v, int $max): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $t = trim($v);
        return $t === '' ? null : mb_substr($t, 0, $max);
    }

    private function displayName(User $user): string
    {
        $name = trim((string) $user->getFirstName() . ' ' . (string) $user->getLastName());
        return $name !== '' ? $name : $user->getEmail();
    }
}

<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Notification;

use Bayti\Api\Domain\Notification\DeviceToken;
use Bayti\Api\Domain\Notification\DeviceTokenRepository;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Responder;
use Bayti\Api\Notification\Push\PushException;
use Bayti\Api\Notification\Push\PushMessage;
use Bayti\Api\Notification\Push\PushSenderInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * POST /v3/admin/notifications
 *
 * Admin push broadcast — parity with (and a real implementation of) the
 * legacy admin/send_notifications.php, which was only a single-token
 * scaffold. Sends a push notification to every active device token,
 * optionally narrowed to an audience (customers / vendors / admins).
 *
 * Body:
 *   title    string  required  — notification title
 *   body     string  required  — notification body
 *   audience string  optional  — all | customers | vendors | admins (default all)
 *   data     object  optional  — extra key/value payload forwarded to the client
 *
 * Behaviour:
 *   - Fans out one send per token. A single failed token never aborts the
 *     run (each send is isolated); failures are counted and logged.
 *   - Tokens FCM reports as UNREGISTERED are deactivated so they drop out
 *     of future broadcasts.
 *   - Returns a summary { audience, recipients, sent, failed }.
 *
 * Admin role is enforced by AdminAuthMiddleware on the route group.
 */
final class SendBroadcastNotificationController
{
    use Responder;

    private const AUDIENCES = ['all', 'customers', 'vendors', 'admins'];

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly DeviceTokenRepository $deviceTokens,
        private readonly PushSenderInterface $pushSender,
        private readonly LoggerInterface $logger,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);

        $title = trim((string) ($body['title'] ?? ''));
        $message = trim((string) ($body['body'] ?? ''));
        if ($title === '') {
            throw HttpException::badRequest('title is required.');
        }
        if ($message === '') {
            throw HttpException::badRequest('body is required.');
        }

        $audience = (string) ($body['audience'] ?? 'all');
        if (!in_array($audience, self::AUDIENCES, true)) {
            throw HttpException::badRequest(
                'audience must be one of: ' . implode(', ', self::AUDIENCES) . '.'
            );
        }

        $data = [];
        if (isset($body['data']) && is_array($body['data'])) {
            // FCM data values must be strings.
            foreach ($body['data'] as $k => $v) {
                $data[(string) $k] = is_scalar($v) ? (string) $v : json_encode($v);
            }
        }

        $tokens = $this->deviceTokens->findAllActiveTokenStrings($audience);
        $push = new PushMessage($title, $message, $data);

        $sent = 0;
        $failed = 0;
        foreach ($tokens as $token) {
            try {
                $this->pushSender->sendToToken($token, $push, [
                    'event' => 'admin.broadcast',
                    'audience' => $audience,
                ]);
                $sent++;
            } catch (PushException $e) {
                $failed++;
                if ($e->isTokenDead()) {
                    // Permanently dead token — prune so it leaves the pool.
                    $this->deviceTokens->deactivateByToken($token);
                }
                $this->logger->warning('admin broadcast: token send failed', [
                    'event' => 'admin.broadcast',
                    'kind' => $e->kind,
                ]);
            }
        }

        return $this->ok([
            'data' => [
                'audience' => $audience,
                'recipients' => count($tokens),
                'sent' => $sent,
                'failed' => $failed,
            ],
        ]);
    }
}

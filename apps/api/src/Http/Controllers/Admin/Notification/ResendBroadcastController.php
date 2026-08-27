<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Notification;

use Bayti\Api\Domain\Notification\BroadcastSender;
use Bayti\Api\Domain\Notification\NotificationBroadcast;
use Bayti\Api\Domain\Notification\NotificationBroadcastRecipient;
use Bayti\Api\Domain\Notification\NotificationBroadcastRecipientRepository;
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
 * POST /v3/admin/notification-broadcasts/{id}/resend
 *
 * Resend a past broadcast. Creates a NEW broadcast (referencing the source
 * via resent_from_broadcast_id), the original history record is never
 * modified. Body: mode = 'failed' (default, only the devices that failed)
 * or 'all' (every original recipient). Targets are re-resolved to CURRENT
 * active tokens, so dead tokens are skipped.
 */
final class ResendBroadcastController
{
    use Responder;

    /** Same inline/queue split as the compose controller. */
    private const INLINE_THRESHOLD = 200;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly BroadcastSender $sender,
        private readonly NotificationBroadcastSerializer $serializer,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $_r, array $args): ResponseInterface
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        $id = (int) ($args['id'] ?? 0);
        $source = $id > 0 ? $this->em->getRepository(NotificationBroadcast::class)->find($id) : null;
        if (!$source instanceof NotificationBroadcast) {
            throw HttpException::notFound('Broadcast not found.');
        }

        $terminal = [
            NotificationBroadcast::STATUS_SENT,
            NotificationBroadcast::STATUS_PARTIALLY_DELIVERED,
            NotificationBroadcast::STATUS_FAILED,
        ];
        if (!in_array($source->getStatus(), $terminal, true)) {
            throw HttpException::badRequest('Only a finished broadcast can be resent.');
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $mode = (string) ($body['mode'] ?? NotificationBroadcast::RESEND_FAILED);
        if (!in_array($mode, [NotificationBroadcast::RESEND_ALL, NotificationBroadcast::RESEND_FAILED], true)) {
            throw HttpException::badRequest("mode must be 'all' or 'failed'.");
        }
        $onlyFailed = $mode === NotificationBroadcast::RESEND_FAILED;

        /** @var NotificationBroadcastRecipientRepository $recipientRepo */
        $recipientRepo = $this->em->getRepository(NotificationBroadcastRecipient::class);
        $totals = $recipientRepo->countResendTargetsByPlatform($id, $onlyFailed);
        if ($totals['total'] === 0) {
            throw HttpException::badRequest(
                $onlyFailed
                    ? 'No failed recipients with an active device remain to resend to.'
                    : 'No original recipients with an active device remain to resend to.',
            );
        }

        $broadcast = new NotificationBroadcast(
            title: $source->getTitle(),
            body: $source->getBody(),
            audience: $source->getAudience(),
            sentByUserId: $user->getId(),
            imageUrl: $source->getImageUrl(),
            deepLink: $source->getDeepLink(),
            data: $source->getData(),
        );
        $broadcast->markResend($id, $mode);

        $names = [(int) $user->getId() => $this->displayName($user)];

        if ($totals['total'] <= self::INLINE_THRESHOLD) {
            $broadcast->markProcessing();
            $this->em->persist($broadcast);
            $this->em->flush();
            $this->sender->process($broadcast);
            return $this->ok(['data' => array_merge(
                $this->serializer->detailShape($broadcast, $names),
                ['queued' => false],
            )]);
        }

        $this->em->persist($broadcast);
        $this->em->flush();
        return $this->ok(['data' => array_merge(
            $this->serializer->detailShape($broadcast, $names),
            ['queued' => true],
        )]);
    }

    private function displayName(User $user): string
    {
        $name = trim((string) $user->getFirstName() . ' ' . (string) $user->getLastName());
        return $name !== '' ? $name : $user->getEmail();
    }
}

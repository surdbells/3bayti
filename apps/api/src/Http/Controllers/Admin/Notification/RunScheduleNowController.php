<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Notification;

use Bayti\Api\Domain\Notification\NotificationBroadcast;
use Bayti\Api\Domain\Notification\NotificationSchedule;
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
 * POST /v3/admin/notification-schedules/{id}/run-now
 *
 * Manually emit one occurrence right now, creates a QUEUED broadcast from
 * the schedule (the dispatcher sends it within a minute). This is out-of-band:
 * it does NOT advance the recurrence, so the normal cadence is unaffected. The
 * new broadcast is linked to the schedule and appears among its occurrences.
 */
final class RunScheduleNowController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
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
        $schedule = $id > 0 ? $this->em->getRepository(NotificationSchedule::class)->find($id) : null;
        if (!$schedule instanceof NotificationSchedule) {
            throw HttpException::notFound('Schedule not found.');
        }
        if ($schedule->getStatus() === NotificationSchedule::STATUS_CANCELLED) {
            throw HttpException::badRequest('This schedule is cancelled.');
        }

        $broadcast = new NotificationBroadcast(
            title: $schedule->getTitle(),
            body: $schedule->getBody(),
            audience: $schedule->getAudience(),
            sentByUserId: $user->getId(),
            imageUrl: $schedule->getImageUrl(),
            deepLink: $schedule->getDeepLink(),
            data: $schedule->getData(),
        );
        $broadcast->setTemplateId($schedule->getTemplateId());
        $broadcast->setScheduleId($schedule->getId());
        $this->em->persist($broadcast);
        $this->em->flush();

        return $this->ok(['data' => array_merge(
            $this->serializer->detailShape($broadcast),
            ['queued' => true],
        )]);
    }
}

<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Notification;

use Bayti\Api\Domain\Notification\NotificationSchedule;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\NotificationScheduleSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** POST /v3/admin/notification-schedules/{id}/cancel — stop future runs. Past
 *  occurrences (broadcasts) are kept for history. */
final class CancelScheduleController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly NotificationScheduleSerializer $serializer,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $_r, array $args): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        $schedule = $id > 0 ? $this->em->getRepository(NotificationSchedule::class)->find($id) : null;
        if (!$schedule instanceof NotificationSchedule) {
            throw HttpException::notFound('Schedule not found.');
        }
        if (in_array($schedule->getStatus(), [NotificationSchedule::STATUS_COMPLETED, NotificationSchedule::STATUS_CANCELLED], true)) {
            throw HttpException::badRequest('This schedule is already finished.');
        }
        $schedule->cancel();
        $this->em->flush();

        return $this->ok(['data' => $this->serializer->shape($schedule)]);
    }
}

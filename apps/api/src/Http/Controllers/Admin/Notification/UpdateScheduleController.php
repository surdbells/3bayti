<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Notification;

use Bayti\Api\Domain\Notification\NotificationSchedule;
use Bayti\Api\Domain\Notification\TemplateVariableResolver;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\NotificationScheduleSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** PUT /v3/admin/notification-schedules/{id} — edit a draft/scheduled schedule. */
final class UpdateScheduleController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly TemplateVariableResolver $variables,
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
        if (!$schedule->isEditable()) {
            throw HttpException::badRequest('Only a draft or scheduled notification can be edited.');
        }

        $in = ScheduleInput::parse((array) ($request->getParsedBody() ?? []), $this->variables, $this->em);
        $schedule->reschedule(
            title: $in['title'],
            body: $in['body'],
            audience: $in['audience'],
            frequency: $in['frequency'],
            startAt: $in['startAt'],
            endAt: $in['endAt'],
            name: $in['name'],
            templateId: $in['templateId'],
            imageUrl: $in['imageUrl'],
            deepLink: $in['deepLink'],
            data: $in['data'],
            timezone: $in['timezone'],
        );
        $this->em->flush();

        return $this->ok(['data' => $this->serializer->shape($schedule)]);
    }
}

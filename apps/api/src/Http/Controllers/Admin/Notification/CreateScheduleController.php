<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Notification;

use Bayti\Api\Domain\Notification\NotificationSchedule;
use Bayti\Api\Domain\Notification\TemplateVariableResolver;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\NotificationScheduleSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** POST /v3/admin/notification-schedules, schedule a (recurring) notification. */
final class CreateScheduleController
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

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        $in = ScheduleInput::parse((array) ($request->getParsedBody() ?? []), $this->variables, $this->em);

        $schedule = new NotificationSchedule(
            title: $in['title'],
            body: $in['body'],
            audience: $in['audience'],
            frequency: $in['frequency'],
            startAt: $in['startAt'],
            endAt: $in['endAt'],
            createdByUserId: $user->getId(),
            name: $in['name'],
            templateId: $in['templateId'],
            imageUrl: $in['imageUrl'],
            deepLink: $in['deepLink'],
            data: $in['data'],
            timezone: $in['timezone'],
            status: $in['status'],
        );
        $this->em->persist($schedule);
        $this->em->flush();

        return $this->created(['data' => $this->serializer->shape($schedule)]);
    }
}

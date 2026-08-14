<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Notification;

use Bayti\Api\Domain\Notification\NotificationTemplate;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\NotificationTemplateSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /v3/admin/notification-templates/{id}. */
final class GetTemplateController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly NotificationTemplateSerializer $serializer,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $_r, array $args): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        $template = $id > 0 ? $this->em->getRepository(NotificationTemplate::class)->find($id) : null;
        if (!$template instanceof NotificationTemplate) {
            throw HttpException::notFound('Template not found.');
        }
        return $this->ok(['data' => $this->serializer->shape($template)]);
    }
}

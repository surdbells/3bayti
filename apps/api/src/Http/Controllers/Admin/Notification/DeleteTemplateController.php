<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Notification;

use Bayti\Api\Domain\Notification\NotificationTemplate;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** DELETE /v3/admin/notification-templates/{id}. Broadcasts that used it keep
 *  their copied message (template_id FK is ON DELETE SET NULL). */
final class DeleteTemplateController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
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
        $this->em->remove($template);
        $this->em->flush();
        return $this->noContent();
    }
}

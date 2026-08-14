<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Notification;

use Bayti\Api\Domain\Notification\TemplateVariableResolver;
use Bayti\Api\Http\Responder;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/admin/notification-templates/variables
 *
 * The catalog of supported {{variables}} (key + label + sample) — the compose
 * UI renders these as insertable chips and validates against them.
 */
final class GetTemplateVariablesController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $_r, array $args): ResponseInterface
    {
        return $this->ok(['data' => TemplateVariableResolver::catalog()]);
    }
}

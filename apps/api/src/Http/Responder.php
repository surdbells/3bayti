<?php

declare(strict_types=1);

namespace Bayti\Api\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ResponseFactoryInterface;

/**
 * Mixed into controllers to provide tiny JSON-response helpers.
 *
 * Why a trait instead of a base class
 * ----------------------------------
 * Controllers are autowired by PHP-DI; making them extend a base
 * class is fine but adds nothing. A trait keeps controllers as
 * plain composable classes. A controller using this trait declares:
 *
 *   use Responder;
 *
 *   public function __construct(
 *       private readonly ResponseFactoryInterface $responseFactory,
 *       ...
 *   ) {}
 *
 * Then `$this->ok(['user' => ...])` and `$this->created([...])`
 * "just work".
 */
trait Responder
{
    /**
     * Subclasses MUST inject ResponseFactoryInterface as
     * $this->responseFactory. PHP-DI does this automatically when
     * the controller declares it in its constructor.
     */
    abstract protected function getResponseFactory(): ResponseFactoryInterface;

    /**
     * 200 OK with a JSON body.
     *
     * @param array<string, mixed> $body
     */
    protected function ok(array $body = []): ResponseInterface
    {
        return $this->json(200, $body);
    }

    /**
     * 201 Created with a JSON body.
     *
     * @param array<string, mixed> $body
     */
    protected function created(array $body = []): ResponseInterface
    {
        return $this->json(201, $body);
    }

    /**
     * 204 No Content. No body. For successful DELETE / logout / etc.
     */
    protected function noContent(): ResponseInterface
    {
        return $this->getResponseFactory()->createResponse(204);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(int $status, array $body): ResponseInterface
    {
        $response = $this->getResponseFactory()->createResponse($status);

        // Empty `[]` array would JSON-encode to `[]` not `{}`, which
        // is wrong shape for an empty object. We always want object
        // shape for our JSON envelope; an explicit empty body becomes
        // `{}`.
        if ($body === []) {
            $json = '{}';
        } else {
            $encoded = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $json = $encoded !== false ? $encoded : '{}';
        }

        $response->getBody()->write($json);
        return $response->withHeader('Content-Type', 'application/json');
    }
}

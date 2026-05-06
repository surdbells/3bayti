<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Errors;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Outermost middleware — catches everything and renders the standard
 * `{error: {code, message, details}}` envelope.
 *
 * Behaviour
 * ---------
 *   - HttpException (our typed errors) → status + code + message + details
 *     all come from the exception; envelope is built directly.
 *
 *   - Anything else (uncaught \Throwable) → 500 with code = INTERNAL_ERROR.
 *     The original exception is logged in full detail; the client sees
 *     a generic message. In dev mode (APP_ENV != prod), the response
 *     ALSO includes the exception class + message + a few stack frames
 *     for faster debugging.
 *
 * Why we have this in addition to Slim's built-in error middleware
 * ---------------------------------------------------------------
 * Slim's default error handler renders HTML by default; we always
 * want JSON. We could plug in a custom renderer to Slim's middleware
 * but that ties us to its ergonomics. A standalone middleware is
 * easier to test and easier to swap if we move off Slim later.
 *
 * Production safety
 * -----------------
 * Never send exception messages to the client unless they came from
 * a HttpException (which is explicitly safe-to-show). The catch-all
 * branch returns a generic 500 — internal exceptions can leak SQL,
 * file paths, env vars etc. that we DO NOT want a user to see.
 */
final class ApiErrorMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly bool $debugMode = false,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (HttpException $e) {
            return $this->renderHttpException($e);
        } catch (\Throwable $e) {
            // Log everything we have. The client sees a generic 500.
            $this->logger->error('Unhandled exception in request handler', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'method' => $request->getMethod(),
                'uri' => (string) $request->getUri(),
            ]);
            return $this->renderInternalError($e);
        }
    }

    private function renderHttpException(HttpException $e): ResponseInterface
    {
        $response = $this->responseFactory->createResponse($e->status);
        $payload = ['error' => [
            'code' => $e->errorCode,
            'message' => $e->getMessage(),
        ]];
        if ($e->details !== []) {
            $payload['error']['details'] = $e->details;
        }
        return $this->writeJson($response, $payload);
    }

    private function renderInternalError(\Throwable $e): ResponseInterface
    {
        $response = $this->responseFactory->createResponse(500);
        $payload = ['error' => [
            'code' => ErrorCodes::INTERNAL_ERROR,
            'message' => 'An unexpected error occurred.',
        ]];

        if ($this->debugMode) {
            // Helpful in dev; never in prod (debugMode is wired off).
            $payload['error']['debug'] = [
                'class' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 5),
            ];
        }

        return $this->writeJson($response, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeJson(ResponseInterface $response, array $payload): ResponseInterface
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            // Should never happen with our payload shapes, but
            // guard anyway. Send a hard-coded fallback.
            $json = '{"error":{"code":"INTERNAL_ERROR","message":"Failed to encode response."}}';
        }
        $response->getBody()->write($json);
        return $response->withHeader('Content-Type', 'application/json');
    }
}

<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Ai;

use Bayti\Api\Domain\TryOn\TryOnJob;
use Bayti\Api\Domain\TryOn\TryOnJobRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/ai/try-on/{reference} — poll a virtual-try-on job.
 *
 * AuthMiddleware. The job is looked up by its unguessable reference and scoped
 * to the caller (a mismatched owner returns 404, never leaking another user's
 * job). Returns the status and, when succeeded, the generated image URL; when
 * failed, a friendly message. The client polls this until a terminal status.
 */
final class GetTryOnController
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

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $_response, array $args): ResponseInterface
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User || $user->getId() === null) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        $reference = trim($args['reference'] ?? '');
        $repo = $this->em->getRepository(TryOnJob::class);
        $job = $repo instanceof TryOnJobRepository ? $repo->findByReference($reference) : null;

        if ($job === null || $job->getUserId() !== $user->getId()) {
            throw HttpException::notFound('Try-on not found.');
        }

        $payload = [
            'job_reference' => $job->getJobReference(),
            'status' => $job->getStatus(),
            'result_image_url' => $job->getStatus() === TryOnJob::STATUS_SUCCEEDED ? $job->getResultImageUrl() : null,
            'error' => $job->getStatus() === TryOnJob::STATUS_FAILED
                ? ($job->getErrorSample() ?? 'Try-on could not be completed.')
                : null,
        ];

        return $this->ok($payload);
    }
}

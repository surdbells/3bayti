<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\VendorApplication;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Catalog\VendorApplication;
use Bayti\Api\Domain\Catalog\VendorApplicationRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Admin\VendorApplication\Dto\RejectVendorApplicationInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\VendorApplicationSerializer;
use Bayti\Api\Http\Validator\RequestValidator;
use Bayti\Api\Notification\MailerException;
use Bayti\Api\Notification\MailerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * POST /v3/admin/vendor-applications/{id}/reject  (gated vendors.approve)
 *
 * Reject a pending seller application with an optional reason. Marks the
 * application rejected + reject_reason + reviewed_by/at, and optionally
 * emails the applicant (non-blocking).
 *
 * Body: { "reason"?: string }
 *
 * Idempotent: re-calling on an already-rejected application is a 200
 * no-op. Approved applications cannot be rejected (422).
 *
 * Success: 200 { "application": { ...adminShape, status:"rejected" } }
 */
final class RejectVendorApplicationController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly EntityManagerInterface $em,
        private readonly VendorApplicationSerializer $serializer,
        private readonly MailerInterface $mailer,
        private readonly AuditEmitter $audit,
        private readonly LoggerInterface $logger,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $admin = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$admin instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        $idRaw = $args['id'] ?? '';
        if (!ctype_digit((string) $idRaw)) {
            throw HttpException::notFound('Application not found.');
        }

        /** @var VendorApplicationRepository $appRepo */
        $appRepo = $this->em->getRepository(VendorApplication::class);
        $application = $appRepo->findById((int) $idRaw);
        if ($application === null) {
            throw HttpException::notFound('Application not found.');
        }

        $input = $this->validator->parse($request, RejectVendorApplicationInput::class);

        // Idempotent: already rejected → return the existing record.
        if ($application->isRejected()) {
            return $this->ok([
                'application' => $this->serializer->adminShape($application),
            ]);
        }

        // Approved applications cannot be rejected.
        if ($application->isApproved()) {
            throw HttpException::businessRuleViolation(
                message: 'This application has already been approved and cannot be rejected.',
            );
        }

        $before = $this->audit->snapshot($application);

        $application->markRejected($input->reason, $admin);
        $this->em->flush();

        $this->notifyApplicant($application);

        $this->audit->recordUpdate(
            request: $request,
            actor: $admin,
            subject: $application,
            beforeSnapshot: $before,
            afterSnapshot: $this->audit->snapshot($application),
        );

        return $this->ok([
            'application' => $this->serializer->adminShape($application),
        ]);
    }

    /**
     * Email the applicant that their application was not approved.
     * Non-blocking, a mailer failure never fails the rejection.
     */
    private function notifyApplicant(VendorApplication $application): void
    {
        $subject = 'Update on your seller application';
        $reasonLine = $application->getRejectReason() !== null
            ? "\n\nReason: " . $application->getRejectReason()
            : '';
        $text = sprintf(
            "Hello %s,\n\nThank you for your interest in selling on 3bayti. "
            . "After review, we are unable to approve your application at this time.%s\n\n"
            . "You're welcome to reach out to our team if you have questions.",
            $application->getFirstName(),
            $reasonLine,
        );
        $html = nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));

        try {
            $this->mailer->send($application->getEmail(), $subject, $text, $html, [
                'template' => 'vendor_application.rejected',
                'application_id' => $application->getId(),
            ]);
        } catch (MailerException $e) {
            $this->logger->warning('vendor-application reject notification failed (non-blocking)', [
                'application_id' => $application->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}

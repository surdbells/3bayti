<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\VendorApplication;

use Bayti\Api\Domain\Catalog\VendorApplication;
use Bayti\Api\Domain\Catalog\VendorApplicationRepository;
use Bayti\Api\Http\Controllers\VendorApplication\Dto\SubmitVendorApplicationInput;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\RequestContext;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Validator\RequestValidator;
use Bayti\Api\Infrastructure\Cache\KeyValueStore;
use Bayti\Api\Infrastructure\Cache\KeyValueStoreException;
use Bayti\Api\Notification\MailerException;
use Bayti\Api\Notification\MailerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * POST /v3/vendor-applications  (PUBLIC — no auth)
 *
 * A prospective seller submits an application from the storefront
 * "Become a seller" form. This is the ONLY door into the marketplace;
 * self-serve vendor creation is disabled.
 *
 * Anti-spam
 * ---------
 * Throttled at two axes via KeyValueStore (Redis):
 *   - per-IP  (CF-Connecting-IP):  short cooldown + hourly cap
 *   - per-email:                    short cooldown + hourly cap
 * Fail-CLOSED on a cooldown-claim Redis error for the email axis is not
 * attempted (no DB column); instead the per-email PENDING-dedup below is
 * the load-bearing duplicate guard, and the per-IP/email INCR caps are
 * skipped on Redis error (degrade rather than block legit users).
 *
 * Idempotent dedup
 * ----------------
 * If the email already has a PENDING application, we DON'T create a
 * second row — we return 200 with the existing application's id/status
 * and a friendly already_submitted=true flag.
 *
 * Success: 201 { "application": { "id": 123, "status": "pending" } }
 *
 * Optional ops notification (env VENDOR_APPLICATIONS_NOTIFY_EMAIL) is
 * sent non-blocking — a mailer failure never fails the submit.
 */
final class SubmitVendorApplicationController
{
    use Responder;
    use RequestContext;

    // Cooldown: at most one submit per axis per this many seconds.
    private const COOLDOWN_SECONDS = 60;
    // Hourly cap per axis.
    private const HOUR_SECONDS = 3600;
    private const PER_IP_HOURLY_CAP = 10;
    private const PER_EMAIL_HOURLY_CAP = 3;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly EntityManagerInterface $em,
        private readonly KeyValueStore $cache,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $input = $this->validator->parse($request, SubmitVendorApplicationInput::class);

        $ip = $this->extractIp($request);

        // Anti-spam throttle (per-IP + per-email). Throws 429 if tripped.
        $this->enforceRateLimits($ip, $input->email);

        /** @var VendorApplicationRepository $repo */
        $repo = $this->em->getRepository(VendorApplication::class);

        // Idempotent dedup: a still-pending application for this email is
        // not duplicated. Return the existing record so the form shows a
        // friendly "we already have your application" state.
        $existing = $repo->findPendingByEmail($input->email);
        if ($existing !== null) {
            return $this->ok([
                'application' => [
                    'id' => $existing->getId(),
                    'status' => $existing->getStatus(),
                ],
                'already_submitted' => true,
            ]);
        }

        $application = new VendorApplication(
            firstName: $input->first_name,
            lastName: $input->last_name,
            email: $input->email,
            phone: $input->phone,
            businessName: $input->business_name,
            countryCode: $input->country_code,
        );
        $application->setLicenseNumber($input->license_number);
        $application->setCategory($input->category);
        $application->setMessage($input->message);

        $repo->save($application);

        $this->notifyOps($application);

        return $this->created([
            'application' => [
                'id' => $application->getId(),
                'status' => $application->getStatus(),
            ],
        ]);
    }

    /**
     * Per-IP + per-email cooldown and hourly caps. Each axis is
     * independent. A Redis outage degrades to "allow" rather than block
     * (the per-email PENDING dedup is the durable duplicate guard).
     *
     * @throws HttpException 429 when a limit trips.
     */
    private function enforceRateLimits(?string $ip, string $email): void
    {
        // Per-email (always available — email is in the body).
        $this->enforceCooldown('vendorapp:cd:email:' . $email);
        $this->enforceHourlyCap('vendorapp:rl:email:' . $email, self::PER_EMAIL_HOURLY_CAP);

        // Per-IP (skipped entirely when the IP can't be resolved — never
        // block a legit user because we couldn't read their IP).
        if ($ip !== null && $ip !== '') {
            $this->enforceCooldown('vendorapp:cd:ip:' . $ip);
            $this->enforceHourlyCap('vendorapp:rl:ip:' . $ip, self::PER_IP_HOURLY_CAP);
        }
    }

    /**
     * Short cooldown via Redis SET NX EX. The FIRST caller in the window
     * claims the key (proceeds); a subsequent caller inside the window
     * fails the claim → 429. Redis error → allow (degrade).
     */
    private function enforceCooldown(string $key): void
    {
        try {
            $claimed = $this->cache->setIfAbsent($key, (string) time(), self::COOLDOWN_SECONDS);
            if ($claimed === false) {
                throw HttpException::rateLimited(
                    message: 'Please wait a moment before submitting another application.',
                );
            }
        } catch (KeyValueStoreException $e) {
            $this->logger->warning('vendor-application cooldown cache failure — allowing', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Hourly cap via atomic INCR + first-hit TTL. Over the cap → 429.
     * Redis error → allow (degrade).
     */
    private function enforceHourlyCap(string $key, int $cap): void
    {
        try {
            $count = $this->cache->incr($key);
            if ($count === 1) {
                $this->cache->expire($key, self::HOUR_SECONDS);
            }
            if ($count > $cap) {
                throw HttpException::rateLimited(
                    message: 'Too many applications submitted. Please try again later.',
                );
            }
        } catch (KeyValueStoreException $e) {
            $this->logger->warning('vendor-application hourly-cap cache failure — allowing', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Optionally email a configured ops address that a new application
     * arrived. Env-gated (VENDOR_APPLICATIONS_NOTIFY_EMAIL) + entirely
     * non-blocking — a mailer failure never fails the submit.
     */
    private function notifyOps(VendorApplication $application): void
    {
        $to = getenv('VENDOR_APPLICATIONS_NOTIFY_EMAIL');
        if (!is_string($to) || trim($to) === '') {
            return;
        }
        $to = trim($to);

        $name = $application->getBusinessName();
        $subject = 'New seller application: ' . $name;
        $text = sprintf(
            "A new seller application was submitted.\n\n"
            . "Business: %s\nContact: %s %s\nEmail: %s\nPhone: %s\nCategory: %s\n\n"
            . "Review it in the admin portal under Seller Applications.",
            $name,
            $application->getFirstName(),
            $application->getLastName(),
            $application->getEmail(),
            $application->getPhone(),
            $application->getCategory() ?? '-',
        );
        $html = nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));

        try {
            $this->mailer->send($to, $subject, $text, $html, [
                'template' => 'vendor_application.new',
                'application_id' => $application->getId(),
            ]);
        } catch (MailerException $e) {
            $this->logger->warning('vendor-application ops notification failed (non-blocking)', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}

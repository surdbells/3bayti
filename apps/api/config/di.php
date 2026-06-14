<?php

declare(strict_types=1);

use Bayti\Api\Domain\User\OtpService;
use Bayti\Api\Http\Controllers\HealthController;
use Bayti\Api\Http\Errors\ApiErrorMiddleware;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Middleware\OptionalAuthMiddleware;
use Bayti\Api\Http\Validator\RequestValidator;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Infrastructure\Auth\JwtSettings;
use Bayti\Api\Infrastructure\Cache\InMemoryKeyValueStore;
use Bayti\Api\Infrastructure\Cache\KeyValueStore;
use Bayti\Api\Infrastructure\Cache\RedisKeyValueStore;
use Bayti\Api\Infrastructure\Otp\InMemoryOtpProvider;
use Bayti\Api\Infrastructure\Otp\MessageCentralOtpProvider;
use Bayti\Api\Infrastructure\Otp\OtpProvider;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Client as GuzzleClient;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * PHP-DI container definitions.
 *
 * Most controllers/services are autowired (PHP-DI inspects constructors
 * and resolves type-hinted dependencies automatically). We only add
 * explicit factories here when a class needs special construction —
 * e.g. database connections, third-party SDK clients.
 *
 * Keep this file as the single source of truth for "how does X get
 * built?" so we don't end up with hidden new statements scattered
 * around the codebase.
 */

/**
 * Doctrine config and root path are exposed as DI entries so that
 * factory closures can read them via the container instead of
 * capturing them with `use (...)`.
 *
 * Why this matters
 * ----------------
 * PHP-DI's compiled-container mode (enabled when APP_ENV=prod) generates
 * a static PHP class containing serialised factories. Closures with
 * `use ($foo)` cannot be serialised — they hold runtime variable state
 * that compiler can't represent in source code. Reading from the
 * container instead works identically in dev and prod and works under
 * compilation. See PHP-DI docs:
 * https://php-di.org/doc/php-definitions.html#closures
 */
/**
 * Resolve Firebase service-account credentials for FCM from env, in
 * precedence order:
 *   1. FCM_SERVICE_ACCOUNT_JSON  — the full service-account JSON inline
 *   2. FCM_SERVICE_ACCOUNT_FILE  — a path to the service-account JSON
 *   3. FCM_PROJECT_ID / FCM_CLIENT_EMAIL / FCM_PRIVATE_KEY — the three
 *      fields individually
 *
 * Returns [projectId, clientEmail, privateKey]; any unresolved field is
 * an empty string (the caller decides whether that's fatal). Malformed
 * JSON yields empty strings rather than throwing, so a bad value in dev
 * degrades to NullPushSender instead of crashing container resolution.
 *
 * Top-level function (not a closure) so it's callable from inside the
 * DI factory under PHP-DI's compiled-container mode. Guarded with
 * function_exists so the binding test can require this file under a
 * different path without a redeclaration fatal.
 *
 * @return array{0: string, 1: string, 2: string}
 */
if (!function_exists('loadFcmServiceAccount')) {
    function loadFcmServiceAccount(): array
    {
        $project = '';
        $email = '';
        $key = '';

        $json = $_ENV['FCM_SERVICE_ACCOUNT_JSON'] ?? '';
        $file = $_ENV['FCM_SERVICE_ACCOUNT_FILE'] ?? '';

        if ($json === '' && $file !== '' && is_file($file) && is_readable($file)) {
            $json = (string) file_get_contents($file);
        }

        if ($json !== '') {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                $project = is_string($decoded['project_id'] ?? null) ? $decoded['project_id'] : '';
                $email = is_string($decoded['client_email'] ?? null) ? $decoded['client_email'] : '';
                $key = is_string($decoded['private_key'] ?? null) ? $decoded['private_key'] : '';
            }
        }

        // Individual fields override / fill gaps (handy for local dev).
        $project = (string) ($_ENV['FCM_PROJECT_ID'] ?? '') ?: $project;
        $email = (string) ($_ENV['FCM_CLIENT_EMAIL'] ?? '') ?: $email;
        $key = (string) ($_ENV['FCM_PRIVATE_KEY'] ?? '') ?: $key;

        // Env-stored private keys often have literal "\n" — normalise to
        // real newlines so the PEM parses.
        if ($key !== '') {
            $key = str_replace('\n', "\n", $key);
        }

        return [$project, $email, $key];
    }
}

return [
    'app.rootPath' => static fn(): string => dirname(__DIR__),

    /**
     * Loaded once per container build (PHP-DI caches by entry id).
     * Subsequent get('doctrineConfig') returns the same array.
     */
    'doctrineConfig' => static fn(): array => require dirname(__DIR__) . '/config/doctrine.php',

    // -------------------------------------------------------------------
    // Doctrine ORM
    // -------------------------------------------------------------------

    /**
     * Build the Doctrine ORM Configuration once per container,
     * shared by both the EntityManager and any scripts that need
     * raw config access (CLI tools).
     */
    Configuration::class => static function (ContainerInterface $c): Configuration {
        $doctrineConfig = $c->get('doctrineConfig');
        $env = $_ENV['APP_ENV'] ?? 'dev';
        return ($doctrineConfig['config_factory'])($env, $c->get('app.rootPath'));
    },

    /**
     * Build the DBAL Connection from env-driven params.
     */
    Connection::class => static function (ContainerInterface $c): Connection {
        $doctrineConfig = $c->get('doctrineConfig');
        return ($doctrineConfig['connection_factory'])($doctrineConfig['connection']);
    },

    /**
     * The Doctrine EntityManager — main entry point for DB work.
     * Slim controllers and services type-hint EntityManagerInterface
     * and PHP-DI provides this concrete instance.
     */
    EntityManagerInterface::class => static function (ContainerInterface $c): EntityManagerInterface {
        return new EntityManager(
            $c->get(Connection::class),
            $c->get(Configuration::class),
        );
    },

    // -------------------------------------------------------------------
    // PSR-17 ResponseFactory
    // -------------------------------------------------------------------

    /**
     * Middlewares that build their own responses (AuthMiddleware's
     * 401, error handlers) need a ResponseFactory. We bind the
     * Slim PSR-7 factory to the PSR-17 interface here so PHP-DI
     * can inject it by interface anywhere.
     */
    ResponseFactoryInterface::class => static fn (): ResponseFactoryInterface => new ResponseFactory(),

    // -------------------------------------------------------------------
    // Auth — JWT settings + service + middlewares
    // -------------------------------------------------------------------

    /**
     * JwtSettings is built from env vars at container time. The
     * factory throws if JWT_SECRET is missing or too short, which
     * is exactly what we want — the app should refuse to start
     * with a weak signing key.
     */
    JwtSettings::class => static function (): JwtSettings {
        $secret = $_ENV['JWT_SECRET'] ?? '';
        if ($secret === '' || strlen($secret) < 32) {
            // Specific dev guidance — a fresh checkout with no .env
            // would hit this. Helpful error rather than 'invalid
            // argument: shorter than 32'.
            throw new \RuntimeException(
                'JWT_SECRET env var is required and must be at least 32 bytes. ' .
                'Generate one with: php -r "echo bin2hex(random_bytes(64));"'
            );
        }

        return new JwtSettings(
            signingSecret: $secret,
            accessTtlSeconds: (int) ($_ENV['JWT_ACCESS_TOKEN_TTL'] ?? 900),
            refreshTtlSeconds: (int) ($_ENV['JWT_REFRESH_TOKEN_TTL'] ?? 604800),
            issuer: $_ENV['JWT_ISSUER'] ?? '3bayti-api',
        );
    },

    JwtService::class => \DI\autowire(),
    AuthMiddleware::class => \DI\autowire(),
    OptionalAuthMiddleware::class => \DI\autowire(),

    // M2.1 — AdminAuthMiddleware. Takes ResponseFactory + Logger;
    // both autowire-resolvable.
    \Bayti\Api\Http\Middleware\AdminAuthMiddleware::class => \DI\autowire(),
    \Bayti\Api\Http\Middleware\VendorAuthMiddleware::class => \DI\autowire(),

    // M3.1.7-C — Vendor order surface controllers
    \Bayti\Api\Http\Controllers\Vendor\Order\ListVendorOrdersController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Vendor\Order\GetVendorOrderController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Vendor\Order\TransitionVendorOrderItemController::class => \DI\autowire(),

    // M3.2.X.6-D — Vendor self-serve onboarding controllers
    \Bayti\Api\Http\Controllers\Vendor\Onboarding\SubmitOnboardingController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Vendor\Onboarding\GetOnboardingStatusController::class => \DI\autowire(),

    // M3.1.7-F — Cancel order service + admin/customer controllers
    \Bayti\Api\Domain\Order\CancelOrderService::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Admin\Order\CancelOrderController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Order\CancelOrderController::class => \DI\autowire(),

    // M3.2.X.18 — Returns request flow
    //   X.18-B: Flysystem-backed photo storage service
    //   X.18-C: Eligibility + refund calculator services
    //   X.18-D: Customer endpoints (5)
    //   X.18-E: Vendor endpoints (ships in -E)
    //   X.18-F: Admin endpoints (ships in -F)
    \Bayti\Api\Domain\Order\ReturnPhotoStorageService::class => \DI\autowire(),
    // ReturnRequestEligibilityService is bound via factory (not
    // autowire) because its OrderReturnRequestRepository dependency
    // can't be autowired — Doctrine repositories take an
    // EntityManager + ClassMetadata in their constructor, and
    // ClassMetadata has a required $name parameter PHP-DI can't
    // guess. Pulling the repo through $em->getRepository() at
    // factory time is the established pattern (matches OrderSerializer
    // and other repo-using services in this file).
    \Bayti\Api\Domain\Order\ReturnRequestEligibilityService::class => static function (
        \Psr\Container\ContainerInterface $c
    ): \Bayti\Api\Domain\Order\ReturnRequestEligibilityService {
        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);
        /** @var \Bayti\Api\Domain\Order\OrderReturnRequestRepository $repo */
        $repo = $em->getRepository(\Bayti\Api\Domain\Order\OrderReturnRequest::class);
        return new \Bayti\Api\Domain\Order\ReturnRequestEligibilityService(
            returnRepo: $repo,
        );
    },
    \Bayti\Api\Domain\Order\ReturnRefundCalculator::class => \DI\autowire(),
    \Bayti\Api\Http\Serializers\ReturnRequestSerializer::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Order\SubmitReturnController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Order\ListCustomerReturnsController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Order\GetReturnController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Order\CancelReturnController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Order\ServeReturnPhotoController::class => \DI\autowire(),
    // X.18-E — Vendor endpoints
    \Bayti\Api\Http\Controllers\Vendor\Order\ListVendorReturnsController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Vendor\Order\GetVendorReturnController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Vendor\Order\ConfirmReceiptController::class => \DI\autowire(),
    // X.18-F — Admin endpoints
    \Bayti\Api\Http\Controllers\Admin\Order\ListAdminReturnsController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Admin\Order\GetAdminReturnController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Admin\Order\ApproveReturnController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Admin\Order\DenyReturnController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Admin\Order\MarkPickedUpController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Admin\Order\RecordReturnRefundController::class => \DI\autowire(),

    // M3.1.7-G — Dispute persistence + admin endpoints
    \Bayti\Api\Http\Serializers\DisputeSerializer::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Admin\Dispute\ListDisputesController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Admin\Dispute\GetDisputeController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Admin\Dispute\ResolveDisputeController::class => \DI\autowire(),

    // M3.1.7-H — Email notifications (mailer + template renderer + orchestrator)
    //
    // MailerInterface binding selects the implementation based on
    // environment:
    //   - APP_ENV=prod AND ZEPTOMAIL_API_TOKEN set → ZeptoMailHttpMailer
    //   - otherwise                                → NullMailer (logs only)
    //
    // Production deployment MUST set ZEPTOMAIL_API_TOKEN (and the
    // related FROM_* vars) or the factory throws on container
    // resolution. This prevents accidentally silently dropping mail
    // in production.
    \Bayti\Api\Notification\MailerInterface::class => static function (
        ContainerInterface $c,
    ): \Bayti\Api\Notification\MailerInterface {
        $env = $_ENV['APP_ENV'] ?? 'dev';
        $token = $_ENV['ZEPTOMAIL_API_TOKEN'] ?? '';
        $fromEmail = $_ENV['ZEPTOMAIL_FROM_EMAIL'] ?? 'noreply@3bayti.ae';
        $fromName = $_ENV['ZEPTOMAIL_FROM_NAME'] ?? '3bayti';
        $override = $_ENV['MAIL_PROVIDER'] ?? null;

        $useZeptoMail = ($env === 'prod' && $token !== '')
            || $override === 'zeptomail';

        if (!$useZeptoMail) {
            return new \Bayti\Api\Notification\NullMailer(
                $c->get(\Psr\Log\LoggerInterface::class),
            );
        }

        if ($token === '') {
            throw new \RuntimeException(
                'ZeptoMailHttpMailer requires ZEPTOMAIL_API_TOKEN. ' .
                'Either set it, or unset MAIL_PROVIDER and APP_ENV to use NullMailer.',
            );
        }

        return new \Bayti\Api\Notification\ZeptoMailHttpMailer(
            apiToken: $token,
            fromEmail: $fromEmail,
            fromName: $fromName,
            httpClient: null, // adapter constructs its own with timeout
            logger: $c->get(\Psr\Log\LoggerInterface::class),
        );
    },

    \Bayti\Api\Notification\OrderEmailTemplateRenderer::class => \DI\autowire(),

    // M3.2.X.7-A — LocaleResolver autowired for OrderNotificationService.
    // Service object with no dependencies; safe to autowire.
    \Bayti\Api\Notification\LocaleResolver::class => \DI\autowire(),

    // M3.2.X.8-B — PromoCodeResolverService. Holds an optional EM for
    // lazy repository resolution per locked pattern #1; direct-
    // injection paths for tests are also accepted via the constructor.
    // Autowiring works because the constructor parameters all have
    // null defaults — DI passes null for the repo overrides, EM is
    // resolved through the existing EntityManagerInterface binding.
    \Bayti\Api\Domain\Promo\PromoCodeResolverService::class => static function (
        ContainerInterface $c,
    ): \Bayti\Api\Domain\Promo\PromoCodeResolverService {
        return new \Bayti\Api\Domain\Promo\PromoCodeResolverService(
            em: $c->get(\Doctrine\ORM\EntityManagerInterface::class),
        );
    },

    // OrderNotificationService: parse admin recipient list from env.
    // Comma-separated emails; empty list disables admin notifications.
    \Bayti\Api\Notification\OrderNotificationService::class => static function (
        ContainerInterface $c,
    ): \Bayti\Api\Notification\OrderNotificationService {
        $raw = $_ENV['ADMIN_NOTIFICATION_EMAILS'] ?? '';
        $recipients = [];
        if ($raw !== '') {
            foreach (explode(',', $raw) as $email) {
                $email = trim($email);
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $recipients[] = $email;
                }
            }
        }
        return new \Bayti\Api\Notification\OrderNotificationService(
            mailer: $c->get(\Bayti\Api\Notification\MailerInterface::class),
            renderer: $c->get(\Bayti\Api\Notification\OrderEmailTemplateRenderer::class),
            adminRecipients: $recipients,
            logger: $c->get(\Psr\Log\LoggerInterface::class),
            // M3.2.X.4-B: notification_logs persistence. EM passed
            // directly so the NotificationLogRepository is resolved
            // LAZILY per safePersist() call rather than eagerly at
            // service construction time. Avoids eager Doctrine
            // metadata loading that breaks test mocks; see
            // OrderNotificationService class docblock for full
            // rationale.
            em: $c->get(\Doctrine\ORM\EntityManagerInterface::class),
            // M3.2.X.7-B: locale routing. Resolver looks up the
            // recipient's preferred locale (customer / vendor / admin)
            // and tells the renderer which language to render in.
            // Falls back to English for unknown recipients per
            // Q-FallbackBehavior = A.
            localeResolver: $c->get(\Bayti\Api\Notification\LocaleResolver::class),
        );
    },

    // M3.2.Z.4-C — Push notifications (sender + orchestrator).
    //
    // PushSenderInterface binding selects the implementation based on
    // environment, mirroring the MailerInterface block:
    //   - APP_ENV=prod AND FCM service-account creds present → FcmHttpV1Sender
    //   - PUSH_PROVIDER=fcm (explicit override)               → FcmHttpV1Sender
    //   - otherwise                                           → NullPushSender
    //
    // FCM credentials come from a Firebase service-account JSON. Provide
    // EITHER the whole JSON in FCM_SERVICE_ACCOUNT_JSON, OR a path to it
    // in FCM_SERVICE_ACCOUNT_FILE, OR the three fields individually
    // (FCM_PROJECT_ID / FCM_CLIENT_EMAIL / FCM_PRIVATE_KEY). Q-Z4=A:
    // FCM relays to APNs for iOS, so this single adapter serves both.
    \Bayti\Api\Notification\Push\PushSenderInterface::class => static function (
        ContainerInterface $c,
    ): \Bayti\Api\Notification\Push\PushSenderInterface {
        $env = $_ENV['APP_ENV'] ?? 'dev';
        $override = $_ENV['PUSH_PROVIDER'] ?? null;
        $logger = $c->get(\Psr\Log\LoggerInterface::class);

        [$projectId, $clientEmail, $privateKey] = loadFcmServiceAccount();
        $haveCreds = $projectId !== '' && $clientEmail !== '' && $privateKey !== '';

        $useFcm = ($env === 'prod' && $haveCreds) || $override === 'fcm';

        if (!$useFcm) {
            return new \Bayti\Api\Notification\Push\NullPushSender($logger);
        }

        if (!$haveCreds) {
            throw new \RuntimeException(
                'FcmHttpV1Sender requires FCM service-account credentials. '
                . 'Set FCM_SERVICE_ACCOUNT_JSON, FCM_SERVICE_ACCOUNT_FILE, or '
                . 'FCM_PROJECT_ID + FCM_CLIENT_EMAIL + FCM_PRIVATE_KEY. '
                . 'Either set them, or unset PUSH_PROVIDER/APP_ENV to use NullPushSender.',
            );
        }

        return new \Bayti\Api\Notification\Push\FcmHttpV1Sender(
            projectId: $projectId,
            clientEmail: $clientEmail,
            privateKey: $privateKey,
            httpClient: null, // adapter constructs its own with timeout
            logger: $logger,
        );
    },

    \Bayti\Api\Notification\Push\PushNotificationService::class => static function (
        ContainerInterface $c,
    ): \Bayti\Api\Notification\Push\PushNotificationService {
        return new \Bayti\Api\Notification\Push\PushNotificationService(
            sender: $c->get(\Bayti\Api\Notification\Push\PushSenderInterface::class),
            logger: $c->get(\Psr\Log\LoggerInterface::class),
            // EM passed directly so DeviceTokenRepository is resolved
            // LAZILY per fan-out rather than eagerly at construction
            // (same locked pattern as OrderNotificationService).
            em: $c->get(\Doctrine\ORM\EntityManagerInterface::class),
        );
    },

    // M3.2.X.11 — Cart abandonment recovery
    \Bayti\Api\Domain\Cart\CartAbandonmentFinder::class => \DI\autowire(),
    \Bayti\Api\Notification\CartEmailTemplateRenderer::class => \DI\autowire(),
    \Bayti\Api\Notification\UnsubscribeTokenIssuer::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Notification\UnsubscribeController::class => \DI\autowire(),
    \Bayti\Api\Notification\CartNotificationService::class => static function (
        \Psr\Container\ContainerInterface $c,
    ): \Bayti\Api\Notification\CartNotificationService {
        $appUrl = rtrim((string) ($_ENV['APP_URL'] ?? 'http://localhost:8080'), '/');
        return new \Bayti\Api\Notification\CartNotificationService(
            mailer: $c->get(\Bayti\Api\Notification\MailerInterface::class),
            renderer: $c->get(\Bayti\Api\Notification\CartEmailTemplateRenderer::class),
            tokenIssuer: $c->get(\Bayti\Api\Notification\UnsubscribeTokenIssuer::class),
            logger: $c->get(\Psr\Log\LoggerInterface::class),
            appBaseUrl: $appUrl,
            em: $c->get(\Doctrine\ORM\EntityManagerInterface::class),
        );
    },

    // M3.1.7-D — Admin order surface controllers
    \Bayti\Api\Http\Controllers\Admin\Order\ListAdminOrdersController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Admin\Order\GetAdminOrderController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Admin\Order\OverrideOrderStatusController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Admin\Order\OverrideOrderItemStatusController::class => \DI\autowire(),

    // M3.2.X.17 — Order timeline
    \Bayti\Api\Domain\Order\OrderTimelineBuilder::class => \DI\autowire(),
    \Bayti\Api\Http\Serializers\OrderTimelineSerializer::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Admin\Order\GetAdminOrderTimelineController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Vendor\Order\GetVendorOrderTimelineController::class => \DI\autowire(),

    // M3.2.X.4-C — Notification log admin surface
    \Bayti\Api\Http\Serializers\NotificationLogSerializer::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Admin\NotificationLog\ListNotificationLogsController::class => \DI\autowire(),

    // M3.2.X.8-E — PromoCode admin CRUD surface
    \Bayti\Api\Http\Serializers\PromoCodeSerializer::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Admin\PromoCode\ListPromoCodesController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Admin\PromoCode\GetPromoCodeController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Admin\PromoCode\CreatePromoCodeController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Admin\PromoCode\UpdatePromoCodeController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Admin\PromoCode\DeletePromoCodeController::class => \DI\autowire(),

    // M3.1.7-E — Refund flow (full + partial)
    \Bayti\Api\Http\Controllers\Admin\Order\RefundOrderController::class => \DI\autowire(),

    // M2.1 — RequestIdMiddleware (added earlier in M1.6.2.B but
    // listed here for discoverability).
    \Bayti\Api\Http\Middleware\RequestIdMiddleware::class => \DI\autowire(),
    \Bayti\Api\Http\Middleware\CurrencyContextMiddleware::class => \DI\autowire(),

    // M3.2.X.15 — Multi-currency display
    \Bayti\Api\Domain\Currency\CurrencyConversionService::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Admin\Currency\ListFxRatesController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Admin\Currency\UpsertFxRateController::class => \DI\autowire(),

    // M2.1 — Catalog serializers (autowire-friendly, no constructor deps)
    \Bayti\Api\Http\Serializers\BrandSerializer::class => \DI\autowire(),
    \Bayti\Api\Http\Serializers\VendorSerializer::class => \DI\autowire(),
    \Bayti\Api\Http\Serializers\CategorySerializer::class => \DI\autowire(),

    // M2.1 — Catalog admin controllers (Brand)
    \Bayti\Api\Http\Controllers\Admin\Brand\ListBrandsAdminController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Admin\Brand\CreateBrandController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Admin\Brand\UpdateBrandController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Admin\Brand\DeleteBrandController::class => \DI\autowire(),

    // M2.1 — Catalog admin controllers (Vendor)
    \Bayti\Api\Http\Controllers\Admin\Vendor\ListVendorsAdminController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Admin\Vendor\CreateVendorController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Admin\Vendor\UpdateVendorController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Admin\Vendor\DeleteVendorController::class => \DI\autowire(),

    // M3.2.X.6-C — Vendor lifecycle state transition controllers
    \Bayti\Api\Http\Controllers\Admin\Vendor\ApproveVendorController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Admin\Vendor\SuspendVendorController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Admin\Vendor\ReactivateVendorController::class => \DI\autowire(),

    // M3.2.X.14 — Vendor performance metrics
    \Bayti\Api\Domain\Catalog\VendorMetricsCalculator::class => \DI\autowire(),

    // M3.2.X.13 — Vendor analytics dashboard
    \Bayti\Api\Domain\Catalog\VendorAnalyticsCalculator::class => \DI\autowire(),
    \Bayti\Api\Domain\Catalog\VendorDashboardCalculator::class => \DI\autowire(),
    \Bayti\Api\Http\Serializers\VendorAnalyticsSerializer::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Admin\Vendor\GetAdminVendorAnalyticsController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Vendor\GetVendorSelfAnalyticsController::class => \DI\autowire(),

    // M3.2.X.12 — Recommendations engine
    \Bayti\Api\Domain\Catalog\CoPurchaseAffinityCalculator::class => \DI\autowire(),
    \Bayti\Api\Domain\Catalog\CategoryAffinityCalculator::class => \DI\autowire(),
    \Bayti\Api\Domain\Catalog\RecommendationsService::class => \DI\autowire(),
    \Bayti\Api\Console\BuildRecommendationsCommand::class => \DI\autowire(),
    \Bayti\Api\Http\Serializers\RecommendationsSerializer::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Catalog\GetProductRecommendationsController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Me\GetMeRecommendationsController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Admin\Catalog\GetAdminRecommendationsExplainController::class => \DI\autowire(),
    \Bayti\Api\Http\Serializers\VendorMetricsSerializer::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Admin\Vendor\GetAdminVendorMetricsController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Admin\Vendor\ListAdminVendorMetricsController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Vendor\GetVendorSelfMetricsController::class => \DI\autowire(),

    // M2.1 — Catalog admin controllers (Category)
    \Bayti\Api\Http\Controllers\Admin\Category\ListCategoriesAdminController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Admin\Category\CreateCategoryController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Admin\Category\UpdateCategoryController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Admin\Category\DeleteCategoryController::class => \DI\autowire(),

    // M2.1 — Catalog public read controllers
    \Bayti\Api\Http\Controllers\Catalog\ListBrandsController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Catalog\GetBrandController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Catalog\ListVendorsController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Catalog\ListFeaturedVendorsController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Catalog\GetVendorController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Catalog\ListCategoriesController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Catalog\GetCategoryController::class => \DI\autowire(),

    // M2.2 — Product endpoints (Day 2 of 10-day rollout)
    \Bayti\Api\Http\Serializers\ProductSerializer::class => \DI\autowire(),
    \Bayti\Api\Http\Serializers\CartSerializer::class => \DI\autowire(),
    // M3.2.X.8-C — Cart-quote price breakdown shape (subtotal + delivery
    // + discount + total + optional applied_promo block).
    \Bayti\Api\Http\Serializers\CartQuoteSerializer::class => \DI\autowire(),
    \Bayti\Api\Http\Serializers\OrderSerializer::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Catalog\ListProductsController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Catalog\GetProductController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Catalog\ListVendorProductsController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Catalog\GetSitemapDataController::class => \DI\autowire(),

    // M3.2.X.10 — Faceted search backend
    \Bayti\Api\Domain\Catalog\FacetAggregator::class => \DI\autowire(),
    \Bayti\Api\Domain\Catalog\ProductFilterParser::class => \DI\autowire(),
    \Bayti\Api\Http\Serializers\FacetsSerializer::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Catalog\ListFacetsController::class => \DI\autowire(),

    // -------------------------------------------------------------------
    // OTP — provider selection + service
    // -------------------------------------------------------------------

    /**
     * OTP provider selection.
     *
     * APP_ENV=prod  → MessageCentralOtpProvider (real CPaaS)
     * APP_ENV=*     → InMemoryOtpProvider (no network; tests + dev)
     *
     * Override via SMS_PROVIDER=messagecentral if a developer wants
     * to test against the real CPaaS from their machine. NOT a way
     * to use in-memory in prod — production refuses if creds are
     * missing.
     */
    OtpProvider::class => static function (): OtpProvider {
        $env = $_ENV['APP_ENV'] ?? 'dev';
        $override = $_ENV['SMS_PROVIDER'] ?? null;

        $useMessageCentral = $env === 'prod' || $override === 'messagecentral';

        if (!$useMessageCentral) {
            return new InMemoryOtpProvider();
        }

        $customerId = $_ENV['MESSAGECENTRAL_CUSTOMER_ID'] ?? '';
        $apiKey = $_ENV['MESSAGECENTRAL_KEY'] ?? '';
        $email = $_ENV['MESSAGECENTRAL_EMAIL'] ?? '';

        if ($customerId === '' || $apiKey === '' || $email === '') {
            throw new \RuntimeException(
                'MessageCentralOtpProvider requires env vars: MESSAGECENTRAL_CUSTOMER_ID, ' .
                'MESSAGECENTRAL_KEY, MESSAGECENTRAL_EMAIL. ' .
                'Set them, or unset SMS_PROVIDER to use the in-memory adapter for local testing.'
            );
        }

        $http = new GuzzleClient([
            'base_uri' => $_ENV['MESSAGECENTRAL_BASE_URL'] ?? 'https://cpaas.messagecentral.com',
            'timeout' => 10,
            'connect_timeout' => 5,
        ]);

        return new MessageCentralOtpProvider(
            http: $http,
            customerId: $customerId,
            apiKey: $apiKey,
            email: $email,
            country: $_ENV['MESSAGECENTRAL_COUNTRY'] ?? '971',
        );
    },

    OtpService::class => \DI\autowire(),

    // -------------------------------------------------------------------
    // M1.6.1.C — audit log
    // -------------------------------------------------------------------

    /**
     * AuditEmitter is autowired — pulls EntityManagerInterface +
     * Psr\Log\LoggerInterface from DI. Controllers inject it
     * explicitly to record mutating actions.
     */
    \Bayti\Api\Domain\Audit\AuditEmitter::class => \DI\autowire(),

    // -------------------------------------------------------------------
    // Logger — Monolog instance bound to PSR-3 LoggerInterface
    // -------------------------------------------------------------------

    /**
     * Application logger (M1.6.2.B). Per-day rotating JSON file under
     * apps/api/var/logs/. PSR-3 interface so any service taking
     * Psr\Log\LoggerInterface auto-wires this.
     *
     * Already-extant consumers that benefit:
     *   - OtpService (logs Redis fail-open warnings)
     *   - ApiErrorMiddleware (logs unhandled exceptions)
     *
     * Production level: WARNING (less noise). Dev: DEBUG (for visibility).
     * Override via LOG_LEVEL env if needed.
     */
    \Psr\Log\LoggerInterface::class => static function (ContainerInterface $c): \Psr\Log\LoggerInterface {
        $env = $_ENV['APP_ENV'] ?? 'dev';
        $levelOverride = $_ENV['LOG_LEVEL'] ?? null;

        // Compute the log directory relative to this DI config file.
        // di.php lives at apps/api/config/, var/ is sibling.
        $logDir = dirname(__DIR__) . '/var/logs';

        return \Bayti\Api\Infrastructure\Logging\LoggerFactory::create(
            logDir: $logDir,
            env: $env,
            levelOverride: $levelOverride,
        );
    },

    // -------------------------------------------------------------------
    // Object storage — Flysystem (M3.2.X.18-B)
    // -------------------------------------------------------------------

    /**
     * FilesystemOperator — file/blob storage abstraction.
     *
     * v1 of this binding (M3.2.X.18-B) uses LocalFilesystemAdapter
     * rooted at apps/api/var/uploads/. The same FilesystemOperator
     * surface lets us swap to Cloudflare R2 (S3-compatible; configured
     * in .env.example as R2_BUCKET) by replacing this factory body —
     * no consumer-side changes.
     *
     * Why Flysystem and not raw PHP filesystem calls:
     *   - Consumer code (e.g., ReturnPhotoStorageService) shouldn't
     *     care whether the bytes live on local disk or in S3/R2.
     *     Flysystem's FilesystemOperator interface lets us defer the
     *     production-grade storage choice without forcing v1 consumers
     *     to re-write later.
     *   - Stream-based reads (readStream) avoid loading the whole blob
     *     into memory when serving photos through the auth-gated
     *     endpoint.
     *   - Adapter swap is a single-place DI change.
     *
     * Root path
     * =========
     * Local adapter rooted at apps/api/var/uploads/. The directory is
     * created on demand by the adapter (skipIfExists default). It is
     * NOT committed to git (var/ is .gitignored). Operator playbook
     * §2.N (X.18-I) will document:
     *   - cron sweep for orphan blobs (deletes with no matching DB row)
     *   - backup strategy (the local volume must be in the daily DB
     *     backup window since photo evidence is operationally
     *     important for dispute defense)
     *
     * Tests
     * =====
     * Tests rebind this with a per-test temp-directory adapter via
     * HttpTestCase::bind. The default factory is for production
     * + dev only.
     */
    \League\Flysystem\FilesystemOperator::class => static function (ContainerInterface $c): \League\Flysystem\FilesystemOperator {
        $uploadsRoot = dirname(__DIR__) . '/var/uploads';
        // Public visibility: 0644 files, 0755 dirs, and crucially the
        // default for NEW directories is PUBLIC (0755) — Flysystem's own
        // default is PRIVATE (0700), which makes created subdirectories
        // un-traversable by the web-server user and yields 403s on served
        // uploads. Files inherit 0644 so the web server can read them.
        $visibility = new \League\Flysystem\UnixVisibility\PortableVisibilityConverter(
            0644, // file, public
            0600, // file, private
            0755, // dir, public
            0700, // dir, private
            \League\Flysystem\Visibility::PUBLIC, // default for directories
        );
        $adapter = new \League\Flysystem\Local\LocalFilesystemAdapter($uploadsRoot, $visibility);
        return new \League\Flysystem\Filesystem($adapter);
    },

    // Image upload service — product images + vendor logo/cover (Phase 1)
    // Wraps FilesystemOperator with path-scheme helpers and mime/size
    // validation. Swap to R2: change the FilesystemOperator binding above
    // (AwsS3V3Adapter); this binding needs no change.
    \Bayti\Api\Domain\Media\ImageStorageService::class => \DI\autowire(),
    \Bayti\Api\Domain\Compliance\ComplianceDocumentService::class => \DI\autowire(),

    // Gift card repositories (M3.5) — autowire suffices; all deps are
    // EntityManagerInterface which is already bound above.
    \Bayti\Api\Domain\GiftCard\GiftCardRepository::class => \DI\autowire(),

    // -------------------------------------------------------------------
    // Cache / shared state — Redis in production, in-memory in tests
    // -------------------------------------------------------------------

    /**
     * KeyValueStore — used for OTP rate-limit counters (M1.6.1.A) and
     * future per-IP rate limiting (M2+) and other cross-worker state.
     *
     * Production: REDIS_DSN in .env points to localhost Redis. Format:
     *   REDIS_DSN=redis://127.0.0.1:6379
     *   REDIS_DSN=redis://:password@127.0.0.1:6379
     *   REDIS_DSN=redis://:password@127.0.0.1:6379/0  (database 0)
     *
     * Tests: REDIS_DSN unset → InMemoryKeyValueStore. Each test gets
     * a fresh store via test bootstrapping or container rebinding.
     *
     * Local dev: either run Redis locally and set REDIS_DSN, or leave
     * it unset and accept InMemory limitations (no cross-process
     * sharing — fine for solo dev).
     */
    KeyValueStore::class => static function (ContainerInterface $c): KeyValueStore {
        $dsn = $_ENV['REDIS_DSN'] ?? '';

        if ($dsn === '') {
            // No Redis configured — fall back to in-memory.
            // Production should ALWAYS have REDIS_DSN set; if it
            // doesn't, the symptom (rate limits don't survive worker
            // restarts) will be obvious quickly. Adding a hard fail
            // here in production is M1.6 followup — for now, we lean
            // toward "still functional, just less robust" over "won't
            // boot at all if env is missing one var."
            return new InMemoryKeyValueStore();
        }

        // Parse the DSN. We use parse_url because it handles
        // user:password@host:port natively.
        $parts = parse_url($dsn);
        if ($parts === false || !isset($parts['host'])) {
            throw new \RuntimeException(
                "Invalid REDIS_DSN: {$dsn}. Expected format: redis://[:password@]host[:port][/database]",
            );
        }

        $host = $parts['host'];
        $port = (int) ($parts['port'] ?? 6379);
        // parse_url puts the password under 'pass' (not 'password').
        // No password is the no-key-set case (Redis with no AUTH).
        $password = $parts['pass'] ?? null;
        // Path is "/<dbnumber>". parse_url always keeps the leading "/".
        $database = isset($parts['path']) ? (int) ltrim($parts['path'], '/') : 0;

        return new RedisKeyValueStore(
            host: $host,
            port: $port,
            password: $password,
            database: $database,
        );
    },

    // -------------------------------------------------------------------
    // HTTP layer — request validator + error middleware
    // -------------------------------------------------------------------

    /**
     * symfony/validator instance — set up to read constraints from
     * PHP attributes on DTOs. PHP-DI then injects this into
     * RequestValidator wherever needed.
     */
    ValidatorInterface::class => static function (): ValidatorInterface {
        return Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    },

    RequestValidator::class => \DI\autowire(),

    /**
     * Outermost JSON error middleware. Catches HttpException + any
     * uncaught Throwable; renders the {error: {...}} envelope.
     * Debug mode is on for non-prod environments — gives developers
     * stack frames in 500 responses without leaking them in prod.
     */
    ApiErrorMiddleware::class => static function (ContainerInterface $c): ApiErrorMiddleware {
        $env = $_ENV['APP_ENV'] ?? 'dev';

        // Resolve logger defensively — if the logger binding fails for
        // any reason (filesystem issues writing to var/logs), still
        // construct the error middleware with a NullLogger so the
        // app can boot. We always want error handling available even
        // if logging itself is broken.
        $logger = new \Psr\Log\NullLogger();
        try {
            $logger = $c->get(\Psr\Log\LoggerInterface::class);
        } catch (\Throwable) {
            // Fall back to NullLogger; nothing is logged but the app
            // continues to render proper error responses.
        }

        return new ApiErrorMiddleware(
            responseFactory: $c->get(ResponseFactoryInterface::class),
            logger: $logger,
            debugMode: $env !== 'prod',
        );
    },

    // -------------------------------------------------------------------
    // Controllers — autowired, but listed for discoverability.
    // -------------------------------------------------------------------

    HealthController::class => static function (ContainerInterface $c): HealthController {
        // Explicit factory rather than autowire(). Two reasons:
        //
        // 1. PHP-DI's autowiring for ?Type = null parameters is
        //    ambiguous — sometimes it injects the type, sometimes
        //    the default. Explicit construction removes the doubt.
        //
        // 2. Connection construction itself could fail in test or
        //    misconfigured environments (no DB driver available,
        //    DSN parse error, etc.). We catch that and pass null —
        //    liveness endpoint still works without DB; readiness
        //    will report 'no connection injected' degraded state.
        $connection = null;
        try {
            $connection = $c->get(Connection::class);
        } catch (\Throwable) {
            // No DB available — liveness still works; readiness
            // will return degraded with empty checks.
        }

        // KeyValueStore (Redis or InMemory). Same defensive resolution —
        // if the cache binding fails (DSN parse error, etc.), we
        // proceed without a cache check rather than blow up health.
        $cache = null;
        try {
            $cache = $c->get(\Bayti\Api\Infrastructure\Cache\KeyValueStore::class);
        } catch (\Throwable) {
            // No cache binding — readiness simply omits the redis check.
        }

        return new HealthController(
            $c->get(\Psr\Http\Message\ResponseFactoryInterface::class),
            $connection,
            $cache,
        );
    },

    // M1.4.2 — auth controllers (read-only / no-OTP)
    \Bayti\Api\Http\Serializers\UserSerializer::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Auth\ValidateEmailController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Auth\ValidatePhoneController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Auth\LoginController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Auth\MeController::class => \DI\autowire(),

    // M1.4.3 — OTP issuance
    \Bayti\Api\Http\Controllers\Auth\RegisterController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Auth\SendOtpController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Auth\ConfirmController::class => \DI\autowire(),

    // M1.4.4 — password reset
    \Bayti\Api\Http\Controllers\Auth\ResetController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Auth\ResetConfirmController::class => \DI\autowire(),

    // M1.4.5 — token lifecycle
    \Bayti\Api\Http\Controllers\Auth\RefreshController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Auth\LogoutController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Auth\LogoutAllController::class => \DI\autowire(),

    // -------------------------------------------------------------------
    // M3.1.6c — Payment gateway (Q9=A: v3 talks to Noon directly)
    // -------------------------------------------------------------------
    //
    // PaymentGatewayInterface is the only contract callers depend on
    // (C11 pluggable gateway architecture). Currently bound to
    // NoonPaymentGateway; future providers swap in here without
    // touching controller code.
    //
    // NoonWebhookSignatureVerifier is config-flag gated (M3.1.7-A):
    //   NOON_VERIFY_SIGNATURE=true  → HmacSha256SignatureVerifier
    //   NOON_VERIFY_SIGNATURE=false → LoggingOnlyVerifier (default;
    //                                 logs pairs for empirical capture)
    \Bayti\Api\Payment\PaymentGatewayInterface::class => static function (
        ContainerInterface $c
    ): \Bayti\Api\Payment\PaymentGatewayInterface {
        $baseUrl = $_ENV['NOON_API_BASE'] ?? '';
        $businessId = $_ENV['NOON_BUSINESS_IDENTIFIER'] ?? '';
        $appId = $_ENV['NOON_APP_IDENTIFIER'] ?? '';
        // NOON_APP_KEY is the new env var; NOON_API_KEY is the
        // deprecated alias. Adapter checks APP_KEY first; falls
        // back with a deprecation log line.
        $appKey = $_ENV['NOON_APP_KEY'] ?? '';

        $logger = new \Psr\Log\NullLogger();
        try {
            /** @var \Psr\Log\LoggerInterface $logger */
            $logger = $c->get(\Psr\Log\LoggerInterface::class);
        } catch (\Throwable) {
            // Fall back to null logger; payment requests still work
            // but auth/timeout failures aren't recorded externally.
        }

        if ($appKey === '' && ($_ENV['NOON_API_KEY'] ?? '') !== '') {
            $appKey = $_ENV['NOON_API_KEY'];
            $logger->warning(
                'NOON_API_KEY is deprecated; rename to NOON_APP_KEY in your environment '
                . '(NoonPaymentGateway will stop reading the legacy name in M4).'
            );
        }

        if ($baseUrl === '' || $businessId === '' || $appId === '' || $appKey === '') {
            throw new \RuntimeException(
                'NoonPaymentGateway requires env vars: NOON_API_BASE, '
                . 'NOON_BUSINESS_IDENTIFIER, NOON_APP_IDENTIFIER, NOON_APP_KEY. '
                . 'See apps/api/.env.example for the format.'
            );
        }

        $http = new GuzzleClient([
            // base_uri unused — adapter passes absolute URL per call
            'timeout' => 15,
            'connect_timeout' => 5,
        ]);

        return new \Bayti\Api\Payment\Noon\NoonPaymentGateway(
            http: $http,
            baseUrl: $baseUrl,
            businessIdentifier: $businessId,
            appIdentifier: $appId,
            appKey: $appKey,
            logger: $logger,
        );
    },

    \Bayti\Api\Payment\Noon\NoonWebhookSignatureVerifier::class => static function (
        ContainerInterface $c
    ): \Bayti\Api\Payment\Noon\NoonWebhookSignatureVerifier {
        // M3.1.7-A: config-flag gated. NOON_VERIFY_SIGNATURE controls
        // which verifier is bound:
        //
        //   'true' / '1'  → HmacSha256SignatureVerifier (real crypto check)
        //   'false' / '0' → LoggingOnlyVerifier (M3.1.6 default; logs
        //                   pairs for empirical algorithm capture)
        //   unset / other → LoggingOnlyVerifier (safe default until
        //                   operator captures real sandbox webhook
        //                   pairs and confirms HMAC-SHA256 is correct)
        //
        // The retrieve-order-before-acting pattern in NoonWebhookController
        // remains the load-bearing safety mechanism regardless of which
        // verifier is bound — even with LoggingOnlyVerifier accepting
        // everything, a spoofed webhook cannot make us mark an order
        // paid because Noon's GET_ORDER is the source of truth.
        //
        // Roll-out plan:
        //   1. Operator deploys M3.1.7 with NOON_VERIFY_SIGNATURE=false
        //   2. LoggingOnlyVerifier logs body+sig SHA-256 pairs from
        //      real sandbox traffic over a few days
        //   3. Operator confirms HMAC-SHA256 by computing
        //      hash_hmac('sha256', $body, $secret) against captured
        //      pairs OR identifies a different algorithm + ships a
        //      new verifier class
        //   4. Operator flips NOON_VERIFY_SIGNATURE=true; signature
        //      checking is now enforced
        //
        // If the algorithm turns out non-HMAC-SHA256, swap the verifier
        // class in the `true` branch — interface contract is unchanged.

        $logger = new \Psr\Log\NullLogger();
        try {
            /** @var \Psr\Log\LoggerInterface $logger */
            $logger = $c->get(\Psr\Log\LoggerInterface::class);
        } catch (\Throwable) {
            // Continue with NullLogger
        }

        $verifyFlag = strtolower(trim((string) ($_ENV['NOON_VERIFY_SIGNATURE'] ?? '')));
        $verifyEnabled = $verifyFlag === 'true' || $verifyFlag === '1';

        if (!$verifyEnabled) {
            return new \Bayti\Api\Payment\Noon\LoggingOnlyVerifier($logger);
        }

        // Real signature verification path.
        $secret = (string) ($_ENV['NOON_WEBHOOK_SECRET'] ?? '');
        if ($secret === '') {
            // Don't silently degrade — operators who flip the flag
            // expect verification. Failing fast surfaces the missing
            // env var clearly rather than letting webhooks be accepted
            // unchecked.
            throw new \RuntimeException(
                'NOON_VERIFY_SIGNATURE=true requires NOON_WEBHOOK_SECRET to be set. '
                . 'Either provide the secret from Noon merchant portal or set '
                . 'NOON_VERIFY_SIGNATURE=false to use LoggingOnlyVerifier.'
            );
        }

        return new \Bayti\Api\Payment\Noon\HmacSha256SignatureVerifier($secret);
    },

    // Doctrine repositories are accessed via EntityManager::getRepository();
    // no DI registrations needed.
];




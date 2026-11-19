<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Container;

use Bayti\Api\Domain\Cashback\CashbackService;
use Bayti\Api\Domain\Cashback\CashbackSettingsStore;
use Bayti\Api\Notification\CashbackNotifier;
use Bayti\Api\Notification\GiftCardEmailTemplateRenderer;
use Bayti\Api\Notification\MailerInterface;
use Bayti\Api\Notification\Push\PushNotificationService;
use DI\ContainerBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * CashbackService and CashbackNotifier take an OPTIONAL logger (NullLogger
 * by default, for tests). PHP-DI autowiring skips optional parameters, so
 * without an explicit di.php entry every fail-safe catch (accrual, cancel,
 * clawback, release, notification) would log to nowhere in production.
 * Uses the real di.php definitions for the two classes.
 */
#[CoversNothing]
final class CashbackLoggerBindingTest extends TestCase
{
    #[Test]
    public function theCashbackServicesGetTheApplicationLogger(): void
    {
        /** @var array<string, mixed> $definitions */
        $definitions = require __DIR__ . '/../../config/di.php';
        self::assertArrayHasKey(CashbackService::class, $definitions);
        self::assertArrayHasKey(CashbackNotifier::class, $definitions);

        $logger = new class extends NullLogger {};

        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        $builder->addDefinitions([
            CashbackService::class          => $definitions[CashbackService::class],
            CashbackNotifier::class         => $definitions[CashbackNotifier::class],
            LoggerInterface::class          => $logger,
            EntityManagerInterface::class   => $this->createMock(EntityManagerInterface::class),
            CashbackSettingsStore::class    => $this->createMock(CashbackSettingsStore::class),
            PushNotificationService::class  => $this->createMock(PushNotificationService::class),
            MailerInterface::class          => $this->createMock(MailerInterface::class),
            GiftCardEmailTemplateRenderer::class => new GiftCardEmailTemplateRenderer(),
        ]);
        $container = $builder->build();

        $service = $container->get(CashbackService::class);
        $notifier = $container->get(CashbackNotifier::class);

        self::assertSame($logger, (new \ReflectionProperty(CashbackService::class, 'logger'))->getValue($service));
        self::assertSame($logger, (new \ReflectionProperty(CashbackNotifier::class, 'logger'))->getValue($notifier));
    }
}

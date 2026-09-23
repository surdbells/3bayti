<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Console;

use Bayti\Api\Console\SendFollowerProductAlertsCommand;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Following\FollowerProductAlertFinder;
use Bayti\Api\Domain\Following\VendorFollow;
use Bayti\Api\Domain\Following\VendorFollowRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Notification\Push\PushNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Store-follower new-product alert cron: fans out to a store's followers and
 * CLAIMS each product (marks followers_notified_at) so it never re-fires.
 */
final class SendFollowerProductAlertsCommandTest extends TestCase
{
    private EntityManagerInterface $em;
    private FollowerProductAlertFinder $finder;
    private PushNotificationService $push;
    private VendorFollowRepository $followRepo;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        // Finder + push are final-ish services; a plain mock is fine here.
        $this->finder = $this->createMock(FollowerProductAlertFinder::class);
        $this->push = $this->createMock(PushNotificationService::class);
        $this->followRepo = $this->createMock(VendorFollowRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->em->method('getRepository')->willReturnCallback(
            fn (string $class): object => match ($class) {
                VendorFollow::class => $this->followRepo,
                default => throw new \LogicException("Unexpected repo: {$class}"),
            },
        );
        $this->em->method('flush');
    }

    #[Test]
    public function noDueProductsExitsSuccessWithoutPushing(): void
    {
        $this->finder->method('findDue')->willReturn([]);
        $this->push->expects(self::never())->method('newProductFromFollowedStore');

        $tester = $this->tester();
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Nothing to send', $tester->getDisplay());
    }

    #[Test]
    public function fansOutToEachFollowerAndClaimsTheProduct(): void
    {
        $vendor = $this->createMock(Vendor::class);
        $vendor->method('getId')->willReturn(10);
        $product = new Product($vendor, 'abaya-x', 'Abaya X');

        $this->finder->method('findDue')->willReturn([['product_id' => 1, 'vendor_id' => 10]]);
        $this->em->method('find')->willReturn($product);

        $this->followRepo->method('countFollowersOfVendor')->willReturn(2);
        $this->followRepo->method('findFollowersOfVendor')->willReturnCallback(
            fn (Vendor $v, int $limit, int $offset = 0): array => $offset === 0
                ? [$this->makeUser(101), $this->makeUser(102)]
                : [],
        );

        $this->push->expects(self::exactly(2))->method('newProductFromFollowedStore');

        $tester = $this->tester();
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Found 1', $tester->getDisplay());
        // The product was claimed so a later run won't re-fire.
        self::assertNotNull($product->getFollowersNotifiedAt());
    }

    #[Test]
    public function dryRunListsWithoutPushing(): void
    {
        $this->finder->method('findDue')->willReturn([['product_id' => 7, 'vendor_id' => 3]]);
        $this->push->expects(self::never())->method('newProductFromFollowedStore');

        $tester = $this->tester();
        $exit = $tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('DRY RUN', $tester->getDisplay());
        self::assertStringContainsString('product #7', $tester->getDisplay());
    }

    // -----------------------------------------------------------------

    private function tester(): CommandTester
    {
        $command = new SendFollowerProductAlertsCommand($this->em, $this->finder, $this->push, $this->logger);
        $app = new Application();
        $app->add($command);
        return new CommandTester($app->find('stores:send-follower-alerts'));
    }

    private function makeUser(int $id): User
    {
        $user = new User('u' . $id . '@example.com', '+9715000000' . $id, password_hash('p', PASSWORD_BCRYPT), 'AE');
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($user, $id);
        return $user;
    }
}

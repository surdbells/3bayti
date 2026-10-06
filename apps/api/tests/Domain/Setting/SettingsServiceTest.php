<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Setting;

use Bayti\Api\Domain\Setting\AppSetting;
use Bayti\Api\Domain\Setting\AppSettingRepository;
use Bayti\Api\Domain\Setting\SettingsService;
use Bayti\Api\Infrastructure\Cache\InMemoryKeyValueStore;
use Bayti\Api\Infrastructure\Cache\KeyValueStore;
use Bayti\Api\Infrastructure\Cache\KeyValueStoreException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SettingsService::class)]
final class SettingsServiceTest extends TestCase
{
    #[Test]
    public function getReturnsNullForAnUnsetKey(): void
    {
        $repo = $this->fakeRepo();
        $svc = new SettingsService($this->emFor($repo), new InMemoryKeyValueStore());

        self::assertNull($svc->get('nope'));
    }

    #[Test]
    public function setThenGetRoundTripsTheValue(): void
    {
        $repo = $this->fakeRepo();
        $svc = new SettingsService($this->emFor($repo), new InMemoryKeyValueStore());

        $svc->set('otp.providers', ['order' => ['cequens'], 'enabled' => ['cequens' => true]]);

        self::assertSame(
            ['order' => ['cequens'], 'enabled' => ['cequens' => true]],
            $svc->get('otp.providers'),
        );
    }

    #[Test]
    public function readsAreServedFromCacheAfterTheFirstDbHit(): void
    {
        $repo = $this->fakeRepo();
        $repo->store['k'] = new AppSetting('k', ['v' => 1]);
        $svc = new SettingsService($this->emFor($repo), new InMemoryKeyValueStore());

        $svc->get('k'); // populates cache from DB (1 DB read)
        $svc->get('k'); // served from cache (no DB read)
        $svc->get('k');

        self::assertSame(1, $repo->findCalls, 'only the first read should hit the DB');
    }

    #[Test]
    public function absentKeyIsNegativeCachedSoItDoesNotHitTheDbEveryTime(): void
    {
        $repo = $this->fakeRepo();
        $svc = new SettingsService($this->emFor($repo), new InMemoryKeyValueStore());

        $svc->get('missing');
        $svc->get('missing');

        self::assertSame(1, $repo->findCalls, 'the null result should be negative-cached');
    }

    #[Test]
    public function degradesToTheDbWhenTheCacheBackendThrows(): void
    {
        $repo = $this->fakeRepo();
        $repo->store['k'] = new AppSetting('k', ['v' => 2]);

        $cache = $this->createMock(KeyValueStore::class);
        $cache->method('get')->willThrowException(new KeyValueStoreException('redis down'));
        $cache->method('set')->willThrowException(new KeyValueStoreException('redis down'));

        $svc = new SettingsService($this->emFor($repo), $cache);

        // Cache is dead, but the read still resolves from the DB.
        self::assertSame(['v' => 2], $svc->get('k'));
    }

    // -----------------------------------------------------------------
    // Fakes
    // -----------------------------------------------------------------

    private function emFor(AppSettingRepository $repo): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        return $em;
    }

    private function fakeRepo(): AppSettingRepository
    {
        return new class extends AppSettingRepository {
            /** @var array<string, AppSetting> */
            public array $store = [];
            public int $findCalls = 0;

            public function __construct()
            {
                // Bypass Doctrine's EntityRepository constructor.
            }

            public function findByKey(string $key): ?AppSetting
            {
                $this->findCalls++;
                return $this->store[$key] ?? null;
            }

            public function save(AppSetting $setting): void
            {
                $this->store[$setting->getKey()] = $setting;
            }
        };
    }
}

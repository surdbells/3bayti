<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Ai;

use Bayti\Api\Ai\AiProviderInterface;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\Style;
use Bayti\Api\Domain\Catalog\StyleRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Ai\StyleRestyleController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(StyleRestyleController::class)]
final class StyleRestyleControllerTest extends HttpTestCase
{
    private function bindDisabledAi(): void
    {
        $ai = $this->createMock(AiProviderInterface::class);
        $ai->method('isEnabled')->willReturn(false);
        $ai->method('completeJson')->willReturn([]);
        $ai->method('embed')->willReturn([]);
        $this->bind(AiProviderInterface::class, $ai);
    }

    /**
     * @param list<Product> $poolProducts
     * @param array<int, Product> $byId
     */
    private function bindDeps(User $user, array $poolProducts, ?Style $seedStyle, array $byId = []): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('findActivePaginated')->willReturn(['items' => $poolProducts, 'total' => count($poolProducts)]);
        $productRepo->method('findBy')->willReturnCallback(static fn (array $c) => array_values($byId));

        $styleRepo = $this->createMock(StyleRepository::class);
        $styleRepo->method('findActiveBySlug')->willReturn($seedStyle);

        $em = $this->stubEm(function ($em) use ($userRepo, $productRepo, $styleRepo): void {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Product::class, $productRepo],
                [Style::class, $styleRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);
    }

    private function makeProduct(int $id, string $name): Product
    {
        $vendor = new Vendor("v-{$id}", "Store {$id}", "v{$id}@example.test");
        $vendor->approve();
        $p = new Product(vendor: $vendor, slug: "p-{$id}", name: $name);
        $p->setStatus('active');
        $p->setPrice('300.00');
        $rp = new \ReflectionProperty($p, 'id');
        $rp->setAccessible(true);
        $rp->setValue($p, $id);
        return $p;
    }

    private function makeSeedStyle(Product $seed): Style
    {
        $style = new Style('black-look', 'Black Look');
        $style->getProducts()->add($seed);
        return $style;
    }

    #[Test]
    public function restylesASeedStyleIntoAPreview(): void
    {
        $this->bindDisabledAi();
        $user = $this->makeUser(id: 1);
        $seed = $this->makeProduct(100, 'Black Abaya');
        $this->bindDeps($user, [$this->makeProduct(1, 'Silk Scarf'), $this->makeProduct(2, 'Leather Bag')], $this->makeSeedStyle($seed));

        $res = $this->authed($user, ['instruction' => 'make it more elegant for a wedding', 'style_slug' => 'black-look']);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());

        $data = $this->body($res);
        self::assertFalse($data['saved']);
        self::assertArrayHasKey('rationale', $data);
        self::assertCount(2, $data['products']);
        self::assertArrayHasKey('reason', $data['products'][0]);
        self::assertFalse($data['intent']['is_gift']);
    }

    #[Test]
    public function requiresAuth(): void
    {
        $this->bindDisabledAi();
        $user = $this->makeUser(id: 1);
        $this->bindDeps($user, [], null);

        $res = $this->handle($this->jsonRequest('POST', '/v3/ai/styles/restyle', ['instruction' => 'x', 'style_slug' => 'y']));
        self::assertSame(401, $res->getStatusCode(), (string) $res->getBody());
    }

    #[Test]
    public function rejectsAMissingInstruction(): void
    {
        $this->bindDisabledAi();
        $user = $this->makeUser(id: 1);
        $this->bindDeps($user, [], null);

        $res = $this->authed($user, ['style_slug' => 'black-look']);
        self::assertSame(422, $res->getStatusCode(), (string) $res->getBody());
    }

    #[Test]
    public function rejectsAMissingSeed(): void
    {
        $this->bindDisabledAi();
        $user = $this->makeUser(id: 1);
        $this->bindDeps($user, [$this->makeProduct(1, 'Bag')], null); // no style, no products

        $res = $this->authed($user, ['instruction' => 'make it elegant']);
        self::assertSame(422, $res->getStatusCode(), (string) $res->getBody());
    }

    // ===== helpers =====

    /** @param array<string, mixed> $body */
    private function authed(User $user, array $body): ResponseInterface
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        return $this->handle($this->jsonRequest('POST', '/v3/ai/styles/restyle', $body, [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));
    }

    /** @return array<string, mixed> */
    private function body(ResponseInterface $res): array
    {
        $decoded = json_decode((string) $res->getBody(), true);
        return is_array($decoded) ? ($decoded['data'] ?? $decoded) : [];
    }
}

<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Style;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Style;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Style\DeleteMyStyleController;
use Bayti\Api\Http\Controllers\Style\UpdateMyStyleController;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Edit + soft-delete of a customer's own saved look
 * (UpdateMyStyleController / DeleteMyStyleController). Invokes the
 * controllers directly with a mocked EM so the Style entity can be
 * inspected without a database.
 */
#[CoversClass(UpdateMyStyleController::class)]
#[CoversClass(DeleteMyStyleController::class)]
final class StyleWriteControllersTest extends TestCase
{
    // ---- Update -----------------------------------------------------

    #[Test]
    public function ownerCanRenameAndReplaceProducts(): void
    {
        $owner = $this->makeUser(1);
        $style = $this->makeStyle(5, $owner, 'old-slug', 'Old name');
        // Pre-existing single product to be replaced.
        $style->getProducts()->add($this->makeProduct());

        $em = $this->em($style, $owner, priceEach: '120.00');

        $response = (new UpdateMyStyleController((new ResponseFactory()), $em))(
            $this->request($owner, ['name' => 'Eid Look', 'products' => '7,8']),
            $this->emptyResponse(),
            ['id' => '5'],
        );

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true)['data'];
        self::assertSame('Eid Look', $data['name']);
        self::assertSame(2, $data['product_count']);
        self::assertSame('240.00', $data['total_price']);
        // Slug must stay stable so shared links keep resolving.
        self::assertSame('old-slug', $data['slug']);
        self::assertSame('Eid Look', $style->getName());
        self::assertSame(2, $style->getProducts()->count());
    }

    #[Test]
    public function updateOnSomeoneElsesStyleIs404(): void
    {
        $owner = $this->makeUser(1);
        $style = $this->makeStyle(5, $owner, 'slug', 'Name');
        $attacker = $this->makeUser(2);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Style not found');
        (new UpdateMyStyleController((new ResponseFactory()), $this->em($style, $owner)))(
            $this->request($attacker, ['name' => 'x', 'products' => '7']),
            $this->emptyResponse(),
            ['id' => '5'],
        );
    }

    #[Test]
    public function updateOnSoftDeletedStyleIs404(): void
    {
        $owner = $this->makeUser(1);
        $style = $this->makeStyle(5, $owner, 'slug', 'Name', active: false);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Style not found');
        (new UpdateMyStyleController((new ResponseFactory()), $this->em($style, $owner)))(
            $this->request($owner, ['name' => 'x', 'products' => '7']),
            $this->emptyResponse(),
            ['id' => '5'],
        );
    }

    #[Test]
    public function updateRejectsMoreThanFourProducts(): void
    {
        $owner = $this->makeUser(1);
        $style = $this->makeStyle(5, $owner, 'slug', 'Name');

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('at most 4');
        (new UpdateMyStyleController((new ResponseFactory()), $this->em($style, $owner)))(
            $this->request($owner, ['name' => 'x', 'products' => '1,2,3,4,5']),
            $this->emptyResponse(),
            ['id' => '5'],
        );
    }

    #[Test]
    public function updateRejectsEmptyName(): void
    {
        $owner = $this->makeUser(1);
        $style = $this->makeStyle(5, $owner, 'slug', 'Name');

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('name is required');
        (new UpdateMyStyleController((new ResponseFactory()), $this->em($style, $owner)))(
            $this->request($owner, ['name' => '   ', 'products' => '7']),
            $this->emptyResponse(),
            ['id' => '5'],
        );
    }

    // ---- Delete -----------------------------------------------------

    #[Test]
    public function ownerCanSoftDeleteOwnStyle(): void
    {
        $owner = $this->makeUser(1);
        $style = $this->makeStyle(5, $owner, 'slug', 'Name');

        $response = (new DeleteMyStyleController((new ResponseFactory()), $this->em($style, $owner)))(
            $this->request($owner),
            $this->emptyResponse(),
            ['id' => '5'],
        );

        self::assertSame(204, $response->getStatusCode());
        self::assertFalse($style->isActive());
    }

    #[Test]
    public function deleteOnSomeoneElsesStyleIs404(): void
    {
        $owner = $this->makeUser(1);
        $style = $this->makeStyle(5, $owner, 'slug', 'Name');
        $attacker = $this->makeUser(2);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Style not found');
        (new DeleteMyStyleController((new ResponseFactory()), $this->em($style, $owner)))(
            $this->request($attacker),
            $this->emptyResponse(),
            ['id' => '5'],
        );
    }

    #[Test]
    public function deleteIsIdempotentOnAlreadyInactiveStyle(): void
    {
        $owner = $this->makeUser(1);
        $style = $this->makeStyle(5, $owner, 'slug', 'Name', active: false);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($style);
        // Nothing to persist, flush must NOT be called for a no-op delete.
        $em->expects(self::never())->method('flush');

        $response = (new DeleteMyStyleController((new ResponseFactory()), $em))(
            $this->request($owner),
            $this->emptyResponse(),
            ['id' => '5'],
        );

        self::assertSame(204, $response->getStatusCode());
        self::assertFalse($style->isActive());
    }

    // ---- helpers ----------------------------------------------------

    private function em(Style $style, User $owner, string $priceEach = '100.00'): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(
            function (string $class, mixed $id) use ($style, $priceEach): ?object {
                if ($class === Style::class) {
                    return (int) $id === (int) $style->getId() ? $style : null;
                }
                if ($class === Product::class) {
                    $p = $this->createMock(Product::class);
                    $p->method('isActive')->willReturn(true);
                    $p->method('getSalePrice')->willReturn(null);
                    $p->method('getPrice')->willReturn($priceEach);
                    return $p;
                }
                return null;
            },
        );
        $em->method('flush');

        return $em;
    }

    private function makeProduct(): Product
    {
        $p = $this->createMock(Product::class);
        $p->method('isActive')->willReturn(true);
        return $p;
    }

    private function makeUser(int $id): User
    {
        $user = new User('u' . $id . '@example.com', '+9715000000' . $id, password_hash('p', PASSWORD_BCRYPT), 'AE');
        $this->setId($user, $id);
        return $user;
    }

    private function makeStyle(int $id, User $owner, string $slug, string $name, bool $active = true): Style
    {
        $style = new Style($slug, $name, Style::TYPE_COMMUNITY);
        $style->setCreatedByUser($owner);
        $style->setActive($active);
        $this->setId($style, $id);
        return $style;
    }

    private function setId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($entity, $id);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(User $user, array $body = []): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('PUT', '/v3/me/styles/5')
            ->withAttribute(AuthMiddleware::ATTR_USER, $user)
            ->withParsedBody($body);
    }

    private function emptyResponse(): ResponseInterface
    {
        return (new ResponseFactory())->createResponse();
    }
}

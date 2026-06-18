<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Cart;

use Bayti\Api\Domain\Cart\Cart;
use Bayti\Api\Domain\Cart\CartItem;
use Bayti\Api\Domain\Cart\CartRepository;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Promo\PromoCode;
use Bayti\Api\Domain\Promo\PromoCodeRepository;
use Bayti\Api\Domain\Promo\PromoRedemption;
use Bayti\Api\Domain\Promo\PromoRedemptionRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Cart\Dto\QuoteCartInput;
use Bayti\Api\Http\Controllers\Cart\QuoteCartController;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Serializers\CartQuoteSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration tests for POST /v3/cart/quote (M3.2.X.8-C).
 *
 * Drives the full Slim app per HttpTestCase convention. Mocks both
 * the EntityManager (cart + user lookups) and the resolver's
 * repositories (promo code lookup + redemption counts).
 *
 * Coverage:
 *   - Happy path: cart with promo → discount applied + breakdown
 *   - Happy path: cart without promo → breakdown with zero discount
 *   - Each of the 8 PROMO_* rejection codes surfaces as 422
 *   - Empty cart → 422 BUSINESS_RULE_VIOLATION
 *   - Missing auth → 401
 *   - Empty promo_code string → treated as null (no resolver call)
 *   - promo_code length validation → 422 VALIDATION_FAILED
 */
#[CoversClass(QuoteCartController::class)]
#[CoversClass(QuoteCartInput::class)]
#[CoversClass(CartQuoteSerializer::class)]
final class QuoteCartControllerTest extends HttpTestCase
{
    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    /**
     * Build a single-item cart at the given subtotal for the user.
     * Subtotal is set indirectly via item unit_price + quantity 1.
     */
    private function makeCartWithSubtotal(User $user, string $subtotal): Cart
    {
        $cart = new Cart(user: $user);
        $this->setEntityId($cart, 42);

        $product = $this->makeProduct(id: 100, name: 'Silk Abaya', price: $subtotal);
        $item = new CartItem(
            product: $product,
            quantity: 1,
            unitPriceSnapshot: $subtotal,
        );
        $this->setEntityId($item, 555);
        $cart->addItem($item);

        return $cart;
    }

    private function makeProduct(int $id, string $name, string $price): Product
    {
        $product = (new \ReflectionClass(Product::class))->newInstanceWithoutConstructor();
        $this->setEntityProp($product, 'id', $id);
        $this->setEntityProp($product, 'name', $name);
        $this->setEntityProp($product, 'price', $price);
        $this->setEntityProp($product, 'isActive', true);
        $vendor = (new \ReflectionClass(Vendor::class))->newInstanceWithoutConstructor();
        $this->setEntityProp($vendor, 'id', 5);
        $this->setEntityProp($product, 'vendor', $vendor);
        return $product;
    }

    private function setEntityId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($entity, $id);
    }

    private function setEntityProp(object $entity, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($entity::class, $prop);
        $ref->setAccessible(true);
        $ref->setValue($entity, $value);
    }

    private function makePromoCode(
        string $code = 'WELCOME10',
        string $type = PromoCode::DISCOUNT_TYPE_PERCENTAGE,
        string $value = '10.00',
        int $id = 7,
    ): PromoCode {
        $promo = new PromoCode($code, $type, $value);
        $this->setEntityId($promo, $id);
        return $promo;
    }

    /**
     * Bind EM with cart + user + promo repos pre-wired. Promo
     * redemption repo defaults to zero-count (no limits hit).
     *
     * @return array{em: EntityManagerInterface, user: User, cart: Cart}
     */
    private function bindEnv(
        ?PromoCode $promo = null,
        ?Cart $cart = null,
        string $cartSubtotal = '100.00',
        int $globalRedemptionCount = 0,
        int $userRedemptionCount = 0,
    ): array {
        $user = $this->makeUser(id: 7);
        $cart = $cart ?? $this->makeCartWithSubtotal($user, $cartSubtotal);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->with(7)->willReturn($user);

        $cartRepo = $this->createMock(CartRepository::class);
        $cartRepo->method('findActiveForUser')->with($user)->willReturn($cart);

        $promoRepo = $this->createMock(PromoCodeRepository::class);
        $promoRepo->method('findByNormalizedCode')->willReturnCallback(
            fn (string $code): ?PromoCode => $promo !== null
                && $promo->getCode() === PromoCode::normalizeCode($code)
                ? $promo
                : null,
        );

        $redemptionRepo = $this->createMock(PromoRedemptionRepository::class);
        $redemptionRepo->method('countByPromoCodeIdEffective')->willReturn($globalRedemptionCount);
        $redemptionRepo->method('countByUserAndPromoCodeEffective')->willReturn($userRedemptionCount);

        $em = $this->stubEm(function ($em) use ($userRepo, $cartRepo, $promoRepo, $redemptionRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Cart::class, $cartRepo],
                [PromoCode::class, $promoRepo],
                [PromoRedemption::class, $redemptionRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        return ['em' => $em, 'user' => $user, 'cart' => $cart];
    }

    private function bearerHeader(User $user): array
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        return ['Authorization' => 'Bearer ' . $pair->accessToken];
    }

    // -------------------------------------------------------------------
    // Happy paths
    // -------------------------------------------------------------------

    #[Test]
    public function returns200WithDiscountWhenPromoApplies(): void
    {
        $promo = $this->makePromoCode('WELCOME10', PromoCode::DISCOUNT_TYPE_PERCENTAGE, '10.00');
        $env = $this->bindEnv(promo: $promo, cartSubtotal: '100.00');

        $response = $this->handle(
            $this->jsonRequest(
                'POST',
                '/v3/cart/quote',
                ['promo_code' => 'WELCOME10'],
                $this->bearerHeader($env['user']),
            ),
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);

        self::assertSame('AED', $body['data']['currency']);
        self::assertSame('100.00', $body['data']['subtotal']);
        self::assertSame('20.00', $body['data']['delivery_fee']);
        self::assertSame('10.00', $body['data']['discount']);
        self::assertSame('110.00', $body['data']['total']);

        self::assertSame('WELCOME10', $body['data']['applied_promo']['code']);
        self::assertSame('percentage', $body['data']['applied_promo']['discount_type']);
        self::assertSame('10.00', $body['data']['applied_promo']['discount_value']);
        self::assertSame('10.00', $body['data']['applied_promo']['discount_amount']);
    }

    #[Test]
    public function returns200WithoutPromoWhenPromoCodeOmitted(): void
    {
        $env = $this->bindEnv(cartSubtotal: '50.00');

        $response = $this->handle(
            $this->jsonRequest(
                'POST',
                '/v3/cart/quote',
                [],
                $this->bearerHeader($env['user']),
            ),
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);

        self::assertSame('50.00', $body['data']['subtotal']);
        self::assertSame('0.00', $body['data']['discount']);
        self::assertSame('70.00', $body['data']['total']);
        self::assertNull($body['data']['applied_promo']);
    }

    #[Test]
    public function emptyPromoCodeStringIsTreatedAsNull(): void
    {
        $env = $this->bindEnv(cartSubtotal: '50.00');

        $response = $this->handle(
            $this->jsonRequest(
                'POST',
                '/v3/cart/quote',
                ['promo_code' => '   '],
                $this->bearerHeader($env['user']),
            ),
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertNull($body['data']['applied_promo']);
        self::assertSame('0.00', $body['data']['discount']);
    }

    #[Test]
    public function fixedAmountPromoClampedAtSubtotalSurfacesInResponse(): void
    {
        $promo = $this->makePromoCode('BIGSAVE', PromoCode::DISCOUNT_TYPE_FIXED_AMOUNT, '500.00');
        $env = $this->bindEnv(promo: $promo, cartSubtotal: '40.00');

        $response = $this->handle(
            $this->jsonRequest(
                'POST',
                '/v3/cart/quote',
                ['promo_code' => 'BIGSAVE'],
                $this->bearerHeader($env['user']),
            ),
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);

        self::assertSame('40.00', $body['data']['discount']);
        self::assertSame('20.00', $body['data']['total']);
        self::assertSame('500.00', $body['data']['applied_promo']['discount_value']);
        self::assertSame('40.00', $body['data']['applied_promo']['discount_amount']);
    }

    // -------------------------------------------------------------------
    // Promo rejection codes (one test per rule)
    // -------------------------------------------------------------------

    #[Test]
    public function returns422PromoNotFoundForUnknownCode(): void
    {
        $env = $this->bindEnv(promo: null);

        $response = $this->handle(
            $this->jsonRequest(
                'POST',
                '/v3/cart/quote',
                ['promo_code' => 'NOPE'],
                $this->bearerHeader($env['user']),
            ),
        );

        self::assertSame(422, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame(ErrorCodes::PROMO_NOT_FOUND, $body['error']['code']);
    }

    #[Test]
    public function returns422PromoInactiveWhenCodeIsDisabled(): void
    {
        $promo = $this->makePromoCode();
        $promo->setActive(false);
        $env = $this->bindEnv(promo: $promo);

        $response = $this->handle(
            $this->jsonRequest(
                'POST',
                '/v3/cart/quote',
                ['promo_code' => 'WELCOME10'],
                $this->bearerHeader($env['user']),
            ),
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            ErrorCodes::PROMO_INACTIVE,
            $this->jsonBody($response)['error']['code'],
        );
    }

    #[Test]
    public function returns422PromoNotYetValidWithDetail(): void
    {
        $promo = $this->makePromoCode();
        $promo->setValidFrom(new DateTimeImmutable('2030-01-01T00:00:00Z', new DateTimeZone('UTC')));
        $env = $this->bindEnv(promo: $promo);

        $response = $this->handle(
            $this->jsonRequest(
                'POST',
                '/v3/cart/quote',
                ['promo_code' => 'WELCOME10'],
                $this->bearerHeader($env['user']),
            ),
        );

        self::assertSame(422, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame(ErrorCodes::PROMO_NOT_YET_VALID, $body['error']['code']);
        self::assertArrayHasKey('valid_from', $body['error']['details']);
    }

    #[Test]
    public function returns422PromoExpiredWithDetail(): void
    {
        $promo = $this->makePromoCode();
        $promo->setValidUntil(new DateTimeImmutable('2020-01-01T00:00:00Z', new DateTimeZone('UTC')));
        $env = $this->bindEnv(promo: $promo);

        $response = $this->handle(
            $this->jsonRequest(
                'POST',
                '/v3/cart/quote',
                ['promo_code' => 'WELCOME10'],
                $this->bearerHeader($env['user']),
            ),
        );

        self::assertSame(422, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame(ErrorCodes::PROMO_EXPIRED, $body['error']['code']);
        self::assertArrayHasKey('valid_until', $body['error']['details']);
    }

    #[Test]
    public function returns422PromoCurrencyMismatch(): void
    {
        $promo = $this->makePromoCode();
        $promo->setCurrency('USD');
        $env = $this->bindEnv(promo: $promo);

        $response = $this->handle(
            $this->jsonRequest(
                'POST',
                '/v3/cart/quote',
                ['promo_code' => 'WELCOME10'],
                $this->bearerHeader($env['user']),
            ),
        );

        self::assertSame(422, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame(ErrorCodes::PROMO_CURRENCY_MISMATCH, $body['error']['code']);
        self::assertSame('USD', $body['error']['details']['promo_currency']);
        self::assertSame('AED', $body['error']['details']['cart_currency']);
    }

    #[Test]
    public function returns422PromoMinSubtotalNotMetWithDetail(): void
    {
        $promo = $this->makePromoCode();
        $promo->setMinSubtotal('200.00');
        $env = $this->bindEnv(promo: $promo, cartSubtotal: '50.00');

        $response = $this->handle(
            $this->jsonRequest(
                'POST',
                '/v3/cart/quote',
                ['promo_code' => 'WELCOME10'],
                $this->bearerHeader($env['user']),
            ),
        );

        self::assertSame(422, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame(ErrorCodes::PROMO_MIN_SUBTOTAL_NOT_MET, $body['error']['code']);
        self::assertSame('200.00', $body['error']['details']['min_subtotal']);
    }

    #[Test]
    public function returns422PromoGlobalLimitReached(): void
    {
        $promo = $this->makePromoCode();
        $promo->setUsageLimitGlobal(5);
        $env = $this->bindEnv(promo: $promo, globalRedemptionCount: 5);

        $response = $this->handle(
            $this->jsonRequest(
                'POST',
                '/v3/cart/quote',
                ['promo_code' => 'WELCOME10'],
                $this->bearerHeader($env['user']),
            ),
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            ErrorCodes::PROMO_GLOBAL_LIMIT_REACHED,
            $this->jsonBody($response)['error']['code'],
        );
    }

    #[Test]
    public function returns422PromoUserLimitReached(): void
    {
        $promo = $this->makePromoCode();
        $promo->setUsageLimitPerUser(1);
        $env = $this->bindEnv(promo: $promo, userRedemptionCount: 1);

        $response = $this->handle(
            $this->jsonRequest(
                'POST',
                '/v3/cart/quote',
                ['promo_code' => 'WELCOME10'],
                $this->bearerHeader($env['user']),
            ),
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            ErrorCodes::PROMO_USER_LIMIT_REACHED,
            $this->jsonBody($response)['error']['code'],
        );
    }

    // -------------------------------------------------------------------
    // Empty cart + auth + input validation
    // -------------------------------------------------------------------

    #[Test]
    public function returns422WhenCartIsEmpty(): void
    {
        $user = $this->makeUser(id: 7);
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->with(7)->willReturn($user);

        $cartRepo = $this->createMock(CartRepository::class);
        $cartRepo->method('findActiveForUser')->with($user)->willReturn(null);

        $em = $this->stubEm(function ($em) use ($userRepo, $cartRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Cart::class, $cartRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest(
                'POST',
                '/v3/cart/quote',
                [],
                $this->bearerHeader($user),
            ),
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            ErrorCodes::BUSINESS_RULE_VIOLATION,
            $this->jsonBody($response)['error']['code'],
        );
    }

    #[Test]
    public function returns401WithoutAuthorizationHeader(): void
    {
        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/cart/quote', []),
        );

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function returns422WhenPromoCodeExceedsMaxLength(): void
    {
        $env = $this->bindEnv(cartSubtotal: '50.00');
        $tooLong = str_repeat('A', PromoCode::CODE_MAX_LENGTH + 5);

        $response = $this->handle(
            $this->jsonRequest(
                'POST',
                '/v3/cart/quote',
                ['promo_code' => $tooLong],
                $this->bearerHeader($env['user']),
            ),
        );

        self::assertSame(422, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame(ErrorCodes::VALIDATION_FAILED, $body['error']['code']);
        self::assertArrayHasKey('promo_code', $body['error']['details']['fields']);
    }
}

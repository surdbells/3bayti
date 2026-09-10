<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Shipping;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderAddress;
use Bayti\Api\Domain\Order\OrderItem;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Shipping\Oto\OtoClient;
use Bayti\Api\Shipping\Oto\OtoOrderPayloadBuilder;
use Bayti\Api\Shipping\Oto\OtoShippingProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OtoShippingProvider::class)]
#[CoversClass(OtoClient::class)]
final class OtoDeliveryOptionsTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $history = [];

    /** @param list<Response> $responses */
    private function provider(array $responses): OtoShippingProvider
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $client = new OtoClient(new Client(['handler' => $stack]), 'https://api.tryoto.test', 'refresh-token');
        return new OtoShippingProvider($client, new OtoOrderPayloadBuilder(1.0));
    }

    #[Test]
    public function listsCarriersFromTheFeeCheckEndpoint(): void
    {
        $provider = $this->provider([
            new Response(200, [], (string) json_encode(['access_token' => 'a'])),
            new Response(200, [], (string) json_encode([
                'success' => true,
                'traceId' => 'abc',
                'deliveryCompany' => [
                    [
                        'deliveryOptionId' => 345,
                        'deliveryCompanyName' => 'kwickbox',
                        'deliveryOptionName' => 'Kwickbox Express',
                        'price' => 18.5,
                        'avgDeliveryTime' => '2-3 days',
                    ],
                    [
                        'deliveryOptionId' => 12,
                        'deliveryCompanyName' => 'aramex',
                        'deliveryOptionName' => 'Aramex Domestic',
                        'price' => 22,
                        'avgDeliveryTime' => '1-2 days',
                    ],
                ],
            ])),
        ]);

        [$order, $vendor, $items] = $this->orderFixture();
        $options = $provider->listDeliveryOptions($order, $vendor, $items);

        // Hit the correct (singular) endpoint with a city+weight quote.
        $req = $this->history[1]['request'];
        self::assertSame('/rest/v2/checkOTODeliveryFee', $req->getUri()->getPath());
        $sent = json_decode((string) $req->getBody(), true);
        self::assertSame('Dubai', $sent['originCity']);
        self::assertSame('Abu Dhabi', $sent['destinationCity']);
        self::assertArrayHasKey('weight', $sent);
        self::assertArrayNotHasKey('items', $sent);

        // Parsed the `deliveryCompany` array into option rows.
        self::assertCount(2, $options);
        self::assertSame('345', $options[0]['id']);
        self::assertSame('Kwickbox Express', $options[0]['name']);
        self::assertSame(18.5, $options[0]['price']);
        self::assertSame('AED', $options[0]['currency']);
        self::assertSame('2-3 days', $options[0]['eta']);
        self::assertSame('kwickbox', $options[0]['company']);
    }

    #[Test]
    public function returnsEmptyWhenTheQuoteCallFails(): void
    {
        $provider = $this->provider([
            new Response(200, [], (string) json_encode(['access_token' => 'a'])),
            new Response(502, [], 'gateway error'),
        ]);

        [$order, $vendor, $items] = $this->orderFixture();
        self::assertSame([], $provider->listDeliveryOptions($order, $vendor, $items));
    }

    /** @return array{0: Order, 1: Vendor, 2: list<OrderItem>} */
    private function orderFixture(): array
    {
        $vendor = new Vendor('store-7', 'Store 7', 'v7@example.test');
        $this->setProp($vendor, 'id', 7);
        $vendor->setPickupCity('Dubai');

        $user = new User('c@example.test', '+971500000001', password_hash('p', PASSWORD_BCRYPT), 'AE');
        $this->setProp($user, 'id', 1);
        $order = new Order(user: $user, orderReference: '3B-OPT', subtotal: '100.00');
        $this->setProp($order, 'id', 700);
        $order->addAddress(new OrderAddress(
            type: 'shipping', firstName: 'Mariam', phone: '+971508099229',
            email: 'c@example.test', street: 'St 1', city: 'Abu Dhabi',
            lastName: 'A', stateProvince: 'Abu Dhabi', countryCode: 'AE',
        ));

        $product = (new \ReflectionClass(Product::class))->newInstanceWithoutConstructor();
        $this->setProp($product, 'id', 900);
        $this->setProp($product, 'vendor', $vendor);
        $item = new OrderItem(
            product: $product, vendor: $vendor, quantity: 2, unitPrice: '50.00',
            productNameSnapshot: 'Thing', productImageSnapshot: null,
        );
        $this->setProp($item, 'id', 1);
        $order->addItem($item);

        return [$order, $vendor, [$item]];
    }

    private function setProp(object $entity, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($entity::class, $prop);
        $ref->setAccessible(true);
        $ref->setValue($entity, $value);
    }
}

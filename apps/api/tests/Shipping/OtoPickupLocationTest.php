<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Shipping;

use Bayti\Api\Shipping\NullShippingProvider;
use Bayti\Api\Shipping\Oto\OtoClient;
use Bayti\Api\Shipping\Oto\OtoOrderPayloadBuilder;
use Bayti\Api\Shipping\Oto\OtoShippingProvider;
use Bayti\Api\Shipping\ShippingException;
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
final class OtoPickupLocationTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $history = [];

    /** @param list<Response> $responses */
    private function provider(array $responses): OtoShippingProvider
    {
        $this->history = [];
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));
        $client = new OtoClient(
            new Client(['handler' => $stack]),
            'https://api.tryoto.test',
            'refresh-token',
        );
        return new OtoShippingProvider($client, new OtoOrderPayloadBuilder(1.0));
    }

    /** The valid input the controller hands the provider. */
    private function input(): array
    {
        return [
            'code' => 'my-store-63',
            'name' => 'My Store',
            'contact_name' => 'Aisha Owner',
            'contact_email' => 'store@example.test',
            'phone' => '+971500000000',
            'address' => 'Warehouse 12, Street 4',
            'city' => 'Dubai',
            'country' => 'ae',
            'type' => 'branch',
            'postcode' => '00000',
            'lat' => 25.2048,
            'lon' => 55.2708,
        ];
    }

    #[Test]
    public function createsAnOtoPickupLocationAndReturnsTheCode(): void
    {
        $provider = $this->provider([
            new Response(200, [], (string) json_encode(['access_token' => 'access-abc'])),
            new Response(200, [], (string) json_encode([
                'success' => true,
                'pickupLocationCode' => 'my-store-63',
                'warhouseId' => '9001',
                'message' => 'warhouse has been created',
            ])),
        ]);

        $result = $provider->createPickupLocation($this->input());

        self::assertSame('my-store-63', $result['code']);
        self::assertSame('My Store', $result['name']);
        self::assertSame('Dubai', $result['city']);

        // The second request is the createPickupLocation call — verify the mapping.
        $createReq = $this->history[1]['request'];
        self::assertSame('/rest/v2/createPickupLocation', $createReq->getUri()->getPath());
        $sent = json_decode((string) $createReq->getBody(), true);
        self::assertSame('my-store-63', $sent['code']);
        self::assertSame('+971500000000', $sent['mobile']);      // phone → mobile
        self::assertSame('Aisha Owner', $sent['contactName']);
        self::assertSame('store@example.test', $sent['contactEmail']);
        self::assertSame('AE', $sent['country']);                // upper-cased
        self::assertSame('branch', $sent['type']);
        self::assertSame('active', $sent['status']);
        self::assertSame('00000', $sent['postcode']);
        self::assertSame(25.2048, $sent['lat']);   // geo pin from Google Places
        self::assertSame(55.2708, $sent['lon']);
    }

    #[Test]
    public function omitsTheGeoPinWhenCoordinatesAreMissing(): void
    {
        $provider = $this->provider([
            new Response(200, [], (string) json_encode(['access_token' => 'a'])),
            new Response(200, [], (string) json_encode(['success' => true, 'pickupLocationCode' => 'x'])),
        ]);

        $input = $this->input();
        unset($input['lat'], $input['lon']);
        $provider->createPickupLocation($input);

        $sent = json_decode((string) $this->history[1]['request']->getBody(), true);
        self::assertArrayNotHasKey('lat', $sent);
        self::assertArrayNotHasKey('lon', $sent);
    }

    #[Test]
    public function fallsBackToTheSubmittedCodeWhenOtoOmitsIt(): void
    {
        $provider = $this->provider([
            new Response(200, [], (string) json_encode(['access_token' => 'access-abc'])),
            new Response(200, [], (string) json_encode(['success' => true])),
        ]);

        $result = $provider->createPickupLocation($this->input());

        self::assertSame('my-store-63', $result['code']);
    }

    #[Test]
    public function defaultsCountryToAeAndTypeToWarehouse(): void
    {
        $provider = $this->provider([
            new Response(200, [], (string) json_encode(['access_token' => 'a'])),
            new Response(200, [], (string) json_encode(['success' => true, 'pickupLocationCode' => 'x'])),
        ]);

        $input = $this->input();
        $input['country'] = '';
        $input['type'] = 'bogus';
        $provider->createPickupLocation($input);

        $sent = json_decode((string) $this->history[1]['request']->getBody(), true);
        self::assertSame('AE', $sent['country']);
        self::assertSame('warehouse', $sent['type']);
    }

    #[Test]
    public function normalisesANonIso2CountryNameToAe(): void
    {
        // A free-text country name ("United Arab Emirates") must not reach OTO,
        // which requires an ISO2 code — normalise it to AE (UAE-only platform).
        $provider = $this->provider([
            new Response(200, [], (string) json_encode(['access_token' => 'a'])),
            new Response(200, [], (string) json_encode(['success' => true, 'pickupLocationCode' => 'x'])),
        ]);

        $input = $this->input();
        $input['country'] = 'United Arab Emirates';
        $provider->createPickupLocation($input);

        $sent = json_decode((string) $this->history[1]['request']->getBody(), true);
        self::assertSame('AE', $sent['country']);
    }

    #[Test]
    public function surfacesAnOtoSoftFailureAsAShippingException(): void
    {
        $provider = $this->provider([
            new Response(200, [], (string) json_encode(['access_token' => 'a'])),
            new Response(200, [], (string) json_encode(['success' => false, 'message' => 'code already exists'])),
        ]);

        $this->expectException(ShippingException::class);
        $this->expectExceptionMessageMatches('/code already exists/');
        $provider->createPickupLocation($this->input());
    }

    #[Test]
    public function nullProviderRefusesToCreateWhenShippingIsNotConfigured(): void
    {
        $this->expectException(ShippingException::class);
        (new NullShippingProvider())->createPickupLocation($this->input());
    }
}

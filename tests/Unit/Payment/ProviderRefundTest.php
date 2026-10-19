<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use App\Http\RouteCollection;
use App\Http\Router;
use App\I18n\Translator;
use App\Infrastructure\HttpClient;
use App\Payment\Method\BankTransferMethod;
use App\Payment\Method\PayPalMethod;
use App\Payment\Method\RevolutMethod;
use App\Payment\Method\StripeMethod;
use App\Service\OrderLinks;
use App\Support\Config;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

/** Records requests and answers with canned responses: no network. */
final class FakeHttpClient extends HttpClient
{
    /** @var list<array{method: string, url: string, headers: list<string>, body: mixed, form: bool}> */
    public array $requests = [];

    /** @param list<array{status: int, body: array<mixed>}> $responses */
    public function __construct(private array $responses)
    {
    }

    public function request(string $method, string $url, array $headers = [], array|string|null $body = null, bool $form = false, int $timeout = 20): array
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body', 'form');

        return array_shift($this->responses) ?? throw new RuntimeException('Unexpected extra HTTP request');
    }
}

final class ProviderRefundTest extends TestCase
{
    /** @return array{config: Config, router: Router, links: OrderLinks, translator: Translator} */
    private function deps(): array
    {
        $config = Config::fromArray([
            'STORE_CURRENCY' => 'EUR', 'STRIPE_SECRET_KEY' => 'sk_test_x', 'PAYPAL_CLIENT_ID' => 'id', 'PAYPAL_CLIENT_SECRET' => 'secret',
            'PAYPAL_SANDBOX' => 'true', 'REVOLUT_SECRET_KEY' => 'rk', 'REVOLUT_SANDBOX' => 'true',
        ]);
        // refund() uses neither the router nor the links: they only need to exist.
        $links = (new ReflectionClass(OrderLinks::class))->newInstanceWithoutConstructor();

        return [
            'config' => $config,
            'router' => new Router(new RouteCollection()),
            'links' => $links,
            'translator' => new Translator(dirname(__DIR__, 3) . '/translations'),
        ];
    }

    public function testStripeRefundSendsPaymentIntentAmountAndIdempotencyKey(): void
    {
        $http = new FakeHttpClient([['status' => 200, 'body' => ['id' => 're_1', 'status' => 'succeeded']]]);
        $method = new StripeMethod(...$this->deps(), http: $http);

        $this->assertTrue($method->supportsRefund());
        $this->assertSame('re_1', $method->refund('pi_123', 12.5, 'ORD-1'));

        $request = $http->requests[0];
        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://api.stripe.com/v1/refunds', $request['url']);
        $this->assertTrue($request['form']);
        $this->assertSame('pi_123', $request['body']['payment_intent']);
        $this->assertSame(1250, $request['body']['amount']);
        $this->assertTrue(in_array('Idempotency-Key: refund-ORD-1', $request['headers'], true));
        $this->assertTrue(in_array('Authorization: Bearer sk_test_x', $request['headers'], true));
    }

    public function testStripeRefundFailureThrows(): void
    {
        $http = new FakeHttpClient([['status' => 400, 'body' => ['error' => ['message' => 'Charge already refunded']]]]);
        $method = new StripeMethod(...$this->deps(), http: $http);

        $this->assertThrows(RuntimeException::class, fn () => $method->refund('pi_123', 10.0, 'ORD-1'), 'Charge already refunded');
    }

    public function testStripeRefundNeedsAPaymentIntentAndDoesNotCallOut(): void
    {
        $http = new FakeHttpClient([]);
        $method = new StripeMethod(...$this->deps(), http: $http);

        $this->assertThrows(RuntimeException::class, fn () => $method->refund('cs_test_session', 10.0, 'ORD-1'), 'payment intent');
        $this->assertCount(0, $http->requests);
    }

    public function testPayPalRefundsTheCapture(): void
    {
        $http = new FakeHttpClient([
            ['status' => 200, 'body' => ['access_token' => 'tok']],
            ['status' => 201, 'body' => ['id' => 'RF1', 'status' => 'COMPLETED']],
        ]);
        $method = new PayPalMethod(...$this->deps(), http: $http);

        $this->assertSame('RF1', $method->refund('CAP9', 20.0, 'ORD-2'));

        $request = $http->requests[1];
        $this->assertSame('https://api-m.sandbox.paypal.com/v2/payments/captures/CAP9/refund', $request['url']);
        $this->assertSame(['value' => '20.00', 'currency_code' => 'EUR'], $request['body']['amount']);
        $this->assertTrue(in_array('PayPal-Request-Id: refund-ORD-2', $request['headers'], true));
        $this->assertTrue(in_array('Authorization: Bearer tok', $request['headers'], true));
    }

    public function testPayPalFailedRefundThrows(): void
    {
        $http = new FakeHttpClient([
            ['status' => 200, 'body' => ['access_token' => 'tok']],
            ['status' => 422, 'body' => ['message' => 'Capture fully refunded']],
        ]);
        $method = new PayPalMethod(...$this->deps(), http: $http);

        $this->assertThrows(RuntimeException::class, fn () => $method->refund('CAP9', 20.0, 'ORD-2'), 'Capture fully refunded');
    }

    public function testRevolutRefundsTheOrder(): void
    {
        $http = new FakeHttpClient([['status' => 200, 'body' => ['id' => 'rev-refund-1']]]);
        $method = new RevolutMethod(...$this->deps(), http: $http);

        $this->assertSame('rev-refund-1', $method->refund('rev-order-1', 9.99, 'ORD-3'));

        $request = $http->requests[0];
        $this->assertSame('https://sandbox-merchant.revolut.com/api/orders/rev-order-1/refund', $request['url']);
        $this->assertSame(999, $request['body']['amount']);
        $this->assertSame('EUR', $request['body']['currency']);
    }

    public function testRevolutErrorThrows(): void
    {
        $http = new FakeHttpClient([['status' => 422, 'body' => ['message' => 'Order not refundable']]]);
        $method = new RevolutMethod(...$this->deps(), http: $http);

        $this->assertThrows(RuntimeException::class, fn () => $method->refund('rev-order-1', 9.99, 'ORD-3'), 'Order not refundable');
    }

    public function testOfflineMethodsDefaultToUnsupported(): void
    {
        $method = new BankTransferMethod(...$this->deps());

        $this->assertFalse($method->supportsRefund());
        $this->assertThrows(RuntimeException::class, fn () => $method->refund('x', 1.0, 'ORD-4'), 'not supported');
    }
}

<?php

declare(strict_types=1);

namespace ZapSMTP\Tests;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;
use ZapSMTP\Client;
use ZapSMTP\Exception\ZapSMTPException;
use ZapSMTP\Model\Email;
use ZapSMTP\Model\SendEmailResponse;
use ZapSMTP\Tests\Support\MockHttpClient;

final class ClientTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (['ZAP_API_KEY', 'ZAP_BASE_URL'] as $k) {
            putenv($k);
            unset($_ENV[$k], $_SERVER[$k]);
        }
    }

    public function testReadsTheKeyAndBaseUrlFromTheEnvironment(): void
    {
        putenv('ZAP_API_KEY=zap_env');
        putenv('ZAP_BASE_URL=https://env.test/v1');
        $http = new MockHttpClient(MockHttpClient::json(200, ['id' => 'e1']));
        $zap = new Client(null, ['http_client' => $http]);
        $zap->emails->get('e1');
        self::assertSame('https://env.test/v1/emails/e1', $http->url(0));
        self::assertSame('Bearer zap_env', $http->header(0, 'Authorization'));
    }

    public function testReadsEnvAndServerArraysToo(): void
    {
        $_ENV['ZAP_API_KEY'] = 'zap_from_env_array';
        $_SERVER['ZAP_BASE_URL'] = 'https://server.test/v1';
        $http = new MockHttpClient(MockHttpClient::json(200, []));
        $zap = new Client(null, ['http_client' => $http]);
        $zap->usage->get();
        self::assertSame('https://server.test/v1/usage', $http->url(0));
        self::assertSame('Bearer zap_from_env_array', $http->header(0, 'Authorization'));
    }

    public function testDefaultsToTheProductionApi(): void
    {
        $zap = new Client('k');
        self::assertSame('https://api.zapsmtp.com/v1', $zap->getBaseUrl());
        self::assertSame(60.0, $zap->getTimeout());
        self::assertSame(2, $zap->getMaxRetries());
    }

    public function testNeedsAKey(): void
    {
        $this->expectException(ZapSMTPException::class);
        $this->expectExceptionMessage('Missing API key');
        new Client();
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function badOptions(): iterable
    {
        yield 'unknown' => [['basUrl' => 'x'], 'Unknown client option "basUrl"'];
        yield 'base_url' => [['base_url' => ''], 'base_url must be a URL'];
        yield 'timeout' => [['timeout' => 0], 'timeout must be'];
        yield 'max_retries' => [['max_retries' => -1], 'max_retries must be'];
        yield 'http_client' => [['http_client' => new \stdClass()], 'http_client must be a PSR-18 client'];
        yield 'sleeper' => [['sleeper' => 'no such function'], 'sleeper must be callable'];
        yield 'default_headers' => [['default_headers' => 'X: 1'], 'default_headers must be an array'];
        yield 'default_headers values' => [['default_headers' => ['X' => 1]], 'default_headers must be an array of header names to string values'];
    }

    /** @param array<string, mixed> $options */
    #[\PHPUnit\Framework\Attributes\DataProvider('badOptions')]
    public function testChecksItsOptions(array $options, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        /** @phpstan-ignore argument.type */
        new Client('k', $options);
    }

    public function testChecksRequestOptions(): void
    {
        $zap = $this->client(new MockHttpClient(MockHttpClient::json(200, [])));
        $cases = [
            [['idempotencyKey' => 'x'], 'Unknown request option "idempotencyKey"'],
            [['idempotency_key' => 5], 'idempotency_key must be a string'],
            [['timeout' => 'soon'], 'timeout must be'],
            [['max_retries' => 1.5], 'max_retries must be'],
            [['headers' => ['X' => ['a']]], 'headers must be an array of header names to string values'],
        ];
        foreach ($cases as [$options, $message]) {
            try {
                /** @phpstan-ignore argument.type */
                $zap->usage->get($options);
                self::fail('no exception');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testSendsTheHeadersEveryRequestCarries(): void
    {
        $http = new MockHttpClient(MockHttpClient::json(200, ['id' => 'x']));
        $this->client($http, ['default_headers' => ['X-Team' => 'mail', 'accept' => 'application/x-test']])
            ->emails->get('a/b c', ['headers' => ['X-Extra' => '1', 'x-team' => 'override']]);
        self::assertSame('https://api.test/v1/emails/a%2Fb%20c', $http->url(0));
        self::assertMatchesRegularExpression('#^zapsmtp-php/' . preg_quote(Client::VERSION, '#') . ' \(php/' . preg_quote(\PHP_VERSION, '#') . '\)$#', $http->header(0, 'User-Agent'));
        self::assertSame('override', $http->header(0, 'X-Team'));
        self::assertSame('1', $http->header(0, 'X-Extra'));
        self::assertSame('application/x-test', $http->header(0, 'Accept'));
        self::assertSame('', $http->header(0, 'Content-Type'));
        self::assertSame('', $http->header(0, 'Idempotency-Key'));
        self::assertSame('', $http->bodies[0]);
    }

    public function testSerializesQueryParametersArraysRepeatMapsBecomeKeyName(): void
    {
        $http = new MockHttpClient(MockHttpClient::json(200, ['object' => 'list', 'data' => [], 'has_more' => false]));
        $zap = $this->client($http);
        /** @phpstan-ignore argument.type (q: null is skipped) */
        $zap->emails->list(['limit' => 5, 'q' => null, 'metadata' => ['order_id' => 'A-1', 'a b' => 'c']]);
        self::assertSame('https://api.test/v1/emails?limit=5&metadata%5Border_id%5D=A-1&metadata%5Ba+b%5D=c', $http->url(0));
        // a list repeats its key; booleans are true/false; nested values are skipped
        $zap->threads->list(['unread' => true, 'tag' => ['a', 'b'], 'x' => false, 'm' => ['k' => ['nested']], 'o' => new \stdClass()]);
        self::assertSame('https://api.test/v1/threads?unread=true&tag=a&tag=b&x=false', $http->url(1));
    }

    public function testSendsJsonBodiesAndAnIdempotencyKeyOnSends(): void
    {
        $http = new MockHttpClient(MockHttpClient::json(200, ['id' => 'e1']));
        $zap = $this->client($http);
        $sent = $zap->emails->send(['from' => 'a@x.test', 'to' => 'b@y.test', 'subject' => 'Hi', 'text' => 'Hello ✓/']);
        self::assertInstanceOf(SendEmailResponse::class, $sent);
        self::assertSame('e1', $sent->id);
        self::assertSame(['id' => 'e1'], $sent->toArray());
        self::assertSame('application/json', $http->header(0, 'Content-Type'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $http->header(0, 'Idempotency-Key'));
        self::assertSame('{"from":"a@x.test","to":"b@y.test","subject":"Hi","text":"Hello ✓/"}', $http->bodies[0]);
        $zap->emails->send(['from' => 'a', 'to' => 'b', 'subject' => 's'], ['idempotency_key' => 'order-1']);
        self::assertSame('order-1', $http->header(1, 'Idempotency-Key'));
        $zap->emails->send(['to' => 'b']);
        self::assertNotSame($http->header(0, 'Idempotency-Key'), $http->header(2, 'Idempotency-Key'));
    }

    public function testSendsAnEmptyParamsBodyAsAnObject(): void
    {
        $http = new MockHttpClient(MockHttpClient::json(200, ['html' => '']));
        $this->client($http)->templates->render('t1');
        self::assertSame('{}', $http->bodies[0]);
        $this->client($http)->emails->cancel('e1');
        self::assertSame('', $http->bodies[1]);
    }

    public function testRefusesABodyThatIsNotJson(): void
    {
        $this->expectException(ZapSMTPException::class);
        $this->expectExceptionMessage('cannot be encoded as JSON');
        $this->client(new MockHttpClient())->emails->send(['to' => "\xB1\x31"]);
    }

    public function testSendsABatchWithItsValidationMode(): void
    {
        $http = new MockHttpClient(MockHttpClient::json(200, ['data' => []]));
        $zap = $this->client($http);
        $zap->emails->sendBatch([['from' => 'a', 'to' => 'b', 'subject' => 's']]);
        self::assertFalse($http->requests[0]->hasHeader('x-batch-validation'));
        self::assertSame('[{"from":"a","to":"b","subject":"s"}]', $http->bodies[0]);
        $zap->emails->sendBatch([['from' => 'a', 'to' => 'b', 'subject' => 's']], ['validation' => 'permissive', 'idempotency_key' => 'b-1']);
        self::assertSame('permissive', $http->header(1, 'x-batch-validation'));
        self::assertSame('b-1', $http->header(1, 'Idempotency-Key'));
    }

    public function testAnswersNullFor204(): void
    {
        $http = new MockHttpClient(new Response(204));
        $zap = $this->client($http);
        $zap->domains->delete('d1');
        self::assertSame(204, $zap->lastResponse()?->statusCode);
        self::assertSame('DELETE', $http->requests[0]->getMethod());
    }

    public function testKeepsABodyThatIsNotJsonAsText(): void
    {
        $zap = $this->client(new MockHttpClient(new Response(200, [], 'plain')));
        $usage = $zap->usage->get();
        self::assertSame([], $usage->toArray());
        self::assertSame('plain', $usage->getLastResponse()?->body);
    }

    public function testGivesTheStatusHeadersAndRequestId(): void
    {
        $zap = $this->client(new MockHttpClient(MockHttpClient::json(200, ['id' => 'e1'], ['RateLimit-Remaining' => '9'])));
        self::assertNull($zap->lastResponse());
        $email = $zap->emails->get('e1');
        $res = $email->getLastResponse();
        self::assertNotNull($res);
        self::assertSame(200, $res->statusCode);
        self::assertSame('req_1', $res->requestId);
        self::assertSame('9', $res->getHeader('ratelimit-remaining'));
        self::assertSame(['9'], $res->headers['ratelimit-remaining']);
        self::assertNull($res->getHeader('x-missing'));
        self::assertSame($res, $zap->lastResponse());
    }

    public function testUploadsAnImageFileAsMultipart(): void
    {
        $http = new MockHttpClient(MockHttpClient::json(201, ['id' => 'img']));
        $zap = $this->client($http);
        $dir = sys_get_temp_dir() . '/zap-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("$dir/logo.png", "\x89PNG");

        self::assertSame('img', $zap->templateAssets->uploadFile("$dir/logo.png", ['name' => 'Logo'])->id);
        $ct = $http->header(0, 'Content-Type');
        self::assertMatchesRegularExpression('/^multipart\/form-data; boundary=\S+$/', $ct);
        self::assertStringContainsString('name="file"; filename="Logo"', $http->bodies[0]);
        self::assertStringContainsString("\x89PNG", $http->bodies[0]);
        self::assertStringContainsString("name=\"name\"\r\nContent-Length: 4\r\n\r\nLogo", $http->bodies[0]);

        $zap->templateAssets->uploadFile("$dir/logo.png");
        self::assertStringContainsString('filename="logo.png"', $http->bodies[1]);
        self::assertStringNotContainsString('name="name"', $http->bodies[1]);

        $zap->templateAssets->uploadFile(Utils::streamFor('x'), ['filename' => 'a.gif', 'name' => 'A']);
        self::assertStringContainsString('filename="a.gif"', $http->bodies[2]);
        $fh = fopen('php://memory', 'r+');
        self::assertNotFalse($fh);
        fwrite($fh, 'xy');
        rewind($fh);
        $zap->templateAssets->uploadFile($fh);
        self::assertStringContainsString('filename="image"', $http->bodies[3]);
        unlink("$dir/logo.png");
        rmdir($dir);
    }

    public function testRefusesAnUploadItCannotRead(): void
    {
        $zap = $this->client(new MockHttpClient());
        try {
            $zap->templateAssets->uploadFile('/no/such/file.png');
            self::fail('no exception');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Cannot read the file /no/such/file.png', $e->getMessage());
        }
        $this->expectException(\InvalidArgumentException::class);
        /** @phpstan-ignore argument.type */
        $zap->templateAssets->uploadFile(42);
    }

    public function testRetriesAMultipartUploadOn429WithTheWholeBody(): void
    {
        $http = new MockHttpClient(MockHttpClient::apiError(429, 'rate_limit_exceeded', ['Retry-After' => '0']), MockHttpClient::json(201, ['id' => 'img']));
        $this->client($http)->templateAssets->uploadFile(Utils::streamFor('bytes'), ['name' => 'a.png']);
        self::assertCount(2, $http->bodies);
        self::assertSame($http->bodies[0], $http->bodies[1]);
        self::assertStringContainsString('bytes', $http->bodies[1]);
    }

    public function testGivesGuzzleThePerRequestTimeout(): void
    {
        $seen = [];
        $mock = new MockHandler([
            function (RequestInterface $r, array $o) use (&$seen) {
                $seen[] = $o;

                return new Response(200, [], '{"id":"e1"}');
            },
            function (RequestInterface $r, array $o) use (&$seen) {
                $seen[] = $o;

                return new Response(500, [], '{}');
            },
        ]);
        $zap = new Client('k', ['http_client' => new Guzzle(['handler' => HandlerStack::create($mock)]), 'timeout' => 7, 'max_retries' => 0]);
        self::assertSame('e1', $zap->emails->get('e1')->id);
        self::assertSame(7.0, $seen[0]['timeout']);
        self::assertFalse($seen[0]['allow_redirects']);
        $this->expectException(\ZapSMTP\Exception\ServerException::class);
        try {
            $zap->emails->get('e1', ['timeout' => 1.5]);
        } finally {
            self::assertSame(1.5, $seen[1]['timeout']);
        }
    }

    public function testKeepsTheKeyOutOfDumps(): void
    {
        $zap = new Client('zap_secret_key');
        self::assertStringNotContainsString('zap_secret_key', print_r($zap, true));
        self::assertSame(['baseUrl' => 'https://api.zapsmtp.com/v1', 'timeout' => 60.0, 'maxRetries' => 2], $zap->__debugInfo());
    }

    public function testDecodesModelsWithTypedProperties(): void
    {
        $zap = $this->client(new MockHttpClient(MockHttpClient::json(200, [
            'object' => 'email', 'id' => 'e1', 'to' => ['a@x.test'], 'test' => false, 'metadata' => null,
            'events' => [['status' => 'delivered', 'attempts' => 2.0, 'response_code' => 'x', 'extra' => 1]], 'new_field' => 'kept',
        ])));
        $email = $zap->emails->get('e1');
        self::assertInstanceOf(Email::class, $email);
        self::assertSame(['a@x.test'], $email->to);
        self::assertFalse($email->test);
        self::assertNull($email->metadata);
        self::assertNotNull($email->events);
        self::assertSame('delivered', $email->events[0]->status);
        self::assertSame(2, $email->events[0]->attempts);
        self::assertNull($email->events[0]->response_code);
        self::assertSame(1, $email->events[0]['extra']);
        self::assertSame('kept', $email['new_field']);
        /** @phpstan-ignore property.notFound (unknown fields read through __get) */
        self::assertSame('kept', $email->new_field);
        self::assertTrue(isset($email->new_field));
        self::assertNull($email->subject);
    }

    public function testRefusesIdsThatAreNotAPathSegment(): void
    {
        $http = new MockHttpClient(MockHttpClient::json(200, []));
        $zap = $this->client($http);
        foreach ([['', 'id must not be empty'], ['.', 'id must not be "."'], ['..', 'id must not be ".."']] as [$id, $message]) {
            try {
                $zap->emails->get($id);
                self::fail('no exception');
            } catch (\ZapSMTP\Exception\ValidationException $e) {
                self::assertSame($message, $e->getMessage());
                self::assertSame('invalid_parameter', $e->getErrorCode());
                self::assertSame(0, $e->getStatusCode());
            }
        }
        try {
            $zap->templates->versions->get('t1', '..');
            self::fail('no exception');
        } catch (\ZapSMTP\Exception\ValidationException $e) {
            self::assertSame('vid must not be ".."', $e->getMessage());
        }
        self::assertCount(0, $http->requests);
        $zap->emails->get('...');
        self::assertSame('https://api.test/v1/emails/...', $http->url(0));
    }

    public function testSendsEmptyMapsAsObjectsAndEmptyListsAsArrays(): void
    {
        $http = new MockHttpClient(MockHttpClient::json(200, ['id' => 'e1']));
        $zap = $this->client($http);
        /** @phpstan-ignore argument.type (an attachment without its fields) */
        $zap->emails->send(['to' => 'b@y.test', 'headers' => [], 'metadata' => [], 'tags' => [], 'attachments' => [[]],
            'tracking' => [], 'template' => ['id' => 't1', 'variables' => []], 'cc' => []]);
        self::assertSame('{"to":"b@y.test","headers":{},"metadata":{},"tags":[],"attachments":[{}],"tracking":{},"template":{"id":"t1","variables":{}},"cc":[]}', $http->bodies[0]);
        /** @phpstan-ignore argument.type (an empty email) */
        $zap->emails->sendBatch([['to' => 'b', 'metadata' => []], []]);
        self::assertSame('[{"to":"b","metadata":{}},{}]', $http->bodies[1]);
        $zap->emails->sendBatch([]);
        self::assertSame('[]', $http->bodies[2]);
        /** @phpstan-ignore argument.type (stdClass and null pass through as they are) */
        $zap->emails->send(['to' => 'b', 'headers' => new \stdClass(), 'tracking' => null]);
        self::assertSame('{"to":"b","headers":{},"tracking":null}', $http->bodies[3]);
    }

    public function testRefusesAnEmptyIdempotencyKeyBeforeSending(): void
    {
        $http = new MockHttpClient(MockHttpClient::json(200, ['id' => 'e1']));
        $zap = $this->client($http);
        $cases = [
            [['idempotency_key' => '']],
            [['idempotency_key' => "  \t"]],
            [['headers' => ['idempotency-key' => ' ']]],
            [['headers' => ['IDEMPOTENCY-KEY' => '']]],
        ];
        foreach ($cases as [$options]) {
            try {
                $zap->emails->send(['to' => 'b@y.test'], $options);
                self::fail('no exception');
            } catch (\ZapSMTP\Exception\ValidationException $e) {
                self::assertSame('idempotency_key must not be empty', $e->getMessage());
                self::assertSame('invalid_parameter', $e->getErrorCode());
            }
        }
        try {
            $this->client($http, ['default_headers' => ['Idempotency-Key' => '']])->usage->get();
            self::fail('no exception');
        } catch (\ZapSMTP\Exception\ValidationException $e) {
            self::assertSame('idempotency_key must not be empty', $e->getMessage());
        }
        self::assertCount(0, $http->requests);
        // a caller's header replaces the SDK's own, whatever its case
        $zap->emails->send(['to' => 'b@y.test'], ['headers' => ['idempotency-KEY' => 'mine']]);
        self::assertSame(['mine'], $http->requests[0]->getHeader('Idempotency-Key'));
    }
}

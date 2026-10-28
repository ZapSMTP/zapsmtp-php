<?php

declare(strict_types=1);

namespace ZapSMTP\Tests;

use ZapSMTP\ApiObject;
use ZapSMTP\Model\Email;
use ZapSMTP\Model\EmailEvent;

final class ApiObjectTest extends TestCase
{
    public function testConvertsWhatFitsAndLeavesTheRestNull(): void
    {
        $e = new EmailEvent(['attempts' => 2.0, 'response_code' => 1.5, 'status' => 5, 'type' => null]);
        self::assertSame(2, $e->attempts);
        self::assertNull($e->response_code);
        self::assertNull($e->status);
        self::assertNull($e->type);
        self::assertSame(['attempts' => 2.0, 'response_code' => 1.5, 'status' => 5, 'type' => null], $e->toArray());

        $m = new Email([
            'to' => ['a' => 'x'],           // a map where a list is due
            'metadata' => ['k' => 1],        // a value of another type
            'events' => [['status' => 'ok'], 'oops'],
            'recipients' => 'none',
            'test' => 'yes',
        ]);
        self::assertNull($m->to);
        self::assertNull($m->metadata);
        self::assertNull($m->events);
        self::assertNull($m->recipients);
        self::assertNull($m->test);
        $ok = new Email(['metadata' => ['k' => 'v'], 'recipients' => [null]]);
        self::assertSame(['k' => 'v'], $ok->metadata);
        self::assertSame([null], $ok->recipients);
    }

    public function testDecodesFloatsNestedModelsAndAnyJson(): void
    {
        $obj = new class (['n' => 3, 'f' => 'x', 'any' => ['deep' => [1]], 'm' => ['a' => [1, 2]], 'r' => ['status' => 's'], 'w' => 1]) extends ApiObject {
            protected const FIELDS = ['n' => 'float', 'f' => 'float', 'any' => 'mixed', 'm' => ['map', ['list', 'int']], 'r' => EmailEvent::class, 'w' => 'nope'];

            public ?float $n = null;

            public ?float $f = null;

            public mixed $any = null;

            /** @var array<string, list<int>>|null */
            public ?array $m = null;

            public ?EmailEvent $r = null;

            public mixed $w = null;
        };
        self::assertSame(3.0, $obj->n);
        self::assertNull($obj->f);
        self::assertSame(['deep' => [1]], $obj->any);
        self::assertSame(['a' => [1, 2]], $obj->m);
        self::assertSame('s', $obj->r?->status);
        self::assertNull($obj->w);
        self::assertNotNull($obj->r);
        self::assertNull($obj->r->getLastResponse());
    }

    public function testIsAReadOnlyArray(): void
    {
        $e = new EmailEvent(['status' => 'ok', 'extra' => ['a' => 1]]);
        self::assertTrue(isset($e['status']));
        self::assertFalse(isset($e['missing']));
        self::assertNull($e['missing']);
        self::assertSame(['a' => 1], $e['extra']);
        self::assertSame('{"status":"ok","extra":{"a":1}}', json_encode($e));
        self::assertSame('{}', json_encode(new EmailEvent()));
        self::assertSame([], (new EmailEvent('not an object'))->toArray());
        /** @phpstan-ignore property.notFound (unknown fields read through __get) */
        self::assertNull($e->not_a_field);
        self::assertFalse(isset($e->not_a_field));
        try {
            $e['status'] = 'x';
            self::fail('no exception');
        } catch (\LogicException $x) {
            self::assertSame(EmailEvent::class . ' is read-only', $x->getMessage());
        }
        $this->expectException(\LogicException::class);
        unset($e['status']);
    }

    public function testUnknownKeysNeverOverwriteKnownFields(): void
    {
        $e = new EmailEvent(['status' => 'ok', 'rawValues' => 'x', 'rawResponse' => 'y', 'FIELDS' => 'z']);
        self::assertSame('ok', $e->status);
        self::assertSame(['status' => 'ok', 'rawValues' => 'x', 'rawResponse' => 'y', 'FIELDS' => 'z'], $e->toArray());
        self::assertNull($e->getLastResponse());
    }

    public function testANonFiniteNumberBecomesNull(): void
    {
        $zap = $this->client(new \ZapSMTP\Tests\Support\MockHttpClient(new \GuzzleHttp\Psr7\Response(200, [], '{"attempts":1e999,"response_code":-1e999,"status":"ok","extra":1e999}')));
        self::assertSame(['attempts' => null, 'response_code' => null, 'status' => 'ok', 'extra' => null], $zap->usage->get()->toArray());
        $event = new EmailEvent(['attempts' => INF, 'response_code' => -INF, 'status' => 'ok']);
        self::assertNull($event->attempts);
        self::assertNull($event->response_code);
        self::assertSame('ok', $event->status);
        $obj = new class (['any' => NAN, 'f' => INF]) extends \ZapSMTP\ApiObject {
            protected const FIELDS = ['any' => 'mixed', 'f' => 'float'];

            public mixed $any = 'unset';

            public ?float $f = 1.0;
        };
        self::assertNull($obj->any);
        self::assertNull($obj->f);
    }
}

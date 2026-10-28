<?php

declare(strict_types=1);

namespace ZapSMTP\Tests;

use GuzzleHttp\Psr7\Response;
use ZapSMTP\Model\Email;
use ZapSMTP\Page;
use ZapSMTP\Tests\Support\MockHttpClient;

final class PagingTest extends TestCase
{
    /**
     * @param list<string|array<string, mixed>> $items ids, or whole items
     * @param array<string, mixed> $extra
     */
    private static function page(array $items, bool $more, array $extra = []): Response
    {
        $data = array_map(static fn ($i) => \is_string($i) ? ['id' => $i] : $i, $items);

        return MockHttpClient::json(200, ['object' => 'list', 'has_more' => $more, 'data' => $data, ...$extra]);
    }

    public function testWalksEveryPageWithBeforeTheLastIdKeepingTheFilters(): void
    {
        $http = new MockHttpClient(self::page(['a', 'b'], true), self::page(['c'], false));
        $ids = [];
        foreach ($this->client($http)->emails->list(['limit' => 2, 'after' => '2026-01-01T00:00:00Z']) as $i => $email) {
            self::assertInstanceOf(Email::class, $email);
            $ids[$i] = $email->id;
        }
        self::assertSame(['a', 'b', 'c'], $ids);
        self::assertSame('https://api.test/v1/emails?limit=2&after=2026-01-01T00%3A00%3A00Z&before=b', $http->url(1));
    }

    public function testWalksSuppressionsWithNextCursor(): void
    {
        $http = new MockHttpClient(self::page(['a'], true, ['next_cursor' => 'cur1']), self::page(['b'], true, ['next_cursor' => null]));
        $ids = [];
        foreach ($this->client($http)->suppressions->list()->autoPagingIterator() as $s) {
            $ids[] = $s['id'];
        }
        self::assertSame(['a', 'b'], $ids);
        self::assertSame('https://api.test/v1/suppressions?after=cur1', $http->url(1));
        self::assertCount(2, $http->requests);
    }

    public function testGivesOnePage(): void
    {
        $http = new MockHttpClient(self::page(['a'], true, ['next_cursor' => 'c1']));
        $first = $this->client($http)->emails->list();
        self::assertInstanceOf(Page::class, $first);
        self::assertSame([['id' => 'a']], array_map(static fn ($e) => $e->toArray(), $first->data));
        self::assertTrue($first->hasNextPage());
        self::assertTrue($first->hasMore);
        self::assertSame('c1', $first->nextCursor);
        self::assertSame('list', $first->toArray()['object']);
        self::assertSame($first->toArray(), $first->jsonSerialize());
        self::assertSame(200, $first->getLastResponse()?->statusCode);
        self::assertCount(1, $http->requests);
    }

    public function testStopsWhereNoNextPageCanBeAskedFor(): void
    {
        $noId = $this->client(new MockHttpClient(MockHttpClient::json(200, ['object' => 'list', 'has_more' => true, 'data' => [[]]])))->emails->list();
        self::assertFalse($noId->hasNextPage());
        self::assertNull($noId->getNextPage());
        $unpaged = $this->client(new MockHttpClient(self::page(['a'], true)))->domains->list();
        self::assertNull($unpaged->getNextPage());
        $last = $this->client(new MockHttpClient(self::page(['a'], false)))->emails->list();
        self::assertNull($last->getNextPage());
        $empty = $this->client(new MockHttpClient(MockHttpClient::json(200, ['object' => 'list'])))->emails->list();
        self::assertSame([], $empty->data);
        self::assertFalse($empty->hasMore);
        self::assertNull($empty->nextCursor);
        $notJson = $this->client(new MockHttpClient(new Response(200, [], 'oops')))->emails->list();
        self::assertSame([], $notJson->data);
        $noCursor = $this->client(new MockHttpClient(self::page(['a'], true)))->suppressions->list();
        self::assertFalse($noCursor->hasNextPage());
    }

    public function testHasNextPageOnlyInspectsThePage(): void
    {
        $http = new MockHttpClient(self::page(['email-1'], true));
        $page = $this->client($http)->emails->list();
        self::assertTrue($page->hasNextPage());
        self::assertTrue($page->hasNextPage());
        self::assertCount(1, $http->requests);
    }

    public function testConversationPagingUsesTheLastActivityTimeAndTheThreadId(): void
    {
        $last = ['id' => '019aaa00-0000-7000-8000-000000000001', 'last_message_at' => '2026-10-28T08:00:00Z'];
        $http = new MockHttpClient(self::page([$last], true));
        $page = $this->client($http)->threads->list(['limit' => 1, 'unread' => true]);
        $page->getNextPage();
        $q = $http->query(1);
        self::assertSame($last['last_message_at'], $q['before']);
        self::assertSame($last['id'], $q['before_id']);
        self::assertSame('true', $q['unread']);
        $bare = $this->client(new MockHttpClient(self::page(['x'], true)))->threads->list();
        self::assertFalse($bare->hasNextPage());
    }

    public function testReceivedAttachmentsPageForwardAfterTheLastId(): void
    {
        $http = new MockHttpClient(self::page(['a1', 'a2'], true));
        $this->client($http)->emails->receiving->attachments->list('m1', ['limit' => 2])->getNextPage();
        self::assertSame('a2', $http->query(1)['after']);
        self::assertSame('https://api.test/v1/emails/receiving/m1/attachments?limit=2&after=a2', $http->url(1));
        self::assertFalse($this->client(new MockHttpClient(self::page([], true)))->emails->receiving->attachments->list('m1')->hasNextPage());
    }

    public function testLogsPageBackWithBeforeOrForwardAfterTheNewestEntry(): void
    {
        $back = new MockHttpClient(self::page(['l3', 'l2'], true));
        $this->client($back)->logs->list()->getNextPage();
        self::assertSame('l2', $back->query(1)['before']);
        $fwd = new MockHttpClient(self::page(['l9', 'l8'], true));
        $this->client($fwd)->logs->list(['after' => 'l7'])->getNextPage();
        self::assertSame('l9', $fwd->query(1)['after']);
        self::assertArrayNotHasKey('before', $fwd->query(1));
        self::assertFalse($this->client(new MockHttpClient(self::page([], true)))->logs->list(['after' => 'l7'])->hasNextPage());
        self::assertFalse($this->client(new MockHttpClient(self::page([], true)))->logs->list()->hasNextPage());
        self::assertFalse($this->client(new MockHttpClient(self::page([], true)))->emails->receiving->list()->hasNextPage());
    }
}

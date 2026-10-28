<?php

declare(strict_types=1);

namespace ZapSMTP;

/**
 * One page of a list.
 *
 * $page->data holds this page's items. Iterating the page (foreach) walks
 * every item of every page, asking for the next page as it goes:
 *
 *     foreach ($zap->emails->list(['limit' => 100]) as $email) { ... }
 *
 * @template T of ApiObject
 *
 * @implements \IteratorAggregate<int, T>
 */
final class Page implements \IteratorAggregate, \JsonSerializable
{
    /** @var list<T> this page's items */
    public readonly array $data;

    /** Whether the API has more items after this page. */
    public readonly bool $hasMore;

    /** The cursor of the next page, for lists that page by cursor. */
    public readonly ?string $nextCursor;

    /** @var array<array-key, mixed> */
    private readonly array $raw;

    /**
     * @internal
     *
     * @param list<T> $data
     * @param array<array-key, mixed> $raw the decoded body
     * @param (\Closure(): self<T>)|null $next loads the next page; null after the last one
     */
    public function __construct(
        array $data,
        array $raw,
        private readonly ?\Closure $next,
        private readonly ?ApiResponse $response,
    ) {
        $this->data = $data;
        $this->raw = $raw;
        $this->hasMore = ($raw['has_more'] ?? false) === true;
        $this->nextCursor = isset($raw['next_cursor']) && \is_string($raw['next_cursor']) ? $raw['next_cursor'] : null;
    }

    /** Whether there is a next page to ask for. Makes no request. */
    public function hasNextPage(): bool
    {
        return $this->next !== null;
    }

    /**
     * The next page, or null after the last one.
     *
     * @return self<T>|null
     *
     * @throws Exception\ZapSMTPException
     */
    public function getNextPage(): ?self
    {
        return $this->next === null ? null : ($this->next)();
    }

    /**
     * Every item of this page and the pages after it.
     *
     * @return \Generator<int, T>
     *
     * @throws Exception\ZapSMTPException
     */
    public function autoPagingIterator(): \Generator
    {
        $page = $this;
        $i = 0;
        while ($page !== null) {
            foreach ($page->data as $item) {
                yield $i++ => $item;
            }
            $page = $page->getNextPage();
        }
    }

    /** @return \Generator<int, T> */
    public function getIterator(): \Generator
    {
        return $this->autoPagingIterator();
    }

    /** The answer this page came in (status, headers, request id). */
    public function getLastResponse(): ?ApiResponse
    {
        return $this->response;
    }

    /**
     * The page's JSON as received: object, data, has_more, next_cursor.
     *
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }

    public function jsonSerialize(): mixed
    {
        return $this->raw;
    }
}

<?php

declare(strict_types=1);

namespace ZapSMTP\Internal;

use GuzzleHttp\Psr7\MultipartStream;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;
use ZapSMTP\ApiObject;
use ZapSMTP\Exception\ValidationException;
use ZapSMTP\Exception\ZapSMTPException;
use ZapSMTP\Page;

/**
 * What every generated service shares: sending a call and decoding its answer.
 *
 * @internal
 */
abstract class BaseService
{
    public function __construct(protected readonly Transport $transport)
    {
    }

    /**
     * Sends a call whose answer is one object.
     *
     * @template T of ApiObject
     *
     * @param class-string<T> $model
     * @param array<array-key, mixed> $options
     *
     * @return T
     *
     * @throws ZapSMTPException
     */
    protected function requestObject(string $model, Request $req, array $options): ApiObject
    {
        [$data, $response] = $this->transport->send($req, $options);

        return new $model($data, $response);
    }

    /**
     * Sends a call that answers with no content (204).
     *
     * @param array<array-key, mixed> $options
     *
     * @throws ZapSMTPException
     */
    protected function requestNone(Request $req, array $options): void
    {
        $this->transport->send($req, $options);
    }

    /**
     * Sends a list call and returns its first page.
     *
     * @template T of ApiObject
     *
     * @param class-string<T> $model
     * @param array<array-key, mixed> $options
     * @param string|null $paging how the list asks for its next page (sdks/spec/names.json)
     *
     * @return Page<T>
     *
     * @throws ZapSMTPException
     */
    protected function requestPage(string $model, Request $req, array $options, ?string $paging): Page
    {
        [$body, $response] = $this->transport->send($req, $options);
        $raw = \is_array($body) ? $body : [];
        $items = [];
        $rawItems = \is_array($raw['data'] ?? null) ? array_values($raw['data']) : [];
        foreach ($rawItems as $item) {
            $items[] = new $model($item);
        }
        $nextReq = ($raw['has_more'] ?? false) === true ? self::nextPage($req, $raw, $rawItems, $paging) : null;
        $next = $nextReq === null ? null : fn (): Page => $this->requestPage($model, $nextReq, $options, $paging);

        return new Page($items, $raw, $next, $response);
    }

    /**
     * The request for the page after this one, or null when none can be asked for.
     *
     * @param array<array-key, mixed> $raw
     * @param list<mixed> $items
     */
    private static function nextPage(Request $req, array $raw, array $items, ?string $paging): ?Request
    {
        $field = static function (mixed $item, string $name): ?string {
            $v = \is_array($item) ? ($item[$name] ?? null) : null;

            return \is_string($v) && $v !== '' ? $v : null;
        };
        $first = $items[0] ?? null;
        $last = $items === [] ? null : $items[\count($items) - 1];
        $lastId = $field($last, 'id');
        $next = static fn (array $extra): Request => $req->withQuery($extra);

        switch ($paging) {
            case 'cursor':
                $cursor = $field($raw, 'next_cursor');

                return $cursor === null ? null : $next(['after' => $cursor]);
            case 'before':
                return $lastId === null ? null : $next(['before' => $lastId]);
            case 'after':
                return $lastId === null ? null : $next(['after' => $lastId]);
            case 'logs':
                // with after, logs come oldest-first from the server and
                // newest-first in the page: the next newer page starts after
                // the page's newest entry
                if (isset($req->query['after'])) {
                    $firstId = $field($first, 'id');

                    return $firstId === null ? null : $next(['after' => $firstId]);
                }

                return $lastId === null ? null : $next(['before' => $lastId]);
            case 'thread':
                $at = $field($last, 'last_message_at');

                return $lastId === null || $at === null ? null : $next(['before' => $at, 'before_id' => $lastId]);
            default:
                return null;
        }
    }

    /**
     * A multipart/form-data upload of one file.
     *
     * @param mixed $file a file path, an open stream resource or a PSR-7 stream
     * @param array<array-key, mixed> $params name, filename
     *
     * @throws \InvalidArgumentException the file cannot be read
     */
    protected function multipartRequest(string $method, string $path, string $field, mixed $file, array $params): Request
    {
        $name = isset($params['name']) && \is_string($params['name']) ? $params['name'] : null;
        $filename = isset($params['filename']) && \is_string($params['filename']) ? $params['filename'] : $name;
        if (\is_string($file)) {
            if (!is_file($file) || !is_readable($file)) {
                throw new \InvalidArgumentException("Cannot read the file {$file}");
            }
            $filename ??= basename($file);
            $stream = Utils::streamFor(Utils::tryFopen($file, 'rb'));
        } elseif ($file instanceof StreamInterface || \is_resource($file)) {
            $stream = Utils::streamFor($file);
            if (!$stream->isSeekable()) {
                // buffered once, so a retry (after a 429) sends the whole file again
                $buffer = Utils::streamFor(Utils::tryFopen('php://temp', 'r+'));
                Utils::copyToStream($stream, $buffer);
                $buffer->rewind();
                $stream = $buffer;
            }
        } else {
            throw new \InvalidArgumentException('The file must be a path, a stream resource or a PSR-7 stream');
        }
        $parts = [['name' => $field, 'contents' => $stream, 'filename' => $filename ?? 'image']];
        if ($name !== null) {
            $parts[] = ['name' => 'name', 'contents' => $name];
        }
        $body = new MultipartStream($parts);

        return new Request($method, $path, multipart: $body, boundary: $body->getBoundary());
    }

    /**
     * A path segment, escaped. An empty id, "." or ".." would address
     * another resource (URLs normalize %2E%2E too), so it is refused here.
     *
     * @throws ValidationException
     */
    protected static function seg(string $name, string $value): string
    {
        if ($value === '' || $value === '.' || $value === '..') {
            throw new ValidationException(sprintf('%s must not be %s', $name, $value === '' ? 'empty' : '"' . $value . '"'), errorCode: 'invalid_parameter');
        }

        return rawurlencode($value);
    }
}

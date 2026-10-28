<?php

declare(strict_types=1);

namespace ZapSMTP;

/**
 * Builds the attachments of an email: content is sent base64-encoded, and the
 * content type follows the file name's extension unless given.
 *
 *     $zap->emails->send([..., 'attachments' => [
 *         Attachment::fromFile('/path/invoice.pdf'),
 *         Attachment::fromString('logo.png', $bytes, ['content_id' => 'logo']), // <img src="cid:logo">
 *     ]]);
 */
final class Attachment
{
    private const TYPES = [
        'pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'txt' => 'text/plain', 'csv' => 'text/csv', 'html' => 'text/html',
        'ics' => 'text/calendar', 'json' => 'application/json', 'xml' => 'application/xml', 'zip' => 'application/zip',
        'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    /**
     * An attachment from bytes or text.
     *
     * @param array{content_type?: string, content_id?: string} $options content_id makes it inline (cid:)
     *
     * @return array{filename: string, content: string, content_type?: string, content_id?: string}
     */
    public static function fromString(string $filename, string $content, array $options = []): array
    {
        $a = ['filename' => $filename, 'content' => base64_encode($content)];
        $type = $options['content_type'] ?? self::TYPES[strtolower(pathinfo($filename, \PATHINFO_EXTENSION))] ?? null;
        if ($type !== null) {
            $a['content_type'] = $type;
        }
        if (isset($options['content_id'])) {
            $a['content_id'] = $options['content_id'];
        }

        return $a;
    }

    /**
     * An attachment read from a file.
     *
     * @param array{filename?: string, content_type?: string, content_id?: string} $options filename defaults to the
     *        file's base name
     *
     * @return array{filename: string, content: string, content_type?: string, content_id?: string}
     *
     * @throws \InvalidArgumentException the file cannot be read
     */
    public static function fromFile(string $path, array $options = []): array
    {
        $content = is_file($path) && is_readable($path) ? file_get_contents($path) : false;
        if ($content === false) {
            throw new \InvalidArgumentException("Cannot read the file {$path}");
        }
        $filename = $options['filename'] ?? basename($path);
        unset($options['filename']);

        return self::fromString($filename, $content, $options);
    }
}

<?php

declare(strict_types=1);

namespace ZapSMTP\Tests;

use ZapSMTP\Attachment;

final class AttachmentTest extends TestCase
{
    public function testEncodesBytesAndTextAndGuessesTheContentType(): void
    {
        self::assertSame(['filename' => 'a.pdf', 'content' => 'AQI=', 'content_type' => 'application/pdf'], Attachment::fromString('a.pdf', "\x01\x02"));
        self::assertSame(
            ['filename' => 'notes', 'content' => 'aGk=', 'content_type' => 'text/plain', 'content_id' => 'n1'],
            Attachment::fromString('notes', 'hi', ['content_type' => 'text/plain', 'content_id' => 'n1']),
        );
        self::assertArrayNotHasKey('content_type', Attachment::fromString('x.unknown', "\0"));
    }

    public function testReadsAFile(): void
    {
        $path = sys_get_temp_dir() . '/Report-' . bin2hex(random_bytes(4)) . '.PNG';
        file_put_contents($path, "\x89\x50");
        try {
            self::assertSame(['filename' => basename($path), 'content' => 'iVA=', 'content_type' => 'image/png'], Attachment::fromFile($path));
            self::assertSame('r.png', Attachment::fromFile($path, ['filename' => 'r.png'])['filename']);
            self::assertSame('logo', Attachment::fromFile($path, ['content_id' => 'logo'])['content_id'] ?? null);
        } finally {
            unlink($path);
        }
        $this->expectException(\InvalidArgumentException::class);
        Attachment::fromFile('/no/such/file.pdf');
    }
}

<?php

declare(strict_types=1);

namespace ZapSMTP\Tests;

use ZapSMTP\Client;
use ZapSMTP\Exception\ConnectionException;

/**
 * The default (Guzzle) client against a real socket: the timeout must also
 * bound a server that sends its headers and then stalls mid-body.
 */
final class StallingServerTest extends TestCase
{
    public function testTheTimeoutCoversAStalledBody(): void
    {
        $script = <<<'PHP'
            $s = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
            fwrite(STDOUT, stream_socket_get_name($s, false) . "\n");
            fflush(STDOUT);
            $c = stream_socket_accept($s, 10);
            fread($c, 65536);
            fwrite($c, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 100\r\n\r\n{");
            fflush($c);
            sleep(5);
            PHP;
        $proc = proc_open([\PHP_BINARY, '-r', $script], [1 => ['pipe', 'w']], $pipes);
        self::assertIsResource($proc);
        $addr = trim((string) fgets($pipes[1]));
        try {
            $zap = new Client('k', ['base_url' => "http://$addr/v1", 'timeout' => 0.5, 'max_retries' => 0]);
            $t = microtime(true);
            try {
                $zap->emails->get('e1');
                self::fail('no exception');
            } catch (ConnectionException $e) {
                self::assertLessThan(3.0, microtime(true) - $t);
                self::assertNotNull($e->getPrevious());
            }
        } finally {
            proc_terminate($proc);
            proc_close($proc);
        }
    }
}

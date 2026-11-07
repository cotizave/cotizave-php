<?php

declare(strict_types=1);

// Test fixture: a loopback server that sends 200 + headers with a large
// Content-Length, then trickles the body one byte per second and never
// completes it. Used to prove the streamed body read is bounded by the
// request timeout instead of hanging.
$port = (int) ($argv[1] ?? 9700);
$srv = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
if (!$srv) {
    fwrite(STDERR, "bind fail: {$errstr}\n");
    exit(1);
}
fwrite(STDOUT, "READY {$port}\n");
fflush(STDOUT);
$conn = @stream_socket_accept($srv, 20);
if (!$conn) {
    exit(0);
}
@fread($conn, 4096);
@fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 100000\r\n\r\n");
@fflush($conn);
for ($i = 0; $i < 30; $i++) {
    if (@fwrite($conn, ' ') === false) {
        break;
    }
    @fflush($conn);
    sleep(1);
}
@fclose($conn);

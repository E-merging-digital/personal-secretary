<?php

declare(strict_types=1);

$port = isset($argv[1]) ? (int) $argv[1] : 0;
$transcript = $argv[2] ?? '';
if ($port <= 0 || $transcript === '') {
  fwrite(STDERR, "Usage: php smtp_no_starttls.php <port> <transcript>\n");
  exit(64);
}

$server = stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $error);
if ($server === FALSE) {
  fwrite(STDERR, "Unable to start SMTP fixture: {$errno} {$error}\n");
  exit(70);
}

$client = @stream_socket_accept($server, 15);
if ($client === FALSE) {
  fclose($server);
  exit(75);
}

stream_set_timeout($client, 10);
fwrite($client, "220 ps126-no-starttls ESMTP\r\n");
$lines = [];
while (($line = fgets($client)) !== FALSE) {
  $command = rtrim($line, "\r\n");
  $lines[] = $command;
  $verb = strtoupper(strtok($command, ' ') ?: '');

  if ($verb === 'EHLO') {
    fwrite($client, "250-ps126-no-starttls\r\n250 SIZE 1048576\r\n");
    continue;
  }
  if ($verb === 'HELO') {
    fwrite($client, "250 ps126-no-starttls\r\n");
    continue;
  }
  if ($verb === 'STARTTLS') {
    fwrite($client, "454 TLS not available\r\n");
    break;
  }
  if ($verb === 'QUIT') {
    fwrite($client, "221 Bye\r\n");
    break;
  }

  fwrite($client, "550 Command rejected by test fixture\r\n");
}
file_put_contents(
  $transcript,
  $lines === [] ? '' : implode("\n", $lines) . "\n",
  LOCK_EX,
);

fclose($client);
fclose($server);

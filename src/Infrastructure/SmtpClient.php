<?php

declare(strict_types=1);

namespace App\Infrastructure;

use RuntimeException;

/**
 * Minimal SMTP client: implicit TLS ("ssl", usually port 465) or STARTTLS
 * ("tls", usually 587), optional AUTH LOGIN, one message per connection.
 */
final class SmtpClient
{
    private const TIMEOUT = 15;

    /** @var resource|null */
    private $socket = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $encryption = 'tls',
        private readonly string $username = '',
        private readonly string $password = '',
    ) {
    }

    /**
     * Sends a fully built message (headers + body, CRLF line endings).
     *
     * @param list<string> $recipients
     * @throws RuntimeException on any SMTP or connection error
     */
    public function send(string $from, array $recipients, string $message): void
    {
        try {
            $this->connect();
            $this->command('MAIL FROM:<' . $from . '>', [250]);
            foreach ($recipients as $recipient) {
                $this->command('RCPT TO:<' . $recipient . '>', [250, 251]);
            }
            $this->command('DATA', [354]);

            // Dot-stuffing: a line starting with "." gets a second one.
            $body = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $message)) ?? $message;
            $this->write(str_replace("\n", "\r\n", rtrim($body, "\n")) . "\r\n.\r\n");
            $this->expect([250]);
            $this->command('QUIT', [221]);
        } finally {
            $this->close();
        }
    }

    private function connect(): void
    {
        $scheme = $this->encryption === 'ssl' ? 'ssl' : 'tcp';
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $this->host]]);
        $socket = @stream_socket_client("{$scheme}://{$this->host}:{$this->port}", $errno, $error, self::TIMEOUT, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new RuntimeException("SMTP: could not connect to {$this->host}:{$this->port} ({$error})");
        }
        stream_set_timeout($socket, self::TIMEOUT);
        $this->socket = $socket;

        $this->expect([220]);
        $this->ehlo();

        if ($this->encryption === 'tls') {
            $this->command('STARTTLS', [220]);
            $method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
            if (stream_socket_enable_crypto($socket, true, $method) !== true) {
                throw new RuntimeException('SMTP: STARTTLS negotiation failed');
            }
            $this->ehlo();
        }

        if ($this->username !== '') {
            $this->command('AUTH LOGIN', [334]);
            $this->command(base64_encode($this->username), [334], 'username');
            $this->command(base64_encode($this->password), [235], 'password');
        }
    }

    private function ehlo(): void
    {
        $name = preg_replace('/[^A-Za-z0-9.-]/', '', (string) gethostname()) ?: 'localhost';
        $this->command('EHLO ' . $name, [250]);
    }

    /** @param list<int> $codes */
    private function command(string $line, array $codes, ?string $redacted = null): string
    {
        $this->write($line . "\r\n");

        return $this->expect($codes, $redacted ?? $line);
    }

    /** @param list<int> $codes */
    private function expect(array $codes, string $after = 'connect'): string
    {
        $response = '';
        while (($line = fgets($this->socket ?? throw new RuntimeException('SMTP: not connected'), 1024)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new RuntimeException('SMTP: unexpected reply to ' . strtok($after, ' ') . ': ' . trim($response ?: 'no response'));
        }

        return $response;
    }

    private function write(string $data): void
    {
        while ($data !== '') {
            $written = $this->socket !== null ? fwrite($this->socket, $data) : false;
            if ($written === false || $written === 0) {
                throw new RuntimeException('SMTP: connection lost');
            }
            $data = substr($data, $written);
        }
    }

    private function close(): void
    {
        if ($this->socket !== null) {
            fclose($this->socket);
            $this->socket = null;
        }
    }
}

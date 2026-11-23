<?php
declare(strict_types=1);

final class EmailDelivery
{
    public static function configured(): bool
    {
        $host = getenv('SMTP_HOST');
        return getenv('APP_ENV') !== 'test' && $host !== false && trim($host) !== '';
    }

    public static function send(string $to, string $subject, string $message): void
    {
        $from = getenv('SMTP_FROM');
        if ($from === false || $from === '') {
            $from = getenv('NEEN_MAIL_FROM');
        }
        if (($from === false || $from === '') && getenv('APP_ENV') === 'test' && !self::configured()) {
            $from = 'no-reply@example.com';
        }
        if ($from === false || filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Email sender is not configured.');
        }

        $host = getenv('APP_ENV') === 'test' ? false : getenv('SMTP_HOST');
        if ($host === false || trim($host) === '') {
            self::sendWithMail($to, $from, $subject, $message);
            return;
        }

        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n]/', $subject . $from . $to)) {
            throw new RuntimeException('Email message headers are invalid.');
        }

        $portValue = getenv('SMTP_PORT');
        $port = $portValue === false || $portValue === '' ? 587 : filter_var($portValue, FILTER_VALIDATE_INT);
        if (!is_int($port) || $port < 1 || $port > 65535) {
            throw new RuntimeException('SMTP port is invalid.');
        }
        $encryption = strtolower((string) (getenv('SMTP_ENCRYPTION') ?: 'tls'));
        if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
            throw new RuntimeException('SMTP encryption mode is invalid.');
        }
        if (!preg_match('/\A[A-Za-z0-9.-]+\z/', $host)) {
            throw new RuntimeException('SMTP host is invalid.');
        }
        $username = getenv('SMTP_USER');
        $password = getenv('SMTP_PASS');
        if (($username === false) !== ($password === false) || ($username !== false && $password === false)) {
            throw new RuntimeException('SMTP username and password must be configured together.');
        }

        $transport = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $host,
        ]]);
        $socket = stream_socket_client(
            $transport . $host . ':' . $port,
            $errorCode,
            $errorMessage,
            10,
            STREAM_CLIENT_CONNECT,
            $context,
        );
        if ($socket === false) {
            throw new RuntimeException('SMTP connection failed: ' . $errorMessage, $errorCode);
        }
        stream_set_timeout($socket, 10);

        try {
            self::expect($socket, [220]);
            self::command($socket, 'EHLO ' . self::clientName(), [250]);
            if ($encryption === 'tls') {
                self::command($socket, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('SMTP TLS negotiation failed.');
                }
                self::command($socket, 'EHLO ' . self::clientName(), [250]);
            }

            if ($username !== false && $username !== '') {
                self::command($socket, 'AUTH LOGIN', [334]);
                self::command($socket, base64_encode($username), [334]);
                self::command($socket, base64_encode((string) $password), [235]);
            }

            self::command($socket, 'MAIL FROM:<' . $from . '>', [250]);
            self::command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
            self::command($socket, 'DATA', [354]);
            $headers = [
                'From: ' . $from,
                'To: ' . $to,
                'Subject: ' . self::encodeHeader($subject),
                'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
            ];
            $body = preg_replace("/\r\n|\r|\n/", "\r\n", $message) ?? $message;
            $body = preg_replace('/^\./m', '..', $body) ?? $body;
            fwrite($socket, implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.\r\n");
            self::expect($socket, [250]);
            self::command($socket, 'QUIT', [221]);
        } finally {
            fclose($socket);
        }
    }

    private static function sendWithMail(string $to, string $from, string $subject, string $message): void
    {
        if (getenv('APP_ENV') === 'test') {
            $sink = getenv('NEEN_TEST_MAIL_FILE');
            if ($sink === false || $sink === '') {
                throw new RuntimeException('Test email sink is not configured.');
            }
            $handle = fopen($sink, 'ab');
            if ($handle === false) {
                throw new RuntimeException('Test email sink is unavailable.');
            }
            chmod($sink, 0600);
            try {
                if (fwrite($handle, json_encode([
                    'to' => $to,
                    'subject' => $subject,
                    'message' => $message,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL) === false) {
                    throw new RuntimeException('Test email could not be recorded.');
                }
            } finally {
                fclose($handle);
            }
            return;
        }

        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Email recipient is invalid.');
        }
        if (!mail($to, $subject, $message, "From: {$from}\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8")) {
            throw new RuntimeException('Email delivery failed.');
        }
    }

    private static function command($socket, string $command, array $codes): void
    {
        if (fwrite($socket, $command . "\r\n") === false) {
            throw new RuntimeException('SMTP command could not be sent.');
        }
        self::expect($socket, $codes);
    }

    private static function expect($socket, array $codes): void
    {
        $response = '';
        do {
            $line = fgets($socket, 515);
            if ($line === false) {
                throw new RuntimeException('SMTP server returned an incomplete response.');
            }
            $response .= $line;
        } while (strlen($line) >= 4 && $line[3] === '-');

        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new RuntimeException('SMTP server rejected a mail operation (' . $code . ').');
        }
    }

    private static function clientName(): string
    {
        $host = parse_url((string) (getenv('NEEN_APP_URL') ?: 'localhost'), PHP_URL_HOST);
        return is_string($host) && preg_match('/\A[A-Za-z0-9.-]+\z/', $host) ? $host : 'localhost';
    }

    private static function encodeHeader(string $value): string
    {
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }
        return $value;
    }
}

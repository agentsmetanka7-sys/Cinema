<?php

declare(strict_types=1);

namespace CinemaApp\Src;

final class Mailer
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly string $fromEmail,
        private readonly string $fromName,
        private readonly string $encryption,
        private readonly int $timeoutSeconds,
    ) {
    }

    public static function fromEnv(): self
    {
        $host = trim((string) (getenv('SMTP_HOST') ?: ''));
        $port = (int) (getenv('SMTP_PORT') ?: 587);
        $username = trim((string) (getenv('SMTP_USERNAME') ?: ''));
        $password = (string) (getenv('SMTP_PASSWORD') ?: '');
        $fromEmail = trim((string) (getenv('SMTP_FROM_EMAIL') ?: $username));
        $fromName = trim((string) (getenv('SMTP_FROM_NAME') ?: 'Кінотеатр'));
        $encryption = strtolower(trim((string) (getenv('SMTP_ENCRYPTION') ?: 'tls')));
        $timeout = (int) (getenv('SMTP_TIMEOUT') ?: 15);

        return new self($host, $port, $username, $password, $fromEmail, $fromName, $encryption, $timeout);
    }

    public function isConfigured(): bool
    {
        return $this->host !== '' && $this->fromEmail !== '';
    }

    /**
     * @param array<string, mixed> $booking
     */
    public function sendTicket(string $toEmail, string $toName, array $booking): bool
    {
        if (!$this->isConfigured()) {
            error_log('SMTP is not configured: set SMTP_HOST/SMTP_PORT/SMTP_USERNAME/SMTP_PASSWORD/SMTP_FROM_EMAIL');
            return false;
        }

        $subject = 'Ваш квиток — ' . (string) ($booking['movie_title_snapshot'] ?? 'Кінотеатр');

        $seatLabels = [];
        foreach (($booking['items'] ?? []) as $item) {
            $seatLabels[] = (string) ($item['seat_label'] ?? '');
        }

        $textBody =
            "Вітаємо, {$toName}!\n\n" .
            "Ваше бронювання підтверджено.\n" .
            'Код бронювання: ' . (string) ($booking['booking_code'] ?? '') . "\n" .
            'Фільм: ' . (string) ($booking['movie_title_snapshot'] ?? '') . "\n" .
            'Зал: ' . (string) ($booking['hall_name_snapshot'] ?? '') . "\n" .
            'Сеанс: ' . (string) ($booking['showtime_snapshot'] ?? '') . "\n" .
            'Місця: ' . implode(', ', array_filter($seatLabels)) . "\n" .
            'Сума: ' . number_format((float) ($booking['total_amount'] ?? 0), 2, '.', '') . " ₴\n\n" .
            "Дякуємо, що обрали Кінотеатр!";

        $htmlBody =
            '<h2>Ваш квиток підтверджено</h2>' .
            '<p>Вітаємо, <strong>' . $this->escape($toName) . '</strong>!</p>' .
            '<ul>' .
            '<li><strong>Код:</strong> ' . $this->escape((string) ($booking['booking_code'] ?? '')) . '</li>' .
            '<li><strong>Фільм:</strong> ' . $this->escape((string) ($booking['movie_title_snapshot'] ?? '')) . '</li>' .
            '<li><strong>Зал:</strong> ' . $this->escape((string) ($booking['hall_name_snapshot'] ?? '')) . '</li>' .
            '<li><strong>Сеанс:</strong> ' . $this->escape((string) ($booking['showtime_snapshot'] ?? '')) . '</li>' .
            '<li><strong>Місця:</strong> ' . $this->escape(implode(', ', array_filter($seatLabels))) . '</li>' .
            '<li><strong>Сума:</strong> ' . number_format((float) ($booking['total_amount'] ?? 0), 2, '.', '') . ' ₴</li>' .
            '</ul>' .
            '<p>Гарного перегляду!</p>';

        return $this->send($toEmail, $toName, $subject, $textBody, $htmlBody);
    }

    private function send(string $toEmail, string $toName, string $subject, string $textBody, string $htmlBody): bool
    {
        $transport = $this->encryption === 'ssl' ? 'ssl://' : '';
        $socket = @stream_socket_client(
            $transport . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            $this->timeoutSeconds,
            STREAM_CLIENT_CONNECT
        );

        if (!is_resource($socket)) {
            error_log('SMTP connection failed: ' . $errstr . ' (' . $errno . ')');
            return false;
        }

        stream_set_timeout($socket, $this->timeoutSeconds);

        try {
            $this->expect($socket, [220]);
            $this->cmd($socket, 'EHLO localhost', [250]);

            if ($this->encryption === 'tls') {
                $this->cmd($socket, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('Cannot enable TLS');
                }
                $this->cmd($socket, 'EHLO localhost', [250]);
            }

            if ($this->username !== '') {
                $this->cmd($socket, 'AUTH LOGIN', [334]);
                $this->cmd($socket, base64_encode($this->username), [334]);
                $this->cmd($socket, base64_encode($this->password), [235]);
            }

            $this->cmd($socket, 'MAIL FROM:<' . $this->fromEmail . '>', [250]);
            $this->cmd($socket, 'RCPT TO:<' . $toEmail . '>', [250, 251]);
            $this->cmd($socket, 'DATA', [354]);

            $boundary = 'bnd_' . bin2hex(random_bytes(8));
            $headers = [
                'Date: ' . date(DATE_RFC2822),
                'From: ' . $this->formatAddress($this->fromName, $this->fromEmail),
                'To: ' . $this->formatAddress($toName, $toEmail),
                'Subject: ' . $this->encodeHeader($subject),
                'MIME-Version: 1.0',
                'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            ];

            $message =
                implode("\r\n", $headers) . "\r\n\r\n" .
                '--' . $boundary . "\r\n" .
                "Content-Type: text/plain; charset=UTF-8\r\n" .
                "Content-Transfer-Encoding: 8bit\r\n\r\n" .
                $textBody . "\r\n\r\n" .
                '--' . $boundary . "\r\n" .
                "Content-Type: text/html; charset=UTF-8\r\n" .
                "Content-Transfer-Encoding: 8bit\r\n\r\n" .
                $htmlBody . "\r\n\r\n" .
                '--' . $boundary . '--\r\n';

            $message = preg_replace('/\n\.\n/', "\n..\n", $message) ?: $message;
            fwrite($socket, $message . "\r\n.\r\n");
            $this->expect($socket, [250]);
            $this->cmd($socket, 'QUIT', [221]);

            fclose($socket);
            return true;
        } catch (\Throwable $e) {
            error_log('SMTP send failed: ' . $e->getMessage());
            fclose($socket);
            return false;
        }
    }

    /** @param resource $socket */
    private function cmd($socket, string $command, array $codes): void
    {
        fwrite($socket, $command . "\r\n");
        $this->expect($socket, $codes);
    }

    /** @param resource $socket */
    private function expect($socket, array $codes): void
    {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }

        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new \RuntimeException('SMTP response ' . $code . ': ' . trim($response));
        }
    }

    private function formatAddress(string $name, string $email): string
    {
        $safeName = str_replace(['"', "\r", "\n"], '', $name);

        return $this->encodeHeader($safeName) . ' <' . $email . '>';
    }

    private function encodeHeader(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

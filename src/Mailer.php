<?php

declare(strict_types=1);

/**
 * Read an SMTP server response, handling multi-line 250- style responses.
 *
 * @param resource $socket
 */
function smtpRead($socket): string
{
    $response = '';

    while ($line = fgets($socket, 512)) {
        $response .= $line;

        // A line ending in " " after the code means continuation; a line
        // ending in the code + space means the last line of the response.
        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }

    return $response;
}

/**
 * Send a command to the SMTP server and return the response.
 *
 * @param resource $socket
 * @throws RuntimeException if the server returns a non-2xx / non-3xx code.
 */
function smtpCommand($socket, string $command): string
{
    fwrite($socket, $command . "\r\n");
    $response = smtpRead($socket);
    $code     = (int) substr($response, 0, 3);

    if ($code < 200 || $code >= 400) {
        throw new RuntimeException("SMTP error after '{$command}': {$response}");
    }

    return $response;
}

/**
 * Send a plain-text email via raw socket SMTP.
 *
 * @param array  $config  Full application config; uses $config['smtp'].
 * @param string $to      Recipient email address.
 * @param string $toName  Recipient display name.
 * @param string $subject Email subject.
 * @param string $body    Plain-text body (UTF-8).
 * @throws RuntimeException on connection or SMTP protocol failure.
 */
function sendMail(array $config, string $to, string $toName, string $subject, string $body): void
{
    $smtp   = $config['smtp'];
    $host   = (string) $smtp['host'];
    $port   = (int) $smtp['port'];
    $from   = (string) $smtp['from'];

    // Strip CR/LF from all header values to prevent email header injection.
    // Applied to $to as well, even though it should be a validated address,
    // as defence-in-depth per Security Auditor H-1.
    $fromName = str_replace(["\r", "\n"], ' ', (string) $smtp['from_name']);
    $toName   = str_replace(["\r", "\n"], ' ', $toName);
    $to       = str_replace(["\r", "\n"], ' ', $to);
    $subject  = str_replace(["\r", "\n"], ' ', $subject);

    $socket = stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 10);

    if ($socket === false) {
        throw new RuntimeException("SMTP connect failed: {$errstr} ({$errno})");
    }

    stream_set_timeout($socket, 15);

    // Read greeting.
    smtpRead($socket);

    // Handshake.
    smtpCommand($socket, 'EHLO asyncstandup');
    smtpCommand($socket, "MAIL FROM:<{$from}>");
    smtpCommand($socket, "RCPT TO:<{$to}>");
    smtpCommand($socket, 'DATA');

    // Headers.
    fwrite($socket, "From: {$fromName} <{$from}>\r\n");
    fwrite($socket, "To: {$toName} <{$to}>\r\n");
    fwrite($socket, "Subject: {$subject}\r\n");
    fwrite($socket, 'Date: ' . date('r') . "\r\n");
    fwrite($socket, "MIME-Version: 1.0\r\n");
    fwrite($socket, "Content-Type: text/plain; charset=UTF-8\r\n");
    fwrite($socket, "Content-Transfer-Encoding: 8bit\r\n");
    fwrite($socket, "\r\n");

    // Body — escape lines that start with a lone dot.
    $escapedBody = preg_replace('/^\.$/m', '..', $body);
    fwrite($socket, $escapedBody . "\r\n");

    // End of data.
    smtpCommand($socket, '.');
    smtpCommand($socket, 'QUIT');
    fclose($socket);
}

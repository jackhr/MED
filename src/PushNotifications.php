<?php

declare(strict_types=1);

final class PushNotifications
{
    public static function isConfigured(): bool
    {
        return self::publicKey() !== ''
            && self::privateKeyPem() !== ''
            && self::subject() !== '';
    }

    public static function publicKey(): string
    {
        return trim((string) Env::get('PUSH_VAPID_PUBLIC_KEY', ''));
    }

    public static function sendToEndpoint(string $endpoint): array
    {
        if (!self::isConfigured()) {
            return [
                'ok' => false,
                'status' => 0,
                'error' => 'Push credentials are not configured.',
                'transport' => null,
            ];
        }

        if (!self::isValidSubject(self::subject())) {
            return [
                'ok' => false,
                'status' => 0,
                'error' => 'PUSH_VAPID_SUBJECT must start with "mailto:" or "https://".',
                'transport' => null,
            ];
        }

        $audience = self::audienceFromEndpoint($endpoint);
        if ($audience === null) {
            return [
                'ok' => false,
                'status' => 0,
                'error' => 'Invalid push subscription endpoint.',
                'transport' => null,
            ];
        }

        $jwt = self::buildVapidJwt($audience);
        if ($jwt === null) {
            return [
                'ok' => false,
                'status' => 0,
                'error' => 'Unable to sign VAPID JWT.',
                'transport' => null,
            ];
        }

        $headers = [
            'TTL: 60',
            'Content-Length: 0',
            'Authorization: vapid t=' . $jwt . ', k=' . self::publicKey(),
            'Crypto-Key: p256ecdsa=' . self::publicKey(),
        ];

        if (
            function_exists('curl_init')
            && function_exists('curl_setopt')
            && function_exists('curl_exec')
            && defined('CURLOPT_POST')
        ) {
            return self::sendWithCurl($endpoint, $headers);
        }

        return self::sendWithHttpStream($endpoint, $headers);
    }

    private static function sendWithCurl(string $endpoint, array $headers): array
    {
        $curl = curl_init($endpoint);
        if ($curl === false) {
            return [
                'ok' => false,
                'status' => 0,
                'error' => 'Unable to initialize cURL.',
                'transport' => 'curl',
            ];
        }

        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_POSTFIELDS, '');
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_TIMEOUT, 12);

        $responseBody = curl_exec($curl);
        $curlError = curl_error($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        $ok = $statusCode >= 200 && $statusCode < 300;
        $error = $ok ? null : trim($curlError !== '' ? $curlError : (string) $responseBody);

        return [
            'ok' => $ok,
            'status' => $statusCode,
            'error' => $error,
            'transport' => 'curl',
        ];
    }

    private static function sendWithHttpStream(string $endpoint, array $headers): array
    {
        $allowUrlFopen = filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL);
        if ($allowUrlFopen !== true) {
            return [
                'ok' => false,
                'status' => 0,
                'error' => 'cURL is unavailable and allow_url_fopen is disabled.',
                'transport' => 'stream',
            ];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers) . "\r\n",
                'content' => '',
                'protocol_version' => 1.1,
                'ignore_errors' => true,
                'timeout' => 12,
            ],
        ]);

        $responseBody = @file_get_contents($endpoint, false, $context);
        $responseHeaders = $http_response_header ?? [];
        $statusCode = self::statusCodeFromHeaders(is_array($responseHeaders) ? $responseHeaders : []);
        $ok = $statusCode >= 200 && $statusCode < 300;

        if ($ok) {
            return [
                'ok' => true,
                'status' => $statusCode,
                'error' => null,
                'transport' => 'stream',
            ];
        }

        $errorMessage = '';
        if ($responseBody !== false && trim((string) $responseBody) !== '') {
            $errorMessage = trim((string) $responseBody);
        } else {
            $lastError = error_get_last();
            $errorMessage = is_array($lastError) && isset($lastError['message'])
                ? (string) $lastError['message']
                : '';
        }

        if ($errorMessage === '') {
            $errorMessage = $statusCode > 0
                ? 'Push request failed with status ' . $statusCode . '.'
                : 'Push request failed.';
        }

        return [
            'ok' => false,
            'status' => $statusCode,
            'error' => $errorMessage,
            'transport' => 'stream',
        ];
    }

    private static function statusCodeFromHeaders(array $headers): int
    {
        foreach ($headers as $headerLine) {
            if (!is_string($headerLine)) {
                continue;
            }

            if (preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d{3})\b/', $headerLine, $match) === 1) {
                return (int) $match[1];
            }
        }

        return 0;
    }

    private static function audienceFromEndpoint(string $endpoint): ?string
    {
        $parts = parse_url($endpoint);
        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;

        if (!is_string($scheme) || !is_string($host) || $scheme === '' || $host === '') {
            return null;
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if ($port !== null && $port > 0) {
            return strtolower($scheme) . '://' . strtolower($host) . ':' . $port;
        }

        return strtolower($scheme) . '://' . strtolower($host);
    }

    private static function buildVapidJwt(string $audience): ?string
    {
        $header = [
            'typ' => 'JWT',
            'alg' => 'ES256',
        ];
        $claims = [
            'aud' => $audience,
            'exp' => time() + 12 * 60 * 60,
            'sub' => self::subject(),
        ];

        $headerSegment = self::base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES));
        $claimsSegment = self::base64UrlEncode(json_encode($claims, JSON_UNESCAPED_SLASHES));
        $unsignedToken = $headerSegment . '.' . $claimsSegment;

        $privateKey = openssl_pkey_get_private(self::privateKeyPem());
        if ($privateKey === false) {
            return null;
        }

        $signatureDer = '';
        $signed = openssl_sign($unsignedToken, $signatureDer, $privateKey, OPENSSL_ALGO_SHA256);
        if (PHP_VERSION_ID < 80000) {
            openssl_pkey_free($privateKey);
        }

        if (!$signed) {
            return null;
        }

        $signatureRaw = self::derSignatureToRaw($signatureDer, 64);
        if ($signatureRaw === null) {
            return null;
        }

        return $unsignedToken . '.' . self::base64UrlEncode($signatureRaw);
    }

    private static function privateKeyPem(): string
    {
        $rawValue = (string) Env::get('PUSH_VAPID_PRIVATE_KEY', '');
        if ($rawValue === '') {
            return '';
        }

        return str_replace('\n', "\n", $rawValue);
    }

    private static function subject(): string
    {
        return trim((string) Env::get('PUSH_VAPID_SUBJECT', ''));
    }

    private static function isValidSubject(string $subject): bool
    {
        if ($subject === '') {
            return false;
        }

        return str_starts_with($subject, 'mailto:')
            || str_starts_with($subject, 'https://');
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function derSignatureToRaw(string $derSignature, int $targetLength): ?string
    {
        $sequence = unpack('C*', $derSignature);
        if (!is_array($sequence) || count($sequence) < 8) {
            return null;
        }

        $offset = 1;
        if (($sequence[$offset] ?? null) !== 0x30) {
            return null;
        }

        $offset += 2;
        if (($sequence[$offset] ?? null) !== 0x02) {
            return null;
        }

        $offset += 1;
        $rLength = (int) ($sequence[$offset] ?? 0);
        $offset += 1;
        $r = substr($derSignature, $offset - 1, $rLength);
        $offset += $rLength;

        if (($sequence[$offset] ?? null) !== 0x02) {
            return null;
        }

        $offset += 1;
        $sLength = (int) ($sequence[$offset] ?? 0);
        $offset += 1;
        $s = substr($derSignature, $offset - 1, $sLength);

        if ($r === false || $s === false) {
            return null;
        }

        $partLength = (int) ($targetLength / 2);
        $r = ltrim($r, "\x00");
        $s = ltrim($s, "\x00");

        if (strlen($r) > $partLength || strlen($s) > $partLength) {
            return null;
        }

        return str_pad($r, $partLength, "\x00", STR_PAD_LEFT)
            . str_pad($s, $partLength, "\x00", STR_PAD_LEFT);
    }
}

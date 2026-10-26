<?php
declare(strict_types=1);

namespace Nimby\Http;

use RuntimeException;

/**
 * HTTP client built on PHP's curl extension (available on IONOS shared hosting).
 * Retries transient failures (network errors, 5xx, 429) with a short back-off.
 */
final class CurlHttpClient implements HttpClient
{
    public function __construct(
        private int $timeoutSeconds = 60,
        private int $maxAttempts = 3,
        private string $userAgent = 'NIMBY planning monitor (nimby.parknews.co.uk)'
    ) {
    }

    public function getJson(string $url, array $params = []): array
    {
        $full = $params ? $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($params) : $url;
        return $this->request($full, null);
    }

    public function postJson(string $url, array $params): array
    {
        return $this->request($url, http_build_query($params));
    }

    private function request(string $url, ?string $body): array
    {
        $lastError = 'unknown error';
        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_TIMEOUT        => $this->timeoutSeconds,
                CURLOPT_USERAGENT      => $this->userAgent,
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
                CURLOPT_ENCODING       => '', // accept gzip
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }

            $response = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                $lastError = "network error: {$curlError}";
            } elseif ($status >= 500 || $status === 429) {
                $lastError = "HTTP {$status}";
            } elseif ($status >= 400) {
                throw new RuntimeException("HTTP {$status} from {$this->describe($url)}");
            } else {
                $decoded = json_decode((string) $response, true);
                if (!is_array($decoded)) {
                    throw new RuntimeException("Response from {$this->describe($url)} was not JSON");
                }
                return $decoded;
            }

            if ($attempt < $this->maxAttempts) {
                sleep(5 * $attempt);
            }
        }
        throw new RuntimeException("Request to {$this->describe($url)} failed after {$this->maxAttempts} attempts ({$lastError})");
    }

    private function describe(string $url): string
    {
        return strtok($url, '?') ?: $url;
    }
}

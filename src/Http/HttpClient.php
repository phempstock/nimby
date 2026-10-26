<?php
declare(strict_types=1);

namespace Nimby\Http;

/**
 * Minimal HTTP interface so the council client can be tested without the network.
 */
interface HttpClient
{
    /** GET a URL with query parameters and decode the JSON response. */
    public function getJson(string $url, array $params = []): array;

    /** POST form-encoded parameters and decode the JSON response. */
    public function postJson(string $url, array $params): array;
}

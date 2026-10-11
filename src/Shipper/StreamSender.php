<?php

namespace Watchman\Shipper;

/** Fallback when ext-curl is missing. */
final class StreamSender implements HttpSender
{
    public function post(string $url, array $headers, string $body, int $timeoutSeconds): array
    {
        $lines = '';
        foreach ($headers as $name => $value) {
            $lines .= $name.': '.$value."\r\n";
        }

        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => $lines,
            'content' => $body,
            'timeout' => $timeoutSeconds,
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);

        $http_response_header = [];
        $response = @file_get_contents($url, false, $context);

        $status = 0;
        foreach ($http_response_header as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $status = (int) $m[1];
            }
        }

        $error = $response === false ? (error_get_last()['message'] ?? 'request failed') : null;

        return ['status' => $status, 'body' => is_string($response) ? $response : '', 'error' => $error];
    }
}

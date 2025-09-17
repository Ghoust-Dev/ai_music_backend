<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class TopMediaiBaseService
{
    protected string $apiKey;
    protected string $baseUrl;
    protected int $timeout;
    protected int $retryAttempts;
    protected int $retryDelay;

    public function __construct()
    {
        $this->apiKey = config('topmediai.api_key');
        $this->baseUrl = config('topmediai.base_url');
        $this->timeout = config('topmediai.timeout', 30);
        $this->retryAttempts = config('topmediai.retry_attempts', 3);
        $this->retryDelay = config('topmediai.retry_delay', 1000);

        if (empty($this->apiKey)) {
            throw new Exception('TopMediai API key not configured');
        }
    }

    /**
     * Make HTTP request to TopMediai API
     */
    protected function makeRequest(string $method, string $endpoint, array $data = []): array
    {
        $url = $this->baseUrl . $endpoint;
        
        Log::info('TopMediai API Request', [
            'method' => $method,
            'url' => $url,
            'data' => $data
        ]);

        $response = $this->executeRequest($method, $url, $data);
        
        if ($response->failed()) {
            $this->handleApiError($response, $endpoint);
        }

        $responseData = $response->json();
        
        Log::info('TopMediai API Response', [
            'endpoint' => $endpoint,
            'status' => $response->status(),
            'response' => $responseData
        ]);

        return $responseData;
    }

    /**
     * Execute HTTP request with retry logic
     */
    protected function executeRequest(string $method, string $url, array $data): Response
    {
        $attempt = 0;
        
        while ($attempt < $this->retryAttempts) {
            try {
                $response = Http::timeout($this->timeout)
                    ->withHeaders([
                        'x-api-key' => $this->apiKey,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ])
                    ->send($method, $url, [
                        'json' => $data
                    ]);

                // If successful or non-retryable error, return immediately
                if ($response->successful() || !$this->shouldRetry($response)) {
                    return $response;
                }

            } catch (Exception $e) {
                Log::error('TopMediai API Request Exception', [
                    'attempt' => $attempt + 1,
                    'error' => $e->getMessage(),
                    'url' => $url
                ]);

                if ($attempt === $this->retryAttempts - 1) {
                    throw $e;
                }
            }

            $attempt++;
            if ($attempt < $this->retryAttempts) {
                usleep($this->retryDelay * 1000); // Convert to microseconds
                Log::info('Retrying TopMediai API request', [
                    'attempt' => $attempt + 1,
                    'url' => $url
                ]);
            }
        }

        throw new Exception('Max retry attempts exceeded for TopMediai API');
    }

    /**
     * Determine if request should be retried
     */
    protected function shouldRetry(Response $response): bool
    {
        $status = $response->status();
        
        // Retry on server errors and rate limiting
        return in_array($status, [429, 500, 502, 503, 504]);
    }

    /**
     * Handle API errors
     */
    protected function handleApiError(Response $response, string $endpoint): void
    {
        $status = $response->status();
        $body = $response->body();
        
        Log::error('TopMediai API Error', [
            'endpoint' => $endpoint,
            'status' => $status,
            'response' => $body
        ]);

        switch ($status) {
            case 401:
                throw new Exception('TopMediai API: Unauthorized - Check API key');
            case 403:
                throw new Exception('TopMediai API: Forbidden - Insufficient permissions');
            case 404:
                throw new Exception('TopMediai API: Endpoint not found');
            case 422:
                throw new Exception('TopMediai API: Validation error - ' . $body);
            case 429:
                throw new Exception('TopMediai API: Rate limit exceeded');
            case 500:
                throw new Exception('TopMediai API: Internal server error');
            default:
                throw new Exception("TopMediai API: HTTP {$status} - {$body}");
        }
    }

    /**
     * GET request helper
     */
    protected function get(string $endpoint, array $params = []): array
    {
        $url = $endpoint;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        
        return $this->makeRequest('GET', $url);
    }

    /**
     * POST request helper
     */
    protected function post(string $endpoint, array $data = []): array
    {
        return $this->makeRequest('POST', $endpoint, $data);
    }

    /**
     * Validate required fields in request data
     */
    protected function validateRequired(array $data, array $required): void
    {
        foreach ($required as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                throw new Exception("Required field '{$field}' is missing or empty");
            }
        }
    }

    /**
     * Format endpoint URL with parameters
     */
    protected function formatEndpoint(string $endpoint, array $params = []): string
    {
        foreach ($params as $key => $value) {
            $endpoint = str_replace('{' . $key . '}', $value, $endpoint);
        }
        
        return $endpoint;
    }
}
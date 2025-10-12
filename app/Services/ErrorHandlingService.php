<?php

namespace App\Services;

use App\Models\GeneratedContent;
use App\Models\GenerationRequest;
use App\Models\Generation;
use App\Jobs\CheckTaskStatusJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Exception;

class ErrorHandlingService
{
    /**
     * Map TopMediai error codes to user-friendly messages
     */
    protected array $errorMessages = [
        // Balance & Quota Errors
        400015 => [
            'user_message' => 'Service temporarily busy. Please try again in a few minutes.',
            'display_message' => 'Server is currently busy processing other requests. Your song will be ready soon!',
            'retry_after' => 300, // 5 minutes
            'category' => 'quota',
            'retry_recommended' => true
        ],
        
        // Rate Limiting
        429 => [
            'user_message' => 'Too many requests. Please wait a moment and try again.',
            'display_message' => 'You\'re generating songs faster than we can process! Take a short break.',
            'retry_after' => 120, // 2 minutes
            'category' => 'rate_limit',
            'retry_recommended' => true
        ],
        
        // Server Errors
        500 => [
            'user_message' => 'Our music generator is having a moment. Please try again.',
            'display_message' => 'The AI is being creative! Sometimes it needs a moment to think.',
            'retry_after' => 180, // 3 minutes
            'category' => 'server_error',
            'retry_recommended' => true
        ],
        
        502 => [
            'user_message' => 'Service temporarily unavailable. Please try again shortly.',
            'display_message' => 'The music studio is briefly offline. We\'ll be back in a moment!',
            'retry_after' => 180, // 3 minutes
            'category' => 'server_error',
            'retry_recommended' => true
        ],
        
        503 => [
            'user_message' => 'Service is currently busy. Please try again in a few minutes.',
            'display_message' => 'High demand right now! Your musical masterpiece is worth the wait.',
            'retry_after' => 240, // 4 minutes
            'category' => 'server_error',
            'retry_recommended' => true
        ],
        
        // Authentication Errors
        401 => [
            'user_message' => 'Authentication error. Please restart the app.',
            'display_message' => 'Oops! Please restart the app to refresh your session.',
            'retry_after' => 60, // 1 minute
            'category' => 'auth_error',
            'retry_recommended' => false
        ],
        
        // Bad Request
        400 => [
            'user_message' => 'Invalid request. Please check your input and try again.',
            'display_message' => 'Something seems off with your song request. Please try different settings.',
            'retry_after' => 60, // 1 minute
            'category' => 'validation_error',
            'retry_recommended' => false
        ],
        
        // Not Found
        404 => [
            'user_message' => 'Service endpoint not found.',
            'display_message' => 'The music service seems to be updating. Please try again shortly.',
            'retry_after' => 120, // 2 minutes
            'category' => 'service_error',
            'retry_recommended' => true
        ]
    ];

    /**
     * Handle TopMediai API errors with user-friendly responses
     */
    public function handleTopMediaiError(int $statusCode, array $response = [], string $context = 'generation'): array
    {
        $errorInfo = $this->getErrorInfo($statusCode);
        
        // Log technical details for debugging
        $this->logError($statusCode, $response, $context, $errorInfo);
        
        // Track error frequency for monitoring
        $this->trackErrorFrequency($statusCode, $context);
        
        return [
            'success' => false,
            'message' => $errorInfo['user_message'],
            'display_message' => $errorInfo['display_message'],
            'error_code' => $this->generateErrorCode($statusCode, $context),
            'retry_after' => $errorInfo['retry_after'],
            'retry_recommended' => $errorInfo['retry_recommended'],
            'category' => $errorInfo['category'],
            'support_message' => $this->getSupportMessage($errorInfo['category']),
            'timestamp' => now()->toISOString()
        ];
    }

    /**
     * Handle general exceptions with context-aware messages
     */
    public function handleException(Exception $exception, string $context = 'general', array $additionalData = []): array
    {
        $errorCode = $this->generateErrorCode(0, $context);
        
        // Determine if this is a network/connection error
        $isNetworkError = $this->isNetworkException($exception);
        
        if ($isNetworkError) {
            $userMessage = 'Connection issue. Please check your internet and try again.';
            $displayMessage = 'Looks like there\'s a connection hiccup. Check your internet and give it another shot!';
            $retryAfter = 60;
            $category = 'network_error';
        } else {
            $userMessage = 'Something unexpected happened. Please try again.';
            $displayMessage = 'The app hit a small snag. These things happen - please try again!';
            $retryAfter = 120;
            $category = 'system_error';
        }

        // Log exception details
        Log::error('Exception in ' . $context, [
            'error_code' => $errorCode,
            'exception_type' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'context' => $context,
            'additional_data' => $additionalData,
            'trace' => $exception->getTraceAsString()
        ]);

        return [
            'success' => false,
            'message' => $userMessage,
            'display_message' => $displayMessage,
            'error_code' => $errorCode,
            'retry_after' => $retryAfter,
            'retry_recommended' => true,
            'category' => $category,
            'support_message' => $this->getSupportMessage($category),
            'timestamp' => now()->toISOString()
        ];
    }

    /**
     * Handle task status check errors
     */
    public function handleStatusCheckError(string $taskId, Exception $exception, int $retryCount = 0): array
    {
        $errorCode = $this->generateErrorCode(0, 'status_check');
        
        Log::warning('Task status check failed', [
            'task_id' => $taskId,
            'retry_count' => $retryCount,
            'error' => $exception->getMessage(),
            'error_code' => $errorCode
        ]);

        if ($retryCount < 3) {
            return [
                'success' => false,
                'message' => 'Status check temporarily failed, will retry automatically.',
                'should_retry' => true,
                'retry_delay' => $this->calculateStatusCheckRetryDelay($retryCount),
                'category' => 'status_check_retry'
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Unable to check song status right now. Please try manually.',
                'user_message' => 'We\'re having trouble checking your song. Try the refresh button!',
                'should_retry' => false,
                'category' => 'status_check_failed'
            ];
        }
    }

    /**
     * Create standardized error response for task timeout
     */
    public function handleTaskTimeout(string $taskId, int $totalAttempts, int $totalMinutes): array
    {
        $errorCode = $this->generateErrorCode(408, 'timeout');
        
        Log::warning('Task timed out', [
            'task_id' => $taskId,
            'total_attempts' => $totalAttempts,
            'total_minutes' => $totalMinutes,
            'error_code' => $errorCode
        ]);

        return [
            'success' => false,
            'message' => 'Song generation took longer than expected and was cancelled.',
            'display_message' => 'This song was taking a really long time to create. Try generating a new one!',
            'error_code' => $errorCode,
            'retry_after' => 120,
            'retry_recommended' => true,
            'category' => 'timeout',
            'support_message' => 'If this keeps happening, try simpler prompts or different settings.',
            'timeout_info' => [
                'total_attempts' => $totalAttempts,
                'total_minutes' => $totalMinutes,
                'max_allowed_minutes' => 45
            ]
        ];
    }

    /**
     * Retry failed generation with exponential backoff
     */
    public function scheduleRetry(string $taskId, array $originalParams, int $retryCount = 1): array
    {
        $maxRetries = 3;
        
        if ($retryCount > $maxRetries) {
            return [
                'success' => false,
                'message' => 'Maximum retry attempts reached.',
                'retry_scheduled' => false
            ];
        }

        $retryDelay = $this->calculateRetryDelay($retryCount);
        
        try {
            // Schedule retry with exponential backoff
            CheckTaskStatusJob::dispatch($taskId, 15, 0)
                ->delay(now()->addSeconds($retryDelay));
            
            Log::info('Retry scheduled for failed task', [
                'task_id' => $taskId,
                'retry_count' => $retryCount,
                'delay_seconds' => $retryDelay,
                'scheduled_for' => now()->addSeconds($retryDelay)->toISOString()
            ]);

            return [
                'success' => true,
                'message' => 'Retry scheduled successfully.',
                'retry_scheduled' => true,
                'retry_delay' => $retryDelay,
                'retry_count' => $retryCount
            ];

        } catch (Exception $e) {
            Log::error('Failed to schedule retry', [
                'task_id' => $taskId,
                'retry_count' => $retryCount,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to schedule retry.',
                'retry_scheduled' => false
            ];
        }
    }

    /**
     * Get error information by status code
     */
    protected function getErrorInfo(int $statusCode): array
    {
        if (isset($this->errorMessages[$statusCode])) {
            return $this->errorMessages[$statusCode];
        }

        // Default error for unknown status codes
        return [
            'user_message' => 'Can you try again',
            'display_message' => 'Something unexpected happened. Give it another try!',
            'retry_after' => 120,
            'category' => 'unknown_error',
            'retry_recommended' => true
        ];
    }

    /**
     * Generate unique error codes for tracking
     */
    protected function generateErrorCode(int $statusCode, string $context): string
    {
        $timestamp = now()->format('ymdHi'); // YYMMDDHHII
        $random = substr(md5(uniqid()), 0, 4);
        
        return "ERR_{$statusCode}_{$context}_{$timestamp}_{$random}";
    }

    /**
     * Log error details for monitoring and debugging
     */
    protected function logError(int $statusCode, array $response, string $context, array $errorInfo): void
    {
        Log::warning('TopMediai API error handled', [
            'status_code' => $statusCode,
            'context' => $context,
            'category' => $errorInfo['category'],
            'user_message' => $errorInfo['user_message'],
            'retry_after' => $errorInfo['retry_after'],
            'response_data' => $response,
            'timestamp' => now()->toISOString()
        ]);
    }

    /**
     * Track error frequency for monitoring
     */
    protected function trackErrorFrequency(int $statusCode, string $context): void
    {
        $key = "error_count_{$statusCode}_{$context}_" . now()->format('Y-m-d-H');
        $count = Cache::increment($key, 1);
        
        // Set expiry for 2 hours if this is the first increment
        if ($count === 1) {
            Cache::put($key, 1, now()->addHours(2));
        }

        // Alert if error frequency is high (more than 10 in an hour)
        if ($count > 10) {
            Log::alert('High error frequency detected', [
                'status_code' => $statusCode,
                'context' => $context,
                'count_this_hour' => $count,
                'hour' => now()->format('Y-m-d H:00')
            ]);
        }
    }

    /**
     * Get context-appropriate support messages
     */
    protected function getSupportMessage(string $category): string
    {
        return match($category) {
            'quota' => 'If this keeps happening, the service might be experiencing high demand.',
            'rate_limit' => 'Take a short break between generations for the best experience.',
            'server_error' => 'These issues usually resolve quickly. Try again in a few minutes.',
            'auth_error' => 'Restarting the app usually fixes authentication issues.',
            'validation_error' => 'Try different song settings or simpler prompts.',
            'network_error' => 'Check your internet connection and try again.',
            'timeout' => 'Complex songs sometimes take longer. Try simpler prompts.',
            default => 'If this problem continues, try restarting the app.'
        };
    }

    /**
     * Check if exception is network-related
     */
    protected function isNetworkException(Exception $exception): bool
    {
        $networkKeywords = [
            'connection', 'timeout', 'network', 'dns', 'host', 'curl', 'socket',
            'ssl', 'certificate', 'unreachable', 'refused'
        ];
        
        $message = strtolower($exception->getMessage());
        
        foreach ($networkKeywords as $keyword) {
            if (strpos($message, $keyword) !== false) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Calculate retry delay with exponential backoff
     */
    protected function calculateRetryDelay(int $retryCount): int
    {
        // Exponential backoff: 30s, 60s, 120s
        return min(30 * pow(2, $retryCount - 1), 120);
    }

    /**
     * Calculate status check retry delay
     */
    protected function calculateStatusCheckRetryDelay(int $retryCount): int
    {
        return match($retryCount) {
            0 => 30,  // 30 seconds
            1 => 60,  // 1 minute
            2 => 120, // 2 minutes
            default => 180 // 3 minutes
        };
    }

    /**
     * Get error statistics for monitoring
     */
    public function getErrorStatistics(string $timeframe = '24h'): array
    {
        $pattern = match($timeframe) {
            '1h' => 'error_count_*_' . now()->format('Y-m-d-H'),
            '24h' => 'error_count_*_' . now()->format('Y-m-d') . '*',
            default => 'error_count_*'
        };

        // This would require implementing cache key scanning
        // For now, return a placeholder structure
        return [
            'timeframe' => $timeframe,
            'total_errors' => 0,
            'by_status_code' => [],
            'by_category' => [],
            'high_frequency_alerts' => []
        ];
    }
}

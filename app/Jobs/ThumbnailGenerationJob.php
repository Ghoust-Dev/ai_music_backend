<?php

namespace App\Jobs;

use App\Models\GeneratedContent;
use App\Services\ThumbnailGenerationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Exception;

class ThumbnailGenerationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    public int $tries = 5; // Retry until success
    public int $maxExceptions = 5;
    public int $timeout = 60;
    
    private int $contentId;
    private string $musicPrompt;
    private ?string $genre;
    private ?string $mood;
    private int $taskIndex;
    
    /**
     * Create a new job instance.
     */
    public function __construct(int $contentId, string $musicPrompt, ?string $genre = null, ?string $mood = null, int $taskIndex = 1)
    {
        $this->contentId = $contentId;
        $this->musicPrompt = $musicPrompt;
        $this->genre = $genre;
        $this->mood = $mood;
        $this->taskIndex = $taskIndex;
        
        // Start with high priority queue for thumbnails
        $this->onQueue('thumbnails');
    }
    
    /**
     * Execute the job.
     */
    public function handle(ThumbnailGenerationService $thumbnailService): void
    {
        $content = GeneratedContent::find($this->contentId);
        
        if (!$content) {
            Log::warning('Thumbnail job: GeneratedContent not found', [
                'content_id' => $this->contentId
            ]);
            return;
        }
        
        // Skip if already completed
        if ($content->thumbnail_generation_status === 'completed') {
            Log::info('Thumbnail job: Already completed', [
                'content_id' => $this->contentId,
                'custom_thumbnail_url' => $content->custom_thumbnail_url
            ]);
            return;
        }
        
        // Update status to processing
        $content->update([
            'thumbnail_generation_status' => 'processing',
            'thumbnail_retry_count' => $this->attempts()
        ]);
        
        Log::info('Thumbnail generation started', [
            'content_id' => $this->contentId,
            'attempt' => $this->attempts(),
            'max_tries' => $this->tries,
            'prompt' => $this->musicPrompt,
            'genre' => $this->genre,
            'mood' => $this->mood,
            'task_index' => $this->taskIndex
        ]);
        
        try {
            $result = $thumbnailService->generateThumbnail(
                $this->musicPrompt,
                $this->genre,
                $this->mood,
                $this->taskIndex
            );
            
            if ($result['success']) {
                $content->update([
                    'custom_thumbnail_url' => $result['image_url'],
                    'thumbnail_generation_status' => 'completed',
                    'thumbnail_prompt_used' => $result['prompt_used'],
                    'thumbnail_completed_at' => now()
                ]);
                
                Log::info('Thumbnail generation completed successfully', [
                    'content_id' => $this->contentId,
                    'image_url' => $result['image_url'],
                    'cost' => $result['cost'] ?? 'unknown',
                    'attempts_used' => $this->attempts(),
                    'model' => $result['model'] ?? 'unknown'
                ]);
                
            } else {
                throw new Exception($result['error'] ?? 'Unknown error from ThumbnailGenerationService');
            }
            
        } catch (Exception $e) {
            Log::error('Thumbnail generation attempt failed', [
                'content_id' => $this->contentId,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
                'max_tries' => $this->tries,
                'will_retry' => $this->attempts() < $this->tries
            ]);
            
            // Update retry count
            $content->update([
                'thumbnail_retry_count' => $this->attempts()
            ]);
            
            // If this is the final attempt, mark as failed
            if ($this->attempts() >= $this->tries) {
                $content->update([
                    'thumbnail_generation_status' => 'failed'
                ]);
                
                Log::error('Thumbnail generation permanently failed', [
                    'content_id' => $this->contentId,
                    'total_attempts' => $this->attempts(),
                    'final_error' => $e->getMessage(),
                    'music_prompt' => $this->musicPrompt
                ]);
            }
            
            throw $e; // Re-throw to trigger retry mechanism
        }
    }
    
    /**
     * Calculate delay between retries (exponential backoff)
     */
    public function backoff(): array
    {
        return [
            5,   // 5 seconds after 1st failure
            15,  // 15 seconds after 2nd failure  
            45,  // 45 seconds after 3rd failure
            120, // 2 minutes after 4th failure
            300  // 5 minutes after 5th failure
        ];
    }
    
    /**
     * Handle job failure after all retries exhausted
     */
    public function failed(Exception $exception): void
    {
        Log::error('Thumbnail generation job permanently failed', [
            'content_id' => $this->contentId,
            'exception' => $exception->getMessage(),
            'attempts' => $this->attempts(),
            'music_prompt' => $this->musicPrompt,
            'genre' => $this->genre,
            'mood' => $this->mood
        ]);
        
        // Ensure database is updated to failed status
        if ($content = GeneratedContent::find($this->contentId)) {
            $content->update([
                'thumbnail_generation_status' => 'failed',
                'thumbnail_retry_count' => $this->attempts()
            ]);
            
            Log::info('Thumbnail status updated to failed in database', [
                'content_id' => $this->contentId
            ]);
        }
    }
}
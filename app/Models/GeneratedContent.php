<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeneratedContent extends Model
{
    use HasFactory;

    protected $table = 'generated_content';

    protected $fillable = [
        'user_id',
        'title',
        'content_type',
        'topmediai_task_id',
        'status',
        'prompt',
        'mood',
        'genre',
        'instruments',
        'language',
        'duration',
        'content_url',
        'thumbnail_url',
        'download_url',
        'preview_url',
        'metadata',
        'started_at',
        'completed_at',
        'error_message',
        'retry_count',
        'is_premium_generation',
        'last_accessed_at',
    ];

    protected $casts = [
        'instruments' => 'array',
        'metadata' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'last_accessed_at' => 'datetime',
        'is_premium_generation' => 'boolean',
    ];

    /**
     * Get the user that owns this content
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(AiMusicUser::class, 'user_id');
    }

    /**
     * Get the generation request for this content
     */
    public function generationRequest(): BelongsTo
    {
        return $this->belongsTo(GenerationRequest::class, 'topmediai_task_id', 'topmediai_task_id');
    }

    /**
     * Check if content is completed
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if content has failed
     */
    public function hasFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Check if content is still processing
     */
    public function isProcessing(): bool
    {
        return in_array($this->status, ['pending', 'processing']);
    }

    /**
     * Check if content has URLs available
     */
    public function hasUrls(): bool
    {
        return !empty($this->content_url) || !empty($this->download_url);
    }

    /**
     * Get content for download
     */
    public function getDownloadUrl(): ?string
    {
        return $this->download_url ?? $this->content_url;
    }

    /**
     * Get content for streaming/preview
     */
    public function getStreamUrl(): ?string
    {
        return $this->preview_url ?? $this->content_url;
    }

    /**
     * Get formatted duration
     */
    public function getFormattedDuration(): string
    {
        if (!$this->duration) {
            return '0:00';
        }

        $minutes = floor($this->duration / 60);
        $seconds = $this->duration % 60;

        return sprintf('%d:%02d', $minutes, $seconds);
    }

    /**
     * Get file size from metadata
     */
    public function getFileSize(): ?string
    {
        return $this->metadata['file_size'] ?? null;
    }

    /**
     * Get audio format from metadata
     */
    public function getFormat(): string
    {
        return $this->metadata['format'] ?? 'mp3';
    }

    /**
     * Mark content as accessed
     */
    public function markAsAccessed(): void
    {
        $this->update(['last_accessed_at' => now()]);
    }

    /**
     * Scope: Filter by content type
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('content_type', $type);
    }

    /**
     * Scope: Filter by status
     */
    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope: Filter completed content
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope: Filter processing content
     */
    public function scopeProcessing($query)
    {
        return $query->whereIn('status', ['pending', 'processing']);
    }

    /**
     * Scope: Filter by user
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope: Recent content
     */
    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /**
     * Scope: Premium content only
     */
    public function scopePremium($query)
    {
        return $query->where('is_premium_generation', true);
    }

    /**
     * Scope: Free content only
     */
    public function scopeFree($query)
    {
        return $query->where('is_premium_generation', false);
    }

    /**
     * Get lyrics text for lyrics content
     */
    public function getLyricsText(): ?string
    {
        if ($this->content_type !== 'lyrics') {
            return null;
        }

        return $this->metadata['lyrics_text'] ?? null;
    }

    /**
     * Get word count for lyrics
     */
    public function getWordCount(): int
    {
        if ($this->content_type !== 'lyrics') {
            return 0;
        }

        return $this->metadata['word_count'] ?? 0;
    }

    /**
     * Get voice configuration for vocal content
     */
    public function getVoiceConfig(): array
    {
        if ($this->content_type !== 'vocal') {
            return [];
        }

        return $this->metadata['voice_config'] ?? [];
    }

    /**
     * Get conversion information
     */
    public function getConversionInfo(): array
    {
        return [
            'conversion_type' => $this->metadata['conversion_type'] ?? null,
            'original_audio_url' => $this->metadata['original_audio_url'] ?? null,
            'original_content_id' => $this->metadata['original_content_id'] ?? null,
        ];
    }

    /**
     * Check if this is a converted content
     */
    public function isConversion(): bool
    {
        return isset($this->metadata['conversion_type']);
    }

    /**
     * Get the original content if this is a conversion
     */
    public function originalContent(): ?self
    {
        $originalId = $this->metadata['original_content_id'] ?? null;
        
        if (!$originalId) {
            return null;
        }

        return self::find($originalId);
    }

    /**
     * Get all conversions of this content
     */
    public function conversions()
    {
        return self::whereJsonContains('metadata->original_content_id', $this->id);
    }

    /**
     * Generate share data for this content
     */
    public function getShareData(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'type' => $this->content_type,
            'duration' => $this->getFormattedDuration(),
            'genre' => $this->genre,
            'mood' => $this->mood,
            'created_at' => $this->created_at->format('Y-m-d'),
        ];
    }
}
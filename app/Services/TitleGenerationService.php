<?php

namespace App\Services;

class TitleGenerationService
{
    /**
     * Generate intelligent title based on mode and parameters
     */
    public function generateTitle(array $params, string $mode, int $taskIndex = 1): string
    {
        // Priority 1: Use custom music_name if provided
        if (!empty($params['music_name'])) {
            return $this->formatTitle($params['music_name'], $taskIndex);
        }

        // Priority 2: Generate smart title based on mode
        $baseTitle = match($mode) {
            'text_to_song' => $this->generateTextToSongTitle($params),
            'lyrics_to_song' => $this->generateLyricsToSongTitle($params),
            'instrumental' => $this->generateInstrumentalTitle($params),
            default => $this->generateFallbackTitle($params)
        };

        return $this->formatTitle($baseTitle, $taskIndex);
    }

    /**
     * Generate title for text-to-song mode
     */
    protected function generateTextToSongTitle(array $params): string
    {
        $prompt = $params['prompt'] ?? '';
        
        // Extract meaningful keywords from prompt
        $keywords = $this->extractKeywords($prompt);
        
        if (!empty($keywords)) {
            $title = implode(' ', array_slice($keywords, 0, 4)); // Max 4 keywords
            
            // Add genre/mood context if available
            $context = $this->buildTitleContext($params);
            if ($context) {
                $title = "{$title} ({$context})";
            }
            
            return ucwords(strtolower($title));
        }
        
        // Fallback: clean up prompt
        return $this->cleanPromptForTitle($prompt);
    }

    /**
     * Generate title for lyrics-to-song mode
     */
    protected function generateLyricsToSongTitle(array $params): string
    {
        $lyrics = $params['lyrics'] ?? '';
        
        // Try to extract title from lyrics structure
        $extractedTitle = $this->extractTitleFromLyrics($lyrics);
        if ($extractedTitle) {
            return $extractedTitle;
        }
        
        // Extract first meaningful line
        $firstLine = $this->getFirstMeaningfulLine($lyrics);
        if ($firstLine) {
            return ucwords(strtolower($firstLine));
        }
        
        // Fallback to prompt if available
        if (!empty($params['prompt'])) {
            return $this->cleanPromptForTitle($params['prompt']);
        }
        
        return 'Original Song';
    }

    /**
     * Generate title for instrumental mode
     */
    protected function generateInstrumentalTitle(array $params): string
    {
        $prompt = $params['prompt'] ?? '';
        
        // Create descriptive instrumental title
        $titleParts = [];
        
        // Add mood/genre prefix
        if (!empty($params['mood'])) {
            $titleParts[] = ucfirst($params['mood']);
        }
        
        // Add main instruments
        if (!empty($params['instruments']) && is_array($params['instruments'])) {
            $primaryInstrument = $this->getPrimaryInstrument($params['instruments']);
            if ($primaryInstrument) {
                $titleParts[] = ucfirst(str_replace('_', ' ', $primaryInstrument));
            }
        }
        
        // Add genre suffix
        if (!empty($params['genre'])) {
            $titleParts[] = ucfirst($params['genre']);
        }
        
        // Combine parts
        if (!empty($titleParts)) {
            $baseTitle = implode(' ', $titleParts);
            
            // Add descriptive suffix
            $suffixes = ['Piece', 'Theme', 'Melody', 'Composition', 'Instrumental'];
            $baseTitle .= ' ' . $suffixes[array_rand($suffixes)];
            
            return $baseTitle;
        }
        
        // Fallback: use prompt keywords
        $keywords = $this->extractKeywords($prompt);
        if (!empty($keywords)) {
            return ucwords(implode(' ', array_slice($keywords, 0, 3))) . ' Instrumental';
        }
        
        return 'Instrumental Piece';
    }

    /**
     * Generate fallback title
     */
    protected function generateFallbackTitle(array $params): string
    {
        if (!empty($params['prompt'])) {
            return $this->cleanPromptForTitle($params['prompt']);
        }
        
        if (!empty($params['lyrics'])) {
            $firstLine = $this->getFirstMeaningfulLine($params['lyrics']);
            if ($firstLine) {
                return ucwords(strtolower($firstLine));
            }
        }
        
        // Create generic title with available info
        $titleParts = [];
        
        if (!empty($params['genre'])) {
            $titleParts[] = ucfirst($params['genre']);
        }
        
        if (!empty($params['mood'])) {
            $titleParts[] = ucfirst($params['mood']);
        }
        
        $titleParts[] = 'Song';
        
        return implode(' ', $titleParts);
    }

    /**
     * Extract meaningful keywords from text
     */
    protected function extractKeywords(string $text): array
    {
        // Remove common stop words
        $stopWords = [
            'the', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with',
            'by', 'from', 'up', 'about', 'into', 'through', 'during', 'before',
            'after', 'above', 'below', 'between', 'among', 'through', 'during',
            'before', 'after', 'above', 'below', 'between', 'among', 'is', 'are',
            'was', 'were', 'be', 'been', 'being', 'have', 'has', 'had', 'do',
            'does', 'did', 'will', 'would', 'could', 'should', 'may', 'might',
            'must', 'shall', 'can', 'this', 'that', 'these', 'those', 'a', 'an'
        ];
        
        // Clean and split text
        $words = preg_split('/\s+/', strtolower(trim($text)));
        $words = array_filter($words, function($word) use ($stopWords) {
            $word = preg_replace('/[^a-z]/', '', $word);
            return strlen($word) > 2 && !in_array($word, $stopWords);
        });
        
        return array_values($words);
    }

    /**
     * Try to extract title from lyrics structure
     */
    protected function extractTitleFromLyrics(string $lyrics): ?string
    {
        $lines = explode("\n", $lyrics);
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // Look for title patterns
            if (preg_match('/^title:\s*(.+)$/i', $line, $matches)) {
                return trim($matches[1]);
            }
            
            if (preg_match('/^\[(.+)\]$/', $line, $matches)) {
                $sectionName = trim($matches[1]);
                if (!in_array(strtolower($sectionName), ['verse', 'chorus', 'bridge', 'intro', 'outro'])) {
                    return $sectionName;
                }
            }
        }
        
        return null;
    }

    /**
     * Get first meaningful line from lyrics
     */
    protected function getFirstMeaningfulLine(string $lyrics): ?string
    {
        $lines = explode("\n", $lyrics);
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // Skip empty lines, section markers, and very short lines
            if (empty($line) || 
                preg_match('/^\[.+\]$/', $line) || 
                preg_match('/^(verse|chorus|bridge|intro|outro)/i', $line) ||
                strlen($line) < 5) {
                continue;
            }
            
            // Return first meaningful line, cleaned up
            $cleanLine = preg_replace('/[^\w\s]/', '', $line);
            if (strlen($cleanLine) >= 5) {
                return substr($cleanLine, 0, 30); // Limit length
            }
        }
        
        return null;
    }

    /**
     * Clean prompt for use as title
     */
    protected function cleanPromptForTitle(string $prompt): string
    {
        // Remove common prompt prefixes
        $prompt = preg_replace('/^(create|generate|make|produce)\s+(a|an)?\s*/i', '', $prompt);
        $prompt = preg_replace('/\s*(song|music|track|piece)(\s+about|\s+with|\s+for)?\s*/i', '', $prompt);
        
        // Clean and format
        $title = preg_replace('/[^\w\s]/', '', $prompt);
        $title = preg_replace('/\s+/', ' ', $title);
        $title = trim($title);
        
        return ucwords(strtolower($title));
    }

    /**
     * Build title context from genre/mood
     */
    protected function buildTitleContext(array $params): ?string
    {
        $contextParts = [];
        
        if (!empty($params['genre'])) {
            $contextParts[] = ucfirst($params['genre']);
        }
        
        if (!empty($params['mood']) && empty($params['genre'])) {
            $contextParts[] = ucfirst($params['mood']);
        }
        
        return !empty($contextParts) ? implode(' ', $contextParts) : null;
    }

    /**
     * Get primary instrument from list
     */
    protected function getPrimaryInstrument(array $instruments): ?string
    {
        // Priority order for instruments in titles
        $priorityInstruments = [
            'piano', 'guitar', 'violin', 'saxophone', 'trumpet', 
            'drums', 'bass', 'organ', 'flute', 'cello'
        ];
        
        foreach ($priorityInstruments as $instrument) {
            if (in_array($instrument, $instruments)) {
                return $instrument;
            }
        }
        
        // Return first instrument if no priority match
        return $instruments[0] ?? null;
    }

    /**
     * Format title with variation indicator and length limits
     */
    protected function formatTitle(string $baseTitle, int $taskIndex): string
    {
        // Clean up the title
        $title = trim($baseTitle);
        $title = preg_replace('/\s+/', ' ', $title);
        
        // Add variation indicator for multiple tasks
        if ($taskIndex > 1) {
            $variations = ['Version', 'Take', 'Mix', 'Variation', 'Alt'];
            $variation = $variations[($taskIndex - 2) % count($variations)];
            $title .= " ({$variation} {$taskIndex})";
        }
        
        // Ensure proper capitalization
        $title = $this->properTitleCase($title);
        
        // Limit to reasonable length
        if (strlen($title) > 60) {
            $title = substr($title, 0, 57) . '...';
        }
        
        return $title;
    }

    /**
     * Apply proper title case formatting
     */
    protected function properTitleCase(string $title): string
    {
        // Words that should remain lowercase in titles
        $lowercaseWords = ['a', 'an', 'the', 'and', 'but', 'or', 'for', 'nor', 'on', 'at', 'to', 'from', 'by', 'of', 'in', 'with'];
        
        $words = explode(' ', strtolower($title));
        
        foreach ($words as $i => $word) {
            // Always capitalize first and last word
            if ($i === 0 || $i === count($words) - 1) {
                $words[$i] = ucfirst($word);
            }
            // Capitalize if not in lowercase list
            elseif (!in_array($word, $lowercaseWords)) {
                $words[$i] = ucfirst($word);
            }
            // Keep lowercase words as they are
        }
        
        return implode(' ', $words);
    }

    /**
     * Generate multiple title suggestions
     */
    public function generateTitleSuggestions(array $params, string $mode, int $count = 3): array
    {
        $suggestions = [];
        
        // Generate primary title
        $primaryTitle = $this->generateTitle($params, $mode, 1);
        $suggestions[] = $primaryTitle;
        
        // Generate variations
        for ($i = 2; $i <= $count; $i++) {
            if ($mode === 'instrumental') {
                $suggestions[] = $this->generateInstrumentalVariation($params, $i);
            } else {
                $suggestions[] = $this->generateTitleVariation($params, $mode, $i);
            }
        }
        
        return array_unique($suggestions);
    }

    /**
     * Generate title variation
     */
    protected function generateTitleVariation(array $params, string $mode, int $variation): string
    {
        $baseTitle = $this->generateTitle($params, $mode, 1);
        
        // Remove existing variation indicators
        $baseTitle = preg_replace('/\s*\([^)]+\)$/', '', $baseTitle);
        
        $prefixes = ['The', 'My', 'New', 'Another'];
        $suffixes = ['Song', 'Melody', 'Tune', 'Theme'];
        
        switch ($variation) {
            case 2:
                return $prefixes[array_rand($prefixes)] . ' ' . $baseTitle;
            case 3:
                return $baseTitle . ' ' . $suffixes[array_rand($suffixes)];
            default:
                return $baseTitle . " (Version {$variation})";
        }
    }

    /**
     * Generate instrumental variation
     */
    protected function generateInstrumentalVariation(array $params, int $variation): string
    {
        $baseTitle = $this->generateTitle($params, 'instrumental', 1);
        
        $instrumentalTerms = ['Composition', 'Piece', 'Theme', 'Melody', 'Suite', 'Movement'];
        $descriptors = ['Beautiful', 'Serene', 'Dynamic', 'Elegant', 'Peaceful'];
        
        switch ($variation) {
            case 2:
                return $descriptors[array_rand($descriptors)] . ' ' . $instrumentalTerms[array_rand($instrumentalTerms)];
            case 3:
                return $baseTitle . ' in ' . (ucfirst($params['genre'] ?? 'Classical')) . ' Style';
            default:
                return $baseTitle . " (Movement {$variation})";
        }
    }
}
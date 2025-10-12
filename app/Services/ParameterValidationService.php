<?php

namespace App\Services;

use Exception;

class ParameterValidationService
{
    /**
     * Supported recording environments
     */
    const RECORDING_ENVIRONMENTS = [
        'studio' => 'Professional studio recording',
        'live' => 'Live concert recording',
        'concert_hall' => 'Concert hall acoustics',
        'bedroom' => 'Bedroom/home recording',
        'garage' => 'Garage band style',
        'acoustic' => 'Acoustic/unplugged style',
        'ambient' => 'Ambient/atmospheric recording',
        'vintage' => 'Vintage recording style',
        'modern' => 'Modern crisp production',
        'lofi' => 'Lo-fi/nostalgic quality'
    ];

    /**
     * Supported musical moods
     */
    const MUSICAL_MOODS = [
        'happy', 'sad', 'energetic', 'calm', 'romantic', 'aggressive',
        'melancholic', 'uplifting', 'dark', 'mysterious', 'nostalgic',
        'epic', 'dramatic', 'peaceful', 'intense', 'dreamy', 'powerful',
        'gentle', 'fierce', 'contemplative', 'celebratory'
    ];

    /**
     * Supported genres
     */
    const MUSICAL_GENRES = [
        'pop', 'rock', 'hip-hop', 'jazz', 'classical', 'country', 'blues',
        'electronic', 'folk', 'reggae', 'punk', 'metal', 'r&b', 'soul',
        'funk', 'disco', 'house', 'techno', 'dubstep', 'ambient', 'indie',
        'alternative', 'gospel', 'latin', 'world', 'experimental'
    ];

    /**
     * Supported instruments
     */
    const INSTRUMENTS = [
        // Strings
        'guitar', 'electric_guitar', 'acoustic_guitar', 'bass', 'violin', 
        'viola', 'cello', 'double_bass', 'harp', 'banjo', 'mandolin',
        
        // Keyboards
        'piano', 'electric_piano', 'organ', 'synthesizer', 'accordion',
        
        // Drums & Percussion
        'drums', 'acoustic_drums', 'electronic_drums', 'percussion',
        'tambourine', 'maracas', 'bongos', 'congas', 'timpani',
        
        // Brass
        'trumpet', 'trombone', 'horn', 'tuba', 'saxophone',
        
        // Woodwinds
        'flute', 'clarinet', 'oboe', 'bassoon', 'recorder',
        
        // Voice
        'vocals', 'choir', 'background_vocals', 'harmonies',
        
        // Electronic
        'synth_bass', 'synth_lead', 'synth_pad', 'drum_machine',
        'sampler', 'vocoder', 'talk_box'
    ];

    /**
     * Enhanced validation rules for all parameters
     */
    public function getEnhancedValidationRules(string $mode): array
    {
        $baseRules = [
            // Core parameters (from Phase 3)
            'mood' => 'nullable|string|max:50|in:' . implode(',', self::MUSICAL_MOODS),
            'genre' => 'nullable|string|max:50|in:' . implode(',', self::MUSICAL_GENRES),
            'model_version' => 'nullable|string|in:v3.0,v3.5,v4.0,v4.5,v4.5-plus',
            
            // PHASE 4: Enhanced parameters
            'music_name' => 'nullable|string|min:1|max:100',
            'recording_environment' => 'nullable|string|in:' . implode(',', array_keys(self::RECORDING_ENVIRONMENTS)),
            'duration' => 'nullable|integer|min:30|max:300', // 30 seconds to 5 minutes
            'instruments' => 'nullable|array|max:10', // Maximum 10 instruments
            'instruments.*' => 'string|in:' . implode(',', self::INSTRUMENTS),
            
            // Language and voice (enhanced)
            'language' => 'nullable|string|in:english,spanish,french,german,italian,chinese,japanese,korean,portuguese',
            'gender' => 'nullable|string|in:male,female,mixed,random',
            
            // Additional metadata
            'tags' => 'nullable|array|max:5',
            'tags.*' => 'string|max:20',
            'tempo' => 'nullable|string|in:slow,medium,fast,very_fast',
            'energy_level' => 'nullable|integer|min:1|max:10',
        ];

        $modeSpecificRules = match($mode) {
            'text_to_song' => [
                'prompt' => 'required|string|min:5|max:200', // Max 200 chars - sent to TopMediai style field
                'is_instrumental' => 'nullable|boolean',
                'lyrics' => 'prohibited', // Cannot have lyrics in text-to-song mode
            ],
            'lyrics_to_song' => [
                'lyrics' => 'required|string|min:10', // No max limit - TopMediai accepts long lyrics
                'is_instrumental' => 'nullable|boolean',
                'prompt' => 'nullable|string|max:200', // Optional context prompt
            ],
            'instrumental' => [
                'prompt' => 'required|string|min:5|max:200', // Max 200 chars - sent to TopMediai style field
                'is_instrumental' => 'required|boolean|accepted',
                'gender' => 'prohibited', // No gender for instrumental
                'language' => 'prohibited', // No language for instrumental
                'lyrics' => 'prohibited', // No lyrics for instrumental
            ],
            default => []
        };

        return array_merge($baseRules, $modeSpecificRules);
    }

    /**
     * Validate and process instruments array
     */
    public function processInstruments(?array $instruments): array
    {
        if (empty($instruments)) {
            return [];
        }

        // Remove duplicates and validate
        $validInstruments = [];
        foreach ($instruments as $instrument) {
            $instrument = strtolower(trim($instrument));
            
            if (in_array($instrument, self::INSTRUMENTS) && !in_array($instrument, $validInstruments)) {
                $validInstruments[] = $instrument;
            }
        }

        // Limit to maximum 10 instruments
        return array_slice($validInstruments, 0, 10);
    }

    /**
     * Validate recording environment
     */
    public function validateRecordingEnvironment(?string $environment): ?string
    {
        if (empty($environment)) {
            return null;
        }

        $environment = strtolower(trim($environment));
        
        if (array_key_exists($environment, self::RECORDING_ENVIRONMENTS)) {
            return $environment;
        }

        return null;
    }

    /**
     * Process and validate duration
     */
    public function processDuration(?int $duration): ?int
    {
        if ($duration === null) {
            return null;
        }

        // Clamp duration between 30 and 300 seconds (5 minutes)
        return max(30, min(300, $duration));
    }

    /**
     * Sanitize and validate music name
     */
    public function processMusicName(?string $musicName): ?string
    {
        if (empty($musicName)) {
            return null;
        }

        // Clean and validate music name
        $cleanName = trim($musicName);
        $cleanName = preg_replace('/[^a-zA-Z0-9\s\-_\'\"(),.!?]/', '', $cleanName);
        $cleanName = preg_replace('/\s+/', ' ', $cleanName);
        
        if (strlen($cleanName) < 1 || strlen($cleanName) > 100) {
            return null;
        }

        return $cleanName;
    }

    /**
     * Process mood with fallback suggestions
     */
    public function processMood(?string $mood): ?string
    {
        if (empty($mood)) {
            return null;
        }

        $mood = strtolower(trim($mood));

        // Direct match
        if (in_array($mood, self::MUSICAL_MOODS)) {
            return $mood;
        }

        // Fuzzy matching for common variations
        $moodMappings = [
            'cheerful' => 'happy',
            'excited' => 'energetic',
            'chill' => 'calm',
            'relaxed' => 'calm',
            'love' => 'romantic',
            'angry' => 'aggressive',
            'emotional' => 'melancholic',
            'depressing' => 'sad',
            'upbeat' => 'uplifting',
            'scary' => 'dark',
            'spooky' => 'mysterious',
            'old' => 'nostalgic',
            'grand' => 'epic',
            'strong' => 'powerful',
            'soft' => 'gentle',
            'wild' => 'fierce',
            'thinking' => 'contemplative',
            'party' => 'celebratory'
        ];

        return $moodMappings[$mood] ?? null;
    }

    /**
     * Process genre with fallback suggestions
     */
    public function processGenre(?string $genre): ?string
    {
        if (empty($genre)) {
            return null;
        }

        $genre = strtolower(trim($genre));

        // Direct match
        if (in_array($genre, self::MUSICAL_GENRES)) {
            return $genre;
        }

        // Fuzzy matching for common variations
        $genreMappings = [
            'hiphop' => 'hip-hop',
            'rap' => 'hip-hop',
            'rnb' => 'r&b',
            'dance' => 'electronic',
            'edm' => 'electronic',
            'classic' => 'classical',
            'acoustic' => 'folk',
            'heavy_metal' => 'metal',
            'hardrock' => 'rock',
            'softrock' => 'rock',
            'poprock' => 'pop',
            'indie_rock' => 'indie',
            'alternative_rock' => 'alternative'
        ];

        return $genreMappings[$genre] ?? null;
    }

    /**
     * Get parameter recommendations based on mode and inputs
     */
    public function getParameterRecommendations(string $mode, array $params): array
    {
        $recommendations = [];

        // Recommend instruments based on genre
        if (!empty($params['genre']) && empty($params['instruments'])) {
            $recommendations['instruments'] = $this->getRecommendedInstruments($params['genre']);
        }

        // Recommend recording environment based on genre
        if (!empty($params['genre']) && empty($params['recording_environment'])) {
            $recommendations['recording_environment'] = $this->getRecommendedEnvironment($params['genre']);
        }

        // Recommend duration based on mode
        if (empty($params['duration'])) {
            $recommendations['duration'] = match($mode) {
                'text_to_song' => 180, // 3 minutes
                'lyrics_to_song' => $this->estimateDurationFromLyrics($params['lyrics'] ?? ''),
                'instrumental' => 240, // 4 minutes
                default => 180
            };
        }

        return $recommendations;
    }

    /**
     * Get recommended instruments for a genre
     */
    protected function getRecommendedInstruments(string $genre): array
    {
        return match($genre) {
            'rock' => ['electric_guitar', 'bass', 'drums', 'vocals'],
            'pop' => ['piano', 'guitar', 'drums', 'vocals', 'synthesizer'],
            'jazz' => ['piano', 'saxophone', 'bass', 'drums'],
            'classical' => ['violin', 'piano', 'cello', 'viola'],
            'country' => ['acoustic_guitar', 'banjo', 'vocals', 'harmonica'],
            'electronic' => ['synthesizer', 'drum_machine', 'synth_bass'],
            'hip-hop' => ['drum_machine', 'synth_bass', 'vocals'],
            'folk' => ['acoustic_guitar', 'vocals', 'harmonica'],
            'metal' => ['electric_guitar', 'bass', 'drums', 'vocals'],
            default => ['guitar', 'drums', 'vocals']
        };
    }

    /**
     * Get recommended recording environment for a genre
     */
    protected function getRecommendedEnvironment(string $genre): string
    {
        return match($genre) {
            'rock', 'metal', 'punk' => 'studio',
            'classical' => 'concert_hall',
            'jazz' => 'live',
            'folk', 'country' => 'acoustic',
            'electronic', 'hip-hop' => 'modern',
            'indie', 'alternative' => 'bedroom',
            'ambient' => 'ambient',
            default => 'studio'
        };
    }

    /**
     * Estimate duration from lyrics length
     */
    protected function estimateDurationFromLyrics(string $lyrics): int
    {
        $wordCount = str_word_count($lyrics);
        
        // Rough estimation: 120-140 words per minute in songs
        $estimatedMinutes = $wordCount / 130;
        $estimatedSeconds = max(60, min(300, $estimatedMinutes * 60));
        
        return (int) round($estimatedSeconds);
    }

    /**
     * Process gender parameter - handle 'random' selection
     */
    public function processGender(?string $gender): ?string
    {
        if ($gender === 'random') {
            // Randomly select male, female, or null (empty string like TopMediai API expects)
            $options = ['male', 'female', ''];
            return $options[array_rand($options)];
        }
        
        return $gender;
    }

    /**
     * Validate all parameters and return processed values
     */
    public function validateAndProcessParameters(array $params, string $mode): array
    {
        $processed = [
            'instruments' => $this->processInstruments($params['instruments'] ?? null),
            'recording_environment' => $this->validateRecordingEnvironment($params['recording_environment'] ?? null),
            'duration' => $this->processDuration($params['duration'] ?? null),
            'music_name' => $this->processMusicName($params['music_name'] ?? null),
            'mood' => $this->processMood($params['mood'] ?? null),
            'genre' => $this->processGenre($params['genre'] ?? null),
            'gender' => $this->processGender($params['gender'] ?? null),
        ];

        // Add recommendations if parameters are missing
        $recommendations = $this->getParameterRecommendations($mode, array_merge($params, $processed));
        
        foreach ($recommendations as $key => $value) {
            if (empty($processed[$key])) {
                $processed[$key] = $value;
            }
        }

        return array_filter($processed, fn($value) => $value !== null);
    }
}
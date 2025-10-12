<?php

namespace App\Services;

use Exception;

class GenerationModeService
{
    protected ParameterValidationService $paramService;

    public function __construct()
    {
        $this->paramService = new ParameterValidationService();
    }

    /**
     * Detect generation mode based on request parameters
     */
    public function detectMode(array $params): string
    {
        // Based on BACKEND_REQUIREMENTS_SUMMARY.md logic:
        // Mode 2: Lyrics-to-Song - has 'lyrics' parameter
        if (isset($params['lyrics']) && !empty(trim($params['lyrics']))) {
            return 'lyrics_to_song';
        }
        
        // Mode 3: Instrumental - has 'is_instrumental' = true
        if (isset($params['is_instrumental']) && $params['is_instrumental'] === true) {
            return 'instrumental';
        }
        
        // Mode 1: Text-to-Song - default mode (has 'prompt')
        return 'text_to_song';
    }

    /**
     * Validate parameters for the detected mode
     */
    public function validateModeParams(array $params, string $mode): array
    {
        $errors = [];

        switch ($mode) {
            case 'text_to_song':
                // Mode 1 requirements: prompt, language, gender
                if (empty($params['prompt'])) {
                    $errors['prompt'] = 'Prompt is required for text-to-song mode';
                }
                if (isset($params['lyrics']) && !empty($params['lyrics'])) {
                    $errors['lyrics'] = 'Lyrics cannot be provided for text-to-song mode (detected mode conflict)';
                }
                if (isset($params['is_instrumental']) && $params['is_instrumental'] === true) {
                    $errors['is_instrumental'] = 'is_instrumental cannot be true for text-to-song mode (use instrumental mode instead)';
                }
                break;

            case 'lyrics_to_song':
                // Mode 2 requirements: lyrics, instruments, gender
                if (empty($params['lyrics'])) {
                    $errors['lyrics'] = 'Lyrics are required for lyrics-to-song mode';
                }
                if (isset($params['is_instrumental']) && $params['is_instrumental'] === true) {
                    $errors['is_instrumental'] = 'is_instrumental cannot be true for lyrics-to-song mode (use instrumental mode instead)';
                }
                break;

            case 'instrumental':
                // Mode 3 requirements: prompt, instruments, is_instrumental=true
                if (empty($params['prompt'])) {
                    $errors['prompt'] = 'Prompt is required for instrumental mode';
                }
                if (!isset($params['is_instrumental']) || $params['is_instrumental'] !== true) {
                    $errors['is_instrumental'] = 'is_instrumental must be true for instrumental mode';
                }
                if (isset($params['lyrics']) && !empty($params['lyrics'])) {
                    $errors['lyrics'] = 'Lyrics cannot be provided for instrumental mode';
                }
                if (isset($params['gender'])) {
                    $errors['gender'] = 'Gender cannot be specified for instrumental mode (no vocals)';
                }
                if (isset($params['language'])) {
                    $errors['language'] = 'Language cannot be specified for instrumental mode (no vocals)';
                }
                break;

            default:
                $errors['mode'] = 'Invalid generation mode detected';
        }

        return $errors;
    }

    /**
     * Get required parameters for a specific mode
     */
    public function getRequiredParams(string $mode): array
    {
        return match($mode) {
            'text_to_song' => ['prompt'],
            'lyrics_to_song' => ['lyrics'],
            'instrumental' => ['prompt', 'is_instrumental'],
            default => ['prompt']
        };
    }

    /**
     * Get optional parameters for a specific mode
     */
    public function getOptionalParams(string $mode): array
    {
        return match($mode) {
            'text_to_song' => [
                'language', 'gender', 'mood', 'genre', 'music_name', 
                'recording_environment', 'duration', 'model_version', 'instruments',
                'tags', 'tempo', 'energy_level'
            ],
            'lyrics_to_song' => [
                'instruments', 'gender', 'mood', 'genre', 'music_name',
                'recording_environment', 'duration', 'model_version', 'prompt',
                'tags', 'tempo', 'energy_level', 'language'
            ],
            'instrumental' => [
                'instruments', 'mood', 'genre', 'music_name',
                'recording_environment', 'duration', 'model_version',
                'tags', 'tempo', 'energy_level'
            ],
            default => []
        };
    }

    /**
     * Build TopMediai request based on mode and parameters (PHASE 4 Enhanced)
     */
    public function buildTopMediaiRequest(array $params, string $mode): array
    {
        // PHASE 4: Process and validate all parameters first
        $processedParams = $this->paramService->validateAndProcessParameters($params, $mode);
        
        // Merge processed parameters back into original params
        $enhancedParams = array_merge($params, $processedParams);

        // Base request structure for TopMediai V3
        $baseRequest = [
            'mv' => $enhancedParams['model_version'] ?? 'v4.0', // Default to v4.0
        ];

        switch ($mode) {
            case 'text_to_song':
                return $this->buildTextToSongRequest($enhancedParams, $baseRequest);
            
            case 'lyrics_to_song':
                return $this->buildLyricsToSongRequest($enhancedParams, $baseRequest);
            
            case 'instrumental':
                return $this->buildInstrumentalRequest($enhancedParams, $baseRequest);
            
            default:
                throw new Exception("Unsupported generation mode: {$mode}");
        }
    }

    /**
     * Build TopMediai request for Text-to-Song mode (Mode 1) - PHASE 4 Enhanced
     */
    protected function buildTextToSongRequest(array $params, array $baseRequest): array
    {
        $request = array_merge($baseRequest, [
            'action' => 'auto', // Use auto mode - AI generates lyrics from style
            'style' => $this->generateEnhancedStyleFromParams($params),
            'instrumental' => 0, // Always has vocals for text-to-song
            'gender' => $params['gender'] ?? 'male',
        ]);

        // PHASE 4: Add duration if specified
        if (!empty($params['duration'])) {
            $request['duration'] = $params['duration'];
        }

        return $request;
    }

    /**
     * Build TopMediai request for Lyrics-to-Song mode (Mode 2) - PHASE 4 Enhanced
     */
    protected function buildLyricsToSongRequest(array $params, array $baseRequest): array
    {
        $request = array_merge($baseRequest, [
            'action' => 'custom',
            'style' => $this->generateEnhancedStyleFromParams($params),
            'lyrics' => $params['lyrics'], // Use actual lyrics
            'instrumental' => 0, // Always has vocals for lyrics-to-song
            'gender' => $params['gender'] ?? 'male',
        ]);

        // PHASE 4: Add duration if specified
        if (!empty($params['duration'])) {
            $request['duration'] = $params['duration'];
        }

        return $request;
    }

    /**
     * Build TopMediai request for Instrumental mode (Mode 3) - PHASE 4 Enhanced
     */
    protected function buildInstrumentalRequest(array $params, array $baseRequest): array
    {
        $request = array_merge($baseRequest, [
            'action' => 'auto', // Use auto mode for instrumental
            'style' => $this->generateEnhancedStyleFromParams($params),
            'instrumental' => 1, // No vocals for instrumental
            'gender' => 'male', // Not used for instrumental but required by API
        ]);

        // PHASE 4: Add duration if specified
        if (!empty($params['duration'])) {
            $request['duration'] = $params['duration'];
        }

        return $request;
    }

    /**
     * PHASE 4: Enhanced style generation from parameters
     */
    protected function generateEnhancedStyleFromParams(array $params): string
    {
        $style = '';

        // Start with prompt or base description
        if (!empty($params['prompt'])) {
            $style = $params['prompt'];
        } elseif (!empty($params['lyrics'])) {
            $style = 'Music for provided lyrics';
        } else {
            $style = 'Generated music';
        }

        // PHASE 4: Enhanced style building
        $styleElements = [];

        // Add genre with specific styling
        if (!empty($params['genre'])) {
            $styleElements[] = "in {$params['genre']} style";
        }

        // Add mood with enhanced descriptions
        if (!empty($params['mood'])) {
            $moodDescriptions = [
                'happy' => 'with uplifting and joyful energy',
                'sad' => 'with melancholic and emotional depth',
                'energetic' => 'with high energy and driving rhythm',
                'calm' => 'with peaceful and soothing atmosphere',
                'romantic' => 'with intimate and loving sentiment',
                'aggressive' => 'with intense and powerful expression',
                'mysterious' => 'with enigmatic and intriguing character',
                'epic' => 'with grand and cinematic scope',
                'nostalgic' => 'with wistful and reminiscent feeling'
            ];
            
            $moodDesc = $moodDescriptions[$params['mood']] ?? "in a {$params['mood']} mood";
            $styleElements[] = $moodDesc;
        }

        // Add instruments with detailed descriptions
        if (!empty($params['instruments']) && is_array($params['instruments'])) {
            $instrumentGroups = $this->groupInstruments($params['instruments']);
            $instrumentDesc = $this->generateInstrumentDescription($instrumentGroups);
            if ($instrumentDesc) {
                $styleElements[] = "featuring {$instrumentDesc}";
            }
        }

        // Add recording environment with atmosphere
        if (!empty($params['recording_environment'])) {
            $envDescriptions = [
                'studio' => 'with professional studio production quality',
                'live' => 'with live concert energy and audience presence',
                'concert_hall' => 'with concert hall acoustics and reverb',
                'bedroom' => 'with intimate bedroom recording atmosphere',
                'garage' => 'with raw garage band energy',
                'acoustic' => 'with acoustic and unplugged intimacy',
                'ambient' => 'with atmospheric and spacious sound',
                'vintage' => 'with warm vintage recording character',
                'modern' => 'with crisp modern production',
                'lofi' => 'with nostalgic lo-fi character'
            ];
            
            $envDesc = $envDescriptions[$params['recording_environment']] ?? "with {$params['recording_environment']} recording quality";
            $styleElements[] = $envDesc;
        }

        // Add tempo information
        if (!empty($params['tempo'])) {
            $tempoDesc = match($params['tempo']) {
                'slow' => 'at a slow, relaxed tempo',
                'medium' => 'at a moderate, comfortable pace',
                'fast' => 'at a fast, driving tempo',
                'very_fast' => 'at a very fast, energetic pace',
                default => "at {$params['tempo']} tempo"
            };
            $styleElements[] = $tempoDesc;
        }

        // Add energy level
        if (!empty($params['energy_level']) && is_numeric($params['energy_level'])) {
            $energy = (int) $params['energy_level'];
            if ($energy <= 3) {
                $styleElements[] = 'with low, subdued energy';
            } elseif ($energy <= 6) {
                $styleElements[] = 'with moderate energy';
            } elseif ($energy <= 8) {
                $styleElements[] = 'with high energy';
            } else {
                $styleElements[] = 'with maximum intensity and energy';
            }
        }

        // Add duration hint
        if (!empty($params['duration'])) {
            $minutes = round($params['duration'] / 60, 1);
            $styleElements[] = "structured for approximately {$minutes} minutes";
        }

        // Combine all elements
        $fullStyle = $style . ' ' . implode(', ', $styleElements);

        // IMPORTANT: Truncate the style to avoid API errors (limit is 200)
        $safeStyle = mb_strimwidth($fullStyle, 0, 195, '...');

        return $safeStyle;
    }

    /**
     * Group instruments by category for better descriptions
     */
    protected function groupInstruments(array $instruments): array
    {
        $groups = [
            'strings' => [],
            'keys' => [],
            'drums' => [],
            'brass' => [],
            'woodwinds' => [],
            'vocals' => [],
            'electronic' => []
        ];

        $instrumentMapping = [
            // Strings
            'guitar' => 'strings', 'electric_guitar' => 'strings', 'acoustic_guitar' => 'strings',
            'bass' => 'strings', 'violin' => 'strings', 'viola' => 'strings', 'cello' => 'strings',
            'double_bass' => 'strings', 'harp' => 'strings', 'banjo' => 'strings', 'mandolin' => 'strings',
            
            // Keys
            'piano' => 'keys', 'electric_piano' => 'keys', 'organ' => 'keys', 
            'synthesizer' => 'keys', 'accordion' => 'keys',
            
            // Drums
            'drums' => 'drums', 'acoustic_drums' => 'drums', 'electronic_drums' => 'drums',
            'percussion' => 'drums', 'tambourine' => 'drums', 'maracas' => 'drums',
            'bongos' => 'drums', 'congas' => 'drums', 'timpani' => 'drums',
            
            // Brass
            'trumpet' => 'brass', 'trombone' => 'brass', 'horn' => 'brass', 
            'tuba' => 'brass', 'saxophone' => 'brass',
            
            // Woodwinds
            'flute' => 'woodwinds', 'clarinet' => 'woodwinds', 'oboe' => 'woodwinds',
            'bassoon' => 'woodwinds', 'recorder' => 'woodwinds',
            
            // Vocals
            'vocals' => 'vocals', 'choir' => 'vocals', 'background_vocals' => 'vocals', 
            'harmonies' => 'vocals',
            
            // Electronic
            'synth_bass' => 'electronic', 'synth_lead' => 'electronic', 'synth_pad' => 'electronic',
            'drum_machine' => 'electronic', 'sampler' => 'electronic', 'vocoder' => 'electronic',
            'talk_box' => 'electronic'
        ];

        foreach ($instruments as $instrument) {
            $category = $instrumentMapping[$instrument] ?? 'other';
            if (isset($groups[$category])) {
                $groups[$category][] = $instrument;
            }
        }

        return array_filter($groups);
    }

    /**
     * Generate intelligent instrument description
     */
    protected function generateInstrumentDescription(array $instrumentGroups): string
    {
        $descriptions = [];

        foreach ($instrumentGroups as $category => $instruments) {
            if (empty($instruments)) continue;

            $count = count($instruments);
            $categoryDesc = match($category) {
                'strings' => $count > 1 ? 'string ensemble' : 'strings',
                'keys' => $count > 1 ? 'keyboard arrangement' : 'keys',
                'drums' => $count > 1 ? 'percussion section' : 'rhythm section',
                'brass' => $count > 1 ? 'brass section' : 'brass',
                'woodwinds' => $count > 1 ? 'woodwind section' : 'woodwinds',
                'vocals' => $count > 1 ? 'vocal arrangement' : 'vocals',
                'electronic' => $count > 1 ? 'electronic elements' : 'synth',
                default => implode(', ', $instruments)
            };

            $descriptions[] = $categoryDesc;
        }

        return implode(' and ', $descriptions);
    }

    /**
     * Get mode description for logging/display
     */
    public function getModeDescription(string $mode): string
    {
        return match($mode) {
            'text_to_song' => 'Text-to-Song (AI generates music and vocals from description)',
            'lyrics_to_song' => 'Lyrics-to-Song (AI generates music for provided lyrics)',
            'instrumental' => 'Instrumental (AI generates instrumental music only)',
            default => 'Unknown mode'
        };
    }

    /**
     * Get validation rules for Laravel request validation (PHASE 4 Enhanced)
     */
    public function getValidationRules(string $mode): array
    {
        return $this->paramService->getEnhancedValidationRules($mode);
    }

    /**
     * Get parameter recommendations for frontend
     */
    public function getParameterRecommendations(string $mode, array $params = []): array
    {
        return $this->paramService->getParameterRecommendations($mode, $params);
    }
}
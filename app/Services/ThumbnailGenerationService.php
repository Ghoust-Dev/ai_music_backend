<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Exception;

class ThumbnailGenerationService
{
    private string $apiKey;
    private string $baseUrl = 'https://api.runware.ai/v1';
    
    public function __construct()
    {
        $this->apiKey = config('services.runware.api_key');
        
        if (!$this->apiKey) {
            throw new Exception('Runware API key not configured');
        }
    }
    
    /**
     * Generate high-resolution thumbnail using FLUX.1 [schnell]
     */
    public function generateThumbnail(string $musicPrompt, string $genre = null, string $mood = null, int $taskIndex = 1): array
    {
        try {
            $visualPrompt = $this->buildVisualPrompt($musicPrompt, $genre, $mood, $taskIndex);
            
            $payload = [
                [
                    "taskType" => "imageInference",
                    "taskUUID" => (string) Str::uuid(),
                    "positivePrompt" => $visualPrompt,
                    // ✅ ENHANCED: Stronger negative prompt to prevent ANY text/typography
                    "negativePrompt" => "text, letters, words, typography, title, artist name, watermark, signature, logo, writing, characters, numbers, labels, captions, subtitles, font, alphabet, any text elements, low quality, blurry, pixelated, ugly, distorted, deformed, bad anatomy, artifacts",
                    "model" => "runware:100@1", // FLUX.1 [schnell]
                    "height" => 1024,
                    "width" => 1024,
                    "steps" => 4, // FLUX schnell optimized steps
                    "CFGScale" => 1.0, // FLUX schnell optimized CFG
                    "numberResults" => 1,
                    "outputType" => "URL",
                    "outputFormat" => "JPG",
                    "outputQuality" => 95,
                    "checkNSFW" => false, // Music thumbnails shouldn't need NSFW check
                    "includeCost" => true
                ]
            ];
            
            Log::info('Runware thumbnail generation request', [
                'prompt' => $visualPrompt,
                'model' => 'FLUX.1 [schnell]',
                'genre' => $genre,
                'mood' => $mood
            ]);
            
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json'
            ])->timeout(30)->post($this->baseUrl, $payload);
            
            if (!$response->successful()) {
                throw new Exception('Runware API request failed: HTTP ' . $response->status() . ' - ' . $response->body());
            }
            
            $responseData = $response->json();
            
            if (!isset($responseData['data'][0])) {
                throw new Exception('Invalid Runware API response structure: ' . json_encode($responseData));
            }
            
            $result = $responseData['data'][0];
            
            if ($result['taskType'] !== 'imageInference' || !isset($result['imageURL'])) {
                throw new Exception('Runware generation failed: ' . ($result['message'] ?? 'Unknown error'));
            }
            
            Log::info('Runware thumbnail generated successfully', [
                'image_url' => $result['imageURL'],
                'cost' => $result['cost'] ?? 'unknown',
                'prompt_used' => $visualPrompt
            ]);
            
            return [
                'success' => true,
                'image_url' => $result['imageURL'],
                'cost' => $result['cost'] ?? null,
                'prompt_used' => $visualPrompt,
                'model' => 'FLUX.1 [schnell]'
            ];
            
        } catch (Exception $e) {
            Log::error('Runware thumbnail generation failed', [
                'error' => $e->getMessage(),
                'prompt' => $musicPrompt,
                'genre' => $genre,
                'mood' => $mood
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Convert music prompt/lyrics to visual art prompt - PURE IMAGERY, NO TEXT
     */
    private function buildVisualPrompt(string $musicPrompt, ?string $genre, ?string $mood, int $taskIndex = 1): string
    {
        // ✅ If input is very long (lyrics), extract themes instead of using raw text
        if (strlen($musicPrompt) > 500) {
            Log::info('Long input detected (likely lyrics), extracting visual themes', [
                'original_length' => strlen($musicPrompt),
                'will_extract_themes' => true
            ]);
            $musicPrompt = $this->extractThemesFromLyrics($musicPrompt);
            Log::info('Extracted themes from lyrics', [
                'themes' => $musicPrompt,
                'themes_length' => strlen($musicPrompt)
            ]);
        }
        
        // ✅ ENHANCED: Pure visual album cover - ABSOLUTELY NO TEXT/TYPOGRAPHY
        $baseStyle = "professional music album cover artwork, pure visual imagery without any text, abstract artistic design, clean visual composition, high resolution photography, studio quality, commercial music industry standard, minimalist aesthetic, visual storytelling, NO TEXT OR TYPOGRAPHY ANYWHERE, purely visual album art";
        
        // 🎯 TASK VARIATIONS: Make each thumbnail unique
        $taskVariations = [
            1 => "primary version, centered visual composition, main artistic focus, professional studio lighting, classic album cover layout with pure imagery",
            2 => "alternative version, dynamic visual angle, creative perspective, artistic lighting, modern album cover design with abstract elements",
            3 => "variant style, unique visual composition, bold artistic approach, dramatic lighting with pure visual elements",
            4 => "special edition style, premium visual design, sophisticated layout, elegant visual presentation without text"
        ];
        
        // Get task-specific variation
        $taskVariation = $taskVariations[$taskIndex] ?? $taskVariations[1];
        
        // 🎵 ENHANCED: Genre-specific album cover styles - PURE VISUAL, NO TEXT
        $genreStyles = [
            'pop' => 'bright commercial colors, modern pop visual design, clean aesthetic, mainstream appeal, glossy professional finish, contemporary visual elements, vibrant imagery',
            'rock' => 'bold visual graphics, dynamic rock composition, energetic feel, dramatic concert lighting, edgy rock design, powerful visual impact, raw energy imagery',
            'electronic' => 'neon electronic colors, futuristic EDM design, digital art style, glowing effects, cyberpunk aesthetic, electronic music visual, abstract digital patterns',
            'jazz' => 'sophisticated jazz design, warm vintage colors, elegant jazz composition, smooth gradients, classic album cover style, timeless aesthetic, sultry atmosphere',
            'classical' => 'elegant classical design, gold orchestral accents, ornate classical details, refined visual elements, prestigious album cover, sophisticated presentation, majestic imagery',
            'hip-hop' => 'urban hip-hop aesthetic, bold street art style, dynamic rap composition, graffiti-inspired visuals, modern hip-hop design, powerful visual statement, raw urban energy',
            'country' => 'rustic country elements, warm americana tones, country music style, natural textures, vintage country feel, authentic design, countryside imagery',
            'folk' => 'organic folk design, natural acoustic colors, handcrafted folk feel, vintage folk textures, authentic folk aesthetic, intimate presentation, nature-inspired',
            'blues' => 'moody blues atmosphere, deep emotional colors, vintage blues aesthetic, soulful depth, smoky blues ambiance, classic blues design, emotional intensity',
            'reggae' => 'vibrant reggae colors, tropical island elements, relaxed reggae vibe, natural reggae themes, authentic caribbean colors, positive energy, island paradise',
            'metal' => 'dark metal colors, aggressive metal design, metallic textures, intense metal atmosphere, gothic metal elements, powerful metal visual, fierce imagery',
            'r&b' => 'smooth R&B gradients, soulful romantic colors, elegant R&B design, sophisticated styling, smooth contemporary feel, premium R&B aesthetic, sensual atmosphere',
            'indie' => 'artistic indie design, unique alternative aesthetic, creative indie visuals, handmade indie feel, authentic indie style, independent music visual, artistic expression',
            'dance' => 'energetic dance colors, dynamic club patterns, dance music atmosphere, neon dance accents, rhythmic visual design, party energy visual, pulsing lights'
        ];
        
        // 🎭 ENHANCED: Mood-specific album cover elements
        $moodStyles = [
            'happy' => 'bright uplifting colors, cheerful album design, positive energy visual, sunny optimistic feel, joyful vibrant presentation',
            'sad' => 'muted melancholic colors, emotional album design, soft dramatic lighting, introspective depth, contemplative visual mood',
            'energetic' => 'dynamic high-energy visual, vibrant explosive colors, movement and action, powerful energetic composition, intense visual impact',
            'calm' => 'serene peaceful colors, minimalist calm design, tranquil zen-like aesthetic, soft soothing presentation, relaxing visual atmosphere',
            'romantic' => 'warm romantic colors, intimate album design, elegant dreamy lighting, passionate visual mood, sophisticated romantic aesthetic',
            'dark' => 'dramatic shadow design, mysterious deep colors, intense gothic atmosphere, powerful dark visual, sophisticated noir aesthetic',
            'upbeat' => 'lively celebratory colors, festive album design, joyful dynamic visual, party energy presentation, positive upbeat aesthetic',
            'chill' => 'relaxed cool colors, laid-back design, smooth atmospheric gradients, casual comfortable feel, easy-going visual mood',
            'aggressive' => 'sharp powerful edges, bold intense contrasts, fierce visual impact, strong aggressive design, dominant visual presence',
            'nostalgic' => 'vintage nostalgic colors, retro album style, classic aged textures, sentimental timeless design, heritage aesthetic'
        ];
        
        // 🏗️ Build the enhanced visual prompt
        $promptParts = [$baseStyle, $taskVariation];
        
        // Add genre-specific album cover styling
        if ($genre && isset($genreStyles[strtolower($genre)])) {
            $promptParts[] = $genreStyles[strtolower($genre)];
        }
        
        // Add mood-specific visual elements
        if ($mood && isset($moodStyles[strtolower($mood)])) {
            $promptParts[] = $moodStyles[strtolower($mood)];
        }
        
        // Extract and enhance thematic elements from music prompt
        $thematicElements = $this->extractAlbumCoverThemes($musicPrompt);
        if (!empty($thematicElements)) {
            $promptParts[] = $thematicElements;
        }
        
        // 🎨 ENHANCED: Album cover specific quality specifications
        $qualitySpecs = "4K ultra-high resolution, professional album cover photography, award-winning music industry design, studio-grade lighting, perfect commercial composition, premium album artwork quality, music industry standard presentation";
        $promptParts[] = $qualitySpecs;
        
        return implode(', ', $promptParts);
    }
    
    /**
     * Extract visual themes from long lyrics text for thumbnail generation
     */
    private function extractThemesFromLyrics(string $lyrics): string
    {
        $lyrics = strtolower($lyrics);
        $themes = [];
        
        // Extract most frequently mentioned visual/emotional themes
        $themeKeywords = [
            // Nature & Environment
            'night', 'sky', 'star', 'moon', 'sun', 'rain', 'storm', 'ocean', 'sea', 'water',
            'mountain', 'forest', 'tree', 'flower', 'rose', 'fire', 'flame', 'light', 'shadow',
            'cloud', 'wind', 'snow', 'winter', 'summer', 'spring', 'autumn', 'beach', 'sunset',
            'sunrise', 'river', 'lake', 'desert', 'valley', 'hill', 'nature', 'garden',
            
            // Urban & Modern
            'city', 'street', 'road', 'car', 'building', 'skyline', 'neon', 'urban', 'downtown',
            'metro', 'train', 'station', 'cafe', 'club', 'bar', 'hotel', 'apartment',
            
            // Emotions & Abstract
            'love', 'heart', 'soul', 'dream', 'hope', 'fear', 'pain', 'joy', 'peace', 'freedom',
            'power', 'energy', 'magic', 'spirit', 'angel', 'heaven', 'hell', 'devil', 'god',
            'faith', 'prayer', 'destiny', 'fate', 'time', 'eternity', 'forever', 'moment',
            
            // Actions & Movement
            'dance', 'fly', 'run', 'walk', 'jump', 'fall', 'rise', 'climb', 'swim', 'drive',
            'fight', 'escape', 'chase', 'follow', 'lead', 'move', 'shake', 'spin', 'turn',
            
            // Colors & Visuals
            'gold', 'silver', 'red', 'blue', 'black', 'white', 'green', 'purple', 'pink',
            'yellow', 'orange', 'grey', 'dark', 'bright', 'glow', 'shine', 'sparkle', 'flash',
            
            // Relationships & People
            'alone', 'together', 'friend', 'stranger', 'lover', 'angel', 'queen', 'king',
            'girl', 'boy', 'man', 'woman', 'child', 'baby', 'mother', 'father'
        ];
        
        // Count frequency of each theme
        $themeCount = [];
        foreach ($themeKeywords as $keyword) {
            $count = substr_count($lyrics, $keyword);
            if ($count > 0) {
                $themeCount[$keyword] = $count;
            }
        }
        
        // Sort by frequency and get top themes
        arsort($themeCount);
        $topThemes = array_slice(array_keys($themeCount), 0, 8); // Top 8 themes
        
        if (empty($topThemes)) {
            // Fallback: extract first meaningful line
            $lines = preg_split('/[\r\n]+/', $lyrics);
            $firstLine = '';
            foreach ($lines as $line) {
                $line = trim($line);
                if (strlen($line) > 20 && !str_starts_with($line, '[') && !str_starts_with($line, '(')) {
                    $firstLine = $line;
                    break;
                }
            }
            return !empty($firstLine) ? substr($firstLine, 0, 150) : 'emotional music journey, abstract artistic expression';
        }
        
        // Build visual description from themes
        $visualDescription = 'visual themes of ' . implode(', ', array_slice($topThemes, 0, 5));
        
        // Add emotional context if present
        $emotions = array_intersect($topThemes, ['love', 'heart', 'dream', 'hope', 'fear', 'pain', 'joy', 'peace', 'freedom', 'power']);
        if (!empty($emotions)) {
            $visualDescription .= ', emotional atmosphere with ' . implode(' and ', $emotions);
        }
        
        // Add nature elements if present
        $nature = array_intersect($topThemes, ['night', 'sky', 'star', 'moon', 'sun', 'ocean', 'mountain', 'forest', 'fire', 'water']);
        if (!empty($nature)) {
            $visualDescription .= ', natural elements including ' . implode(' and ', array_slice($nature, 0, 3));
        }
        
        return $visualDescription . ', abstract artistic interpretation';
    }
    
    /**
     * Extract album cover visual themes from music prompt
     */
    private function extractAlbumCoverThemes(string $prompt): string
    {
        $prompt = strtolower($prompt);
        $themes = [];
        
        // 🎨 ENHANCED: Music themes to album cover visual elements mapping
        $albumCoverThemeMap = [
            'love' => 'romantic album imagery, heart symbolism, couple silhouettes, warm intimate lighting, love theme album cover',
            'summer' => 'bright summer album colors, beach vacation vibes, tropical album design, sunny warm atmosphere, summer hit aesthetic',
            'night' => 'nighttime album atmosphere, city lights backdrop, moonlit album cover, dark moody lighting, midnight music visual',
            'city' => 'urban album landscape, skyline backdrop, metropolitan music visual, street photography style, city life album cover',
            'nature' => 'natural album landscapes, organic earth tones, mountain/forest imagery, environmental music visual, nature-inspired design',
            'freedom' => 'liberation album imagery, open sky backgrounds, bird flight symbolism, wide horizon views, freedom anthem visual',
            'dance' => 'dynamic dance energy, movement blur effects, club atmosphere, rhythmic visual patterns, dance music album cover',
            'party' => 'celebration album design, festive party atmosphere, confetti effects, party lights, celebratory music visual',
            'rain' => 'atmospheric rain effects, moody weather imagery, water reflection themes, storm album aesthetic, emotional rain visual',
            'fire' => 'intense fire imagery, warm glow effects, passionate energy visual, burning flame symbolism, powerful fire album cover',
            'ocean' => 'oceanic album themes, wave imagery, coastal music visual, blue water atmosphere, maritime album design',
            'space' => 'cosmic album imagery, starfield backgrounds, galaxy themes, celestial music visual, space-themed album cover',
            'vintage' => 'retro album aesthetic, aged texture effects, classic vintage styling, nostalgic music visual, old-school album design',
            'modern' => 'contemporary album design, sleek modern styling, minimalist music visual, futuristic album aesthetic, cutting-edge design',
            'dream' => 'dreamy album atmosphere, surreal visual elements, ethereal music imagery, fantasy album themes, mystical visual effects',
            'peace' => 'peaceful album imagery, serene visual atmosphere, harmony symbolism, tranquil music visual, zen album aesthetic',
            'power' => 'powerful album imagery, strong visual impact, dominant design elements, authority symbolism, empowering music visual',
            'magic' => 'mystical album themes, magical visual effects, enchanted atmosphere, supernatural music imagery, fantasy album cover',
            'hope' => 'hopeful album imagery, uplifting visual themes, bright optimistic colors, inspiring music visual, positive energy design',
            'dreams' => 'aspirational album themes, cloud imagery, reaching skyward, motivational visual elements, dream-chasing album cover',
            'light' => 'bright illuminating effects, radiant album imagery, light beam visuals, glowing atmospheric effects, luminous music design',
            'sky' => 'expansive sky backgrounds, cloud formations, aerial perspectives, heavenly album themes, sky-reaching visual elements',
            'heart' => 'emotional heart symbolism, passionate album imagery, love-centered design, heartfelt music visual, emotional connection themes',
            'soul' => 'soulful album atmosphere, deep emotional imagery, spiritual visual themes, authentic music expression, soul music aesthetic',
            'energy' => 'high-energy visual effects, dynamic album design, powerful motion blur, energetic color schemes, vibrant music visual'
        ];
        
        foreach ($albumCoverThemeMap as $keyword => $albumCoverElement) {
            if (str_contains($prompt, $keyword)) {
                $themes[] = $albumCoverElement;
            }
        }
        
        return implode(', ', array_unique($themes));
    }
}
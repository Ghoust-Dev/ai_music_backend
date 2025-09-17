<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GeneratedContentSeeder extends Seeder
{
    public function run(): void
    {
        $content = [
            [
                'user_id' => 1,
                'title' => 'Summer Vibes',
                'content_type' => 'song',
                'topmediai_task_id' => 'test_task_001',
                'status' => 'completed',
                'prompt' => 'Happy summer song with guitar and drums',
                'mood' => 'happy',
                'genre' => 'pop',
                'instruments' => json_encode(['guitar', 'drums', 'bass']),
                'language' => 'english',
                'duration' => 120,
                'content_url' => 'https://api.topmediai.com/files/music/test_001.mp3',
                'thumbnail_url' => 'https://api.topmediai.com/files/thumbnails/test_001.jpg',
                'download_url' => 'https://api.topmediai.com/download/test_001',
                'metadata' => json_encode([
                    'duration' => 120,
                    'file_size' => '4.2MB',
                    'format' => 'mp3',
                    'bitrate' => '320kbps'
                ]),
                'started_at' => now()->subMinutes(5),
                'completed_at' => now(),
                'is_premium_generation' => false,
                'last_accessed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => 2,
                'title' => 'Energetic Workout',
                'content_type' => 'instrumental',
                'topmediai_task_id' => 'test_task_002',
                'status' => 'completed',
                'prompt' => 'High energy workout music',
                'mood' => 'energetic',
                'genre' => 'electronic',
                'instruments' => json_encode(['synthesizer', 'drums']),
                'language' => 'english',
                'duration' => 180,
                'content_url' => 'https://api.topmediai.com/files/music/test_002.mp3',
                'thumbnail_url' => 'https://api.topmediai.com/files/thumbnails/test_002.jpg',
                'download_url' => 'https://api.topmediai.com/download/test_002',
                'metadata' => json_encode([
                    'duration' => 180,
                    'file_size' => '6.1MB',
                    'format' => 'mp3',
                    'bitrate' => '320kbps'
                ]),
                'started_at' => now()->subMinutes(10),
                'completed_at' => now()->subMinutes(2),
                'is_premium_generation' => true,
                'last_accessed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        DB::table('generated_content')->insert($content);
    }
}
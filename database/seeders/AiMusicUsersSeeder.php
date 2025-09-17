<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AiMusicUsersSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            [
                'device_id' => 'test_device_001',
                'device_fingerprint' => md5('test_device_001_fingerprint'),
                'subscription_status' => 'free',
                'usage_count' => 5,
                'monthly_usage' => 5,
                'usage_reset_date' => now()->format('Y-m-d'),
                'last_active_at' => now(),
                'device_info' => json_encode([
                    'platform' => 'android',
                    'version' => '1.0.0',
                    'model' => 'Samsung Galaxy S21'
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'device_id' => 'test_device_002',
                'device_fingerprint' => md5('test_device_002_fingerprint'),
                'subscription_status' => 'premium',
                'usage_count' => 25,
                'monthly_usage' => 25,
                'usage_reset_date' => now()->format('Y-m-d'),
                'last_active_at' => now(),
                'device_info' => json_encode([
                    'platform' => 'ios',
                    'version' => '1.0.0',
                    'model' => 'iPhone 14 Pro'
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        DB::table('ai_music_users')->insert($users);
    }
}
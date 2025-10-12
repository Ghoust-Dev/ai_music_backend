<?php

namespace App\Services;

/**
 * Service to parse product IDs from App Store / Play Store
 * and extract credits, duration, and product type
 */
class ProductParserService
{
    /**
     * Parse product ID to extract credits and metadata
     * 
     * Supported formats:
     * - subscription_{duration}_{credits} → e.g., "subscription_monthly_250"
     * - {name}_subscription_{duration}_{credits} → e.g., "premium_subscription_yearly_3000"
     * - credits_{amount}_{type} → e.g., "credits_500_premium"
     * - pack_{amount}_{type} → e.g., "pack_1000_mega"
     * 
     * @param string $productId The product ID from App Store or Play Store
     * @return array ['type', 'credits', 'duration_days', 'product_id', 'metadata']
     */
    public function parseProductId(string $productId): array
    {
        $lowerProductId = strtolower($productId);
        $parts = explode('_', $lowerProductId);
        
        // Check if it's a subscription
        if (strpos($lowerProductId, 'subscription') !== false) {
            return $this->parseSubscription($productId, $parts);
        }
        
        // Check if it's a credit pack or addon pack
        if (in_array($parts[0], ['credits', 'pack', 'addon'])) {
            return $this->parseCreditPack($productId, $parts);
        }
        
        // Fallback: Try to extract credits from anywhere in the ID
        $credits = $this->extractNumberFromString($productId);
        
        return [
            'type' => 'unknown',
            'credits' => $credits,
            'duration_days' => 0,
            'product_id' => $productId,
            'metadata' => [
                'parsed_from' => 'fallback',
                'parts' => $parts,
            ],
        ];
    }
    
    /**
     * Parse subscription product ID
     */
    private function parseSubscription(string $productId, array $parts): array
    {
        $credits = $this->extractNumberFromArray($parts);
        $duration = $this->parseDuration($parts);
        $durationName = $this->extractDurationName($parts);
        
        return [
            'type' => 'subscription',
            'credits' => $credits,
            'duration_days' => $duration,
            'duration_name' => $durationName,
            'product_id' => $productId,
            'metadata' => [
                'parsed_from' => 'subscription_format',
                'parts' => $parts,
            ],
        ];
    }
    
    /**
     * Parse credit pack product ID
     */
    private function parseCreditPack(string $productId, array $parts): array
    {
        $credits = $this->extractNumberFromArray($parts);
        $packType = $this->extractPackType($parts);
        
        return [
            'type' => 'credit_pack',
            'credits' => $credits,
            'duration_days' => 0, // Credit packs are lifetime
            'pack_type' => $packType,
            'product_id' => $productId,
            'metadata' => [
                'parsed_from' => 'credit_pack_format',
                'parts' => $parts,
                'lifetime' => true,
            ],
        ];
    }
    
    /**
     * Extract first number from array of parts
     */
    private function extractNumberFromArray(array $parts): int
    {
        foreach ($parts as $part) {
            if (is_numeric($part)) {
                return (int) $part;
            }
        }
        return 0;
    }
    
    /**
     * Extract number from string using regex
     */
    private function extractNumberFromString(string $str): int
    {
        preg_match('/\d+/', $str, $matches);
        return isset($matches[0]) ? (int) $matches[0] : 0;
    }
    
    /**
     * Parse duration from product ID parts
     */
    private function parseDuration(array $parts): int
    {
        $durationMap = [
            'daily' => 1,
            'day' => 1,
            'weekly' => 7,
            'week' => 7,
            'biweekly' => 14,
            'monthly' => 30,
            'month' => 30,
            'quarterly' => 90,
            'quarter' => 90,
            'biannual' => 180,
            'semiannual' => 180,
            'yearly' => 365,
            'year' => 365,
            'annual' => 365,
            'lifetime' => 36500, // 100 years
        ];
        
        foreach ($parts as $part) {
            $lowerPart = strtolower($part);
            if (isset($durationMap[$lowerPart])) {
                return $durationMap[$lowerPart];
            }
        }
        
        return 30; // Default to monthly
    }
    
    /**
     * Extract duration name from parts
     */
    private function extractDurationName(array $parts): string
    {
        $durations = ['daily', 'weekly', 'monthly', 'quarterly', 'yearly', 'annual', 'lifetime'];
        
        foreach ($parts as $part) {
            if (in_array(strtolower($part), $durations)) {
                return strtolower($part);
            }
        }
        
        return 'monthly'; // Default
    }
    
    /**
     * Extract pack type from parts
     */
    private function extractPackType(array $parts): string
    {
        // Last part is usually the type
        $lastPart = end($parts);
        
        if (!is_numeric($lastPart) && strlen($lastPart) > 2) {
            return $lastPart;
        }
        
        // Check for known types
        $types = ['basic', 'standard', 'premium', 'mega', 'ultimate', 'starter', 'pro'];
        foreach ($parts as $part) {
            if (in_array(strtolower($part), $types)) {
                return strtolower($part);
            }
        }
        
        return 'standard'; // Default
    }
    
    /**
     * Validate if product ID follows naming convention
     */
    public function isValidFormat(string $productId): bool
    {
        $parsed = $this->parseProductId($productId);
        
        // Valid if we extracted credits and determined type
        return $parsed['credits'] > 0 && $parsed['type'] !== 'unknown';
    }
    
    /**
     * Get suggested product IDs for documentation
     */
    public static function getExamples(): array
    {
        return [
            'subscriptions' => [
                'subscription_weekly_50' => ['credits' => 50, 'duration' => '7 days'],
                'subscription_monthly_250' => ['credits' => 250, 'duration' => '30 days'],
                'subscription_yearly_3000' => ['credits' => 3000, 'duration' => '365 days'],
                'premium_subscription_monthly_500' => ['credits' => 500, 'duration' => '30 days'],
            ],
            'credit_packs' => [
                'credits_100_basic' => ['credits' => 100, 'type' => 'basic'],
                'credits_250_standard' => ['credits' => 250, 'type' => 'standard'],
                'credits_500_premium' => ['credits' => 500, 'type' => 'premium'],
                'pack_1000_mega' => ['credits' => 1000, 'type' => 'mega'],
            ],
        ];
    }
}

<?php

namespace App\Services;

class PantryFreshnessService
{
    /**
     * Return a conservative, reviewable estimate when an item has no printed
     * expiry date.  These dates are prompts to check an item, not a claim that
     * it has spoiled.
     */
    public function estimate(string $name, ?string $unit, ?string $storage, string $purchaseSource = 'unknown'): array
    {
        $text = strtolower($name.' '.$unit);
        $isPackaged = preg_match('/\b(can|canned|pack|packet|sachet|bottle|jar|tuna|sardines|corned|evaporated|condensed)\b/', $text) === 1;

        if ($isPackaged) {
            return $this->estimatedForPackagedItem($purchaseSource);
        }

        if (preg_match('/\b(rice|salt|sugar|flour|dried beans?|lentils|coffee|tea|vinegar|cooking oil)\b/', $text)) {
            // These pantry staples do not become unsafe overnight. Without a
            // printed date, keep them as undated stock instead of inventing an
            // expiry reminder; users can still add a printed date themselves.
            return [
                'expiry_date' => null,
                'review_date' => null,
                'status' => 'fresh',
                'confidence' => 'low',
            ];
        }

        if (preg_match('/\b(pasta|noodles)\b/', $text)) {
            return $this->estimatedForDryGood($purchaseSource);
        }

        // Fresh unpacked purchases get a prompt tomorrow, not immediately on
        // the day they are entered. The scheduled refresh marks them review
        // only once that date arrives.
        return [
            'expiry_date' => now()->addDay()->toDateString(),
            'review_date' => now()->addDay()->toDateString(),
            'status' => 'fresh',
            'confidence' => $storage === 'refrigerated' ? 'medium' : 'low',
        ];
    }

    private function estimatedForPackagedItem(string $purchaseSource): array
    {
        $months = $purchaseSource === 'sari_sari_store' ? 6 : 12;
        $reviewMonths = $purchaseSource === 'sari_sari_store' ? 1 : max(1, $months - 2);

        return [
            'expiry_date' => now()->addMonths($months)->toDateString(),
            'review_date' => now()->addMonths($reviewMonths)->toDateString(),
            'status' => 'fresh',
            // Without the printed label, estimates must remain explicitly low confidence.
            'confidence' => 'low',
        ];
    }

    private function estimatedForDryGood(string $purchaseSource): array
    {
        $months = $purchaseSource === 'sari_sari_store' ? 3 : 6;

        return [
            'expiry_date' => now()->addMonths($months)->toDateString(),
            'review_date' => now()->addMonths(max(1, $months - 1))->toDateString(),
            'status' => 'fresh',
            'confidence' => 'low',
        ];
    }
}

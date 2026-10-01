<?php

namespace Tests\Unit;

use App\Services\ShoppingQuantitySuggestionService;
use PHPUnit\Framework\TestCase;

class ShoppingQuantitySuggestionServiceTest extends TestCase
{
    private ShoppingQuantitySuggestionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ShoppingQuantitySuggestionService();
    }

    public function test_fractional_countable_items_round_up(): void
    {
        $this->assertSame(['quantity' => 1.0, 'unit' => 'pieces', 'note' => 'Rounded up to whole purchase units'], $this->service->suggest(0.8, 'pieces'));
    }

    public function test_small_liquids_become_practical_millilitres(): void
    {
        $this->assertSame(100.0, $this->service->suggest(6.6, 'tbsp')['quantity']);
        $this->assertSame('ml', $this->service->suggest(6.6, 'tbsp')['unit']);
    }

    public function test_weights_are_rounded_and_small_kilograms_use_grams(): void
    {
        $this->assertSame(['quantity' => 760.0, 'unit' => 'g', 'note' => 'Rounded to a practical weight'], $this->service->suggest(0.753, 'kg'));
        $this->assertSame(['quantity' => 1000.0, 'unit' => 'g', 'note' => 'Rounded to a practical weight'], $this->service->suggest(1000, 'g'));
    }

    public function test_purchase_units_are_whole_and_unknown_units_remain_reviewable(): void
    {
        $this->assertSame(2.0, $this->service->suggest(1.2, 'cans')['quantity']);
        $this->assertSame('Amount to confirm', $this->service->suggest(2, 'handful')['note']);
        $this->assertNull($this->service->suggest(2, 'handful')['quantity']);
    }
}
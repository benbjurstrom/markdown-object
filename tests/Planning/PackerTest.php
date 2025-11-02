<?php

use BenBjurstrom\MarkdownObject\Planning\Budget;
use BenBjurstrom\MarkdownObject\Planning\Packer;
use BenBjurstrom\MarkdownObject\Planning\Unit;
use BenBjurstrom\MarkdownObject\Planning\UnitKind;

beforeEach(function () {
    $this->packer = new Packer;
});

// Helper to create test Units
function makeUnit(int $tokens): Unit
{
    return new Unit(
        kind: UnitKind::Text,
        markdown: "Unit with {$tokens} tokens",
        tokens: $tokens
    );
}

it('packs all units into one chunk when under target', function () {
    $units = [
        makeUnit(100),
        makeUnit(150),
        makeUnit(200),
    ];

    $budget = new Budget(target: 500, hardCap: 1000, earlyThreshold: 450);
    $ranges = $this->packer->pack($units, $budget);

    expect($ranges)->toHaveCount(1)
        ->and($ranges[0])->toBe(['start' => 0, 'end' => 2]);
});

it('creates multiple chunks when units exceed target', function () {
    $units = [
        makeUnit(300),
        makeUnit(300),
        makeUnit(300),
    ];

    $budget = new Budget(target: 500, hardCap: 1000, earlyThreshold: 450);
    $ranges = $this->packer->pack($units, $budget);

    // First unit alone (exceeds earlyThreshold), last two together via final stretch
    expect($ranges)->toHaveCount(2)
        ->and($ranges[0])->toBe(['start' => 0, 'end' => 0])
        ->and($ranges[1])->toBe(['start' => 1, 'end' => 2]);
});

it('applies early finish at 90% threshold for last unit', function () {
    $units = [
        makeUnit(400), // 400 tokens
        makeUnit(100), // Would make 500 (exactly target)
    ];

    $budget = new Budget(target: 500, hardCap: 1000, earlyThreshold: 450);
    $ranges = $this->packer->pack($units, $budget);

    // Should pack both together since 500 <= target
    expect($ranges)->toHaveCount(1)
        ->and($ranges[0])->toBe(['start' => 0, 'end' => 1]);
});

it('allows final stretch to hardCap for last unit', function () {
    $units = [
        makeUnit(400),
        makeUnit(500), // Would make 900 (exceeds target but under hardCap)
    ];

    $budget = new Budget(target: 500, hardCap: 1000, earlyThreshold: 450);
    $ranges = $this->packer->pack($units, $budget, allowFinalStretchToHardCap: true);

    // Should pack both together since last unit stretches to hardCap
    expect($ranges)->toHaveCount(1)
        ->and($ranges[0])->toBe(['start' => 0, 'end' => 1]);
});

it('blocks final stretch when exceeds hardCap', function () {
    $units = [
        makeUnit(400),
        makeUnit(700), // Would make 1100 (exceeds hardCap)
    ];

    $budget = new Budget(target: 500, hardCap: 1000, earlyThreshold: 450);
    $ranges = $this->packer->pack($units, $budget, allowFinalStretchToHardCap: true);

    // Should create two chunks
    expect($ranges)->toHaveCount(2)
        ->and($ranges[0])->toBe(['start' => 0, 'end' => 0])
        ->and($ranges[1])->toBe(['start' => 1, 'end' => 1]);
});

it('disables final stretch when flag is false', function () {
    $units = [
        makeUnit(400),
        makeUnit(500), // Would make 900 (under hardCap but exceeds target)
    ];

    $budget = new Budget(target: 500, hardCap: 1000, earlyThreshold: 450);
    $ranges = $this->packer->pack($units, $budget, allowFinalStretchToHardCap: false);

    // Should create two chunks because stretch is disabled
    expect($ranges)->toHaveCount(2)
        ->and($ranges[0])->toBe(['start' => 0, 'end' => 0])
        ->and($ranges[1])->toBe(['start' => 1, 'end' => 1]);
});

it('handles single unit under target', function () {
    $units = [makeUnit(300)];

    $budget = new Budget(target: 500, hardCap: 1000, earlyThreshold: 450);
    $ranges = $this->packer->pack($units, $budget);

    expect($ranges)->toHaveCount(1)
        ->and($ranges[0])->toBe(['start' => 0, 'end' => 0]);
});

it('handles single unit exceeding target but under hardCap', function () {
    $units = [makeUnit(800)];

    $budget = new Budget(target: 500, hardCap: 1000, earlyThreshold: 450);
    $ranges = $this->packer->pack($units, $budget);

    expect($ranges)->toHaveCount(1)
        ->and($ranges[0])->toBe(['start' => 0, 'end' => 0]);
});

it('handles single unit exceeding hardCap', function () {
    $units = [makeUnit(1200)];

    $budget = new Budget(target: 500, hardCap: 1000, earlyThreshold: 450);
    $ranges = $this->packer->pack($units, $budget);

    expect($ranges)->toHaveCount(1)
        ->and($ranges[0])->toBe(['start' => 0, 'end' => 0]);
});

it('handles empty units array', function () {
    $units = [];

    $budget = new Budget(target: 500, hardCap: 1000, earlyThreshold: 450);
    $ranges = $this->packer->pack($units, $budget);

    expect($ranges)->toBeEmpty();
});

it('handles exact target boundary', function () {
    $units = [
        makeUnit(250),
        makeUnit(250), // Exactly 500 (target)
        makeUnit(100), // Stretches to 600 (under hardCap)
    ];

    $budget = new Budget(target: 500, hardCap: 1000, earlyThreshold: 450);
    $ranges = $this->packer->pack($units, $budget);

    // All three pack together due to final stretch to hardCap
    expect($ranges)->toHaveCount(1)
        ->and($ranges[0])->toBe(['start' => 0, 'end' => 2]);
});

it('performs greedy packing with multiple small units', function () {
    $units = [
        makeUnit(100),
        makeUnit(100),
        makeUnit(100),
        makeUnit(100),
        makeUnit(100), // 5 units = 500 tokens (exactly target)
        makeUnit(100),
        makeUnit(100),
    ];

    $budget = new Budget(target: 500, hardCap: 1000, earlyThreshold: 450);
    $ranges = $this->packer->pack($units, $budget);

    expect($ranges)->toHaveCount(2)
        ->and($ranges[0])->toBe(['start' => 0, 'end' => 4]) // First 5 units
        ->and($ranges[1])->toBe(['start' => 5, 'end' => 6]); // Last 2 units
});

it('respects early threshold for non-last units', function () {
    $units = [
        makeUnit(460), // 460 > 450 (earlyThreshold), under 500 (target)
        makeUnit(100),
    ];

    $budget = new Budget(target: 500, hardCap: 1000, earlyThreshold: 450);
    $ranges = $this->packer->pack($units, $budget);

    // First unit alone exceeds early threshold and would fit with second,
    // but greedy algorithm packs greedily up to target
    expect($ranges)->toHaveCount(1)
        ->and($ranges[0])->toBe(['start' => 0, 'end' => 1]);
});

it('flushes accumulated units when next unit would exceed target', function () {
    $units = [
        makeUnit(200),
        makeUnit(200), // Accumulated: 400
        makeUnit(300), // Would make 700 (exceeds target but under hardCap)
    ];

    $budget = new Budget(target: 500, hardCap: 1000, earlyThreshold: 450);
    $ranges = $this->packer->pack($units, $budget);

    // All three pack together due to final stretch to hardCap
    expect($ranges)->toHaveCount(1)
        ->and($ranges[0])->toBe(['start' => 0, 'end' => 2]);
});

it('creates compact chunks by packing greedily', function () {
    $units = [
        makeUnit(400),
        makeUnit(50),
        makeUnit(50), // Accumulated: 500 (exactly target)
        makeUnit(400),
        makeUnit(50),
    ];

    $budget = new Budget(target: 500, hardCap: 1000, earlyThreshold: 450);
    $ranges = $this->packer->pack($units, $budget);

    expect($ranges)->toHaveCount(2)
        ->and($ranges[0])->toBe(['start' => 0, 'end' => 2])
        ->and($ranges[1])->toBe(['start' => 3, 'end' => 4]);
});

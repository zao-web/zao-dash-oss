<?php

namespace App\Services\AI;

/**
 * Immutable result from multi-model consortium.
 */
readonly class ConsortiumResult
{
    public function __construct(
        public string $content,
        public bool $consolidated,
        public array $providers,
        public array $individualResponses,
        public float $confidence,
        public string $reasoning,
        public array $conflicts = [],
    ) {}

    /**
     * Check if the result has high confidence.
     */
    public function isHighConfidence(): bool
    {
        return $this->confidence >= 0.8;
    }

    /**
     * Check if there were conflicts between models.
     */
    public function hasConflicts(): bool
    {
        return ! empty($this->conflicts);
    }

    /**
     * Get the number of models that contributed.
     */
    public function providerCount(): int
    {
        return count($this->providers);
    }

    /**
     * Convert to array for JSON serialization.
     */
    public function toArray(): array
    {
        return [
            'content' => $this->content,
            'consolidated' => $this->consolidated,
            'providers' => $this->providers,
            'provider_count' => $this->providerCount(),
            'confidence' => $this->confidence,
            'is_high_confidence' => $this->isHighConfidence(),
            'reasoning' => $this->reasoning,
            'conflicts' => $this->conflicts,
            'has_conflicts' => $this->hasConflicts(),
        ];
    }
}

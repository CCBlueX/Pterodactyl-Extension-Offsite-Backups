<?php

declare(strict_types=1);

namespace OffsiteBackups\Support;

/**
 * What a retention keeps: the newest N, or the newest per day/ISO week/month bucket, and nothing older than max age.
 */
final readonly class Policy
{
    public function __construct(
        public ?int $newest = null,
        public ?int $daily = null,
        public ?int $weekly = null,
        public ?int $monthly = null,
        public ?int $maxAgeDays = null,
    ) {}

    public static function newest(int $count, ?int $maxAgeDays = null): self
    {
        return new self(newest: $count, maxAgeDays: $maxAgeDays);
    }

    public static function gfs(?int $daily, ?int $weekly, ?int $monthly, ?int $maxAgeDays = null): self
    {
        return new self(daily: $daily, weekly: $weekly, monthly: $monthly, maxAgeDays: $maxAgeDays);
    }

    /** True when no count rule limits the copies, so only max age can delete. */
    public function keepsAll(): bool
    {
        return ($this->newest ?? 0) < 1 && ($this->daily ?? 0) < 1 && ($this->weekly ?? 0) < 1 && ($this->monthly ?? 0) < 1;
    }

    public function isEmpty(): bool
    {
        return $this->keepsAll() && ($this->maxAgeDays ?? 0) < 1;
    }

    public function describe(): ?string
    {
        $parts = array_filter([
            ($this->newest ?? 0) > 0 ? "newest {$this->newest}" : null,
            ($this->daily ?? 0) > 0 ? "{$this->daily} daily" : null,
            ($this->weekly ?? 0) > 0 ? "{$this->weekly} weekly" : null,
            ($this->monthly ?? 0) > 0 ? "{$this->monthly} monthly" : null,
            ($this->maxAgeDays ?? 0) > 0 ? "max {$this->maxAgeDays} days" : null,
        ]);

        return $parts === [] ? null : implode(', ', $parts);
    }
}

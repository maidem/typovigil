<?php

declare(strict_types=1);

namespace Maidemde\Typovigil\Domain;

/**
 * How urgent an update is. Order matters: higher = worse.
 */
enum Severity: string
{
    case Ok = 'ok';
    case Outdated = 'outdated';
    case Critical = 'critical';

    public function rank(): int
    {
        return match ($this) {
            self::Ok => 0,
            self::Outdated => 1,
            self::Critical => 2,
        };
    }

    public function isWorseThan(self $other): bool
    {
        return $this->rank() > $other->rank();
    }
}

<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Domain\Finding;

enum Severity: string
{
    case BLOCKER = 'blocker';
    case WARNING = 'warning';
    case INFO = 'info';
}

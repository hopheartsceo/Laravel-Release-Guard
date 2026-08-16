<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Domain\Finding;

enum Confidence: string
{
    case DEFINITE = 'definite';
    case PROBABLE = 'probable';
    case UNKNOWN = 'unknown';
}

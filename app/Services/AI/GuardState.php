<?php

declare(strict_types=1);

namespace App\Services\AI;

enum GuardState: string
{
    /** Under both caps; everything goes. */
    case Proceed = 'proceed';

    /** Over soft cap; reviewed work still goes, autonomous triggers do not. */
    case PauseAutonomous = 'pause_autonomous';

    /** Over hard cap OR manual kill switch active; no AI calls at all. */
    case PauseAll = 'pause_all';
}

<?php

namespace App\Modules\WalkIns\DTOs;

use App\Modules\WalkIns\Enums\CloseAction;

/** One staff disposition of a queue close: the entry and what staff recorded for it (increment 5.8, D-F). */
final class CloseDisposition
{
    public function __construct(
        public readonly string $entryId,
        public readonly CloseAction $action,
    ) {}
}

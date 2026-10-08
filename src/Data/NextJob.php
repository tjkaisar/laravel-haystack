<?php

namespace Sammyjo20\LaravelHaystack\Data;

use Illuminate\Contracts\Queue\ShouldQueue;
use Sammyjo20\LaravelHaystack\Models\HaystackBale;

class NextJob
{
    /**
     * Constructor
     */
    public function __construct(
        public readonly ShouldQueue $job,
        public readonly HaystackBale $haystackRow,
    ) {
        //
    }
}

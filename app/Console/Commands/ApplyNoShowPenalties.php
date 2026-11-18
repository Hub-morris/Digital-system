<?php

namespace App\Console\Commands;

use App\Services\PenaltyService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('penalties:apply-no-shows')]
#[Description('Mark departed bookings without check-in as no-shows and apply configured penalties')]
class ApplyNoShowPenalties extends Command
{
    public function handle(PenaltyService $penaltyService): int
    {
        $processed = $penaltyService->applyDepartedNoShows();

        $this->info("Processed {$processed} no-show booking(s).");

        return self::SUCCESS;
    }
}

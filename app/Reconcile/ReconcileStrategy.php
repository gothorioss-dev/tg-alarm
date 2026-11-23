<?php

namespace App\Reconcile;

use App\Models\PublicationRecord;
use App\Models\Schedule;

/** Decides whether a new publication replaces the current schedule of its date. */
interface ReconcileStrategy
{
    public function decide(PublicationRecord $incoming, ?Schedule $current): Decision;
}

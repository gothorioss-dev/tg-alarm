<?php

namespace App\Address;

/** What users are told about an outage at their address. */
enum Change: string
{
    case Appeared = 'new';
    case Changed = 'changed';
    case Cancelled = 'cancelled';
    case Ended = 'ended';
}

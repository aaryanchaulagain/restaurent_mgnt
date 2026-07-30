<?php

namespace App\Enums\Partner;

enum RestaurantStatus: string
{
    case PendingSetup = 'pending_setup';
    case Active = 'active';
    case TemporarilyClosed = 'temporarily_closed';
    case Suspended = 'suspended';
    case Disabled = 'disabled';
    case Archived = 'archived';

    public function isPubliclyVisible(): bool
    {
        return in_array($this, [self::Active, self::TemporarilyClosed], true);
    }
}

<?php

namespace App\Enums\Partner;

enum ApplicationStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case ChangesRequested = 'changes_requested';
    case Resubmitted = 'resubmitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';
    case Expired = 'expired';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted, self::Withdrawn],
            self::Submitted => [self::UnderReview, self::Withdrawn],
            self::UnderReview => [self::ChangesRequested, self::Approved, self::Rejected],
            self::ChangesRequested => [self::Resubmitted, self::Withdrawn],
            self::Resubmitted => [self::UnderReview],
            self::Rejected => [self::UnderReview], // reopen only
            self::Approved, self::Withdrawn, self::Expired => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    public function isEditableByApplicant(): bool
    {
        return in_array($this, [self::Draft, self::ChangesRequested], true);
    }

    public function isApprovable(): bool
    {
        return in_array($this, [self::UnderReview, self::Resubmitted], true);
    }
}

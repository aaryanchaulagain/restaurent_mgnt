<?php

namespace App\Policies;

use App\Models\RestaurantApplication;
use App\Models\RestaurantDocument;
use App\Models\User;

class RestaurantApplicationPolicy
{
    public function viewOwn(User $user, RestaurantApplication $application): bool
    {
        return $user->id === $application->applicant_user_id
            && $user->hasPermission('view_own_restaurant_application');
    }

    public function updateOwn(User $user, RestaurantApplication $application): bool
    {
        return $user->id === $application->applicant_user_id
            && $user->hasPermission('edit_own_restaurant_application')
            && $application->status->isEditableByApplicant();
    }

    public function submitOwn(User $user, RestaurantApplication $application): bool
    {
        return $user->id === $application->applicant_user_id
            && $user->hasPermission('submit_restaurant_application');
    }

    public function withdrawOwn(User $user, RestaurantApplication $application): bool
    {
        return $user->id === $application->applicant_user_id
            && $user->hasPermission('withdraw_own_restaurant_application');
    }

    public function review(User $user): bool
    {
        return $user->isSuperAdmin() && $user->hasPermission('review_restaurant_applications');
    }

    public function approve(User $user): bool
    {
        return $user->isSuperAdmin() && $user->hasPermission('approve_restaurant_applications');
    }

    public function reject(User $user): bool
    {
        return $user->isSuperAdmin() && $user->hasPermission('reject_restaurant_applications');
    }

    public function viewAnyAdmin(User $user): bool
    {
        return $user->isSuperAdmin() && $user->hasPermission('view_restaurant_applications');
    }

    public function downloadDocument(User $user, RestaurantDocument $document): bool
    {
        $application = $document->application;
        if (! $application) {
            return false;
        }

        if ($user->id === $application->applicant_user_id) {
            return true;
        }

        return $user->isSuperAdmin() && $user->hasPermission('verify_restaurant_documents');
    }
}

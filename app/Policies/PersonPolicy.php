<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Person;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PersonPolicy
{
    /**
     * Super admins bypass all policy checks via Gate::before() in AppServiceProvider.
     * This method only runs for non-super-admins.
     */
    public function view(User $user, Person $person): bool
    {
        if ($user->assigned_only) {
            // Assigned-only users see only their own clients
            return $person->account_manager_user_id === $user->id;
        }

        // Branch-scoped users: null branch_id = invisible (fail-safe)
        if ($person->branch_id === null) {
            return false;
        }

        return DB::table('user_branch_access')
            ->where('user_id', $user->id)
            ->where('branch_id', $person->branch_id)
            ->exists();
    }

    public function viewAny(User $user): bool
    {
        return true; // list is already scoped by getEloquentQuery()
    }
}

<?php

namespace App\Services;

use App\Exceptions\RoleAssignmentException;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Enforces the four-role model and the single-Owner invariant on role
 * assignment, removal, and demotion. (Requirements 3.1, 3.2, 4.5)
 *
 * The invariant: a Company must always have exactly one Owner. Any operation
 * that would leave a Company with zero Owners (removing/demoting the sole
 * Owner) or more than one Owner (assigning Owner while one already exists) is
 * rejected with a {@see RoleAssignmentException}, leaving the existing Owner
 * assignment unchanged. The database also backs this with a generated-column
 * UNIQUE index (one `owner` per `company_id`) as a last line of defence, but
 * the service is the primary, message-bearing gate.
 *
 * Operations run inside a transaction with a row lock over the Company's users
 * so concurrent role changes cannot race past the invariant check.
 */
class RoleService
{
    /**
     * Assign a role to a Company_User, enforcing the four-role set and the
     * single-Owner invariant.
     *
     * Assigning `owner` is treated as an Owner *transfer*: it is rejected when a
     * different Owner already exists (would create two Owners). Use
     * {@see transferOwnership()} for an explicit, atomic hand-off.
     *
     * @throws RoleAssignmentException on an unknown role or an invariant breach.
     */
    public function assignRole(User $user, string $role): User
    {
        $this->guardRole($role);

        return DB::transaction(function () use ($user, $role): User {
            $companyId = $user->company_id;

            if ($companyId === null) {
                throw new RoleAssignmentException(
                    'Cannot assign a Company role to a user that belongs to no Company.'
                );
            }

            // Lock the Company's user rows for the duration of the check+write.
            $owners = $this->lockOwners($companyId);

            if ($role === User::ROLE_OWNER) {
                $otherOwnerExists = $owners
                    ->contains(fn (User $owner) => $owner->getKey() !== $user->getKey());

                if ($otherOwnerExists) {
                    throw new RoleAssignmentException(
                        'A Company must retain exactly one Owner; assigning a second Owner is not permitted.'
                    );
                }
            } elseif ($user->isOwner() && $this->wouldLeaveNoOwner($owners, $user)) {
                // Assigning a non-Owner role to the sole Owner would leave the
                // Company with zero Owners; that breaches the single-Owner
                // invariant just as demoting the last Owner does.
                throw new RoleAssignmentException(
                    'A Company must retain one Owner; the last Owner cannot be reassigned to a non-Owner role.'
                );
            }

            $user->role = $role;
            $user->save();

            return $user;
        });
    }

    /**
     * Demote an Owner to a non-Owner role. Rejected when it would remove the
     * Company's last Owner. (Requirements 3.2, 4.5)
     *
     * @throws RoleAssignmentException on an unknown role, demoting to `owner`,
     *                                 or removing the last Owner.
     */
    public function demote(User $user, string $role): User
    {
        $this->guardRole($role);

        if ($role === User::ROLE_OWNER) {
            throw new RoleAssignmentException('Demotion target role cannot be Owner.');
        }

        return DB::transaction(function () use ($user, $role): User {
            $companyId = $user->company_id;

            if ($companyId === null) {
                throw new RoleAssignmentException(
                    'Cannot change the role of a user that belongs to no Company.'
                );
            }

            $owners = $this->lockOwners($companyId);

            if ($user->isOwner() && $this->wouldLeaveNoOwner($owners, $user)) {
                throw new RoleAssignmentException(
                    'A Company must retain one Owner; the last Owner cannot be demoted.'
                );
            }

            $user->role = $role;
            $user->save();

            return $user;
        });
    }

    /**
     * Remove a Company_User (revoking access). Rejected when it would remove the
     * Company's last Owner. (Requirements 3.2, 4.4, 4.5)
     *
     * @throws RoleAssignmentException when removing the last Owner.
     */
    public function removeUser(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $companyId = $user->company_id;

            if ($companyId !== null) {
                $owners = $this->lockOwners($companyId);

                if ($user->isOwner() && $this->wouldLeaveNoOwner($owners, $user)) {
                    throw new RoleAssignmentException(
                        'A Company must retain one Owner; the last Owner cannot be removed.'
                    );
                }
            }

            $user->delete();
        });
    }

    /**
     * Atomically transfer the single Owner role from the current Owner to
     * another user of the same Company: the new Owner is promoted and the prior
     * Owner is demoted to the given role, so the Company always has exactly one
     * Owner across the operation. (Requirement 3.2)
     *
     * @throws RoleAssignmentException on cross-Company transfer or an unknown
     *                                 demotion role.
     */
    public function transferOwnership(User $currentOwner, User $newOwner, string $priorOwnerRole = User::ROLE_ADMIN): void
    {
        $this->guardRole($priorOwnerRole);

        if ($priorOwnerRole === User::ROLE_OWNER) {
            throw new RoleAssignmentException('The prior Owner must be demoted to a non-Owner role.');
        }

        DB::transaction(function () use ($currentOwner, $newOwner, $priorOwnerRole): void {
            $companyId = $currentOwner->company_id;

            if ($companyId === null || $newOwner->company_id !== $companyId) {
                throw new RoleAssignmentException(
                    'Ownership can only be transferred between users of the same Company.'
                );
            }

            $this->lockOwners($companyId);

            // Demote first to vacate the single-Owner slot, then promote, so the
            // generated-column UNIQUE index never sees two Owners mid-transfer.
            $currentOwner->role = $priorOwnerRole;
            $currentOwner->save();

            $newOwner->role = User::ROLE_OWNER;
            $newOwner->save();
        });
    }

    /**
     * Reject any role outside the closed four-role set. (Requirement 3.1)
     *
     * @throws RoleAssignmentException
     */
    private function guardRole(string $role): void
    {
        if (! in_array($role, User::ROLES, true)) {
            throw new RoleAssignmentException("Unsupported role: {$role}.");
        }
    }

    /**
     * Lock and return the current Owner rows for a Company. `FOR UPDATE`
     * serialises concurrent role operations on the same Company.
     *
     * @return Collection<int, User>
     */
    private function lockOwners(int $companyId)
    {
        return User::query()
            ->where('company_id', $companyId)
            ->where('role', User::ROLE_OWNER)
            ->lockForUpdate()
            ->get();
    }

    /**
     * Whether demoting/removing $user would leave the Company with no Owner.
     *
     * @param  Collection<int, User>  $owners
     */
    private function wouldLeaveNoOwner($owners, User $user): bool
    {
        $remaining = $owners->reject(fn (User $owner) => $owner->getKey() === $user->getKey());

        return $remaining->isEmpty();
    }
}

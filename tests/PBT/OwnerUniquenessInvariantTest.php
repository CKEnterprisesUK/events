<?php

namespace Tests\PBT;

use App\Exceptions\RoleAssignmentException;
use App\Models\Company;
use App\Models\User;
use App\Services\RoleService;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Property-based test for the single-Owner invariant (Property 5).
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy, and runs against the real
 * MySQL test database so the RoleService's `SELECT ... FOR UPDATE` row locking
 * behaves exactly as in production.
 *
 * The invariant, per Requirements 3.2 and 4.5: a Company always has exactly one
 * Owner. Every way membership changes — adding/promoting via {@see RoleService::assignRole()}
 * (which covers invite-accept role assignment), role changes via
 * {@see RoleService::demote()} / assignRole, ownership hand-off via
 * {@see RoleService::transferOwnership()}, and removal via
 * {@see RoleService::removeUser()} — routes through the RoleService. This test
 * applies random sequences of those operations against a Company that starts
 * with exactly one Owner and asserts the count of Owners is invariant (exactly
 * one) after every operation, and that any rejected operation leaves the
 * standing Owner assignment unchanged.
 */
class OwnerUniquenessInvariantTest extends PbtTestCase
{
    use RefreshDatabase;

    /** Operation kinds exercised in a random sequence. */
    private const OPS = ['assign_owner', 'assign_admin', 'demote', 'remove', 'transfer'];

    /**
     * Property 5: Owner uniqueness invariant — across add / invite-accept /
     * role-change / removal, a Company always has exactly one Owner; any
     * operation that would violate this is rejected and leaves the standing
     * Owner assignment unchanged.
     *
     * **Validates: Requirements 3.2, 4.5**
     */
    // Feature: event-ticketing-platform, Property 5: Owner uniqueness invariant — across add/invite-accept/role-change/removal, always exactly one Owner; violating operations rejected and leave the Owner unchanged
    public function test_a_company_always_has_exactly_one_owner_across_random_role_operations(): void
    {
        $this->forAll(
            // 2–5 non-Owner users seeded alongside the single starting Owner.
            Generator\choose(2, 5),
            // A random sequence of role operations to apply in order.
            Generator\seq(Generator\elements(...self::OPS)),
            // A stream of picks used to select which user each op targets.
            Generator\seq(Generator\nat())
        )
            ->then(function (int $otherCount, array $ops, array $picks): void {
                $roles = app(RoleService::class);

                // Fresh Company seeded with exactly one Owner + some other users.
                $company = Company::factory()->create();

                User::factory()->owner()->create(['company_id' => $company->id]);

                for ($i = 0; $i < $otherCount; $i++) {
                    User::factory()->create([
                        'company_id' => $company->id,
                        'role' => User::ROLE_ADMIN,
                    ]);
                }

                $this->assertOwnerCountIsOne($company, 'seed state');

                $pickIndex = 0;

                foreach ($ops as $op) {
                    // Snapshot the standing Owner id before the operation so we
                    // can confirm a rejected op leaves it unchanged.
                    $ownerBefore = $this->currentOwner($company);
                    $this->assertNotNull($ownerBefore, 'The invariant guarantees a standing Owner before each op.');

                    $members = $this->members($company);
                    // The picks stream is generated independently of the ops
                    // stream and may be shorter; fall back to 0 when exhausted.
                    $pick = $picks[$pickIndex++] ?? 0;
                    $target = $members[$pick % count($members)] ?? null;

                    try {
                        switch ($op) {
                            case 'assign_owner':
                                $roles->assignRole($target, User::ROLE_OWNER);
                                break;
                            case 'assign_admin':
                                $roles->assignRole($target, User::ROLE_ADMIN);
                                break;
                            case 'demote':
                                $roles->demote($target, User::ROLE_ADMIN);
                                break;
                            case 'remove':
                                $roles->removeUser($target);
                                break;
                            case 'transfer':
                                // Hand ownership to a different member if one exists.
                                $newOwner = collect($members)
                                    ->first(fn (User $u) => $u->getKey() !== $ownerBefore->getKey());

                                if ($newOwner === null) {
                                    // Nothing to transfer to; skip this op.
                                    continue 2;
                                }

                                $roles->transferOwnership($ownerBefore, $newOwner);
                                break;
                        }
                    } catch (RoleAssignmentException $e) {
                        // A rejected operation must leave the standing Owner
                        // assignment exactly as it was.
                        $ownerAfter = $this->currentOwner($company);
                        $this->assertNotNull($ownerAfter, "Rejected op ({$op}) must leave a standing Owner.");
                        $this->assertSame(
                            $ownerBefore->getKey(),
                            $ownerAfter->getKey(),
                            "Rejected op ({$op}) must leave the Owner assignment unchanged."
                        );
                    }

                    // Whether the op succeeded or was rejected, the Company must
                    // still have exactly one Owner.
                    $this->assertOwnerCountIsOne($company, "after op {$op}");
                }
            });
    }

    /**
     * Assert the Company has exactly one Owner row in the database.
     */
    private function assertOwnerCountIsOne(Company $company, string $context): void
    {
        $count = User::query()
            ->where('company_id', $company->id)
            ->where('role', User::ROLE_OWNER)
            ->count();

        $this->assertSame(1, $count, "A Company must have exactly one Owner ({$context}).");
    }

    /**
     * The Company's current sole Owner, freshly read from the database.
     */
    private function currentOwner(Company $company): ?User
    {
        return User::query()
            ->where('company_id', $company->id)
            ->where('role', User::ROLE_OWNER)
            ->first();
    }

    /**
     * All current members of the Company, freshly read from the database.
     *
     * @return list<User>
     */
    private function members(Company $company): array
    {
        return User::query()
            ->where('company_id', $company->id)
            ->get()
            ->all();
    }
}

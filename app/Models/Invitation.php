<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\InvitationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An Invitation records that an Owner has invited a user (by email) to join the
 * Owner's Company with an assigned role. (Requirement 4.1)
 *
 * Invitations are Company-owned: the {@see BelongsToCompany} trait registers
 * the global `company_id` tenant scope and auto-fills `company_id` from the
 * resolved tenant on create, so an invitation always belongs to the inviting
 * Company and cross-Company rows never match. Accepting an invitation creates a
 * Company_User scoped to this `company_id`. (Requirements 4.1, 4.2)
 *
 * The assigned `role` is always one of {admin, accountant, scanner}: the Owner
 * role is not invitable — a Company's single Owner is only ever seeded or
 * transferred, never invited. (Requirement 4.6)
 *
 * @property int $id
 * @property int $company_id
 * @property string $email
 * @property string $role
 * @property string $token
 * @property Carbon|null $accepted_at
 * @property Carbon $expires_at
 */
class Invitation extends Model
{
    /** @use HasFactory<InvitationFactory> */
    use BelongsToCompany, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'email',
        'role',
        'token',
        'accepted_at',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Whether this invitation has already been accepted. (4.2)
     */
    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    /**
     * Whether this invitation has passed its expiry.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Whether this invitation can still be accepted (pending and not expired).
     */
    public function isPending(): bool
    {
        return ! $this->isAccepted() && ! $this->isExpired();
    }
}

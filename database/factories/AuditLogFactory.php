<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    /**
     * Default state: a Company-scoped, user-performed event with a simple
     * action and no subject. Use the states below for impersonated staff
     * activity, system events, or a specific action/subject.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'actor_user_id' => null,
            'actor_label' => fake()->name().' <'.fake()->unique()->safeEmail().'>',
            'actor_type' => AuditLog::ACTOR_USER,
            'is_impersonated' => false,
            'impersonator_user_id' => null,
            'action' => AuditLog::EVENT_UPDATED,
            'auditable_type' => null,
            'auditable_id' => null,
            'summary' => 'Did a thing',
            'context' => null,
            'ip_address' => fake()->ipv4(),
            'created_at' => now(),
        ];
    }

    /**
     * A specific action key.
     */
    public function action(string $action): static
    {
        return $this->state(fn (array $attributes) => ['action' => $action]);
    }

    /**
     * An action performed by a Super_Admin while impersonating a Company.
     */
    public function impersonated(?User $superAdmin = null): static
    {
        return $this->state(function (array $attributes) use ($superAdmin): array {
            $superAdmin ??= User::factory()->superAdmin()->create();

            return [
                'actor_user_id' => $superAdmin->getKey(),
                'actor_label' => 'CK staff <'.$superAdmin->email.'>',
                'actor_type' => AuditLog::ACTOR_SUPER_ADMIN,
                'is_impersonated' => true,
                'impersonator_user_id' => $superAdmin->getKey(),
            ];
        });
    }

    /**
     * A system (webhook/queue) event with no acting user.
     */
    public function system(): static
    {
        return $this->state(fn (array $attributes) => [
            'actor_user_id' => null,
            'actor_label' => 'System',
            'actor_type' => AuditLog::ACTOR_SYSTEM,
            'is_impersonated' => false,
            'impersonator_user_id' => null,
        ]);
    }
}

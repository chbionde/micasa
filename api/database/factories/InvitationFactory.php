<?php

namespace Database\Factories;

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Invitation>
 */
class InvitationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'token_hash' => hash('sha256', Str::random(40)),
            'role' => HouseholdRole::Member,
            'created_by' => User::factory(),
            'expires_at' => now()->addDays(7),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => now()]);
    }

    public function accepted(): static
    {
        return $this->state(fn () => [
            'accepted_at' => now(),
            'accepted_by' => User::factory(),
        ]);
    }
}

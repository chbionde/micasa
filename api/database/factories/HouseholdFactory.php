<?php

namespace Database\Factories;

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Household>
 */
class HouseholdFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Casa '.fake()->lastName(),
            'timezone' => 'America/Sao_Paulo',
        ];
    }

    /** Cria a casa já com um admin — o estado mais comum nos testes. */
    public function withAdmin(?User $user = null): static
    {
        return $this->afterCreating(function (Household $household) use ($user) {
            $household->members()->attach(
                ($user ?? User::factory()->create())->id,
                ['role' => HouseholdRole::Admin->value],
            );
        });
    }
}

<?php

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\Invitation;
use App\Models\User;

function casaComPapeis(User $admin, ?User $outro = null, HouseholdRole $papelDoOutro = HouseholdRole::Member): Household
{
    $casa = Household::factory()->create();
    $casa->members()->attach($admin->id, ['role' => HouseholdRole::Admin->value]);

    if ($outro !== null) {
        $casa->members()->attach($outro->id, ['role' => $papelDoOutro->value]);
    }

    return $casa;
}

// ------------------------------------------------------------- renomear

it('admin renomeia a casa', function () {
    $admin = User::factory()->create();
    $casa = casaComPapeis($admin);

    $this->actingAs($admin)
        ->patchJson("/api/households/{$casa->id}", ['nome' => 'Apê da Praia'])
        ->assertOk()
        ->assertJsonPath('data.nome', 'Apê da Praia');

    expect($casa->fresh()->name)->toBe('Apê da Praia');
});

it('admin troca o fuso da casa', function () {
    $admin = User::factory()->create();
    $casa = casaComPapeis($admin);

    $this->actingAs($admin)
        ->patchJson("/api/households/{$casa->id}", ['nome' => $casa->name, 'fuso' => 'America/Manaus'])
        ->assertOk();

    expect($casa->fresh()->timezone)->toBe('America/Manaus');
});

it('recusa fuso inexistente', function () {
    $admin = User::factory()->create();
    $casa = casaComPapeis($admin);

    $this->actingAs($admin)
        ->patchJson("/api/households/{$casa->id}", ['nome' => 'Casa', 'fuso' => 'Marte/Olympus'])
        ->assertInvalid(['fuso']);
});

it('recusa nome vazio', function () {
    $admin = User::factory()->create();
    $casa = casaComPapeis($admin);

    $this->actingAs($admin)
        ->patchJson("/api/households/{$casa->id}", ['nome' => '  '])
        ->assertInvalid(['nome']);
});

it('membro comum não renomeia a casa', function () {
    $admin = User::factory()->create();
    $membro = User::factory()->create();
    $casa = casaComPapeis($admin, $membro);

    $this->actingAs($membro)
        ->patchJson("/api/households/{$casa->id}", ['nome' => 'Casa do Membro'])
        ->assertForbidden();

    expect($casa->fresh()->name)->not->toBe('Casa do Membro');
});

it('estranho não renomeia casa alheia', function () {
    $casa = casaComPapeis(User::factory()->create());

    $this->actingAs(User::factory()->create())
        ->patchJson("/api/households/{$casa->id}", ['nome' => 'Invadida'])
        ->assertForbidden();
});

// ------------------------------------------------- sair e apagar a casa

it('quem sai sozinho apaga a casa e fica sem casa', function () {
    $dono = User::factory()->create();
    $casa = casaComPapeis($dono);
    Invitation::factory()->create(['household_id' => $casa->id, 'created_by' => $dono->id]);
    $dono->active_household_id = $casa->id;
    $dono->save();

    $this->actingAs($dono)
        ->deleteJson("/api/households/{$casa->id}/members/{$dono->id}")
        ->assertNoContent();

    $dono->refresh();

    expect(Household::find($casa->id))->toBeNull()
        ->and($dono->active_household_id)->toBeNull()
        // Cascata levou vínculo e convites junto.
        ->and(Invitation::count())->toBe(0)
        ->and(DB::table('household_user')->count())->toBe(0);
});

it('quem sai sozinho de uma casa mantém as outras', function () {
    $pessoa = User::factory()->create();
    $casaSozinho = casaComPapeis($pessoa);
    $outraCasa = casaComPapeis(User::factory()->create(), $pessoa);
    $pessoa->active_household_id = $casaSozinho->id;
    $pessoa->save();

    $this->actingAs($pessoa)
        ->deleteJson("/api/households/{$casaSozinho->id}/members/{$pessoa->id}")
        ->assertNoContent();

    expect(Household::find($casaSozinho->id))->toBeNull()
        ->and($pessoa->fresh()->active_household_id)->toBe($outraCasa->id);
});

it('último admin com outras pessoas na casa continua bloqueado', function () {
    $admin = User::factory()->create();
    $membro = User::factory()->create();
    $casa = casaComPapeis($admin, $membro);

    $this->actingAs($admin)
        ->deleteJson("/api/households/{$casa->id}/members/{$admin->id}")
        ->assertInvalid(['member']);

    expect(Household::find($casa->id))->not->toBeNull()
        ->and($admin->fresh()->isMemberOf($casa))->toBeTrue();
});

it('não apaga a casa quando sai um membro entre vários', function () {
    $admin = User::factory()->create();
    $membro = User::factory()->create();
    $casa = casaComPapeis($admin, $membro);

    $this->actingAs($membro)
        ->deleteJson("/api/households/{$casa->id}/members/{$membro->id}")
        ->assertNoContent();

    expect(Household::find($casa->id))->not->toBeNull()
        ->and($casa->members()->count())->toBe(1);
});

it('usuário sem casa recebe casa_ativa nula', function () {
    $dono = User::factory()->create();
    $casa = casaComPapeis($dono);
    $dono->active_household_id = $casa->id;
    $dono->save();

    $this->actingAs($dono)->deleteJson("/api/households/{$casa->id}/members/{$dono->id}");

    $this->actingAs($dono->fresh())
        ->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('data.casa_ativa', null);

    $this->getJson('/api/households')->assertOk()->assertJsonCount(0, 'data');
});

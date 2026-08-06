<?php

namespace App\Http\Controllers;

use App\Actions\Households\RemoveMember;
use App\Actions\Households\UpdateMemberRole;
use App\Enums\HouseholdRole;
use App\Http\Requests\Households\UpdateMemberRequest;
use App\Http\Resources\MemberResource;
use App\Models\Household;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class MemberController extends Controller
{
    public function index(Request $request, Household $household): AnonymousResourceCollection
    {
        $this->authorize('view', $household);

        return MemberResource::collection($household->members()->orderBy('name')->get());
    }

    public function update(
        UpdateMemberRequest $request,
        Household $household,
        User $member,
        UpdateMemberRole $updateRole,
    ): MemberResource {
        $updateRole->handle(
            $household,
            $member,
            HouseholdRole::from($request->string('papel')->value()),
        );

        return MemberResource::make($household->members()->findOrFail($member->id));
    }

    public function destroy(
        Request $request,
        Household $household,
        User $member,
        RemoveMember $removeMember,
    ): Response {
        // Sair da própria casa é permitido a qualquer membro; remover
        // outra pessoa exige ser admin.
        if ($request->user()->id !== $member->id) {
            $this->authorize('manageMembers', $household);
        } else {
            $this->authorize('view', $household);
        }

        $removeMember->handle($household, $member);

        return response()->noContent();
    }
}

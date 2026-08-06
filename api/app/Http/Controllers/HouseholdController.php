<?php

namespace App\Http\Controllers;

use App\Actions\Households\SwitchActiveHousehold;
use App\Http\Requests\Households\SwitchActiveHouseholdRequest;
use App\Http\Resources\HouseholdResource;
use App\Http\Resources\UserResource;
use App\Models\Household;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class HouseholdController extends Controller
{
    /** Só as casas de quem pediu — nunca a lista completa do sistema. */
    public function index(Request $request): AnonymousResourceCollection
    {
        return HouseholdResource::collection(
            $request->user()->households()->orderBy('name')->get()
        );
    }

    public function switchActive(
        SwitchActiveHouseholdRequest $request,
        SwitchActiveHousehold $switchActive,
    ): UserResource {
        $user = $request->user();

        $switchActive->handle(
            $user,
            Household::findOrFail($request->integer('household_id')),
        );

        return UserResource::make($user->fresh()->load('activeHousehold'));
    }
}

<?php

namespace App\Http\Controllers;

use App\Actions\Invitations\AcceptInvitation;
use App\Actions\Invitations\CreateInvitation;
use App\Actions\Invitations\RevokeInvitation;
use App\Enums\HouseholdRole;
use App\Http\Requests\Invitations\StoreInvitationRequest;
use App\Http\Resources\InvitationResource;
use App\Models\Household;
use App\Models\Invitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class InvitationController extends Controller
{
    public function index(Request $request, Household $household): AnonymousResourceCollection
    {
        $this->authorize('manageMembers', $household);

        $invitations = $household->invitations()
            ->with('creator')
            ->latest()
            ->get();

        return InvitationResource::collection($invitations);
    }

    public function store(
        StoreInvitationRequest $request,
        Household $household,
        CreateInvitation $createInvitation,
    ): JsonResponse {
        $this->authorize('manageMembers', $household);

        $papel = $request->string('papel')->value();

        ['invitation' => $invitation, 'token' => $token] = $createInvitation->handle(
            $household,
            $request->user(),
            $papel !== '' ? HouseholdRole::from($papel) : HouseholdRole::Member,
        );

        return InvitationResource::make($invitation->load('creator'))
            ->additional([
                // Única vez que o token aparece: o banco guarda só o hash.
                'token' => $token,
            ])
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function destroy(
        Request $request,
        Household $household,
        Invitation $invitation,
        RevokeInvitation $revokeInvitation,
    ): InvitationResource {
        $this->authorize('manageMembers', $household);

        return InvitationResource::make($revokeInvitation->handle($invitation));
    }

    public function accept(
        Request $request,
        string $token,
        AcceptInvitation $acceptInvitation,
    ): JsonResponse {
        $household = $acceptInvitation->handle($token, $request->user());

        return response()->json([
            'casa' => [
                'id' => $household->id,
                'nome' => $household->name,
            ],
        ]);
    }
}

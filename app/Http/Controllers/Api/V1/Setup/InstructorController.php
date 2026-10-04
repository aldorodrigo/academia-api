<?php

namespace App\Http\Controllers\Api\V1\Setup;

use App\Actions\Invitations\CreateInvitation;
use App\Actions\Onboarding\ManageInstructors;
use App\Enums\InvitationStatus;
use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\Invitation;
use App\Models\User;
use App\Rules\MobilePhone;
use App\Support\Onboarding\Team;
use App\Support\Scheduling\ScheduleConflicts;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Paso 4 de la guía: técnicos (invitados con sus categorías) y el admin que también da clases.
 */
class InstructorController extends Controller
{
    public function index(Request $request, CurrentOrganization $current): JsonResponse
    {
        return response()->json(['data' => $this->team($request->user(), $current)]);
    }

    public function store(Request $request, CurrentOrganization $current, ManageInstructors $manage): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'required_without:email', 'string', new MobilePhone(forCodes: false)],
            'email' => ['nullable', 'required_without:phone', 'email', 'max:255'],
            'group_ids' => ['nullable', 'array'],
            'group_ids.*' => ['integer'],
        ], [
            'name.required' => 'Ingresá el nombre.',
            'phone.required_without' => 'Ingresá el celular o el correo.',
            'email.required_without' => 'Ingresá el celular o el correo.',
        ]);

        $result = $manage->invite(
            $current->get(),
            $request->user(),
            $data['name'],
            $data['email'] ?? null,
            $data['group_ids'] ?? [],
            $data['phone'] ?? null,
        );

        // Un técnico que ya existe y queda con dos categorías a la vez: aviso.
        $warnings = $result['user'] === null ? [] : ScheduleConflicts::forInstructor(
            $result['user'],
            $result['user']->instructedGroups()->pluck('groups.id')->all(),
        );

        $item = $result['user'] !== null
            ? $this->userItem($result['user']->load('instructedGroups'), $current)
            : [
                ...$this->invitationItem($result['invitation']),
                'link' => Invitation::urlFor($result['token']),
                'whatsapp_url' => $result['invitation']->whatsappUrl($result['token']),
            ];

        return response()->json(['data' => $item, 'warnings' => $warnings], Response::HTTP_CREATED);
    }

    public function me(Request $request, CurrentOrganization $current, ManageInstructors $manage): JsonResponse
    {
        $data = $request->validate([
            'teaches' => ['required', 'boolean'],
            'group_ids' => ['nullable', 'array'],
            'group_ids.*' => ['integer'],
        ]);

        $manage->setTeaching($current->get(), $request->user(), $data['teaches'], $data['group_ids'] ?? []);

        return response()->json([
            'data' => $this->team($request->user()->fresh(), $current),
            'warnings' => $data['teaches'] ? ScheduleConflicts::forInstructor($request->user(), $data['group_ids'] ?? []) : [],
        ]);
    }

    public function update(Request $request, CurrentOrganization $current, ManageInstructors $manage, int $user): JsonResponse
    {
        $data = $request->validate(['group_ids' => ['present', 'array'], 'group_ids.*' => ['integer']]);
        $instructor = User::query()->findOrFail($user);

        $manage->setGroups($current->get(), $instructor, $data['group_ids']);

        return response()->json([
            'data' => $this->userItem($instructor->load('instructedGroups'), $current),
            'warnings' => ScheduleConflicts::forInstructor($instructor, $data['group_ids']),
        ]);
    }

    public function resend(int $invitation, CreateInvitation $create): JsonResponse
    {
        $invitation = $this->pendingInvitation($invitation);

        $token = $create->resend($invitation);
        $renewed = Invitation::query()->where('token_hash', Invitation::hashToken($token))->firstOrFail();

        return response()->json(['data' => [
            'link' => Invitation::urlFor($token),
            'whatsapp_url' => $renewed->whatsappUrl($token),
        ]]);
    }

    public function revoke(int $invitation): Response
    {
        $this->pendingInvitation($invitation)->update(['revoked_at' => now()]);

        return response()->noContent();
    }

    private function pendingInvitation(int $id): Invitation
    {
        $invitation = Invitation::query()->findOrFail($id);

        abort_unless(in_array($invitation->status(), [InvitationStatus::Pending, InvitationStatus::Expired], true), 404);

        return $invitation;
    }

    /**
     * @return array{me: array{teaches: bool, group_ids: list<int>}, instructors: list<array<string, mixed>>}
     */
    private function team(User $me, CurrentOrganization $current): array
    {
        $organization = $current->get();
        $instructors = Team::instructors($organization);
        $mine = $instructors->firstWhere('id', $me->id);

        return [
            'me' => [
                'teaches' => $mine !== null,
                'group_ids' => $mine === null ? [] : $mine->instructedGroups->pluck('id')->values()->all(),
            ],
            'instructors' => [
                ...$instructors->reject(fn (User $user) => $user->id === $me->id)
                    ->map(fn (User $user) => $this->userItem($user, $current))->values()->all(),
                ...Team::invitations($organization, $instructors)
                    ->map(fn (Invitation $invitation) => $this->invitationItem($invitation))->values()->all(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function userItem(User $user, CurrentOrganization $current): array
    {
        return [
            'user_id' => $user->id,
            'invitation_id' => null,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'status' => 'activo',
            'groups' => $user->instructedGroups
                ->where('organization_id', $current->id())
                ->sortBy('name')
                ->map(fn (Group $group) => ['id' => $group->id, 'name' => $group->name])
                ->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function invitationItem(Invitation $invitation): array
    {
        $groups = Group::query()->whereKey($invitation->group_ids ?? [])->orderBy('name')->get(['id', 'name']);

        return [
            'user_id' => null,
            'invitation_id' => $invitation->id,
            'name' => $invitation->name ?? $invitation->contact(),
            'email' => $invitation->email,
            'phone' => $invitation->phone,
            'status' => $invitation->status() === InvitationStatus::Expired ? 'vencida' : 'invitado',
            'groups' => $groups->map(fn (Group $group) => ['id' => $group->id, 'name' => $group->name])->values(),
        ];
    }
}

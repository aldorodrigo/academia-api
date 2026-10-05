<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Bandeja "Avisos" de la app: la copia de cada aviso (push y correo) que le llegó a la cuenta en la
 * organización activa (y los que no son de ninguna), los más nuevos primero, con leído / no leído.
 * Le llega a toda cuenta aunque no tenga la app instalada ni un correo verificado.
 */
class NotificationInboxController extends Controller
{
    public const PER_PAGE = 20;

    public function __construct(private CurrentOrganization $current) {}

    public function index(Request $request): JsonResponse
    {
        $page = $this->inbox($request->user())->paginate(self::PER_PAGE);

        return response()->json([
            'data' => collect($page->items())->map(fn (DatabaseNotification $notification) => $this->notification($notification))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'unread' => self::unreadCount($request->user(), $this->current->id()),
            ],
        ]);
    }

    public function read(Request $request, string $notification): JsonResponse
    {
        $found = $this->inbox($request->user())->whereKey($notification)->first();
        abort_if($found === null, 404, 'No encontramos este aviso.');

        $found->markAsRead();

        return response()->json(['data' => $this->notification($found)]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $this->inbox($request->user())->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['data' => ['unread' => 0]]);
    }

    /**
     * Avisos sin leer de la cuenta en la organización.
     */
    public static function unreadCount(User $user, ?int $organizationId): int
    {
        return self::scope($user->notifications(), $organizationId)->whereNull('read_at')->count();
    }

    /**
     * @return MorphMany<DatabaseNotification, User>
     */
    private function inbox(User $user): MorphMany
    {
        return self::scope($user->notifications(), $this->current->id());
    }

    /**
     * @template T of MorphMany|Builder
     *
     * @param  T  $query
     * @return T
     */
    private static function scope(MorphMany|Builder $query, ?int $organizationId): MorphMany|Builder
    {
        return $query->where('data->format', 'tuku')
            ->where(fn (Builder $query) => $query->whereNull('organization_id')->orWhere('organization_id', $organizationId));
    }

    /**
     * @return array<string, mixed>
     */
    private function notification(DatabaseNotification $notification): array
    {
        $data = $notification->data;
        $timezone = $this->current->get()?->timezone ?? config('app.timezone');

        return [
            'id' => $notification->id,
            'type' => $data['type'] ?? null,
            'title' => $data['title'] ?? '',
            'body' => $data['body'] ?? '',
            'route' => $data['route'] ?? null,
            'read_at' => $notification->read_at?->setTimezone($timezone)->toIso8601String(),
            'created_at' => $notification->created_at->setTimezone($timezone)->toIso8601String(),
        ];
    }
}

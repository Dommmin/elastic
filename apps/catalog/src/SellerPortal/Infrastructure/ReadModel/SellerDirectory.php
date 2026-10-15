<?php

namespace SellerPortal\Infrastructure\ReadModel;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Odczyt sprzedawców dla UI: lista w panelu i profil zalogowanego użytkownika.
 *
 * Sprzedawcy z seedera (user_id null) są tu pełnoprawnymi wierszami — mają
 * oferty i moderator musi móc ich zablokować, choć nikt się na nich nie loguje.
 */
final class SellerDirectory
{
    /**
     * @return LengthAwarePaginator<int, array{id: int, name: string, city: string, status: string, status_reason: string|null, status_changed_at: string|null, has_account: bool, email: string|null}>
     */
    public function paginate(?string $status, int $perPage = 25): LengthAwarePaginator
    {
        return DB::table('sellers')
            ->leftJoin('users', 'users.id', '=', 'sellers.user_id')
            ->when($status !== null, fn ($query) => $query->where('sellers.status', $status))
            ->orderBy('sellers.id')
            ->select(['sellers.id', 'sellers.name', 'sellers.city', 'sellers.status', 'sellers.status_reason', 'sellers.status_changed_at', 'sellers.user_id', 'users.email'])
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (object $row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'city' => (string) $row->city,
                'status' => (string) $row->status,
                'status_reason' => $row->status_reason,
                'status_changed_at' => $row->status_changed_at,
                'has_account' => $row->user_id !== null,
                'email' => $row->email,
            ]);
    }

    /**
     * @return array{id: int, name: string, city: string, status: string, status_reason: string|null}|null
     */
    public function forUser(int $userId): ?array
    {
        $row = DB::table('sellers')
            ->where('user_id', $userId)
            ->first(['id', 'name', 'city', 'status', 'status_reason']);

        return $row === null ? null : [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'city' => (string) $row->city,
            'status' => (string) $row->status,
            'status_reason' => $row->status_reason,
        ];
    }
}

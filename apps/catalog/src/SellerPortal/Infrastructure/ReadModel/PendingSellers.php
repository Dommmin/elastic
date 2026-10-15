<?php

namespace SellerPortal\Infrastructure\ReadModel;

use Illuminate\Support\Facades\DB;

/**
 * Kolejka zgłoszeń sprzedawców czekających na moderatora.
 *
 * DLACZEGO model odczytu, a nie repozytorium: lista dla UI nie potrzebuje
 * agregatów ani reguł, tylko płaskich wierszy w kolejności „najstarsze
 * pierwsze”, żeby nikt nie czekał w nieskończoność.
 */
final class PendingSellers
{
    /**
     * @return list<array{id: int, name: string, city: string, email: string|null, created_at: string|null}>
     */
    public function all(): array
    {
        return DB::table('sellers')
            ->leftJoin('users', 'users.id', '=', 'sellers.user_id')
            ->where('sellers.status', 'pending')
            ->orderBy('sellers.created_at')
            ->orderBy('sellers.id')
            ->get(['sellers.id', 'sellers.name', 'sellers.city', 'users.email', 'sellers.created_at'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'city' => (string) $row->city,
                'email' => $row->email,
                'created_at' => $row->created_at,
            ])
            ->values()
            ->all();
    }
}

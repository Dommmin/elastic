<?php

namespace App\Seller360\Simulation;

use App\Support\TaxIdGenerator;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Random\Randomizer;
use RuntimeException;
use SellerPortal\Application\Port\DomainEventDispatcher;
use SellerPortal\Application\Seller\ApproveSeller;
use SellerPortal\Application\Seller\ApproveSellerHandler;
use SellerPortal\Domain\Seller\City;
use SellerPortal\Domain\Seller\Seller;
use SellerPortal\Domain\Seller\SellerId;
use SellerPortal\Domain\Seller\SellerName;
use SellerPortal\Domain\Seller\SellerRepository;
use SellerPortal\Domain\Seller\TaxId;
use SellerPortal\Domain\Shared\UserId;

/**
 * Syntetyczni sprzedawcy: plan (deterministyczny z ziarna) i zapis drogą domenową.
 *
 * DLACZEGO jawne ID `5 000 000 + i` zamiast sekwencji: ten sam seed daje te same ID, więc
 * `planted.json` jest powtarzalny także po `--purge` (sekwencja Postgresa się nie cofa). NIP to
 * `TaxIdGenerator::forSeed(5 000 000 + i)` — rozłączny z ziarnami prawdziwych sprzedawców (= ich id),
 * fabryk (60M+) i RaceFixture (80M+).
 *
 * Zapis: `Seller::register` → `SellerRepository::save` → `ApproveSellerHandler`, czyli te same
 * zdarzenia `seller.*` w outboksie co przy rejestracji z UI (projektor buduje z nich `profile`, `nip`,
 * `region`, `synthetic`). Konto użytkownika wstawiamy wprost (`users.synthetic = true`), bo to tylko
 * właściciel sprzedawcy, bez logowania.
 */
final class SyntheticSellers
{
    public const ID_BASE = 5_000_000;

    private const PREFIXES = ['Sklep', 'Hurtownia', 'Pracownia', 'Dom', 'Studio', 'Magazyn', 'Manufaktura', 'Butik'];

    private const CORES = ['Zielony Las', 'Pod Lipą', 'Bałtyk', 'Tatry', 'Wisła', 'Mazury', 'Złoty Kłos', 'Stary Młyn', 'Jantar', 'Bursztyn', 'Kasztan', 'Sosna'];

    private const ACCESS_TOKEN_LENGTH = 40;

    public function __construct(
        private readonly SellerRepository $sellers,
        private readonly ApproveSellerHandler $approve,
        private readonly DomainEventDispatcher $events,
    ) {}

    /**
     * Miasta z listy referencyjnej, podzielone na mazowieckie i pozostałe. Pomijamy nazwy, które po
     * normalizacji (jak w `RegionResolver` w search) występują w kilku województwach — region takiego
     * miasta byłby niejednoznaczny.
     *
     * @return array{mazowieckie: list<string>, other: list<string>}
     */
    public static function cities(string $csvPath): array
    {
        $handle = @fopen($csvPath, 'r');
        if ($handle === false) {
            throw new RuntimeException("Nie można odczytać listy miast: {$csvPath}");
        }

        $byKey = [];
        try {
            fgetcsv($handle, escape: '');
            while (($row = fgetcsv($handle, escape: '')) !== false) {
                if (! isset($row[0], $row[1]) || trim($row[0]) === '') {
                    continue;
                }
                $key = strtr(mb_strtolower(trim($row[0])), ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z']);
                $byKey[$key][] = [trim($row[0]), trim($row[1])];
            }
        } finally {
            fclose($handle);
        }

        $cities = ['mazowieckie' => [], 'other' => []];
        foreach ($byKey as $entries) {
            if (count(array_unique(array_column($entries, 1))) !== 1) {
                continue;
            }
            [$city, $region] = $entries[0];
            $cities[$region === PlantedSellers::REGION ? 'mazowieckie' : 'other'][] = $city;
        }

        return $cities;
    }

    /**
     * Plan jednego sprzedawcy. Zasadzeni i bliskie pudła mieszkają w mazowieckim, pudło „region” poza
     * nim; pozostali w dowolnym mieście z listy.
     *
     * @param  array{mazowieckie: list<string>, other: list<string>}  $cities
     * @return array{index: int, id: int, nip: string, name: string, city: string}
     */
    public static function draw(Randomizer $random, int $index, ?string $role, array $cities): array
    {
        $pool = match (true) {
            $role === null => [...$cities['mazowieckie'], ...$cities['other']],
            PlantedSellers::satisfies($role, 'region') => $cities['mazowieckie'],
            default => $cities['other'],
        };

        $name = sprintf(
            '%s %s %d',
            self::PREFIXES[$random->getInt(0, count(self::PREFIXES) - 1)],
            self::CORES[$random->getInt(0, count(self::CORES) - 1)],
            $index + 1,
        );

        return [
            'index' => $index,
            'id' => self::ID_BASE + $index,
            'nip' => TaxIdGenerator::forSeed(self::ID_BASE + $index),
            'name' => $name,
            'city' => $pool[$random->getInt(0, count($pool) - 1)],
        ];
    }

    /**
     * Użytkownik + rejestracja + akceptacja. Wołać w transakcji paczki (handlery robią zagnieżdżone).
     *
     * @param  array{index: int, id: int, nip: string, name: string, city: string}  $spec
     */
    public function persist(array $spec, string $passwordHash, int $moderatorId, DateTimeImmutable $now): void
    {
        $userId = DB::table('users')->insertGetId([
            'name' => $spec['name'],
            'email' => "seller360-sim-{$spec['id']}@example.invalid",
            'password' => $passwordHash,
            'synthetic' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $seller = Seller::register(
            SellerId::fromInt($spec['id']),
            UserId::fromInt((int) $userId),
            SellerName::fromString($spec['name']),
            City::fromString($spec['city']),
            TaxId::fromString($spec['nip']),
            true,
            $now,
        );
        $this->sellers->save($seller);
        $this->events->dispatch($seller->releaseEvents());

        $this->approve->handle(new ApproveSeller($spec['id'], $moderatorId));
    }

    /** Hasło nie do odgadnięcia (konto syntetyczne nie służy do logowania), liczone raz na przebieg. */
    public static function unusablePasswordHash(): string
    {
        return password_hash(bin2hex(random_bytes(self::ACCESS_TOKEN_LENGTH / 2)), PASSWORD_BCRYPT);
    }

    /**
     * Usuwa syntetycznych sprzedawców z ofertami, kontami rozliczeniowymi i fakturami oraz
     * syntetycznych użytkowników. Outboksu nie ruszamy: to historia już wysłanych zdarzeń.
     *
     * @return array{sellers: int, users: int, invoices: int, offers: int}
     */
    public static function purge(): array
    {
        $counts = ['sellers' => 0, 'users' => 0, 'invoices' => 0, 'offers' => 0];

        DB::table('sellers')->where('synthetic', true)->orderBy('id')->select('id')
            ->chunkById(1000, function ($rows) use (&$counts): void {
                $ids = $rows->pluck('id')->all();
                DB::transaction(function () use ($ids, &$counts): void {
                    $counts['offers'] += DB::table('offers')->whereIn('seller_id', $ids)->delete();
                    $counts['invoices'] += DB::table('invoices')->whereIn('seller_id', $ids)->delete();
                    DB::table('billing_accounts')->whereIn('seller_id', $ids)->delete();
                    $counts['sellers'] += DB::table('sellers')->whereIn('id', $ids)->delete();
                });
            });

        $counts['users'] = DB::table('users')->where('synthetic', true)->delete();

        return $counts;
    }
}

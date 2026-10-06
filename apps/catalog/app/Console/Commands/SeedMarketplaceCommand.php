<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Offer;
use App\Models\Outbox;
use App\Models\Product;
use App\Models\Seller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * `php artisan marketplace:seed {--n=1500} {--fresh}` — dane pod ETAP 7
 * (wyszukiwarka): tyle produktów/ofert, żeby facety i histogram cen miały
 * sens, bez pretensji do wolumenu docelowego (5 mln, D-13 — to ETAP 8).
 *
 * DLACZEGO produkty i oferty NIE idą przez `Offer::createWithOutbox()`
 * per-oferta, tylko oferty tworzone są zwykłym `Offer::create()`, a event
 * emitowany jest RĘCZNIE, jeden na produkt, PO dodaniu wszystkich jego ofert:
 *
 * `ProductSyncHandler` (Symfony) używa external versioning per DOKUMENT ES
 * (jeden dokument = jeden produkt), ale `sequence` w evencie to wersja
 * AGREGATU, który go wygenerował — `product.version` dla zdarzeń produktowych,
 * `offer.version` dla zdarzeń ofertowych. To DWA NIEZALEŻNE liczniki pisane
 * do TEGO SAMEGO pola wersji dokumentu. Świeży produkt ma version=1;
 * świeża oferta TEGO SAMEGO produktu też ma version=1 (pierwsza oferta na
 * koncie) — `offer.created` z sequence=1 próbowałby nadpisać dokument, który
 * `product.created` (też sequence=1) już ustawił na wersję 1, i ES odrzuci to
 * jako "stale" (409), bo external version musi ROSNĄĆ. Efekt: część ofert
 * nigdy nie trafia do indeksu przy typowym tempie seedowania. To jest
 * udokumentowane, świadomie NIE naprawione ograniczenie z ETAPU 6 (patrz
 * README, sekcja "Stan realizacji" i RUNBOOK #018) — kolizja sequence między
 * różnymi agregatami piszącymi do tego samego dokumentu.
 *
 * Zamiast obchodzić to hackiem w produkcyjnym kodzie (`ProductSyncHandler`
 * zostaje nietknięty — to nie jest miejsce na łatanie znanego ograniczenia
 * architektury), seeder unika kolizji STRUKTURALNIE: buduje pełny stan
 * produktu (wszystkie oferty) w Postgresie NAJPIERW, a dopiero potem emituje
 * DOKŁADNIE JEDNO zdarzenie `product.created` (sequence = product.version).
 * `search-consumer` i tak robi pełny read-back (`GET .../projection`), więc
 * jeden event wystarcza, by zaindeksować produkt razem ze wszystkimi jego
 * ofertami. To odtwarza realny pipeline (outbox → RabbitMQ → consumer → ES)
 * bez wchodzenia w znany bug — nie jest to "ominięcie outboxu", tylko wybór
 * GRANULARNOŚCI zdarzeń zgodny z tym, jak i tak działa read-back.
 */
class SeedMarketplaceCommand extends Command
{
    protected $signature = 'marketplace:seed
        {--n=1500 : Ile produktów wygenerować}
        {--fresh : Wyczyść istniejące dane katalogu przed seedowaniem}
        {--no-publish : Nie wołaj outbox:publish na końcu (zostaw zdarzenia w kolejce)}';

    protected $description = 'Generuje dane katalogu (marki, kategorie, sprzedawców, produkty, oferty) pod ETAP 7 i publikuje je do ES przez prawdziwy pipeline outboxu';

    /**
     * Kategorie z 2-poziomową hierarchią i szablonami nazw — nie losowe
     * `fake()->words()`, żeby zapytania pełnotekstowe i synonimy
     * (infra/elasticsearch/analysis/synonyms.txt) miały co faktycznie robić.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private const TAXONOMY = [
        'Elektronika' => [
            'Laptopy' => ['Laptop {model} 14"', 'Laptop {model} 15.6" gamingowy', 'Ultrabook {model} 13"'],
            'Smartfony' => ['Smartfon {model} 128GB', 'Smartfon {model} 256GB 5G', 'Smartfon {model} Pro'],
            'Słuchawki' => ['Słuchawki bezprzewodowe {model}', 'Słuchawki douszne {model} ANC', 'Słuchawki nauszne {model}'],
        ],
        'Odzież' => [
            'Buty do biegania' => ['Buty do biegania {model}', 'Buty do biegania {model} Boost', 'Buty do biegania {model} Trail'],
            'Kurtki' => ['Kurtka zimowa {model}', 'Kurtka przeciwdeszczowa {model}', 'Kurtka softshell {model}'],
            'Koszulki' => ['Koszulka sportowa {model}', 'Koszulka bawełniana {model}', 'Koszulka termoaktywna {model}'],
        ],
        'Dom i ogród' => [
            'Meble ogrodowe' => ['Stół ogrodowy {model}', 'Zestaw mebli ogrodowych {model}', 'Leżak ogrodowy {model}'],
            'Oświetlenie' => ['Lampa LED {model}', 'Żyrandol {model}', 'Lampa ogrodowa solarna {model}'],
            'Narzędzia' => ['Wiertarka {model}', 'Szlifierka kątowa {model}', 'Zestaw narzędzi {model}'],
        ],
        'Sport' => [
            'Rowery' => ['Rower górski {model} 29"', 'Rower szosowy {model}', 'Rower elektryczny {model}'],
            'Fitness' => ['Hantle {model} zestaw', 'Mata do jogi {model}', 'Ławeczka treningowa {model}'],
        ],
    ];

    private const MODELS = ['Alpha', 'Nova', 'Pro X', 'Vento', 'Zenit', 'Kompakt', 'Extreme', 'Neo', 'Prime', 'Ultra'];

    public function handle(): int
    {
        $target = (int) $this->option('n');

        if ($this->option('fresh')) {
            $this->warn('Czyszczę istniejące dane katalogu (--fresh)...');
            DB::table('outbox')->truncate();
            DB::table('offers')->truncate();
            DB::table('products')->truncate();
            DB::table('categories')->truncate();
            DB::table('brands')->truncate();
            DB::table('sellers')->truncate();
        }

        // Stały seed Fakera — powtarzalne dane pod harness `_rank_eval`
        // (Faza 5), gdzie zapytania kontrolne odnoszą się do konkretnych
        // nazw produktów.
        fake()->seed(42);

        $categories = $this->seedCategories();
        $brands = $this->seedBrands();
        $sellers = $this->seedSellers();

        $this->info("Tworzę {$target} produktów...");
        $bar = $this->output->createProgressBar($target);

        for ($i = 0; $i < $target; $i++) {
            $this->seedProduct($categories, $brands, $sellers);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        if (! $this->option('no-publish')) {
            $this->publishOutboxUntilDrained();
        } else {
            $this->info('Zdarzenia zostawione w outboksie (--no-publish) — uruchom `php artisan outbox:publish` ręcznie.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{id: int, path: string}> lista kategorii liścia (leaf) z gotową ścieżką
     */
    private function seedCategories(): array
    {
        $leaves = [];

        foreach (self::TAXONOMY as $rootName => $subcategories) {
            $root = Category::create([
                'version' => 1,
                'parent_id' => null,
                'name' => $rootName,
                'slug' => Str::slug($rootName),
                'path' => null,
            ]);
            $root->update(['path' => (string) $root->id]);

            foreach (array_keys($subcategories) as $subName) {
                $sub = Category::create([
                    'version' => 1,
                    'parent_id' => $root->id,
                    'name' => $subName,
                    'slug' => Str::slug($rootName.'-'.$subName),
                    'path' => null,
                ]);
                $sub->update(['path' => $root->id.'.'.$sub->id]);

                $leaves[] = [
                    'id' => $sub->id,
                    'path' => $root->id.'.'.$sub->id,
                    'root' => $rootName,
                    'name' => $subName,
                ];
            }
        }

        return $leaves;
    }

    /**
     * @return array<int, Brand>
     */
    private function seedBrands(): array
    {
        return Brand::factory()->count(24)->create()->all();
    }

    /**
     * @return array<int, Seller>
     */
    private function seedSellers(): array
    {
        return Seller::factory()->count(60)->create()->all();
    }

    /**
     * @param  array<int, array{id: int, path: string, root: string, name: string}>  $categories
     * @param  array<int, Brand>  $brands
     * @param  array<int, Seller>  $sellers
     */
    private function seedProduct(array $categories, array $brands, array $sellers): void
    {
        $category = $categories[array_rand($categories)];
        $brand = $brands[array_rand($brands)];
        $template = self::TAXONOMY[$category['root']][$category['name']][array_rand(self::TAXONOMY[$category['root']][$category['name']])];
        $model = self::MODELS[array_rand(self::MODELS)];
        $name = trim($brand->name.' '.str_replace('{model}', $model, $template));

        DB::transaction(function () use ($category, $brand, $sellers, $name, $model) {
            $product = Product::create([
                'version' => 1,
                'brand_id' => $brand->id,
                'category_id' => $category['id'],
                'name' => $name,
                'description' => fake()->paragraph(),
                'attributes' => ['color' => fake()->safeColorName(), 'model' => $model],
                'ean' => fake()->unique()->ean13(),
            ]);

            $offerCount = fake()->numberBetween(2, 4);
            $offerSellers = fake()->randomElements($sellers, min($offerCount, count($sellers)));

            foreach ($offerSellers as $seller) {
                Offer::create([
                    'version' => 1,
                    'product_id' => $product->id,
                    'seller_id' => $seller->id,
                    'price_cents' => fake()->numberBetween(2000, 800000),
                    'currency' => 'PLN',
                    'stock' => fake()->numberBetween(0, 50),
                    'condition' => fake()->randomElement(['new', 'used']),
                    'shipping_days' => fake()->numberBetween(1, 14),
                    'active' => true,
                ]);
            }

            // Jedno zdarzenie na produkt, PO dodaniu ofert — patrz docblock klasy.
            Outbox::create([
                'event_id' => (string) Str::ulid(),
                'aggregate_type' => 'product',
                'aggregate_id' => (string) $product->id,
                'event_type' => 'product.created',
                'payload' => $product->attributesToArray(),
                'sequence' => $product->version,
                'occurred_at' => now(),
            ]);
        });
    }

    private function publishOutboxUntilDrained(): void
    {
        $remaining = Outbox::whereNull('published_at')->count();
        $this->info("Publikuję {$remaining} zdarzeń outboksu do RabbitMQ...");

        $safetyRounds = 0;

        while ($remaining > 0 && $safetyRounds < 200) {
            Artisan::call('outbox:publish', ['--batch' => 1000]);
            $remaining = Outbox::whereNull('published_at')->count();
            $safetyRounds++;
        }

        if ($remaining > 0) {
            $this->warn("Zostało {$remaining} niepublikowanych zdarzeń po {$safetyRounds} próbach — sprawdź połączenie z RabbitMQ.");

            return;
        }

        $this->info('Wszystkie zdarzenia opublikowane. search-consumer zaindeksuje je asynchronicznie — sprawdź `make mq-status` / `make es-health`.');
    }
}

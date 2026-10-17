<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Ordering\Application\Order\CartLine;
use Ordering\Application\Order\PlaceOrdersFromCart;
use Ordering\Application\Order\PlaceOrdersFromCartHandler;
use SharedKernel\DomainException;

/**
 * Jeden kupujący w `orders:race-test`: osobny proces, żeby złożenia szły
 * naprawdę równolegle (osobne połączenia z bazą), a nie po kolei w pętli.
 *
 * DLACZEGO prawdziwy handler, a nie INSERT: mierzymy ścieżkę, którą idzie
 * kupujący z UI (snapshot oferty, transakcja, job sagi w tabeli `jobs`).
 * DLACZEGO `--at`: start Laravela trwa dłużej niż samo złożenie, więc bez
 * wspólnej chwili startu procesy rozjechałyby się w czasie i nie
 * rywalizowałyby o ofertę. Wynik to jedna linia JSON na stdout, którą
 * czyta komenda główna; odmowa domeny to wynik, nie błąd procesu.
 */
class OrdersRaceTestBuyerCommand extends Command
{
    protected $signature = 'orders:race-test:buyer
        {userId : ID kupującego z fixture}
        {offerId : ID oferty z fixture}
        {--at= : Chwila startu (Unix, ms), na którą czekają wszystkie procesy}';

    protected $description = 'Jeden kupujący testu współbieżności (wołany przez orders:race-test)';

    protected $hidden = true;

    public function handle(PlaceOrdersFromCartHandler $handler): int
    {
        $offerId = (int) $this->argument('offerId');
        $price = (int) DB::table('offers')->where('id', $offerId)->value('price_cents');
        $command = new PlaceOrdersFromCart((int) $this->argument('userId'), [new CartLine($offerId, 1, $price)]);

        $this->waitForStart();

        $started = hrtime(true);

        try {
            $orderIds = $handler->handle($command);
            $result = ['result' => 'placed', 'orders' => array_map(static fn ($id): int => $id->value(), $orderIds)];
        } catch (DomainException $e) {
            $result = ['result' => 'refused', 'reason' => defined($e::class.'::CODE') ? (string) constant($e::class.'::CODE') : $e::class];
        }

        $result['ms'] = round((hrtime(true) - $started) / 1e6, 3);

        $this->line((string) json_encode($result));

        return self::SUCCESS;
    }

    private function waitForStart(): void
    {
        $at = $this->option('at');

        if ($at === null) {
            return;
        }

        $delayMicros = (int) (((float) $at - microtime(true) * 1000) * 1000);

        if ($delayMicros > 0) {
            usleep($delayMicros);
        }
    }
}

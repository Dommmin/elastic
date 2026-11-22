<?php

namespace App\Tests\Integration\Messenger;

use App\MessageHandler\AnalyticsEventHandler;
use App\MessageHandler\ProductSyncHandler;
use App\MessageHandler\Seller360EventHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Regresja: AnalyticsEventHandler (BatchHandlerInterface) dostawał od frameworka dodatkowy,
 * bezwarunkowy tag handlera i odpalał się na każdym transporcie. Każdy transport ma mieć
 * dokładnie swój handler.
 */
final class AnalyticsHandlerRegistrationTest extends KernelTestCase
{
    /** @return list<string> warunki (`from_transport=...` albo `-`) wpisów danego handlera w debug:messenger */
    private function registrations(string $handlerClass): array
    {
        $kernel = self::bootKernel();
        $tester = new CommandTester((new Application($kernel))->find('debug:messenger'));
        $tester->execute([], ['decorated' => false]);

        preg_match_all('/handled by '.preg_quote($handlerClass, '/').'(?: \(when ([^)]+)\))?/', $tester->getDisplay(), $m);

        return array_map(static fn (string $when) => $when === '' ? '-' : $when, $m[1]);
    }

    /** @return iterable<string, array{class-string, string}> */
    public static function handlers(): iterable
    {
        yield 'analytics' => [AnalyticsEventHandler::class, 'from_transport=analytics'];
        yield 'seller360' => [Seller360EventHandler::class, 'from_transport=seller360'];
        yield 'product_sync' => [ProductSyncHandler::class, 'from_transport=product_sync'];
    }

    #[DataProvider('handlers')]
    public function test_handler_ma_jedna_rejestracje_tylko_na_swoim_transporcie(string $handlerClass, string $when): void
    {
        self::assertSame([$when], $this->registrations($handlerClass));
    }
}

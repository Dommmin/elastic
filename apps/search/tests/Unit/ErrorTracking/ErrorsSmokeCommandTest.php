<?php

namespace App\Tests\Unit\ErrorTracking;

use App\Command\ErrorsSmokeCommand;
use App\Tracing\TraceContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ErrorsSmokeCommandTest extends TestCase
{
    public function test_odmawia_poza_srodowiskiem_dev(): void
    {
        $trace = new TraceContext;
        $tester = new CommandTester(new ErrorsSmokeCommand($trace, 'prod'));

        self::assertSame(Command::FAILURE, $tester->execute(['marker' => 'abc']));
        self::assertNull($trace->get(), 'Poza dev komenda niczego nie dotyka.');
    }

    public function test_w_dev_ustawia_slad_i_rzuca_wyjatek_ze_znacznikiem(): void
    {
        $trace = new TraceContext;
        $tester = new CommandTester(new ErrorsSmokeCommand($trace, 'dev'));

        try {
            $tester->execute(['marker' => 'abc-123']);
            self::fail('Komenda powinna rzucić wyjątek.');
        } catch (\RuntimeException $e) {
            self::assertSame('errors-smoke:search:abc-123', $e->getMessage());
        }

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $trace->get());
    }

    public function test_znacznik_jest_czyszczony_z_niebezpiecznych_znakow(): void
    {
        $tester = new CommandTester(new ErrorsSmokeCommand(new TraceContext, 'dev'));

        try {
            $tester->execute(['marker' => 'x"><script>']);
            self::fail('Komenda powinna rzucić wyjątek.');
        } catch (\RuntimeException $e) {
            self::assertSame('errors-smoke:search:xscript', $e->getMessage());
        }
    }
}

<?php

namespace App\Tests\Unit\Security;

use App\Security\Alert;
use App\Security\AlertWriter;
use App\Security\DetectionReadiness;
use App\Security\Detector;
use App\Security\SecurityDetectJob;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SecurityDetectJobTest extends TestCase
{
    private function detector(string $id, int &$calls, ?\DateTimeImmutable &$from, ?\DateTimeImmutable &$to, array $alerts = []): Detector
    {
        return new class($id, $calls, $from, $to, $alerts) implements Detector {
            public function __construct(private string $id, private int &$calls, private ?\DateTimeImmutable &$from, private ?\DateTimeImmutable &$to, private array $alerts) {}

            public function id(): string { return $this->id; }

            public function detect(\DateTimeImmutable $from, \DateTimeImmutable $to): array
            {
                ++$this->calls;
                $this->from = $from;
                $this->to = $to;

                return $this->alerts;
            }
        };
    }

    public function test_nazwa_i_interwal(): void
    {
        $job = new SecurityDetectJob([], $this->createStub(AlertWriter::class));

        self::assertSame('security-detect', $job->name());
        self::assertSame(300, $job->intervalSeconds());
    }

    public function test_run_woła_kazdy_detektor_raz_za_ostatnie_2_godziny(): void
    {
        $a = $b = 0;
        $fromA = $toA = $fromB = $toB = null;
        $now = new \DateTimeImmutable('2026-11-25T12:00:00Z');
        $writer = $this->createMock(AlertWriter::class);
        $writer->expects(self::exactly(2))->method('write')->with([])->willReturn(0);

        $job = new SecurityDetectJob([
            $this->detector('a', $a, $fromA, $toA),
            $this->detector('b', $b, $fromB, $toB),
        ], $writer, static fn (): \DateTimeImmutable => $now);
        $job->run();

        self::assertSame([1, 1], [$a, $b]);
        self::assertEquals(new \DateTimeImmutable('2026-11-25T10:00:00Z'), $fromA);
        self::assertEquals($now, $toA);
    }

    public function test_alerty_trafiaja_do_writera_a_filtr_regul_pomija_inne_detektory(): void
    {
        $a = $b = 0;
        $f = $t = null;
        $alert = new Alert('a', 'esql', 'medium', 'ip_hash', 'x', new \DateTimeImmutable('2026-11-25T10:00:00Z'), new \DateTimeImmutable('2026-11-25T11:00:00Z'), 'm', [], false);
        $writer = $this->createMock(AlertWriter::class);
        $writer->expects(self::once())->method('write')->with([$alert])->willReturn(1);

        $job = new SecurityDetectJob([$this->detector('a', $a, $f, $t, [$alert]), $this->detector('b', $b, $f, $t)], $writer);
        $counts = $job->detect(new \DateTimeImmutable('-1 day'), new \DateTimeImmutable(), 'a');

        self::assertSame(['a' => 1], $counts);
        self::assertSame([1, 0], [$a, $b]);
    }

    public function test_wyjatek_jednego_detektora_nie_blokuje_pozostalych_i_job_rzuca_na_koncu(): void
    {
        $ok = 0;
        $f = $t = null;
        $failing = new class implements Detector {
            public function id(): string { return 'zepsuty'; }

            public function detect(\DateTimeImmutable $from, \DateTimeImmutable $to): array { throw new \RuntimeException('ES nie odpowiada'); }
        };
        $writer = $this->createMock(AlertWriter::class);
        $writer->expects(self::once())->method('write')->willReturn(0);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(self::stringContains('zepsuty'), self::anything());

        $job = new SecurityDetectJob([$failing, $this->detector('dobry', $ok, $f, $t)], $writer, null, $logger);

        try {
            $job->run();
            self::fail('Job miał rzucić po przebiegu wszystkich detektorów.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('zepsuty', $e->getMessage());
        }
        self::assertSame(1, $ok);
    }

    public function test_detect_zbiera_bledy_zamiast_je_rzucac(): void
    {
        $ok = 0;
        $f = $t = null;
        $failing = new class implements Detector {
            public function id(): string { return 'zepsuty'; }

            public function detect(\DateTimeImmutable $from, \DateTimeImmutable $to): array { throw new \RuntimeException('ES nie odpowiada'); }
        };
        $writer = $this->createStub(AlertWriter::class);
        $job = new SecurityDetectJob([$failing, $this->detector('dobry', $ok, $f, $t)], $writer);

        $failures = [];
        $counts = $job->detect(new \DateTimeImmutable('-1 day'), new \DateTimeImmutable(), null, $failures);

        self::assertSame(['dobry' => 0], $counts);
        self::assertSame(['zepsuty'], array_keys($failures));
        self::assertSame(1, $ok);
    }

    public function test_nieskonfigurowana_detekcja_to_jedno_ostrzezenie_bez_wyjatku_i_bez_detektorow(): void
    {
        $a = 0;
        $f = $t = null;
        $readiness = $this->createStub(DetectionReadiness::class);
        $readiness->method('missing')->willReturn('brak indeksu alerts-security (make detection-setup)');
        $writer = $this->createMock(AlertWriter::class);
        $writer->expects(self::never())->method('write');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(self::stringContains('alerts-security'));
        $logger->expects(self::never())->method('error');

        $job = new SecurityDetectJob([$this->detector('a', $a, $f, $t)], $writer, null, $logger, $readiness);
        $job->run();

        self::assertSame(0, $a);
    }

    public function test_skonfigurowana_detekcja_biegnie_normalnie(): void
    {
        $a = 0;
        $f = $t = null;
        $readiness = $this->createStub(DetectionReadiness::class);
        $readiness->method('missing')->willReturn(null);
        $writer = $this->createStub(AlertWriter::class);

        (new SecurityDetectJob([$this->detector('a', $a, $f, $t)], $writer, null, new \Psr\Log\NullLogger(), $readiness))->run();

        self::assertSame(1, $a);
    }
}

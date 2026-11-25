<?php

namespace App\Tests\Unit\Security;

use App\Security\Alert;
use PHPUnit\Framework\TestCase;

final class AlertTest extends TestCase
{
    private function alert(string $start = '2026-11-25T10:00:00+00:00', bool $synthetic = true, string $entityId = '920000001'): Alert
    {
        $at = new \DateTimeImmutable($start);

        return new Alert('fresh-account-5star', 'percolator', 'medium', 'user', $entityId, $at, $at, 'Pięć gwiazdek z nowego konta', ['review_rating' => 5], $synthetic);
    }

    public function test_ten_sam_zestaw_pol_daje_to_samo_id_jako_sha1_klucza(): void
    {
        self::assertSame($this->alert()->id(), $this->alert()->id());
        self::assertSame(sha1('fresh-account-5star|user|920000001|2026-11-25T10:00:00+00:00'), $this->alert()->id());
    }

    public function test_id_nie_zalezy_od_strefy_czasowej_zapisu_okna(): void
    {
        self::assertSame($this->alert('2026-11-25T10:00:00+00:00')->id(), $this->alert('2026-11-25T12:00:00+02:00')->id());
    }

    public function test_inne_okno_albo_encja_daje_inne_id(): void
    {
        self::assertNotSame($this->alert()->id(), $this->alert('2026-11-25T10:00:01+00:00')->id());
        self::assertNotSame($this->alert()->id(), $this->alert(entityId: '920000002')->id());
    }

    public function test_dokument_ma_ksztalt_zgodny_z_indeksem_alertow(): void
    {
        $doc = $this->alert('2026-11-25T12:00:00+02:00')->toDocument();

        self::assertSame('2026-11-25T10:00:00+00:00', $doc['@timestamp']);
        self::assertSame(['id' => 'fresh-account-5star', 'kind' => 'percolator'], $doc['rule']);
        self::assertSame('medium', $doc['severity']);
        self::assertSame(['type' => 'user', 'id' => '920000001'], $doc['entity']);
        self::assertSame(['start' => '2026-11-25T10:00:00+00:00', 'end' => '2026-11-25T10:00:00+00:00'], $doc['window']);
        self::assertSame(['review_rating' => 5], $doc['evidence']);
        self::assertTrue($doc['labels']['synthetic']);
    }

    public function test_alert_niesyntetyczny_ma_labels_synthetic_false(): void
    {
        self::assertFalse($this->alert(synthetic: false)->toDocument()['labels']['synthetic']);
    }
}

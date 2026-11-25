<?php

namespace App\Tests\Unit\Security;

use App\Security\DetectionScore;
use PHPUnit\Framework\TestCase;

final class DetectionScoreTest extends TestCase
{
    /** @return array<string, string> */
    private function case(string $type = 'planted', string $rule = 'review-ip-burst', string $entity = 'abc', string $start = '2026-11-20T10:00:00+00:00', string $end = '2026-11-20T11:00:00+00:00', string $entityType = 'ip_hash'): array
    {
        return ['rule' => $rule, 'type' => $type, 'entity_type' => $entityType, 'entity_id' => $entity, 'window_start' => $start, 'window_end' => $end];
    }

    /** @return array<string, mixed> `_source` alertu */
    private function alert(string $rule = 'review-ip-burst', string $entity = 'abc', string $start = '2026-11-20T10:00:00+00:00', string $end = '2026-11-20T11:00:00+00:00', string $entityType = 'ip_hash', bool $synthetic = true): array
    {
        return [
            'rule' => ['id' => $rule, 'kind' => 'esql'],
            'entity' => ['type' => $entityType, 'id' => $entity],
            'window' => ['start' => $start, 'end' => $end],
            'labels' => ['synthetic' => $synthetic],
        ];
    }

    public function test_zasadzony_z_alertem_to_tp(): void
    {
        $score = DetectionScore::score([$this->case()], [$this->alert()])['review-ip-burst'];

        self::assertSame(['tp' => 1, 'fp' => 0, 'fp_near_miss' => 0, 'fn' => 0, 'precision' => 1.0, 'recall' => 1.0], $score);
    }

    public function test_zasadzony_bez_alertu_to_fn_a_precyzja_bez_alertow_to_null(): void
    {
        $score = DetectionScore::score([$this->case()], [])['review-ip-burst'];

        self::assertSame(1, $score['fn']);
        self::assertSame(0, $score['tp']);
        self::assertNull($score['precision']);
        self::assertSame(0.0, $score['recall']);
    }

    public function test_bliskie_pudlo_z_alertem_to_fp_i_fp_near_miss(): void
    {
        $score = DetectionScore::score([$this->case('near_miss')], [$this->alert()])['review-ip-burst'];

        self::assertSame(1, $score['fp']);
        self::assertSame(1, $score['fp_near_miss']);
        self::assertSame(0, $score['fn']);
        self::assertSame(0.0, $score['precision']);
        self::assertNull($score['recall']);
    }

    public function test_bliskie_pudlo_bez_alertu_nic_nie_liczy(): void
    {
        $score = DetectionScore::score([$this->case('near_miss')], [])['review-ip-burst'];

        self::assertSame(['tp' => 0, 'fp' => 0, 'fp_near_miss' => 0, 'fn' => 0, 'precision' => null, 'recall' => null], $score);
    }

    public function test_alert_bez_przypadku_to_fp(): void
    {
        $score = DetectionScore::score([$this->case()], [$this->alert(), $this->alert(entity: 'inny')])['review-ip-burst'];

        self::assertSame(1, $score['tp']);
        self::assertSame(1, $score['fp']);
        self::assertSame(0, $score['fp_near_miss']);
        self::assertSame(0.5, $score['precision']);
    }

    public function test_rozlaczne_okna_nie_pasuja(): void
    {
        // Kubełek poprzedniej godziny styka się z oknem przypadku tylko brzegiem [9:00, 10:00) — to nie przecięcie.
        $score = DetectionScore::score([$this->case()], [$this->alert(start: '2026-11-20T09:00:00+00:00', end: '2026-11-20T10:00:00+00:00')])['review-ip-burst'];

        self::assertSame(['tp' => 0, 'fp' => 1, 'fp_near_miss' => 0, 'fn' => 1, 'precision' => 0.0, 'recall' => 0.0], $score);
    }

    public function test_inna_regula_albo_typ_encji_nie_pasuje(): void
    {
        $score = DetectionScore::score([$this->case()], [$this->alert(rule: 'fresh-account-5star'), $this->alert(entityType: 'user')]);

        self::assertSame(1, $score['review-ip-burst']['fn']);
        self::assertSame(1, $score['review-ip-burst']['fp']);
        self::assertSame(1, $score['fresh-account-5star']['fp']);
    }

    public function test_okno_punktowe_percolatora_pasuje_do_tego_samego_momentu_w_innej_strefie(): void
    {
        $at = '2026-11-20T10:15:00+00:00';
        $score = DetectionScore::score(
            [$this->case(rule: 'fresh-account-5star', entity: '920000001', start: $at, end: $at, entityType: 'user')],
            [$this->alert(rule: 'fresh-account-5star', entity: '920000001', start: '2026-11-20T11:15:00+01:00', end: '2026-11-20T11:15:00+01:00', entityType: 'user')],
        )['fresh-account-5star'];

        self::assertSame(1, $score['tp']);
    }

    public function test_dwa_alerty_jednego_zasadzonego_to_jedno_tp(): void
    {
        $case = $this->case(rule: 'price-bait-and-switch', entity: '7', start: '2026-11-20T10:00:00+00:00', end: '2026-11-21T20:00:00+00:00', entityType: 'offer');
        $alerts = [
            $this->alert(rule: 'price-bait-and-switch', entity: '7', start: '2026-11-20T10:00:00+00:00', end: '2026-11-21T20:00:00+00:00', entityType: 'offer'),
            $this->alert(rule: 'price-bait-and-switch', entity: '7', start: '2026-11-20T12:00:00+00:00', end: '2026-11-21T20:00:00+00:00', entityType: 'offer'),
        ];

        self::assertSame(['tp' => 1, 'fp' => 0, 'fp_near_miss' => 0, 'fn' => 0, 'precision' => 1.0, 'recall' => 1.0], DetectionScore::score([$case], $alerts)['price-bait-and-switch']);
    }

    public function test_wszystkie_reguly_z_przypadkow_sa_w_wyniku_w_stalej_kolejnosci(): void
    {
        $score = DetectionScore::score([$this->case(rule: 'complaints-spike', entityType: 'seller'), $this->case()], []);

        self::assertSame(['review-ip-burst', 'complaints-spike'], array_keys($score));
    }

    public function test_partition_odrzuca_dokumenty_kibany_i_niesyntetyczne_spoza_prawdy(): void
    {
        $php = $this->alert();
        $phpId = sha1('review-ip-burst|ip_hash|abc|2026-11-20T10:00:00+00:00');
        $real = $this->alert(entity: 'prawdziwy', synthetic: false);
        $realId = sha1('review-ip-burst|ip_hash|prawdziwy|2026-11-20T10:00:00+00:00');
        // Prawdziwy alert na encji z prawdy też się liczy (flaga mogła spaść np. przez reklamację spoza symulatora).
        $realOnCase = $this->alert(rule: 'complaints-spike', entity: '5000001', start: '2026-11-19T00:00:00+00:00', end: '2026-11-20T00:00:00+00:00', entityType: 'seller', synthetic: false);
        $realOnCaseId = sha1('complaints-spike|seller|5000001|2026-11-19T00:00:00+00:00');
        $kibana = $this->alert(entity: 'abc', synthetic: false);

        $parts = DetectionScore::partition(
            [$this->case(), $this->case(rule: 'complaints-spike', entity: '5000001', start: '2026-11-19T00:00:00+00:00', end: '2026-11-20T00:00:00+00:00', entityType: 'seller')],
            [
                ['_id' => $phpId, '_source' => $php],
                ['_id' => $realId, '_source' => $real],
                ['_id' => $realOnCaseId, '_source' => $realOnCase],
                ['_id' => 'losowe-id-kibany', '_source' => $kibana],
            ],
        );

        self::assertSame([$php, $realOnCase], $parts['scored']);
        self::assertSame(1, $parts['kibana']);
        self::assertSame(1, $parts['unrelated']);
    }

    public function test_alert_id_liczy_formule_sha1_z_wlasnych_pol(): void
    {
        self::assertSame(sha1('review-ip-burst|ip_hash|abc|2026-11-20T10:00:00+00:00'), DetectionScore::alertId($this->alert(start: '2026-11-20T11:00:00+01:00')));
        self::assertNull(DetectionScore::alertId(['rule' => ['id' => 'x']]));
    }
}

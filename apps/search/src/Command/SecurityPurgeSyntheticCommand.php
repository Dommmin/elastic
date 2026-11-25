<?php

namespace App\Command;

use App\Security\SecuritySyntheticPurger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Sprzątanie po `security:simulate` (catalog nie pisze do ES): zdarzenia i alerty syntetyczne, kliknięcia
 * symulatora, reklamacje `SEC-*` i przeliczenie sekcji reklamacji seller-360 ({@see SecuritySyntheticPurger}).
 * Uruchamiać po opróżnieniu kolejek `search.security.ingest` i `search.analytics.ingest`, inaczej zaległe
 * zdarzenia wrócą do indeksów. Nie rusza zbioru dowodowego ETAPU 13 (`SIM-*`, sprzedawcy z planted.json).
 * Wyników transformów ETAPU 12 purge NIE przelicza (patrz {@see self::TRANSFORMS_NOTE}).
 */
#[AsCommand(name: 'security:purge-synthetic', description: 'Usuwa dane symulatora detekcji nadużyć (zdarzenia, alerty, kliknięcia, reklamacje SEC-)')]
final class SecurityPurgeSyntheticCommand extends Command
{
    public const TRANSFORMS_NOTE = 'Purge NIE przelicza wyników transformów ETAPU 12, w które weszły kliknięcia symulatora: '
        .'product-popularity odśwież `make popularity-refresh` (search:popularity:refresh, transform wsadowy liczy od nowa ze zdarzeń), '
        .'a search-stats-daily (CTR, reguła ctr-drop) to transform ciągły, który nie przelicza kubełków po usunięciu zdarzeń: '
        .'POST _transform/search-stats-daily/_stop, potem _reset i _start (pełne przeliczenie).';

    public function __construct(private readonly SecuritySyntheticPurger $purger)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp('Usuwa z ES dane `security:simulate`: zdarzenia i alerty `labels.synthetic`, alerty Kibany na syntetycznych '
            .'haszach IP, kliknięcia sesji secsim-*, reklamacje SEC-* i przelicza sekcję complaints seller-360. '
            .'Uruchamiać po opróżnieniu kolejek search.security.ingest i search.analytics.ingest.'."\n\n".self::TRANSFORMS_NOTE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->purger->purge();

        $output->writeln(\sprintf('events-security-*: usunięto %d zdarzeń syntetycznych', $result['events']));
        $output->writeln(\sprintf('alerts-security: usunięto %d alertów (syntetyczne i Kibana na syntetycznych haszach IP)', $result['alerts']));
        $output->writeln(\sprintf('events-user-*: usunięto %d kliknięć symulatora (sesje secsim-*)', $result['clicks']));
        $output->writeln(\sprintf('complaints, complaints-unmatched: usunięto %d reklamacji SEC-*', $result['complaints']));
        $output->writeln(\sprintf('seller-360: przeliczono sekcję complaints u %d sprzedawców', $result['sections']));
        $output->writeln('<comment>'.self::TRANSFORMS_NOTE.'</comment>');

        return Command::SUCCESS;
    }
}

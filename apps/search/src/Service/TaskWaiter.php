<?php

namespace App\Service;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;

/**
 * Czekanie na zadanie ES z `wait_for_completion=false` (`_reindex`,
 * `_update_by_query`) przez Tasks API — wspólne dla migracji indeksu
 * i zmiany nazwy sprzedawcy.
 */
final class TaskWaiter
{
    public function __construct(
        private readonly Client $client,
        private readonly float $pollSeconds = 2.0,
    ) {
    }

    /**
     * Śledzi zadanie przez Tasks API do końca. `$shouldCancel()` sprawdzane
     * przy każdym odpytaniu (np. flaga ustawiana przez SIGINT) — wtedy
     * `_cancel` i czekanie, aż zadanie faktycznie się zatrzyma.
     *
     * @param callable(array<string, mixed>): void $onProgress dostaje `task.status`
     * @param callable(): bool                     $shouldCancel
     *
     * @return array{cancelled: bool, result: array<string, mixed>, error: ?array<string, mixed>}
     *                                     `result` = odpowiedź `_reindex` (created, updated, version_conflicts, total, failures…)
     */
    public function wait(string $taskId, callable $onProgress, callable $shouldCancel): array
    {
        $cancelRequested = false;

        while (true) {
            $task = $this->client->tasks()->get(['task_id' => $taskId])->asArray();
            if ($task['completed'] ?? false) {
                $result = $task['response'] ?? $task['task']['status'] ?? [];

                return [
                    'cancelled' => $cancelRequested || ($result['canceled'] ?? null) !== null,
                    'result' => $result,
                    'error' => $task['error'] ?? null,
                ];
            }

            if (! $cancelRequested && $shouldCancel()) {
                try {
                    $this->client->tasks()->cancel(['task_id' => $taskId]);
                } catch (ClientResponseException $e) {
                    // Zadanie skończyło się między get a _cancel — następny get to pokaże.
                    if ($e->getCode() !== 404) {
                        throw $e;
                    }
                }
                $cancelRequested = true;

                continue;
            }

            $onProgress($task['task']['status'] ?? []);
            usleep((int) ($this->pollSeconds * 1_000_000));
        }
    }
}

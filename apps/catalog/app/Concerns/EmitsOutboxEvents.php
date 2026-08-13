<?php

namespace App\Concerns;

use App\Models\Outbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Transactional Outbox po stronie modelu (docs/05-SPOJNOSC-DANYCH.md, sekcja 3).
 *
 * DLACZEGO trait z metodami *WithOutbox(), a nie obserwator na eventach
 * created/updated/deleted:
 *
 * Prawdziwa gwarancja atomowości ("albo obie zmiany, albo żadna") wymaga,
 * żeby INSERT do `outbox` był w TEJ SAMEJ transakcji SQL co zmiana encji.
 * Obserwator Eloquenta odpala się owszem synchronicznie, ale tylko jeśli
 * WYWOŁUJĄCY pamięta, żeby owinąć `$model->save()` w `DB::transaction()`.
 * To jest łatwe do zapomnienia i wtedy cała gwarancja z dokumentu 05 znika
 * po cichu — nikt się nie dowie, dopóki nie zobaczy rozjazdu na produkcji.
 *
 * Metody tego traita SAME owijają się w `DB::transaction()`, więc pominięcie
 * outboxu jest niemożliwe strukturalnie, nie tylko "z konwencji zespołu".
 */
trait EmitsOutboxEvents
{
    /**
     * Tworzy encję i zdarzenie w jednej transakcji.
     *
     * UWAGA: `version` ustawiamy tu JAWNIE (nie polegamy na `->default(1)`
     * z migracji), bo Eloquent po `create()` nie odświeża w pamięci
     * kolumn wypełnionych przez DEFAULT bazy danych — `$model->version`
     * zostałby `null`, co złamałoby `NOT NULL` na `outbox.sequence` przy
     * pierwszym zapisie. DEFAULT w migracji zostaje jako druga linia
     * obrony (na wypadek zapisu z pominięciem tego traita).
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function createWithOutbox(array $attributes, string $eventType, ?callable $payloadResolver = null): static
    {
        return DB::transaction(function () use ($attributes, $eventType, $payloadResolver) {
            $instance = new static;

            /** @var static $model */
            $model = static::create($instance->usesOutboxVersioning()
                ? array_merge($attributes, ['version' => 1])
                : $attributes);

            $model->recordOutboxEvent(
                $eventType,
                $payloadResolver ? $payloadResolver($model) : $model->toOutboxPayload(),
            );

            return $model;
        });
    }

    /**
     * Aktualizuje encję, podbija wersję i zapisuje zdarzenie(a) w jednej
     * transakcji. Jeśli `$eventsByDirtyField` jest podane, każde zmienione
     * pole z tej mapy generuje OSOBNY wpis w outboksie (np. zmiana ceny
     * i stanu magazynowego naraz -> dwa zdarzenia: offer.price_changed
     * i offer.stock_changed — zgodnie z katalogiem zdarzeń w 02-APLIKACJE.md).
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, string>  $eventsByDirtyField  pole => typ zdarzenia
     */
    public function updateWithOutbox(array $attributes, string $defaultEventType, array $eventsByDirtyField = []): static
    {
        return $this->performUpdateWithOutbox($attributes, $defaultEventType, $eventsByDirtyField);
    }

    /**
     * Właściwa implementacja, w osobnej metodzie z jednego powodu: `parent::`
     * w PHP odnosi się do KLASY BAZOWEJ, nigdy do traita. Model, który chce
     * nadpisać `updateWithOutbox()` i dołożyć własne mapowanie pól -> zdarzeń
     * (tak robi `Offer`), nie może wywołać `parent::updateWithOutbox()` — to
     * poleci do `Illuminate\Database\Eloquent\Model`, gdzie taka metoda nie
     * istnieje ("Call to undefined method"). Woła więc `$this->performUpdate...()`
     * wprost, bo trait jest wklejony w tę samą klasę, nie w hierarchię.
     */
    protected function performUpdateWithOutbox(array $attributes, string $defaultEventType, array $eventsByDirtyField = []): static
    {
        return DB::transaction(function () use ($attributes, $defaultEventType, $eventsByDirtyField) {
            $this->fill($attributes);

            $dirtyFields = array_keys($this->getDirty());

            if ($this->usesOutboxVersioning()) {
                $this->version = $this->version + 1;
            }
            $this->save();

            $matchedEvents = array_values(array_intersect_key($eventsByDirtyField, array_flip($dirtyFields)));

            $eventTypes = $matchedEvents !== [] ? $matchedEvents : [$defaultEventType];

            foreach (array_unique($eventTypes) as $eventType) {
                $this->recordOutboxEvent($eventType, $this->toOutboxPayload());
            }

            return $this;
        });
    }

    public function deleteWithOutbox(string $eventType): void
    {
        DB::transaction(function () use ($eventType) {
            $payload = $this->toOutboxPayload();
            $sequence = $this->usesOutboxVersioning() ? $this->version + 1 : 1;
            $this->recordOutboxEvent($eventType, $payload, $sequence);
            $this->delete();
        });
    }

    protected function recordOutboxEvent(string $eventType, array $payload, ?int $sequenceOverride = null): void
    {
        Outbox::create([
            'event_id' => (string) Str::ulid(),
            'aggregate_type' => $this->outboxAggregateType(),
            'aggregate_id' => (string) $this->getKey(),
            'event_type' => $eventType,
            'payload' => $payload,
            // Modele bez kolumny `version` (Review, SavedAlert) mają zawsze
            // dokładnie jedno zdarzenie na aggregat, więc sequence=1 jest
            // poprawne — nie ma kolejności do ochrony, bo nie ma update'ów.
            'sequence' => $sequenceOverride ?? ($this->usesOutboxVersioning() ? $this->version : 1),
            'occurred_at' => now(),
        ]);
    }

    /**
     * Czy ten model ma kolumnę `version` i wymaga ochrony przed
     * nieuporządkowanym dostarczeniem eventów (docs/05-SPOJNOSC-DANYCH.md,
     * sekcja 3.5 — external versioning). Nadpisz na `false` w modelach
     * create-only, bez update'ów w katalogu zdarzeń (Review, SavedAlert).
     */
    protected function usesOutboxVersioning(): bool
    {
        return true;
    }

    protected function outboxAggregateType(): string
    {
        return Str::snake(class_basename($this));
    }

    /**
     * Domyślny ładunek zdarzenia — pełny stan atrybutów modelu.
     * Nadpisz w konkretnym modelu, jeśli event ma nosić więcej (fat event,
     * docs/02-APLIKACJE.md sekcja 7.1, wariant A) albo mniej danych.
     */
    protected function toOutboxPayload(): array
    {
        return $this->attributesToArray();
    }
}

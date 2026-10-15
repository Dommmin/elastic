<?php

use SellerPortal\Domain\Shared\AggregateRoot;
use SellerPortal\Domain\Shared\ConcurrencyConflict;
use SellerPortal\Domain\Shared\DomainEvent;
use SellerPortal\Domain\Shared\DomainException;
use SellerPortal\Domain\Shared\InvalidValue;
use SellerPortal\Domain\Shared\ModeratorId;
use SellerPortal\Domain\Shared\Reason;
use SellerPortal\Domain\Shared\UserId;

test('Reason przycina białe znaki', function () {
    expect(Reason::fromString("  za wysoka cena \n")->value())->toBe('za wysoka cena');
});

test('Reason odrzuca pusty i złożony z samych spacji tekst', function () {
    expect(fn () => Reason::fromString(''))->toThrow(InvalidValue::class)
        ->and(fn () => Reason::fromString("  \t "))->toThrow(InvalidValue::class);
});

test('Reason dopuszcza 500 znaków (w tym wielobajtowych) i odrzuca 501', function () {
    expect(Reason::fromString(str_repeat('ż', 500))->value())->toBe(str_repeat('ż', 500))
        ->and(fn () => Reason::fromString(str_repeat('ż', 501)))->toThrow(InvalidValue::class);
});

test('UserId i ModeratorId są dodatnie i porównywalne', function () {
    expect(UserId::fromInt(5)->value())->toBe(5)
        ->and(UserId::fromInt(5)->equals(UserId::fromInt(5)))->toBeTrue()
        ->and(UserId::fromInt(5)->equals(UserId::fromInt(6)))->toBeFalse()
        ->and(ModeratorId::fromInt(7)->equals(ModeratorId::fromInt(7)))->toBeTrue()
        ->and(fn () => UserId::fromInt(0))->toThrow(InvalidValue::class)
        ->and(fn () => ModeratorId::fromInt(-3))->toThrow(InvalidValue::class);
});

test('ConcurrencyConflict i InvalidValue są wyjątkami domeny z kodem', function () {
    expect(new ConcurrencyConflict)->toBeInstanceOf(DomainException::class)
        ->and(new ConcurrencyConflict)->toBeInstanceOf(\DomainException::class)
        ->and(ConcurrencyConflict::CODE)->toBe('concurrency_conflict');
});

test('AggregateRoot: pendingEvents podgląda, releaseEvents zwraca i czyści', function () {
    $event = new class implements DomainEvent
    {
        public function occurredAt(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-01-01');
        }
    };
    $aggregate = new class($event) extends AggregateRoot
    {
        public function __construct(DomainEvent $event)
        {
            $this->recordThat($event);
        }
    };

    expect($aggregate->pendingEvents())->toBe([$event])
        ->and($aggregate->pendingEvents())->toBe([$event])
        ->and($aggregate->releaseEvents())->toBe([$event])
        ->and($aggregate->pendingEvents())->toBe([])
        ->and($aggregate->releaseEvents())->toBe([]);
});

test('Reason traktuje same twarde spacje jako puste', function () {
    expect(fn () => Reason::fromString("\u{00A0}"))->toThrow(InvalidValue::class)
        ->and(fn () => Reason::fromString("\u{00A0}\u{200B}\u{00A0}"))->toThrow(InvalidValue::class);
});

test('Reason przyjmuje wieloliniowy tekst z textarea', function () {
    expect(Reason::fromString("linia 1\r\nlinia 2\tkoniec")->value())->toBe("linia 1\r\nlinia 2\tkoniec");
});

test('Reason odrzuca znaki sterujące poza \\n, \\r, \\t', function () {
    expect(fn () => Reason::fromString("ab\x07cd"))->toThrow(InvalidValue::class)
        ->and(fn () => Reason::fromString("ab\x7Fcd"))->toThrow(InvalidValue::class);
});

test('każdy konkretny wyjątek domeny ma niepusty CODE', function () {
    $dir = dirname(__DIR__, 4).'/src/SellerPortal/Domain';
    $checked = 0;

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $class = 'SellerPortal\\Domain\\'.str_replace('/', '\\', substr($file->getPathname(), strlen($dir) + 1, -4));
        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract() || ! $reflection->isSubclassOf(DomainException::class)) {
            continue;
        }

        $code = $reflection->getReflectionConstant('CODE');
        expect($code)->not->toBeFalse("$class nie definiuje CODE")
            ->and($code->getValue())->toBeString()->not->toBe('', "$class ma pusty CODE");
        $checked++;
    }

    expect($checked)->toBeGreaterThan(5);
});

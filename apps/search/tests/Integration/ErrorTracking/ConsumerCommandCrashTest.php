<?php

namespace App\Tests\Integration\ErrorTracking;

use App\Tests\Support\FakeSentryTransport;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Crash samej komendy `messenger:consume` (poza obsługą wiadomości, np. nieosiągalny
 * RabbitMQ przy starcie) MA trafić do GlitchTipa. Wyjątek `messenger:consume` w SDK
 * (`tracing.console.excluded_commands`) dotyczy wyłącznie śladów wydajności, a te są
 * wyłączone; łapanie błędów komend (ConsoleListener) nie ma żadnej listy wyjątków.
 * ADR-0009 twierdził odwrotnie.
 */
final class ConsumerCommandCrashTest extends KernelTestCase
{
    public function test_crash_of_messenger_consume_command_is_reported(): void
    {
        self::bootKernel();
        FakeSentryTransport::reset();
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = self::getContainer()->get('event_dispatcher');

        $dispatcher->dispatch(new ConsoleErrorEvent(
            new ArrayInput([]),
            new NullOutput,
            new \RuntimeException('RabbitMQ niedostępny przy starcie konsumenta'),
            new Command('messenger:consume'),
        ), ConsoleEvents::ERROR);

        self::assertCount(1, FakeSentryTransport::$events);
        self::assertSame('consumer', FakeSentryTransport::$events[0]->getTags()['process']);
        self::assertSame('RabbitMQ niedostępny przy starcie konsumenta', FakeSentryTransport::$events[0]->getExceptions()[0]->getValue());
    }
}

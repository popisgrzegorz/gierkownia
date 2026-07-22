<?php

declare(strict_types=1);

namespace App\Tests\Core\Game;

use App\Core\Game\GameDefinition;
use App\Core\Game\Transition;
use PHPUnit\Framework\TestCase;

/**
 * Definicja jest sprawdzana przy budowie, a nie przy pierwszym przejsciu.
 * Literowka w nazwie stanu inaczej wyszlaby dopiero w trakcie gry - czyli
 * przy pelnym salonie gosci.
 */
final class GameDefinitionTest extends TestCase
{
    public function testValidDefinitionIsAccepted(): void
    {
        $definition = new GameDefinition(
            type: 'melodia',
            initialState: 'idle',
            states: ['idle', 'armed', 'buzzed'],
            transitions: [
                new Transition('arm', 'idle', 'armed'),
                new Transition('buzz', 'armed', 'buzzed'),
            ],
        );

        self::assertTrue($definition->hasTransition('arm'));
        self::assertFalse($definition->hasTransition('teleport'));
    }

    public function testInitialStateMustBeOneOfTheStates(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/stan poczatkowy/');

        new GameDefinition('melodia', 'startowy', ['idle', 'armed'], []);
    }

    public function testTransitionCannotLeadToAnUnknownState(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/nieznanego stanu "buzzed"/');

        new GameDefinition('melodia', 'idle', ['idle', 'armed'], [
            new Transition('buzz', 'armed', 'buzzed'),
        ]);
    }

    public function testTransitionCannotStartFromAnUnknownState(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/nieznanego stanu "spinning"/');

        new GameDefinition('kolo', 'idle', ['idle', 'landed'], [
            new Transition('land', 'spinning', 'landed'),
        ]);
    }

    public function testTransitionNeedsAName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Transition('', 'idle', 'armed');
    }

    public function testTransitionNeedsAtLeastOneSourceState(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Transition('arm', [], 'armed');
    }
}

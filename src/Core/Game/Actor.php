<?php

declare(strict_types=1);

namespace App\Core\Game;

use App\Core\Room\Team;

/**
 * Kto wykonuje akcje: ekran hosta czy telefon konkretnej druzyny.
 *
 * Moduly gier musza to rozroznic, bo prawie kazda akcja jest dozwolona tylko
 * dla jednej ze stron - punktuje host, buzzuje druzyna. Rozstrzygniecie zapada
 * raz, przy wejsciu do kontrolera, na podstawie tokenu.
 */
final readonly class Actor
{
    private function __construct(
        public ActorRole $role,
        public ?Team $team,
    ) {
    }

    public static function host(): self
    {
        return new self(ActorRole::Host, null);
    }

    public static function team(Team $team): self
    {
        return new self(ActorRole::Team, $team);
    }

    public function isHost(): bool
    {
        return ActorRole::Host === $this->role;
    }

    public function isTeam(): bool
    {
        return ActorRole::Team === $this->role;
    }

    /** Druzyna wykonujaca akcje - rzuca, gdy akcja przyszla od hosta. */
    public function requireTeam(): Team
    {
        return $this->team ?? throw new IllegalActionException('Ta akcja jest dostepna tylko dla druzyny.');
    }

    public function requireHost(): void
    {
        if (!$this->isHost()) {
            throw new IllegalActionException('Ta akcja jest dostepna tylko dla hosta.');
        }
    }

    public function describe(): string
    {
        return match ($this->role) {
            ActorRole::Host => 'host',
            ActorRole::Team => sprintf('druzyna %s', $this->requireTeam()->name),
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Room;

/**
 * Druzyna w pokoju. Zyje w Redisie jako JSON w hashu room:{code}:teams.
 *
 * deviceToken jest sekretem telefonu - nie moze trafic do widoku hosta
 * ani do broadcastu Mercure, bo kazdy w pokoju moglby podszyc sie pod druzyne.
 */
final readonly class Team implements \JsonSerializable
{
    public function __construct(
        public string $id,
        public string $name,
        public string $color,
        public string $deviceToken,
        /** Numer kolejny dolaczenia - jedyne zrodlo kolejnosci na tablo. */
        public int $seq,
        public int $joinedAt,
    ) {
    }

    /**
     * Odtwarza druzyne z JSON-a zapisanego w Redisie.
     *
     * Kazde pole jest sprawdzane, bo w Redisie moze siedziec wpis zapisany
     * przez starsza wersje aplikacji - lepiej wywalic sie z czytelnym bledem
     * niz zbudowac druzyne z pustymi polami i szukac potem, czemu telefon
     * nie moze sie uwierzytelnic.
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        if (!\is_array($data)) {
            throw new \UnexpectedValueException('Wpis druzyny w Redisie nie jest obiektem JSON.');
        }

        return new self(
            id: self::string($data, 'id'),
            name: self::string($data, 'name'),
            color: self::string($data, 'color'),
            deviceToken: self::string($data, 'deviceToken'),
            seq: self::int($data, 'seq'),
            joinedAt: self::int($data, 'joinedAt'),
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'color' => $this->color,
            'deviceToken' => $this->deviceToken,
            'seq' => $this->seq,
            'joinedAt' => $this->joinedAt,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
    }

    /**
     * Wersja bezpieczna do wyslania na ekran hosta i do broadcastu.
     *
     * @return array{id: string, name: string, color: string}
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'color' => $this->color,
        ];
    }

    /** @param array<mixed> $data */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (!\is_string($value)) {
            throw new \UnexpectedValueException(sprintf('Pole druzyny "%s" nie jest tekstem.', $key));
        }

        return $value;
    }

    /** @param array<mixed> $data */
    private static function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        if (!\is_int($value)) {
            throw new \UnexpectedValueException(sprintf('Pole druzyny "%s" nie jest liczba.', $key));
        }

        return $value;
    }
}

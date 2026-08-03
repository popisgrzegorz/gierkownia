<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Category;
use App\Entity\Track;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Zrodlo dzwieku jest jedno z dwoch, nigdy zero, nigdy oba naraz - regula
 * z ContentManagera przeniesiona na encje (Track::validateAudioSource), zeby
 * pilnowal jej kazdy formularz, ktory kiedykolwiek zapisze Track, a nie tylko
 * stary panel.
 */
final class TrackTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    public function testTrackWithOnlyAFileIsValid(): void
    {
        $track = new Track(new Category('na-lato', 'Na lato', Category::KIND_MELODIA), 'plik.mp3', 'Tytul', 'Wykonawca');

        self::assertCount(0, $this->validator->validate($track));
    }

    public function testTrackWithOnlyALinkIsValid(): void
    {
        $track = Track::fromUrl(new Category('na-lato', 'Na lato', Category::KIND_MELODIA), 'https://przyklad.pl/a.mp3', 'Tytul', 'Wykonawca');

        self::assertCount(0, $this->validator->validate($track));
    }

    public function testTrackWithNeitherAFileNorALinkIsRejected(): void
    {
        $track = new Track(new Category('na-lato', 'Na lato', Category::KIND_MELODIA), null, 'Tytul', 'Wykonawca');

        $violations = $this->validator->validate($track);

        self::assertCount(1, $violations);
        self::assertSame('Wybierz plik z nagraniem albo podaj link.', (string) $violations->get(0)->getMessage());
    }

    public function testTrackWithBothAFileAndALinkIsRejected(): void
    {
        $track = new Track(new Category('na-lato', 'Na lato', Category::KIND_MELODIA), 'plik.mp3', 'Tytul', 'Wykonawca');
        $track->setSourceUrl('https://przyklad.pl/a.mp3');

        $violations = $this->validator->validate($track);

        self::assertCount(1, $violations);
        self::assertSame('Wybierz plik z nagraniem albo podaj link.', (string) $violations->get(0)->getMessage());
    }
}

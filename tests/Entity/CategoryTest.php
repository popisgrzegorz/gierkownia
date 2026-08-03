<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Category;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Kategoria bez zadnej gry jest niewidoczna wszedzie - Category::validateGames
 * to lapie. Regula "melodia = zawsze dokladnie ['melodia']" NIE jest tu
 * walidowana jako blad - to normalizacja w kontrolerze panelu, nie pomylka
 * uzytkownika (patrz CategoryCrudController::normalizeGames).
 */
final class CategoryTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    public function testCategoryWithoutAnyGameIsRejected(): void
    {
        $category = new Category('powiedzenia', 'Powiedzenia', Category::KIND_HASLA, []);

        $violations = $this->validator->validate($category);

        self::assertCount(1, $violations);
        self::assertStringContainsString('przynajmniej jedną grę', (string) $violations->get(0)->getMessage());
    }

    public function testCategoryWithAGameIsValid(): void
    {
        $category = new Category('powiedzenia', 'Powiedzenia', Category::KIND_HASLA, ['kolo']);

        self::assertCount(0, $this->validator->validate($category));
    }

    /** Konstruktor sam dokleja ['melodia'] - nowa kategoria nagran nigdy nie zaczyna pusta. */
    public function testFreshMelodiaCategoryDefaultsToItsOwnGame(): void
    {
        $category = new Category('na-lato', 'Na lato', Category::KIND_MELODIA, []);

        self::assertSame(['melodia'], $category->getGames());
        self::assertCount(0, $this->validator->validate($category));
    }
}

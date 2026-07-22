<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\PuzzleWord;
use App\Repository\CategoryRepository;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** @extends AbstractCrudController<\App\Entity\PuzzleWord> */
class PuzzleWordCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return PuzzleWord::class;
    }

    public function __construct(
        private readonly CategoryRepository $categories,
    ) {
    }

    /**
     * EasyAdmin robi "new PuzzleWord()", a encja wymaga kategorii i hasla -
     * bez tego formularz dodawania konczy sie bledem "Too few arguments".
     *
     * Kategoria musi byc **zarzadzana przez Doctrine**: swiezo utworzona atrapa
     * jest odrzucana przez pole wyboru ("must be managed"). Bierzemy wiec
     * pierwsza istniejaca kategorie kola - formularz i tak pozwoli ja zmienic.
     */
    public function createEntity(string $entityFqcn): PuzzleWord
    {
        $category = $this->categories->findOneBy(['gameType' => 'kolo'], ['name' => 'ASC']);

        if (null === $category) {
            // Haslo bez kategorii nie ma sensu, a pusty formularz i tak by nie
            // zapisal - lepiej powiedziec wprost, czego brakuje.
            throw new BadRequestHttpException('Najpierw utwórz kategorię dla koła fortuny.');
        }

        return new PuzzleWord($category, '');
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Hasło')
            ->setEntityLabelInPlural('Hasła do koła')
            ->setDefaultSort(['category' => 'ASC', 'position' => 'ASC'])
            ->setHelp('index', 'Hasła zapisujemy wielkimi literami. Polskie znaki są osobnymi literami: „Ł” nie odsłania „L”.');
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('category', 'Kategoria')
            ->setQueryBuilder(static fn (QueryBuilder $qb): QueryBuilder => $qb->andWhere('entity.gameType = :t')->setParameter('t', 'kolo'));

        yield TextField::new('phrase', 'Hasło');
        yield TextField::new('hint', 'Podpowiedź')->setRequired(false);
        yield IntegerField::new('position', 'Kolejność')->onlyOnForms();
    }
}

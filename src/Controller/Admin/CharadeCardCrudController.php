<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\CharadeCard;
use App\Repository\CategoryRepository;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** @extends AbstractCrudController<\App\Entity\CharadeCard> */
class CharadeCardCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return CharadeCard::class;
    }

    public function __construct(
        private readonly CategoryRepository $categories,
    ) {
    }

    /**
     * Patrz PuzzleWordCrudController::createEntity() - ten sam powod:
     * konstruktor encji wymaga kategorii, i to zarzadzanej przez Doctrine.
     */
    public function createEntity(string $entityFqcn): CharadeCard
    {
        $category = $this->categories->findOneBy(['gameType' => 'kalambury'], ['name' => 'ASC']);

        if (null === $category) {
            throw new BadRequestHttpException('Najpierw utwórz kategorię dla kalambur.');
        }

        return new CharadeCard($category, '');
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Kartę')
            ->setEntityLabelInPlural('Karty kalambur')
            ->setDefaultSort(['category' => 'ASC', 'position' => 'ASC'])
            ->setHelp('index', 'Moduł kalambur jeszcze nie powstał — karty czekają w bazie.');
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('category', 'Kategoria')
            ->setQueryBuilder(static fn (QueryBuilder $qb): QueryBuilder => $qb->andWhere('entity.gameType = :t')->setParameter('t', 'kalambury'));

        yield TextField::new('word', 'Hasło');
        yield ChoiceField::new('difficulty', 'Trudność')
            ->setChoices(['łatwy' => 'latwy', 'średni' => 'sredni', 'trudny' => 'trudny']);
        yield IntegerField::new('position', 'Kolejność')->onlyOnForms();
    }
}

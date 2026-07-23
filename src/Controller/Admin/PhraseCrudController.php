<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Category;
use App\Entity\Phrase;
use App\Repository\CategoryRepository;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** @extends AbstractCrudController<Phrase> */
class PhraseCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Phrase::class;
    }

    public function __construct(
        private readonly CategoryRepository $categories,
    ) {
    }

    /**
     * EasyAdmin robi "new Phrase()", a encja wymaga kategorii i tresci -
     * bez tego formularz dodawania konczy sie bledem "Too few arguments".
     *
     * Kategoria musi byc **zarzadzana przez Doctrine**: swiezo utworzona atrapa
     * jest odrzucana przez pole wyboru ("must be managed"). Bierzemy wiec
     * pierwsza istniejaca kategorie slownika - formularz i tak pozwoli ja zmienic.
     */
    public function createEntity(string $entityFqcn): Phrase
    {
        $category = $this->categories->findByKind(Category::KIND_HASLA)[0] ?? null;

        if (null === $category) {
            // Haslo bez kategorii nie ma sensu, a pusty formularz i tak by nie
            // zapisal - lepiej powiedziec wprost, czego brakuje.
            throw new BadRequestHttpException('Najpierw utwórz kategorię słownika.');
        }

        return new Phrase($category, '');
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Hasło')
            ->setEntityLabelInPlural('Słownik haseł')
            ->setDefaultSort(['category' => 'ASC', 'position' => 'ASC'])
            ->setHelp(
                'index',
                'Jedno hasło służy wszystkim grom na słowa. O tym, która gra po nie sięgnie, '
                .'decyduje kategoria. Koło fortuny samo zamienia hasło na wersaliki, '
                .'a polskie znaki są tam osobnymi literami: „Ł” nie odsłania „L”.',
            );
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('category', 'Kategoria')
            ->setQueryBuilder(static fn (QueryBuilder $qb): QueryBuilder => $qb
                ->andWhere('entity.kind = :kind')
                ->setParameter('kind', Category::KIND_HASLA));

        yield TextField::new('phrase', 'Hasło');
        yield TextField::new('hint', 'Podpowiedź (koło)')->setRequired(false);
        yield ChoiceField::new('difficulty', 'Trudność (kalambury)')
            ->setChoices(['łatwy' => 'latwy', 'średni' => 'sredni', 'trudny' => 'trudny']);
        yield IntegerField::new('position', 'Kolejność')->onlyOnForms();
    }
}

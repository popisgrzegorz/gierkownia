<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\ContentTypes;
use App\Content\TrackAudioCleaner;
use App\Entity\Category;
use App\Repository\CategoryRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/** @extends AbstractCrudController<\App\Entity\Category> */
class CategoryCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly CategoryRepository $categories,
        private readonly TrackAudioCleaner $cleaner,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Category::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Kategorię')
            ->setEntityLabelInPlural('Kategorie')
            ->setDefaultSort(['kind' => 'ASC', 'name' => 'ASC'])
            ->setHelp('index', 'Kategoria to zestaw, który host wybiera na starcie gry. Hasła są wspólne — jedna kategoria może zasilać koło fortuny i kalambury naraz. W kole jej nazwa jest też podpowiedzią dla graczy.');
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Nazwa');

        yield ChoiceField::new('kind', 'Rodzaj treści')
            ->setChoices(ContentTypes::kindChoices())
            // Zmiana rodzaju pod istniejacymi pozycjami zrobilaby kategorie
            // z nagraniami, ktora udaje slownik - pozycje zostalyby sierotami.
            ->setFormTypeOption('disabled', Crud::PAGE_EDIT === $pageName);

        // Ktore gry moga po nia siegnac. Melodia ma dokladnie jednego odbiorce,
        // wiec przy nagraniach to pole nic nie zmienia - pilnuje tego encja.
        yield ChoiceField::new('games', 'Gry')
            ->setChoices(ContentTypes::phraseGameChoices())
            ->allowMultipleChoices()
            ->renderExpanded();

        // Slug jest kluczem naturalnym: siedzi w sciezkach wgranych plikow
        // i w stanie trwajacej rozgrywki. Pokazujemy go, ale nikt go nie edytuje.
        yield TextField::new('slug', 'Slug')->onlyOnIndex();
    }

    /**
     * EasyAdmin domyslnie robi "new Category()", a nasza encja wymaga trzech
     * argumentow konstruktora - bez tego formularz dodawania wywala sie na 500.
     * Slug zostaje pusty; wypelnia go persistEntity() na podstawie nazwy.
     */
    public function createEntity(string $entityFqcn): Category
    {
        return new Category('', '', Category::KIND_HASLA, ContentTypes::phraseGames());
    }

    /**
     * Slug powstaje z nazwy przy tworzeniu i nigdy sie potem nie zmienia -
     * dlatego generujemy go tutaj, a nie polem formularza.
     *
     * @param Category $entityInstance
     */
    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ('' === $entityInstance->getSlug()) {
            $entityInstance->setSlug($this->categories->uniqueSlugFor($entityInstance->getName()));
        }

        $this->normalizeGames($entityInstance);

        parent::persistEntity($entityManager, $entityInstance);
    }

    /** @param Category $entityInstance */
    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->normalizeGames($entityInstance);

        parent::updateEntity($entityManager, $entityInstance);
    }

    /** @param Category $entityInstance */
    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        foreach ($entityInstance->getTracks() as $track) {
            $this->cleaner->deleteIfUnused($track);
        }

        parent::deleteEntity($entityManager, $entityInstance);
    }

    /**
     * Kategoria z nagraniami ma dokladnie jednego odbiorce - nie ma o czym
     * decydowac, wiec zaznaczenia w polu "Gry" (widocznym, bo obsluguje tez
     * hasla) i tak nic tu nie zmieniaja.
     */
    private function normalizeGames(Category $category): void
    {
        if (Category::KIND_MELODIA === $category->getKind()) {
            $category->setGames(['melodia']);
        }
    }
}

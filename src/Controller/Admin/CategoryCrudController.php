<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Category;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\String\Slugger\AsciiSlugger;

/** @extends AbstractCrudController<\App\Entity\Category> */
class CategoryCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Category::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Kategorię')
            ->setEntityLabelInPlural('Kategorie')
            ->setDefaultSort(['gameType' => 'ASC', 'name' => 'ASC'])
            ->setHelp('index', 'Kategoria to zestaw, który host wybiera na starcie gry. W kole fortuny jej nazwa jest też podpowiedzią dla graczy.');
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Nazwa');

        yield ChoiceField::new('gameType', 'Gra')
            ->setChoices(['Jaka to melodia' => 'melodia', 'Koło fortuny' => 'kolo', 'Kalambury' => 'kalambury'])
            // Zmiana gry pod istniejacymi pozycjami zrobilaby kategorie
            // z utworami, ktora udaje zestaw hasel - pozycje zostalyby sierotami.
            ->setFormTypeOption('disabled', Crud::PAGE_EDIT === $pageName);

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
        return new Category('', '', 'melodia');
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
            $entityInstance->setSlug($this->uniqueSlug($entityManager, $entityInstance->getName()));
        }

        parent::persistEntity($entityManager, $entityInstance);
    }

    private function uniqueSlug(EntityManagerInterface $entityManager, string $name): string
    {
        $base = (new AsciiSlugger('pl'))->slug($name)->lower()->toString();
        $base = '' === $base ? 'kategoria' : $base;

        $repository = $entityManager->getRepository(Category::class);
        $slug = $base;

        // Dwie kategorie o tej samej nazwie to normalna sytuacja przy dwoch grach,
        // a slug jest unikalny w calej tabeli.
        for ($suffix = 2; null !== $repository->findOneBy(['slug' => $slug]); ++$suffix) {
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }
}

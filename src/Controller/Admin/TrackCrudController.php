<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Track;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Metryczka nagrania: tytul, wykonawca, sekunda startu.
 *
 * Bez dodawania - plik audio musi trafic na dysk pod slugiem kategorii, a tym
 * zajmuje sie AudioUploader w starym panelu (/admin). Tutaj mozna poprawiac
 * opisy i kasowac, ale nowe nagranie wgrywa sie tam, gdzie dziala kompletny
 * potok zapisu pliku.
 *
 * @extends AbstractCrudController<Track>
 */
class TrackCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Track::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Nagranie')
            ->setEntityLabelInPlural('Nagrania')
            ->setDefaultSort(['category' => 'ASC', 'position' => 'ASC'])
            ->setHelp('index', 'Nowe nagrania wgrywa się w panelu /admin — tam plik trafia na dysk. Tutaj poprawiasz opisy.');
    }

    public function configureActions(Actions $actions): Actions
    {
        // Bez NEW: wpis bez pliku na dysku wygladalby na gotowe nagranie,
        // a gra wywalilaby sie dopiero przy probie odtworzenia.
        return $actions->disable(Action::NEW);
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('category', 'Kategoria')
            ->setQueryBuilder(static fn (QueryBuilder $qb): QueryBuilder => $qb->andWhere('entity.gameType = :t')->setParameter('t', 'melodia'));

        yield TextField::new('title', 'Tytuł');
        yield TextField::new('artist', 'Wykonawca');

        // Najwazniejsze pole w calej tresci: refren rozpoznaje sie w dwie
        // sekundy, wstep bywa nie do zgadniecia.
        yield IntegerField::new('startAt', 'Start (s)');

        yield TextField::new('path', 'Plik')->onlyOnIndex();
        yield IntegerField::new('position', 'Kolejność')->onlyOnForms();
    }
}

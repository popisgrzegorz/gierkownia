<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Category;
use App\Entity\Track;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;

/**
 * Metryczka nagrania: tytul, wykonawca, sekunda startu, zrodlo dzwieku.
 *
 * Zrodlem jest albo plik na dysku, albo adres zewnetrzny - nigdy oba naraz.
 *
 * Bez dodawania: plik audio musi trafic na dysk pod slugiem kategorii, a tym
 * zajmuje sie AudioUploader w starym panelu (/admin). Nagranie z odnosnikiem
 * dodaje ten sam panel albo komenda `app:melodia:import`.
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
            ->setQueryBuilder(static fn (QueryBuilder $qb): QueryBuilder => $qb
                ->andWhere('entity.kind = :kind')
                ->setParameter('kind', Category::KIND_MELODIA));

        yield TextField::new('title', 'Tytuł');
        yield TextField::new('artist', 'Wykonawca');

        // Najwazniejsze pole w calej tresci: refren rozpoznaje sie w dwie
        // sekundy, wstep bywa nie do zgadniecia.
        yield IntegerField::new('startAt', 'Start (s)');

        yield TextField::new('path', 'Plik')->onlyOnIndex();

        // Adres da sie poprawic, gdy zrodlo przeniesie nagranie pod inny link -
        // inaczej jedyna droga byloby skasowanie i ponowny import.
        yield UrlField::new('sourceUrl', 'Link zewnętrzny')->setRequired(false);
        yield IntegerField::new('position', 'Kolejność')->onlyOnForms();
    }
}

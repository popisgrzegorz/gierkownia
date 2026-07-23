<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;

/**
 * Panel tresci na EasyAdminie.
 *
 * Stoi pod /panel, a nie pod /admin, bo stary panel zostaje przy wgrywaniu
 * nagran: tam plik trafia na dysk pod slugiem kategorii, a przy kasowaniu
 * kategorii znika razem z nia. Przepisanie tego potoku na pole formularza
 * EasyAdmina to osobna robota - do czasu jej wykonania oba panele wspolistnieja
 * i linkuja do siebie.
 *
 * Dostepu pilnuje AdminPanelGuard (ta sama sesja i to samo haslo co /admin),
 * bo aplikacja nie ma uzytkownikow w rozumieniu security-bundle.
 */
#[AdminDashboard(routePath: '/panel', routeName: 'panel')]
class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    public function index(): Response
    {
        // Pusty pulpit nikomu nie sluzy - wchodzimy od razu w kategorie,
        // bo od nich zaczyna sie kazda praca z trescia.
        return $this->redirect(
            $this->adminUrlGenerator->setController(CategoryCrudController::class)->generateUrl(),
        );
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('Nasze Gierki — treści')
            ->renderContentMaximized();
    }

    public function configureMenuItems(): iterable
    {
        // EasyAdmin 5 zastapil linkToCrud() przez linkTo() z FQCN kontrolera
        // (nie encji) - stad CrudController::class, a nie Category::class.
        yield MenuItem::section('Treści');
        yield MenuItem::linkTo(CategoryCrudController::class, 'Kategorie', 'fa fa-folder');
        yield MenuItem::linkTo(TrackCrudController::class, 'Nagrania', 'fa fa-music');
        // Jeden slownik na wszystkie gry na slowa - patrz App\Entity\Phrase.
        yield MenuItem::linkTo(PhraseCrudController::class, 'Słownik haseł', 'fa fa-font');

        yield MenuItem::section('Poza panelem');
        // Wgrywanie plikow zostalo w starym panelu - patrz komentarz klasy.
        yield MenuItem::linkToUrl('Wgraj nagranie', 'fa fa-upload', '/admin');
        yield MenuItem::linkToUrl('Ekran hosta', 'fa fa-tv', '/');
    }
}

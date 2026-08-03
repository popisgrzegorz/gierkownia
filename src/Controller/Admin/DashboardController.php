<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Panel tresci na EasyAdminie - jedyny panel do zarzadzania kategoriami,
 * nagraniami i haslami (stary /admin zostal tylko jako logowanie/wylogowanie,
 * wspolne dla obu adresow).
 *
 * Dostepu pilnuje AdminPanelGuard (ta sama sesja i to samo haslo co /admin),
 * bo aplikacja nie ma uzytkownikow w rozumieniu security-bundle.
 */
#[AdminDashboard(routePath: '/panel', routeName: 'panel')]
class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly UrlGeneratorInterface $urls,
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
        yield MenuItem::linkToUrl('Ekran hosta', 'fa fa-tv', '/');
        // Wylogowanie samo jest akcja POST z CSRF - link prowadzi na mala
        // strone potwierdzenia (admin/logout.html.twig), a nie wprost na akcje.
        // linkToRoute() generuje adres w konwencji tras EasyAdmina (?routeName=...
        // pod /panel), a admin_logout_page to zwykla trasa Symfony - stad
        // gotowy URL przez UrlGeneratorInterface, nie linkToRoute().
        yield MenuItem::linkToUrl('Wyloguj', 'fa fa-right-from-bracket', $this->urls->generate('admin_logout_page'));
    }
}

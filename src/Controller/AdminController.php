<?php

declare(strict_types=1);

namespace App\Controller;

use App\Admin\AdminAccess;
use App\Admin\AudioUploader;
use App\Admin\ContentError;
use App\Admin\ContentManager;
use App\Admin\ContentTypes;
use App\Admin\UploadFailed;
use App\Entity\Category;
use App\Entity\Phrase;
use App\Entity\Track;
use App\Repository\CategoryRepository;
use App\Repository\PhraseRepository;
use App\Repository\TrackRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Panel zarzadzania trescia gier.
 *
 * Zwykle formularze HTML zamiast API i JS-a: panel obsluguje sie z laptopa,
 * raz na jakis czas, a formularz dziala takze wtedy, gdy cokolwiek pojdzie
 * nie tak ze skryptami.
 */
#[Route('/admin')]
final class AdminController extends AbstractController
{
    public function __construct(
        private readonly AdminAccess $access,
        private readonly ContentManager $content,
        private readonly CategoryRepository $categories,
        private readonly TrackRepository $tracks,
        private readonly PhraseRepository $phrases,
        private readonly AudioUploader $uploader,
        private readonly RateLimiterFactory $adminLoginLimiter,
    ) {
    }

    #[Route('/login', name: 'admin_login', methods: ['GET', 'POST'])]
    public function login(Request $request): Response
    {
        if (!$this->access->isEnabled()) {
            return $this->render('admin/disabled.html.twig');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin', (string) $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'Sesja wygasła - spróbuj ponownie.');

                return $this->redirectToRoute('admin_login');
            }

            // Haslo panelu jest jedno, wspolne i slownikowe - bez tego limitu
            // do zlamania brute-force'em starczy kilka godzin (P2-7 audytu).
            if (!$this->adminLoginLimiter->create($request->getClientIp())->consume()->isAccepted()) {
                $this->addFlash('error', 'Zbyt wiele prob logowania - sprobuj ponownie za kilkanascie minut.');

                return $this->redirectToRoute('admin_login');
            }

            if ($this->access->login($request, (string) $request->request->get('password', ''))) {
                return $this->redirectToRoute('admin_categories');
            }

            $this->addFlash('error', 'Nieprawidłowe hasło.');
        }

        if ($this->access->isLoggedIn($request)) {
            return $this->redirectToRoute('admin_categories');
        }

        return $this->render('admin/login.html.twig');
    }

    #[Route('/logout', name: 'admin_logout', methods: ['POST'])]
    public function logout(Request $request): Response
    {
        if ($this->isCsrfTokenValid('admin', (string) $request->request->get('_csrf_token'))) {
            $this->access->logout($request);
        }

        return $this->redirectToRoute('admin_login');
    }

    #[Route('', name: 'admin_categories', methods: ['GET'])]
    public function categories(Request $request): Response
    {
        if ($guard = $this->guard($request)) {
            return $guard;
        }

        return $this->render('admin/categories.html.twig', [
            'byKind' => $this->content->byKind(),
            'kinds' => ContentTypes::kindChoices(),
            'phraseGames' => ContentTypes::phraseGameChoices(),
        ]);
    }

    #[Route('/kategoria', name: 'admin_category_create', methods: ['POST'])]
    public function createCategory(Request $request): Response
    {
        if ($guard = $this->guard($request)) {
            return $guard;
        }

        try {
            $category = $this->content->createCategory(
                (string) $request->request->get('name', ''),
                (string) $request->request->get('kind', ''),
                $this->games($request),
            );
        } catch (ContentError $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_categories');
        }

        return $this->redirectToRoute('admin_category', ['id' => $category->getId()]);
    }

    #[Route('/kategoria/{id}', name: 'admin_category', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function category(Request $request, int $id): Response
    {
        if ($guard = $this->guard($request)) {
            return $guard;
        }

        $category = $this->category404($id);

        return $this->render('admin/category.html.twig', [
            'category' => $category,
            'items' => $this->itemsOf($category),
            'plural' => ContentTypes::plural($category->getKind()),
            'singular' => ContentTypes::singular($category->getKind()),
            'kindLabel' => ContentTypes::label($category->getKind()),
            'phraseGames' => ContentTypes::phraseGameChoices(),
            'extensions' => $this->uploader->allowedExtensions(),
            'uploadLimit' => ini_get('upload_max_filesize') ?: '?',
        ]);
    }

    #[Route('/kategoria/{id}/nazwa', name: 'admin_category_rename', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function renameCategory(Request $request, int $id): Response
    {
        if ($guard = $this->guard($request)) {
            return $guard;
        }

        $category = $this->category404($id);

        try {
            $this->content->renameCategory($category, (string) $request->request->get('name', ''));
            $this->addFlash('success', 'Nazwa zmieniona.');
        } catch (ContentError $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_category', ['id' => $id]);
    }

    #[Route('/kategoria/{id}/gry', name: 'admin_category_games', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function categoryGames(Request $request, int $id): Response
    {
        if ($guard = $this->guard($request)) {
            return $guard;
        }

        try {
            $this->content->setGames($this->category404($id), $this->games($request));
            $this->addFlash('success', 'Zapisano, które gry korzystają z tej kategorii.');
        } catch (ContentError $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_category', ['id' => $id]);
    }

    #[Route('/kategoria/{id}/usun', name: 'admin_category_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deleteCategory(Request $request, int $id): Response
    {
        if ($guard = $this->guard($request)) {
            return $guard;
        }

        $category = $this->category404($id);

        $name = $category->getName();
        $this->content->deleteCategory($category);
        $this->addFlash('success', sprintf('Kategoria "%s" usunięta.', $name));

        return $this->redirectToRoute('admin_categories');
    }

    #[Route('/kategoria/{id}/pozycja', name: 'admin_item_create', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function createItem(Request $request, int $id): Response
    {
        if ($guard = $this->guard($request)) {
            return $guard;
        }

        $category = $this->category404($id);

        $file = $request->files->get('file');

        try {
            $this->content->addItem(
                $category,
                $this->formData($request),
                $file instanceof UploadedFile ? $file : null,
            );
            $this->addFlash('success', 'Dodano.');
        } catch (ContentError|UploadFailed $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_category', ['id' => $id]);
    }

    #[Route('/pozycja/{type}/{id}', name: 'admin_item_update', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function updateItem(Request $request, string $type, int $id): Response
    {
        if ($guard = $this->guard($request)) {
            return $guard;
        }

        $item = $this->item404($type, $id);

        try {
            $this->content->updateItem($item, $this->formData($request));
            $this->addFlash('success', 'Zapisano.');
        } catch (ContentError $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_category', ['id' => $this->categoryIdOf($item)]);
    }

    #[Route('/pozycja/{type}/{id}/usun', name: 'admin_item_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deleteItem(Request $request, string $type, int $id): Response
    {
        if ($guard = $this->guard($request)) {
            return $guard;
        }

        $item = $this->item404($type, $id);
        $categoryId = $this->categoryIdOf($item);

        $this->content->deleteItem($item);
        $this->addFlash('success', 'Usunięto.');

        return $this->redirectToRoute('admin_category', ['id' => $categoryId]);
    }

    /**
     * Odsyla na logowanie albo na informacje o wylaczonym panelu. Dla
     * formularzy (POST) sprawdza tez token CSRF - jedno miejsce dla
     * wszystkich akcji panelu, wiec kazda nowa akcja dostaje to za darmo.
     * Zwraca null, gdy wszystko w porzadku i akcja moze isc dalej.
     */
    private function guard(Request $request): ?Response
    {
        if (!$this->access->isEnabled()) {
            return $this->render('admin/disabled.html.twig');
        }

        if ($this->access->needsLogin($request)) {
            return $this->redirectToRoute('admin_login');
        }

        if ($request->isMethod('POST') && !$this->isCsrfTokenValid('admin', (string) $request->request->get('_csrf_token'))) {
            throw new AccessDeniedHttpException('Token CSRF wygasł lub jest nieprawidłowy - odśwież stronę i spróbuj ponownie.');
        }

        return null;
    }

    private function category404(int $id): Category
    {
        return $this->categories->find($id) ?? throw new NotFoundHttpException('Nie ma takiej kategorii.');
    }

    private function item404(string $type, int $id): Track|Phrase
    {
        $item = match ($type) {
            'melodia' => $this->tracks->find($id),
            'haslo' => $this->phrases->find($id),
            default => null,
        };

        return $item ?? throw new NotFoundHttpException('Nie ma takiej pozycji.');
    }

    private function categoryIdOf(Track|Phrase $item): int
    {
        return $item->getCategory()->getId() ?? 0;
    }

    /** @return array<string, mixed> */
    private function formData(Request $request): array
    {
        $data = [];

        foreach ($request->request->all() as $key => $value) {
            if (\is_string($value) || \is_int($value)) {
                $data[$key] = $value;
            }
        }

        return $data;
    }

    /** @return list<Track|Phrase> */
    private function itemsOf(Category $category): array
    {
        return match ($category->getKind()) {
            Category::KIND_MELODIA => array_values($category->getTracks()->toArray()),
            Category::KIND_HASLA => array_values($category->getPhrases()->toArray()),
            default => [],
        };
    }

    /**
     * Zaznaczone gry z formularza - pole wielokrotnego wyboru przychodzi
     * jako tablica, a moze nie przyjsc wcale.
     *
     * @return list<string>
     */
    private function games(Request $request): array
    {
        $raw = $request->request->all()['games'] ?? [];

        return \is_array($raw) ? array_values(array_filter($raw, '\is_string')) : [];
    }
}

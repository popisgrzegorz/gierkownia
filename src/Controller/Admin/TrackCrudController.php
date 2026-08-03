<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Content\MediaLibrary;
use App\Content\TrackAudioCleaner;
use App\Entity\Category;
use App\Entity\Track;
use App\Repository\CategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FileField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Validator\Constraints\File;

/**
 * Metryczka nagrania: tytul, wykonawca, sekunda startu, zrodlo dzwieku.
 *
 * Zrodlem jest albo plik na dysku, albo adres zewnetrzny - nigdy oba naraz;
 * pilnuje tego Track::validateAudioSource (walidator na encji). Nowe pliki
 * ladujemy wg daty wgrania (rok/miesiac), nie wg kategorii - closure z encja
 * w FileField dostaje ja przed wypelnieniem formularza, wiec nie ma pewnosci,
 * ze `category` jest juz ustawiona (patrz plan migracji panelu).
 *
 * @extends AbstractCrudController<Track>
 */
class TrackCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly MediaLibrary $media,
        private readonly TrackAudioCleaner $cleaner,
        private readonly CategoryRepository $categories,
        private readonly string $projectDir,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Track::class;
    }

    /**
     * EasyAdmin robi "new Track()", a encja wymaga kategorii - bez tego
     * formularz dodawania konczy sie bledem "Too few arguments". Kategoria
     * musi byc zarzadzana przez Doctrine (swieza atrapa odrzuca pole wyboru
     * jako "must be managed") - bierzemy wiec pierwsza istniejaca kategorie
     * nagran, formularz i tak pozwoli ja zmienic.
     */
    public function createEntity(string $entityFqcn): Track
    {
        $category = $this->categories->findByKind(Category::KIND_MELODIA)[0] ?? null;

        if (null === $category) {
            throw new BadRequestHttpException('Najpierw utwórz kategorię nagrań.');
        }

        return new Track($category, null, '', '');
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Nagranie')
            ->setEntityLabelInPlural('Nagrania')
            ->setDefaultSort(['category' => 'ASC', 'position' => 'ASC'])
            ->setHelp('index', 'Podaj plik z dysku albo link zewnętrzny — nigdy oba naraz.')
            ->setHelp('new', 'Podaj plik z dysku albo link zewnętrzny — nigdy oba naraz.')
            ->setHelp('edit', 'Podaj plik z dysku albo link zewnętrzny — nigdy oba naraz.');
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

        // Placeholdery pliku ([YYYY]/[MM]/[slug]-[contenthash]) sa jedynymi
        // uzywanymi tutaj - zadnego zaleznego od encji (patrz doc-comment klasy).
        yield FileField::new('path', 'Plik')
            ->setUploadDir(Path::makeRelative($this->media->directory(), $this->projectDir))
            ->setUploadedFileNamePattern('[YYYY]/[MM]/[slug]-[contenthash].[extension]')
            ->setFileConstraints(new File(
                // Klucze bez wartosci (samo rozszerzenie) dostaja auto-wykryty
                // MIME z tabeli Symfony; m4a i webm nadpisujemy recznie, bo ich
                // rzeczywiste sniffowanie MIME nie pasuje do tej tabeli
                // (webm domyslnie mapuje sie WYLACZNIE na video/webm).
                extensions: [
                    'mp3', 'aac', 'ogg', 'oga', 'opus', 'wav', 'flac',
                    'm4a' => ['audio/mp4', 'audio/m4a', 'audio/x-m4a'],
                    'webm' => ['audio/webm', 'video/webm'],
                ],
                extensionsMessage: 'Format „{{ extension }}" nie jest obsługiwany.',
            ))
            ->setRequired(false)
            ->onlyOnForms();

        // Adres da sie poprawic, gdy zrodlo przeniesie nagranie pod inny link -
        // inaczej jedyna droga byloby skasowanie i ponowny import.
        yield UrlField::new('sourceUrl', 'Link zewnętrzny')->setRequired(false);
        yield IntegerField::new('position', 'Kolejność')->onlyOnForms();
    }

    /** @param Track $entityInstance */
    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->cleaner->deleteIfUnused($entityInstance);

        parent::deleteEntity($entityManager, $entityInstance);
    }
}

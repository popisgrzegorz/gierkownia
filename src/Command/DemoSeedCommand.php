<?php

declare(strict_types=1);

namespace App\Command;

use App\Content\DictionarySeeder;
use App\Content\MediaLibrary;
use App\Entity\Category;
use App\Entity\Track;
use App\Repository\CategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Wypelnia swieza instalacje trescia na start.
 *
 * Tresci zarzadza sie panelem, ale pusta baza po instalacji nie pozwala nawet
 * sprawdzic, czy gra dziala. Ta komenda generuje do melodii proste dzwieki,
 * zeby dalo sie zagrac bez wgrywania czyjejkolwiek muzyki, a hasla do kola
 * i kalambur bierze ze slownika (app:slownik:seed - to samo, jednym wywolaniem).
 *
 * Jest idempotentna: kategorie, ktore juz istnieja, zostawia w spokoju.
 */
#[AsCommand(
    name: 'app:demo:seed',
    description: 'Tworzy przykladowe kategorie i pozycje do wszystkich gier.',
)]
final class DemoSeedCommand extends Command
{
    private const SAMPLE_RATE = 22050;

    /** Nazwa pliku => [tytul, melodia jako pary [czestotliwosc Hz, dlugosc ms]]. */
    private const TUNES = [
        'wlazl-kotek' => ['Wlazł kotek na płotek', [[523, 300], [587, 300], [659, 300], [587, 300], [523, 600]]],
        'panie-janie' => ['Panie Janie', [[392, 300], [440, 300], [494, 300], [392, 300], [392, 300], [440, 300], [494, 300], [392, 600]]],
        'sto-lat' => ['Sto lat', [[349, 250], [349, 250], [392, 500], [349, 500], [466, 500], [440, 900]]],
        'kurki-trzy' => ['Miałam raz kurki trzy', [[440, 250], [440, 250], [523, 250], [523, 250], [587, 250], [587, 250], [523, 700]]],
        'zielona-lodka' => ['Zielona łódka', [[659, 300], [587, 300], [523, 300], [587, 300], [659, 300], [659, 600]]],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CategoryRepository $categories,
        private readonly MediaLibrary $media,
        private readonly DictionarySeeder $dictionary,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rows = [];

        $rows[] = $this->seedMelodia($io);
        $this->entityManager->flush();

        foreach ($this->dictionary->seed() as $seeded) {
            $rows[] = [$seeded['name'], $seeded['games'], (string) $seeded['added']];
        }

        $io->table(['kategoria', 'gry', 'dodano'], $rows);
        $io->success('Gotowe. Resztą treści zarządzasz w panelu (/panel).');

        return Command::SUCCESS;
    }

    /** @return array{string, string, string} */
    private function seedMelodia(SymfonyStyle $io): array
    {
        $category = $this->categories->findOneBySlug('demo-melodia');

        if (null !== $category) {
            return ['demo-melodia', 'melodia', 'juz istnieje'];
        }

        $category = new Category('demo-melodia', 'Piosenki z dzieciństwa (demo)', Category::KIND_MELODIA);
        $this->entityManager->persist($category);

        $dir = $this->media->directory().'/demo';

        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            $io->warning(sprintf('Nie moge utworzyc %s - utwory beda bez plikow.', $dir));
        }

        $position = 0;
        foreach (self::TUNES as $name => [$title, $notes]) {
            $path = sprintf('demo/%s.wav', $name);
            $file = $this->media->absolutePath($path);

            if (!is_file($file)) {
                file_put_contents($file, self::wav($notes));
            }

            $track = new Track($category, $path, $title, 'Tradycyjne');
            $track->setPosition($position++);
            $this->entityManager->persist($track);
        }

        return ['demo-melodia', 'melodia', (string) \count(self::TUNES)];
    }

    /** @param list<array{int, int}> $notes */
    private static function wav(array $notes): string
    {
        $data = '';

        foreach ($notes as [$freq, $ms]) {
            $samples = (int) (self::SAMPLE_RATE * $ms / 1000);

            for ($i = 0; $i < $samples; ++$i) {
                // Obwiednia na starcie i koncu nuty - bez niej kazda zmiana
                // wysokosci daje slyszalny trzask zamiast dzwieku.
                $envelope = min(1.0, $i / 400) * min(1.0, ($samples - $i) / 400);
                $value = (int) (12000 * $envelope * sin(2 * M_PI * $freq * $i / self::SAMPLE_RATE));
                $data .= pack('v', $value & 0xFFFF);
            }
        }

        return 'RIFF'.pack('V', 36 + \strlen($data)).'WAVEfmt '
            .pack('VvvVVvv', 16, 1, 1, self::SAMPLE_RATE, self::SAMPLE_RATE * 2, 2, 16)
            .'data'.pack('V', \strlen($data)).$data;
    }
}

<?php

declare(strict_types=1);

namespace App\Command;

use App\Content\MediaLibrary;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Generuje dzwieki do talii demonstracyjnej.
 *
 * Katalog mediow nie jest wersjonowany (pliki sa duze i zwykle nie nasze),
 * wiec po sklonowaniu repozytorium talia "demo" nie mialaby czego odtwarzac.
 * Ta komenda tworzy kilka prostych melodii z generatora, dzieki czemu gre da
 * sie uruchomic i przetestowac bez wgrywania czyjejkolwiek muzyki.
 */
#[AsCommand(
    name: 'app:demo:audio',
    description: 'Tworzy pliki dzwiekowe dla talii demonstracyjnej.',
)]
final class DemoAudioCommand extends Command
{
    private const SAMPLE_RATE = 22050;

    /** Nazwa pliku => melodia jako pary [czestotliwosc w Hz, dlugosc w ms]. */
    private const TUNES = [
        'wlazl-kotek' => [[523, 300], [587, 300], [659, 300], [587, 300], [523, 600]],
        'panie-janie' => [[392, 300], [440, 300], [494, 300], [392, 300], [392, 300], [440, 300], [494, 300], [392, 600]],
        'sto-lat' => [[349, 250], [349, 250], [392, 500], [349, 500], [466, 500], [440, 900]],
        'kurki-trzy' => [[440, 250], [440, 250], [523, 250], [523, 250], [587, 250], [587, 250], [523, 700]],
        'zielona-lodka' => [[659, 300], [587, 300], [523, 300], [587, 300], [659, 300], [659, 600]],
    ];

    public function __construct(
        private readonly MediaLibrary $media,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Nadpisuje istniejace pliki');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = true === $input->getOption('force');
        $dir = $this->media->directory().'/demo';

        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            $io->error(sprintf('Nie moge utworzyc katalogu %s.', $dir));

            return Command::FAILURE;
        }

        $written = 0;
        $skipped = 0;

        foreach (self::TUNES as $name => $notes) {
            $path = sprintf('%s/%s.wav', $dir, $name);

            if (is_file($path) && !$force) {
                ++$skipped;

                continue;
            }

            file_put_contents($path, self::wav($notes));
            ++$written;
        }

        $io->success(sprintf(
            'Gotowe: %d plikow w %s%s.',
            $written,
            $dir,
            $skipped > 0 ? sprintf(' (%d juz istnialo, --force nadpisze)', $skipped) : '',
        ));

        return Command::SUCCESS;
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

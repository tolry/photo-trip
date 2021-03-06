<?php
/*
 * @author Tobias Olry <tobias.olry@gmail.com>
 */

namespace PhotoTrip\Command;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Finder\Finder;
use PHPExif\Reader\Reader as ExifReader;

class GenerateCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('generate')
            ->setDescription('generate html page')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $cwd = getcwd();

        $conn = $this->connectToDatabase($cwd);

        //$progressBar = new ProgressBar($output, count($finder));
        $pictures = array_map(
            function ($row) {
                $row['coordinates'] = explode(',', json_decode($row['coordinates']));
                $row['path'] = basename($row['path']);

                return $row;
            },
            $conn->fetchAll("SELECT * FROM picture WHERE coordinates IS NOT NULL AND coordinates <> '\"\"'")
        );

        //$progressBar->finish();

        $loader = new \Twig_Loader_Filesystem(__DIR__ . '/../templates');
        $twig = new \Twig_Environment($loader);
        $html = $twig->render('index.html.twig', ['pictures' => $pictures]);
        file_put_contents($cwd . '/index.html', $html);
    }

    private function connectToDatabase($directory)
    {
        $databaseFile = $directory . '/.photo-trip.db';

        if (! file_exists($databaseFile)) {
            throw new \Exception('no photo-trip database found');
        }

        $config = new \Doctrine\DBAL\Configuration();
        $connectionParams = [
            'path' => $databaseFile,
            'driver' => 'pdo_sqlite',
        ];

        return \Doctrine\DBAL\DriverManager::getConnection($connectionParams, $config);
    }
}

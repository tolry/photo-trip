<?php
/*
 * @author Tobias Olry <tobias.olry@gmail.com>
 */

namespace PhotoTrip\Command;

use Imagine\Imagick\Imagine;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use PHPExif\Reader\Reader as ExifReader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Finder\Finder;

class UpdateThumbnailsCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('update-thumbnails')
            ->setDescription('generate all thumbnails anew')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $cwd = getcwd();
        $conn = $this->connectToDatabase($cwd);

        $pictures = $conn->fetchAll("SELECT * FROM picture");
        //$pictures = $conn->fetchAll("SELECT * FROM picture WHERE coordinates IS NOT NULL AND coordinates <> '\"\"'");

        $imagine = new Imagine();
        $size = new \Imagine\Image\Box(400, 400);
        $mode = \Imagine\Image\ImageInterface::THUMBNAIL_INSET;
        $mode = \Imagine\Image\ImageInterface::THUMBNAIL_OUTBOUND;

        $progressBar = new ProgressBar($output, count($pictures));
        foreach ($pictures as $picture) {
            $progressBar->advance();
            if (! file_exists($picture['path'])) {
                syslog(LOG_ERROR, 'missing path ' . $picture['path']);
                continue;
            }

            $imagine->open($picture['path'])
                ->thumbnail($size, $mode)
                ->save($cwd . '/thumbnails/' . $picture['sha1'] . '.jpg')
            ;
        }
        $progressBar->finish();
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

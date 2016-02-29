<?php
/*
 * @author Tobias Olry <tobias.olry@gmail.com>
 */

namespace PhotoTrip\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Finder\Finder;
use PHPExif\Reader\Reader as ExifReader;

class CreateProjectCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('create-project')
            ->setDescription('create a new project in current working directory')
            ->addArgument(
                'folder',
                InputArgument::REQUIRED,
                'folder to scan for pictures'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $folder = $input->getArgument('folder');
        $reader = ExifReader::factory(ExifReader::TYPE_NATIVE);
        $finder = new Finder();

        $finder->files()->in($folder)->name('#\.(jpe?g|nef|nrw)$#i');

        $conn = $this->createDatabase();

        $output->writeln("examining " . count($finder) . " files");

        $progressBar = new ProgressBar($output, count($finder));

        foreach ($finder as $file) {
            $exifData = $reader->read($file);
            $progressBar->advance();

            if (! $exifData->getCreationDate()) {
                $output->writeln("<error>error: no creation date on file " . $file->getPathname() . "</error>");
                continue;
            }

            $conn->insert(
                'picture',
                [
                    'path' => $file->getPathname(),
                    'created_at' => $exifData->getCreationDate()->format('Y-m-d H:i:s'),
                    'camera' => $exifData->getCamera(),
                    'coordinates' => '',
                ]
            );
        }

        $progressBar->finish();
    }

    private function createDatabase()
    {
        $config = new \Doctrine\DBAL\Configuration();
        $connectionParams = [
            'path' => '.photo-trip.db',
            'driver' => 'pdo_sqlite',
        ];
        $conn = \Doctrine\DBAL\DriverManager::getConnection($connectionParams, $config);

        $schema = new \Doctrine\DBAL\Schema\Schema();
        $picture = $schema->createTable("picture");
        $picture->addColumn("path", "string", ["length" => 500]);
        $picture->addColumn("camera", "string", ["length" => 256]);
        $picture->addColumn("coordinates", "string", ["length" => 500]);
        $picture->addColumn("created_at", "datetime");
        $picture->setPrimaryKey(["path"]);
        $picture->addIndex(["camera"]);
        $picture->addIndex(["created_at"]);

        $sql = $schema->toSql(new \Doctrine\DBAL\Platforms\SqlitePlatform());

        foreach ($sql as $query) {
            $conn->query($query);
        }

        return $conn;
    }
}

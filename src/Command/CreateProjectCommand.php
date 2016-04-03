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

class CreateProjectCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('create')
            ->setDescription('create a new project in current working directory')
            ->addArgument(
                'source',
                InputArgument::REQUIRED,
                'folder to scan for pictures'
            )
            ->addArgument(
                'target',
                InputArgument::REQUIRED,
                'project folder - to be created'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $source = $input->getArgument('source');
        $target = $input->getArgument('target');
        //$reader = ExifReader::factory(ExifReader::TYPE_EXIFTOOL);
        $reader = ExifReader::factory(ExifReader::TYPE_NATIVE);
        $reader->getAdapter()->setIncludeThumbnail(true);

        if (file_exists($target)) {
            throw new \Exception("folder $target already exists");
        }

        if (! mkdir($target, 0750, $recursive = true)) {
            throw new \Exception("could not create folder $target");
        }

        $log = new Logger('create-project');
        $log->pushHandler(new StreamHandler($target . '/create.log', Logger::INFO));

        $finder = new Finder();
        $finder
            ->files()
            ->in($source)
            ->name('#\.(jpe?g|nef|nrw|cr2)$#i');

        $conn = $this->createDatabase($target);

        $output->writeln("examining " . count($finder) . " files");

        $progressBar = new ProgressBar($output, count($finder));

        $gps = [];
        foreach ($finder as $file) {
            $exifData = $reader->read($file);
            $rawData = $exifData->getRawData();
            $progressBar->advance();

            $sha1 = sha1($file->getPathname());

            $thumbnail = false;
            if (isset($rawData['THUMBNAIL']['THUMBNAIL'])) {
                $thumbnail = true;
                file_put_contents(
                    $target . '/' . $sha1 . '.jpg',
                    $rawData['THUMBNAIL']['THUMBNAIL']
                );
            }

            if (! $exifData->getCreationDate()) {
                $log->addWarning("no creation date on file " . $file->getPathname());
                continue;
            }

            $camera = $this->findOrCreateCamera($exifData->getCamera(), $conn);

            $coordinates = $exifData->getGPS();
            $coordinates = ($coordinates && $coordinates != '0,0')
                ? $coordinates
                : '';

            $conn->insert(
                'picture',
                [
                    'path' => $file->getPathname(),
                    'sha1' => $sha1,
                    'created_at' => $exifData->getCreationDate()->format('Y-m-d H:i:s'),
                    'camera_id' => $camera['id'],
                    'coordinates' => json_encode($coordinates),
                    'thumbnail' => $thumbnail,
                ]
            );

            if (! empty($coordinates)) {
                $gps[] = explode(',', $coordinates);
            }
        }

        $progressBar->finish();

        $loader = new \Twig_Loader_Filesystem(__DIR__ . '/../templates');
        $twig = new \Twig_Environment($loader);
        $html = $twig->render('index.html.twig', ['gps' => $gps]);
        file_put_contents($target . '/index.html', $html);
    }

    private function createDatabase($target)
    {
        $config = new \Doctrine\DBAL\Configuration();
        $connectionParams = [
            'path' => $target . '/.photo-trip.db',
            'driver' => 'pdo_sqlite',
        ];
        $conn = \Doctrine\DBAL\DriverManager::getConnection($connectionParams, $config);

        $schema = new \Doctrine\DBAL\Schema\Schema();

        $camera = $schema->createTable("camera");
        $camera->addColumn("id", "integer", ["unsigned" => true, "autoincrement" => true]);
        $camera->addColumn("name", "string", ["length" => 255]);
        $camera->setPrimaryKey(["id"]);
        $camera->addIndex(["name"]);

        $picture = $schema->createTable("picture");
        $picture->addColumn("id", "integer", ["unsigned" => true, "autoincrement" => true]);
        $picture->addColumn("path", "string", ["length" => 500]);
        $picture->addColumn("camera_id", "integer", ["unsigned" => true]);
        $picture->addColumn("coordinates", "string", ["length" => 500]);
        $picture->addColumn("thumbnail", "boolean");
        $picture->addColumn("sha1", "string", ["length" => 40]);
        $picture->addColumn("created_at", "datetime");
        $picture->setPrimaryKey(["id"]);
        $picture->addUniqueIndex(["path"]);
        $picture->addIndex(["created_at"]);
        $picture->addForeignKeyConstraint($camera, ["camera_id"], ["id"]);

        $sql = $schema->toSql(new \Doctrine\DBAL\Platforms\SqlitePlatform());

        foreach ($sql as $query) {
            $conn->query($query);
        }

        return $conn;
    }

    private function findOrCreateCamera($cameraName, $connection)
    {
        $statement = $connection->executeQuery("SELECT * FROM camera WHERE name = ?", [$cameraName]);
        $camera = $statement->fetch();

        if ($camera) {
            return $camera;
        }

        $camera = [
            'name' =>$cameraName,
        ];

        $camera['id'] = $connection->insert('camera', $camera);

        return $camera;
    }
}

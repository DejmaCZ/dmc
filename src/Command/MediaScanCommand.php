<?php

namespace App\Command;

use App\Entity\Media;
use App\Entity\MediaType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'media:scan',
    description: 'Scans media directory and creates/updates database records'
)]
class MediaScanCommand extends Command
{
    private $pdo = null;
    private $dirCache = [];
    private $media = null;
    private $mainPdo = null;
    private $dbPath = null;
    
    public function __construct()
    {
        parent::__construct();
        $this->dbPath = __DIR__ . '/../../var/data.db';
    }

    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'Path to scan (required for new media)')
            ->addOption(
                'media-id',
                'm',
                InputOption::VALUE_REQUIRED,
                'Update existing media by ID'
            )
            ->addOption(
                'description',
                'd',
                InputOption::VALUE_REQUIRED,
                'Human readable description of media'

            )
            ->addOption(
                'list',
                'l',
                InputOption::VALUE_NONE,
                'List all available media'
            )
            ->addOption(
                'type',
                't',
                InputOption::VALUE_REQUIRED,
                'Type of media (HDD, DVD, etc.)'
            )
            ->addOption(
                'batch-size',
                'b',
                InputOption::VALUE_REQUIRED,
                'Number of files to process in one batch',
                100
            )
            ->addOption(
                'skip-stats',
                null,
                InputOption::VALUE_NONE,
                'Skip updating statistics after scan'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Vypnout debug režim
        $_ENV['APP_ENV'] = 'prod';
        $_ENV['APP_DEBUG'] = '0';
        
        // Nastavení pro PHP
        ini_set('memory_limit', '512M');
        ini_set('max_execution_time', 0);



        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('list')) {
            // Zkontrolovat a případně vytvořit hlavní databázi
            $this->initMainDatabase($io);
            $this->listAllMedia($io);
            return Command::SUCCESS;
        }


        $batchSize = (int)$input->getOption('batch-size');
        $skipStats = $input->getOption('skip-stats');
        
        $io->title('Starting media scan...');

        try {
            // Zkontrolovat a případně vytvořit hlavní databázi
            $this->initMainDatabase($io);

            if ($mediaId = $input->getOption('media-id')) {
                // UPDATE mód
                $this->media = $this->loadExistingMedia($mediaId);
                $path = $this->media['path'];
                $io->note("Updating media ID: $mediaId");
            } else {
                // ADD mód
                $path = $input->getArgument('path');
                $description = $input->getOption('description');
                $type = $input->getOption('type');

                if (!$path) {
                    $io->error('Path is required for new media');
                    return Command::FAILURE;
                }
                if (!$description) {
                    $io->error('Description is required for new media');
                    return Command::FAILURE;
                }
                if (!$type) {
                    $io->error('Type is required for new media');
                    return Command::FAILURE;
                }

                $identifier = $this->generateIdentifier();
                while ($this->identifierExists($identifier)) {
                    $identifier = $this->generateIdentifier();
                }

                try {
                    $this->media = $this->createNewMedia($path, $identifier, $description, $type);
                } catch (\RuntimeException $e) {
                    // Zkontrolovat, zda neexistuje typ média a případně ho vytvořit
                    if (strpos($e->getMessage(), "Media type '$type' not found") !== false) {
                        $io->note("Media type '$type' not found, creating it...");
                        $this->createMediaType($type);
                        $this->media = $this->createNewMedia($path, $identifier, $description, $type);
                    } else {
                        $io->error($e->getMessage());
                        return Command::FAILURE;
                    }
                }
                $io->note("Created new media with identifier: $identifier");
            }

            // Kontrola existence adresáře
            if (!is_dir($path)) {
                $io->error("Directory '$path' does not exist");
                return Command::FAILURE;
            }

            // Inicializace in-memory SQLite databáze
            $this->initInMemoryDb();
            $io->note("Using in-memory database for scanning");

            $io->section('Collecting files to process...');
            
            // Sbíráme soubory
            $allFiles = [];
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $allFiles[] = $file->getPathname();
                }
            }
            
            $totalFiles = count($allFiles);
            $io->note("Found $totalFiles files to process");

            // Zpracování v dávkách
            $processedFiles = 0;
            $batches = ceil($totalFiles / $batchSize);
            
            $progressBar = $io->createProgressBar($totalFiles);
            $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% (%memory%)');
            $progressBar->start();

            for ($i = 0; $i < $totalFiles; $i += $batchSize) {
                $batchFiles = array_slice($allFiles, $i, $batchSize);
                $currentBatch = floor($i / $batchSize) + 1;

                $this->pdo->beginTransaction();
                
                try {
                    foreach ($batchFiles as $filePath) {
                        $fileInfo = new \SplFileInfo($filePath);
                        $this->processFile($fileInfo);
                        $processedFiles++;
                        $progressBar->advance();
                    }
                    
                    $this->pdo->commit();
                    
                } catch (\Exception $e) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    $io->warning("Error in batch $currentBatch: " . $e->getMessage());
                }
                
                // Resetujeme cache adresářů každých X dávek
                if ($currentBatch % 5 == 0) {
                    $this->dirCache = [];
                    gc_collect_cycles();
                }
            }
            
            $progressBar->finish();
            $io->newLine(2);

            // Exportovat data do hlavní databáze
            $io->section('Exporting data to main database...');
            $this->exportToMainDb($io);
            
            // Update času posledního skenu
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $stmt = $this->mainPdo->prepare("UPDATE media SET last_scanned_at = ? WHERE id = ?");
            $stmt->execute([$now, $this->media['id']]);

            $io->success(sprintf('Scan completed! Processed %d files.', $processedFiles));

            // Aktualizace statistik média
            if (!$skipStats) {
                try {
                    // Vypočítat a uložit statistiky
                    $this->calculateAndStoreStatistics($io);
                } catch (\Exception $e) {
                    $io->warning('Error updating statistics: ' . $e->getMessage());
                }
            } else {
                $io->note('Statistics update skipped (--skip-stats option was used).');
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Error during scan: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function initMainDatabase(SymfonyStyle $io): void
    {
        // Zkontrolovat existenci adresáře
        $dbDir = dirname($this->dbPath);
        if (!is_dir($dbDir)) {
            $io->note("Creating database directory: $dbDir");
            if (!mkdir($dbDir, 0755, true)) {
                throw new \RuntimeException("Failed to create directory: $dbDir");
            }
        }

        // Připojení k hlavní databázi nebo její vytvoření
        $this->mainPdo = new \PDO('sqlite:' . $this->dbPath);
        $this->mainPdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Zkontrolovat, zda existují potřebné tabulky a případně je vytvořit
        $this->ensureMainTablesExist($io);
    }

    private function ensureMainTablesExist(SymfonyStyle $io): void
    {
        // Kontrola, zda tabulka media existuje
        $tablesQuery = $this->mainPdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='media'");
        $mediaTableExists = $tablesQuery->fetchColumn() !== false;

        if (!$mediaTableExists) {
            $io->note("Creating 'media' table");
            $this->mainPdo->exec("
                CREATE TABLE media (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    identifier VARCHAR(10) NOT NULL UNIQUE,
                    description TEXT NOT NULL,
                    path TEXT NOT NULL,
                    media_type_id INTEGER NOT NULL,
                    created_at DATETIME NOT NULL,
                    last_scanned_at DATETIME DEFAULT NULL,
                    FOREIGN KEY (media_type_id) REFERENCES media_type(id)
                )
            ");
        }

        // Kontrola, zda tabulka media_type existuje
        $tablesQuery = $this->mainPdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='media_type'");
        $mediaTypeTableExists = $tablesQuery->fetchColumn() !== false;

        if (!$mediaTypeTableExists) {
            $io->note("Creating 'media_type' table");
            $this->mainPdo->exec("
                CREATE TABLE media_type (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name VARCHAR(50) NOT NULL,
                    icon VARCHAR(50) NOT NULL,
                    description TEXT DEFAULT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT NULL
                );
            ");

            // Přidat základní typy médií
            $this->mainPdo->exec("
            INSERT INTO media_type (name, icon, description) VALUES
            ('HDD', 'hdd', 'Hard Disk Drive'),
            ('Folder', 'folder', 'Folder'),
            ('CD/DVD', 'disc', 'DVD Media'),
            ('USB', 'usb', 'USB Flash Drive'),
            ('NETWORK', 'cloud', 'Network Storage')

        ");
        }
    }

    private function createMediaType(string $name): void
    {
        $icon = strtolower($name); // Jednoduchá default ikona podle názvu
        $stmt = $this->mainPdo->prepare("INSERT INTO media_type (name, icon, description) VALUES (?, ?, ?)");
        $stmt->execute([$name, $icon, $name]);
        
        // Obnovit entity manager, aby viděl nový záznam
        $this->entityManager->clear();
    }

    private function generateIdentifier(): string
    {
        $letters = chr(rand(65, 90)) . chr(rand(65, 90)) . chr(rand(65, 90));
        $numbers = str_pad(rand(0, 999), 3, '0', STR_PAD_LEFT);
        return $letters . $numbers;
    }

    private function identifierExists(string $identifier): bool
    {
        $stmt = $this->mainPdo->prepare("SELECT COUNT(*) FROM media WHERE identifier = ?");
        $stmt->execute([$identifier]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function loadExistingMedia(int $mediaId): array 
    {
        $stmt = $this->mainPdo->prepare("SELECT * FROM media WHERE id = ?");
        $stmt->execute([$mediaId]);
        $media = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$media) {
            throw new \RuntimeException("Media with ID '$mediaId' not found");
        }
        
        // Načíst informace o typu média
        $stmt = $this->mainPdo->prepare("SELECT * FROM media_type WHERE id = ?");
        $stmt->execute([$media['media_type_id']]);
        $mediaType = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$mediaType) {
            throw new \RuntimeException("Media type with ID '{$media['media_type_id']}' not found");
        }
        
        // Přidat typ média do výsledku
        $media['media_type'] = $mediaType;
        
        return $media;
    }

    private function listAllMedia(SymfonyStyle $io): void
    {
        try {
            $results = $this->mainPdo->query("
                SELECT m.id, m.identifier, m.description, mt.name as type, mt.icon
                FROM media m
                JOIN media_type mt ON m.media_type_id = mt.id
                ORDER BY m.id ASC
            ")->fetchAll(\PDO::FETCH_ASSOC);
            
            if (count($results) === 0) {
                $io->info('No media found in database.');
                return;
            }
            
            // Připravit data pro tabulku
            $tableData = [];
            foreach ($results as $row) {
                $tableData[] = [
                    $row['id'],
                    $row['identifier'],
                    $row['type'],
                    $row['description'],
                    $row['icon']
                ];
            }
            
            $io->table(
                ['ID', 'Identifier', 'Type', 'Description', 'Icon'],
                $tableData
            );
        } catch (\Exception $e) {
            $io->error("Error listing media: " . $e->getMessage());
        }
    }

    private function createNewMedia(string $path, string $identifier, string $description, string $type): array
    {
        // Najít typ média
        $stmt = $this->mainPdo->prepare("SELECT id FROM media_type WHERE name = ?");
        $stmt->execute([$type]);
        $mediaTypeId = $stmt->fetchColumn();
        
        if (!$mediaTypeId) {
            throw new \RuntimeException("Media type '$type' not found");
        }
        
        $createdAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        
        $stmt = $this->mainPdo->prepare("
            INSERT INTO media (identifier, description, path, media_type_id, created_at)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$identifier, $description, $path, $mediaTypeId, $createdAt]);
        
        $mediaId = $this->mainPdo->lastInsertId();
        
        // Načíst a vrátit vytvořené médium
        $stmt = $this->mainPdo->prepare("SELECT * FROM media WHERE id = ?");
        $stmt->execute([$mediaId]);
        $media = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        // Přidat informace o typu média
        $media['media_type'] = [
            'id' => $mediaTypeId,
            'name' => $type
        ];
        
        return $media;
    }

    private function initInMemoryDb(): void
    {
        // Vytvořit in-memory databázi
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        
        // Optimalizace
        $this->pdo->exec('PRAGMA synchronous=OFF');
        $this->pdo->exec('PRAGMA journal_mode=MEMORY');
        $this->pdo->exec('PRAGMA temp_store=MEMORY');
        
        // Vytvořit tabulky
        $this->pdo->exec("
            CREATE TABLE directories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                parent_id INTEGER,
                path TEXT NOT NULL,
                name TEXT NOT NULL
            )
        ");
        
        $this->pdo->exec("
            CREATE TABLE files (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                directory_id INTEGER NOT NULL,
                original_filename TEXT NOT NULL,
                full_path TEXT NOT NULL,
                content_hash CHAR(32) NOT NULL,
                extension VARCHAR(50),
                file_size BIGINT,
                file_modified_at DATETIME,
                FOREIGN KEY (directory_id) REFERENCES directories(id)
            )
        ");
        
        // Vytvořit indexy
        $this->pdo->exec("CREATE INDEX idx_directories_path ON directories (path)");
        $this->pdo->exec("CREATE INDEX idx_files_content_hash ON files (content_hash)");
        $this->pdo->exec("CREATE INDEX idx_files_directory ON files (directory_id)");
    }

    private function processDirectory(\SplFileInfo $dir): int 
    {
        $path = $dir->getPathname();
        
        // Pokud je adresář už v cache, vrátíme jeho ID
        if (isset($this->dirCache[$path])) {
            return $this->dirCache[$path];
        }

        // Najdeme adresář v databázi
        $stmt = $this->pdo->prepare("SELECT id FROM directories WHERE path = :path");
        $stmt->execute(['path' => $path]);
        $dirId = $stmt->fetchColumn();
        
        if ($dirId) {
            $this->dirCache[$path] = $dirId;
            return $dirId;
        }

        // Najít parent_id
        $parentPath = dirname($path);
        $parentId = null;
        if ($parentPath !== $path) {
            $parentId = $this->processDirectory(new \SplFileInfo($parentPath));
        }
        
        $data = [
            'parent_id' => $parentId,
            'path' => $path,
            'name' => basename($path)
        ];

        $sql = "INSERT INTO directories 
                (parent_id, path, name) 
                VALUES 
                (:parent_id, :path, :name)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);
        
        $dirId = $this->pdo->lastInsertId();
        $this->dirCache[$path] = $dirId;
        
        return $dirId;
    }

    private function processFile(\SplFileInfo $file): void
    {
        // Nejprve zpracujeme adresář a získáme jeho ID
        $directoryId = $this->processDirectory(new \SplFileInfo(dirname($file->getPathname())));

        // Zkontrolujeme, zda soubor už existuje v databázi
        $stmt = $this->pdo->prepare("SELECT id FROM files WHERE full_path = :path");
        $stmt->execute(['path' => $file->getPathname()]);
        $fileId = $stmt->fetchColumn();
        
        if ($fileId) {
            // Soubor už existuje, můžeme ho přeskočit nebo aktualizovat
            return;
        }

        $data = [
            'directory_id' => $directoryId,
            'original_filename' => $file->getFilename(),
            'full_path' => $file->getPathname(),
            'content_hash' => hash_file('xxh128', $file->getPathname()),
            'extension' => $file->getExtension(),
            'file_size' => $file->getSize(),
            'file_modified_at' => (new \DateTime())->setTimestamp($file->getMTime())->format('Y-m-d H:i:s')
        ];

        $sql = "INSERT INTO files 
                (directory_id, original_filename, full_path, content_hash, extension, file_size, file_modified_at) 
                VALUES 
                (:directory_id, :original_filename, :full_path, :content_hash, :extension, :file_size, :file_modified_at)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);
    }

    private function exportToMainDb(SymfonyStyle $io): void
    {
        $identifier = strtolower($this->media['identifier']);
        $filesTable = 'files_' . $identifier;
        $dirsTable = 'directories_' . $identifier;
        
        try {
            // Použijeme již vytvořené připojení k hlavní databázi
            
            // Nejprve odstranit existující tabulky pro toto médium
            $this->mainPdo->exec("DROP TABLE IF EXISTS $filesTable");
            $this->mainPdo->exec("DROP TABLE IF EXISTS $dirsTable");
            $io->note("Removed existing tables if any");
            
            // Vytvořit tabulky
            $schema = $this->pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='directories'")->fetchColumn();
            $schema = str_replace('CREATE TABLE directories', "CREATE TABLE $dirsTable", $schema);
            $schema = str_replace('parent_id INTEGER', "parent_id INTEGER, media_id INTEGER NOT NULL DEFAULT {$this->media['id']}", $schema);
            $this->mainPdo->exec($schema);
            
            $schema = $this->pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='files'")->fetchColumn();
            $schema = str_replace('CREATE TABLE files', "CREATE TABLE $filesTable", $schema);
            $schema = str_replace('directory_id INTEGER', "directory_id INTEGER, media_id INTEGER NOT NULL DEFAULT {$this->media['id']}", $schema);
            $schema = str_replace('REFERENCES directories', "REFERENCES $dirsTable", $schema);
            $this->mainPdo->exec($schema);
            
            // Vytvořit indexy s novými názvy
            $this->mainPdo->exec("CREATE INDEX idx_{$identifier}_dirs_path ON $dirsTable (path)");
            $this->mainPdo->exec("CREATE INDEX idx_{$identifier}_files_hash ON $filesTable (content_hash)");
            $this->mainPdo->exec("CREATE INDEX idx_{$identifier}_files_dir ON $filesTable (directory_id)");
            
            $io->note("Created tables and indexes in main database");
            
            // Exportovat adresáře
            $dirs = $this->pdo->query("SELECT * FROM directories")->fetchAll(\PDO::FETCH_ASSOC);
            $dirCount = count($dirs);
            
            if ($dirCount > 0) {
                $io->note("Exporting $dirCount directories...");
                
                $this->mainPdo->beginTransaction();
                
                $sql = "INSERT INTO $dirsTable (id, parent_id, media_id, path, name) VALUES (?, ?, ?, ?, ?)";
                $stmt = $this->mainPdo->prepare($sql);
                
                foreach ($dirs as $dir) {
                    $stmt->execute([
                        $dir['id'],
                        $dir['parent_id'],
                        $this->media['id'],
                        $dir['path'],
                        $dir['name']
                    ]);
                }
                
                $this->mainPdo->commit();
            }
            
            // Exportovat soubory po dávkách
            $fileCount = $this->pdo->query("SELECT COUNT(*) FROM files")->fetchColumn();
            
            if ($fileCount > 0) {
                $io->note("Exporting $fileCount files...");
                
                $batchSize = 1000;
                $batches = ceil($fileCount / $batchSize);
                
                $sql = "INSERT INTO $filesTable (id, directory_id, media_id, original_filename, full_path, content_hash, extension, file_size, file_modified_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $this->mainPdo->prepare($sql);
                
                for ($i = 0; $i < $batches; $i++) {
                    $offset = $i * $batchSize;
                    $files = $this->pdo->query("SELECT * FROM files LIMIT $batchSize OFFSET $offset")->fetchAll(\PDO::FETCH_ASSOC);
                    
                    $this->mainPdo->beginTransaction();
                    
                    foreach ($files as $file) {
                        $stmt->execute([
                            $file['id'],
                            $file['directory_id'],
                            $this->media['id'],
                            $file['original_filename'],
                            $file['full_path'],
                            $file['content_hash'],
                            $file['extension'],
                            $file['file_size'],
                            $file['file_modified_at']
                        ]);
                    }
                    
                    $this->mainPdo->commit();
                    
                    $io->note(sprintf("Exported batch %d/%d", $i+1, $batches));
                }
            }
            
            $io->note("Export completed successfully");
        } catch (\Exception $e) {
            $io->error("Error during export: " . $e->getMessage());
            throw $e;
        }
    }

    /**
    * Zajistí vytvoření tabulek pro statistiky, pokud neexistují
    */
    private function ensureStatsTablesExist(SymfonyStyle $io): void
    {
        // Kontrola, zda tabulka media_stats existuje
        $tablesQuery = $this->mainPdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='media_stats'");
        $mediaStatsTableExists = $tablesQuery->fetchColumn() !== false;

        if (!$mediaStatsTableExists) {
            $io->note("Creating 'media_stats' table");
            $this->mainPdo->exec("
                CREATE TABLE media_stats (
                    media_id INTEGER PRIMARY KEY,
                    files_count INTEGER,
                    total_size BIGINT,
                    last_calculated_at DATETIME,
                    directories_count INTEGER,
                    FOREIGN KEY(media_id) REFERENCES media(id)
                )
            ");
        }


        // Kontrola, zda tabulka meta_types existuje
        $tablesQuery = $this->mainPdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='meta_types'");
        $mediaStatsTableExists = $tablesQuery->fetchColumn() !== false;

        if (!$mediaStatsTableExists) {
            $io->note("Creating 'meta_types' table");
            $this->mainPdo->exec("
                CREATE TABLE meta_types (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(50) NOT NULL,            -- např. Author, Genre, Year
                label VARCHAR(100) NOT NULL,          -- zobrazovaný název
                description TEXT,                     -- popis typu metadat
                data_type VARCHAR(20) NOT NULL,       -- text, number, date, boolean
                is_multiple BOOLEAN DEFAULT false,    -- může mít soubor více hodnot tohoto typu?
                created_at DATETIME NOT NULL
                )
            ");

            $this->mainPdo->exec("
            INSERT INTO meta_types (name, label, description, data_type, is_multiple, created_at) VALUES
                -- Základní typy metadat
                ('Author', 'Autor', 'Autor nebo tvůrce souboru', 'text', 1, CURRENT_TIMESTAMP),
                ('Title', 'Název', 'Název díla', 'text', 0, CURRENT_TIMESTAMP),
                ('Year', 'Rok', 'Rok vydání nebo vytvoření', 'number', 0, CURRENT_TIMESTAMP),
                ('Genre', 'Žánr', 'Žánr nebo kategorie', 'text', 1, CURRENT_TIMESTAMP),
                ('Country', 'Země', 'Země původu', 'text', 0, CURRENT_TIMESTAMP),
                ('Language', 'Jazyk', 'Jazyk obsahu', 'text', 1, CURRENT_TIMESTAMP),
                ('Description', 'Popis', 'Popis nebo anotace obsahu', 'text', 0, CURRENT_TIMESTAMP),
                ('Rating', 'Hodnocení', 'Osobní hodnocení (1-10)', 'number', 0, CURRENT_TIMESTAMP),
                ('Tags', 'Štítky', 'Vlastní označení pro snadné vyhledávání', 'text', 1, CURRENT_TIMESTAMP),
                ('Source', 'Zdroj', 'Zdroj nebo původ souboru', 'text', 0, CURRENT_TIMESTAMP);
            
            ");
            
        }

        // Kontrola, zda tabulka meta_values existuje
        $tablesQuery = $this->mainPdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='meta_values'");
        $mediaStatsTableExists = $tablesQuery->fetchColumn() !== false;

        if (!$mediaStatsTableExists) {
            $io->note("Creating 'meta_values' table");
            $this->mainPdo->exec("
                CREATE TABLE meta_values (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                file_hash CHAR(32) NOT NULL,          -- hash souboru
                meta_type_id INTEGER NOT NULL,        -- odkaz na typ metadat
                value TEXT NOT NULL,                  -- hodnota (vždy jako text, konverze dle data_type)
                FOREIGN KEY (meta_type_id) REFERENCES meta_types(id)
            );
            ");
            $this->mainPdo->exec("CREATE INDEX idx_meta_values_file_hash ON meta_values(file_hash);");
        }

        // Kontrola, zda tabulka file_category existuje
        $tablesQuery = $this->mainPdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='file_category'");
        $fileCategoryTableExists = $tablesQuery->fetchColumn() !== false;

        if (!$fileCategoryTableExists) {
            $io->note("Creating 'file_category' table");
            $this->mainPdo->exec("
                CREATE TABLE file_category (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name VARCHAR(255) NOT NULL,
                    description VARCHAR(255) DEFAULT NULL,
                    icon VARCHAR(50) DEFAULT NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME DEFAULT NULL
                )
            ");

            // Přidat základní kategorie
            $io->note("Inserting categories 'file_category' table");
            // $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $this->mainPdo->exec("
                INSERT INTO file_category (name, description, icon, created_at) VALUES
                ('Images', 'Image files', 'file-image', CURRENT_TIMESTAMP),
                ('Movies', 'Video files', 'film', CURRENT_TIMESTAMP),
                ('Documents', 'Document files', 'files-alt', CURRENT_TIMESTAMP),
                ('Audio', 'Audio files', 'headphones', CURRENT_TIMESTAMP),
                ('Archives', 'Archive files', 'archive', CURRENT_TIMESTAMP),
                ('Other', 'Other files', 'file', CURRENT_TIMESTAMP),
                ('Subtitles', 'Subtitles', 'text-center', CURRENT_TIMESTAMP),
                ('Programming', 'Programming and source code files', 'code', CURRENT_TIMESTAMP),
                ('CAD & 3D', 'CAD and 3D model files', 'boxes', CURRENT_TIMESTAMP),
                ('Fonts', 'Font files', 'file-font', CURRENT_TIMESTAMP);
            ");
        }

        // Kontrola, zda tabulka file_extension existuje
        $tablesQuery = $this->mainPdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='file_extension'");
        $fileExtensionTableExists = $tablesQuery->fetchColumn() !== false;

        if (!$fileExtensionTableExists) {
            $io->note("Creating 'file_extension' table");
            $this->mainPdo->exec("
                CREATE TABLE file_extension (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    category_id INTEGER NOT NULL,
                    name VARCHAR(50) NOT NULL,
                    FOREIGN KEY(category_id) REFERENCES file_category(id)
                )
            ");

            // Přidat základní mapování přípon na kategorie
            $io->note("Inserting 'file_extension' table");
            $this->mainPdo->exec("
                INSERT INTO file_extension (category_id, name) VALUES
                    -- 1. Obrázky - rozšířený seznam
                    (1, 'jpg'), (1, 'jpeg'), (1, 'png'), (1, 'gif'), (1, 'bmp'), (1, 'tiff'), (1, 'tif'), 
                    (1, 'webp'), (1, 'svg'), (1, 'raw'), (1, 'cr2'), (1, 'nef'), (1, 'dng'), (1, 'heic'),
                    (1, 'heif'), (1, 'ico'), (1, 'psd'), (1, 'ai'), (1, 'eps'), (1, 'indd'), (1, 'xcf'),
                    (1, 'sketch'), (1, 'cdr'), (1, 'avif'), (1, 'jxr'), (1, 'jfif'), (1, 'exif'), (1, 'hdr'),

                    -- 2. Videa - rozšířený seznam
                    (2, 'mp4'), (2, 'avi'), (2, 'mkv'), (2, 'mov'), (2, 'wmv'), (2, 'flv'), (2, 'webm'),
                    (2, 'm4v'), (2, 'mpg'), (2, 'mpeg'), (2, '3gp'), (2, '3g2'), (2, 'ogv'), (2, 'ts'),
                    (2, 'mts'), (2, 'm2ts'), (2, 'vob'), (2, 'divx'), (2, 'xvid'), (2, 'rm'), (2, 'rmvb'),
                    (2, 'asf'), (2, 'amv'), (2, 'm2v'), (2, 'hevc'), (2, 'h264'), (2, 'h265'), (2, 'f4v'),

                    -- 3. Dokumenty - rozšířený seznam
                    (3, 'doc'), (3, 'docx'), (3, 'pdf'), (3, 'txt'), (3, 'rtf'), (3, 'odt'),
                    (3, 'xls'), (3, 'xlsx'), (3, 'csv'), (3, 'ods'), (3, 'tsv'), (3, 'numbers'),
                    (3, 'ppt'), (3, 'pptx'), (3, 'odp'), (3, 'key'), (3, 'pages'),
                    (3, 'htm'), (3, 'html'), (3, 'xhtml'), (3, 'xml'), (3, 'json'), (3, 'yaml'), (3, 'md'),
                    (3, 'tex'), (3, 'latex'), (3, 'log'), (3, 'epub'), (3, 'mobi'), (3, 'azw'), (3, 'azw3'),
                    (3, 'djvu'), (3, 'chm'), (3, 'wps'), (3, 'wpd'), (3, 'odf'), (3, 'oxps'), (3, 'xps'),

                    -- 4. Audio - rozšířený seznam
                    (4, 'mp3'), (4, 'wav'), (4, 'ogg'), (4, 'flac'), (4, 'aac'), (4, 'm4a'),
                    (4, 'wma'), (4, 'alac'), (4, 'aiff'), (4, 'ape'), (4, 'opus'), (4, 'mid'), (4, 'midi'),
                    (4, 'amr'), (4, 'au'), (4, 'ac3'), (4, 'dts'), (4, 'ra'), (4, 'dss'), (4, 'msv'),
                    (4, 'mpc'), (4, 'voc'), (4, 'gsm'), (4, 'dct'), (4, 'vox'), (4, 'shn'), (4, 'cda'),

                    -- 5. Archivy - rozšířený seznam
                    (5, 'zip'), (5, 'rar'), (5, '7z'), (5, 'tar'), (5, 'gz'), (5, 'bz2'),
                    (5, 'xz'), (5, 'z'), (5, 'lzma'), (5, 'tgz'), (5, 'tbz2'), (5, 'cab'), (5, 'iso'),
                    (5, 'lz'), (5, 'bz'), (5, 'lzh'), (5, 'lha'), (5, 'arj'), (5, 'arc'), (5, 'uue'),
                    (5, 'war'), (5, 'ear'), (5, 'jar'), (5, 'apk'), (5, 'deb'), (5, 'rpm'), (5, 'dmg'),

                    -- 6. Ostatní soubory
                    (6, 'exe'), (6, 'msi'), (6, 'bin'), (6, 'dll'), (6, 'sys'), (6, 'app'), (6, 'bat'),
                    (6, 'sh'), (6, 'com'), (6, 'cmd'), (6, 'vbs'), (6, 'ps1'), (6, 'reg'), (6, 'ini'),
                    (6, 'cfg'), (6, 'conf'), (6, 'db'), (6, 'sql'), (6, 'sqlite'), (6, 'mdb'), (6, 'accdb'),
                    (6, 'pst'), (6, 'lnk'), (6, 'url'), (6, 'torrent'), (6, 'dat'), (6, 'orig'), (6, 'bak'),

                    -- 7. Titulky
                    (7, 'srt'), (7, 'sub'), (7, 'idx'), (7, 'vtt'), (7, 'ass'), (7, 'ssa'), (7, 'smi'),
                    (7, 'sbv'), (7, 'lrc'), (7, 'usf'), (7, 'ttml'), (7, 'dfxp'), (7, 'cap'), (7, 'stl'),

                    -- 8. Zdrojové kódy (nová kategorie)
                    (8, 'c'), (8, 'cpp'), (8, 'h'), (8, 'cs'), (8, 'java'), (8, 'py'), (8, 'js'),
                    (8, 'php'), (8, 'rb'), (8, 'go'), (8, 'rs'), (8, 'swift'), (8, 'kt'), (8, 'ts'),
                    (8, 'html'), (8, 'css'), (8, 'scss'), (8, 'less'), (8, 'jsx'), (8, 'tsx'), (8, 'vue'),
                    (8, 'json'), (8, 'xml'), (8, 'yml'), (8, 'yaml'), (8, 'sql'), (8, 'pl'), (8, 'lua'),

                    -- 9. CAD a 3D (nová kategorie)
                    (9, 'dwg'), (9, 'dxf'), (9, 'stl'), (9, 'obj'), (9, 'fbx'), (9, '3ds'), (9, 'blend'),
                    (9, 'skp'), (9, 'max'), (9, 'c4d'), (9, 'dae'), (9, 'lwo'), (9, 'ma'), (9, 'mb'),
                    (9, 'iges'), (9, 'igs'), (9, 'step'), (9, 'stp'), (9, 'x3d'), (9, 'vrml'), (9, 'wrl'),
                    
                    -- 10. Fonty (nová kategorie)
                    (10, 'ttf'), (10, 'otf'), (10, 'woff'), (10, 'woff2'), (10, 'eot'), (10, 'fnt'), (10, 'fon'),
                    (10, 'pfa'), (10, 'pfb'), (10, 'pfm'), (10, 'afm'), (10, 'bdf'), (10, 'pcf'), (10, 'psf');

                ");

        }

        // Kontrola, zda tabulka media_category_stats existuje
        $tablesQuery = $this->mainPdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='media_category_stats'");
        $mediaCategoryStatsTableExists = $tablesQuery->fetchColumn() !== false;

        if (!$mediaCategoryStatsTableExists) {
            $io->note("Creating 'media_category_stats' table");
            $this->mainPdo->exec("
                CREATE TABLE media_category_stats (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    media_id INTEGER,
                    category_id INTEGER,
                    files_count INTEGER,
                    total_size BIGINT,
                    FOREIGN KEY(media_id) REFERENCES media_stats(media_id),
                    FOREIGN KEY(category_id) REFERENCES file_category(id)
                )
            ");
        }

        // Kontrola, zda tabulka media_extension_stats existuje
        $tablesQuery = $this->mainPdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='media_extension_stats'");
        $mediaExtensionStatsTableExists = $tablesQuery->fetchColumn() !== false;

        if (!$mediaExtensionStatsTableExists) {
            $io->note("Creating 'media_extension_stats' table");
            $this->mainPdo->exec("
                CREATE TABLE media_extension_stats (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    media_id INTEGER,
                    extension VARCHAR(20),
                    files_count INTEGER,
                    total_size BIGINT,
                    FOREIGN KEY(media_id) REFERENCES media_stats(media_id)
                )
            ");
        }
    }

    /**
     * Vypočítá a uloží statistiky média
     */
    private function calculateAndStoreStatistics(SymfonyStyle $io): void
    {
        // Zajistit, že máme potřebné tabulky
        $this->ensureStatsTablesExist($io);

        $io->section('Calculating media statistics...');
        $mediaId = $this->media['id'];
        $identifier = strtolower($this->media['identifier']);
        $filesTable = 'files_' . $identifier;
        $dirsTable = 'directories_' . $identifier;

        // Nejprve odstranit existující statistiky pro toto médium
        $this->removeExistingStatistics($mediaId, $io);

        // Vypočítat celkové statistiky
        $io->note('Calculating overall statistics...');
        
        // Počet souborů a celková velikost
        $stmt = $this->mainPdo->prepare("
            SELECT COUNT(*) as files_count, SUM(file_size) as total_size
            FROM $filesTable
            WHERE media_id = ?
        ");
        $stmt->execute([$mediaId]);
        $overallStats = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        // Počet adresářů
        $stmt = $this->mainPdo->prepare("
            SELECT COUNT(*) as directories_count
            FROM $dirsTable
            WHERE media_id = ?
        ");
        $stmt->execute([$mediaId]);
        $dirsStats = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        // Aktuální čas pro statistiky
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        
        // Uložit celkové statistiky
        $stmt = $this->mainPdo->prepare("
            INSERT INTO media_stats (media_id, files_count, total_size, directories_count, last_calculated_at)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $mediaId,
            $overallStats['files_count'] ?? 0,
            $overallStats['total_size'] ?? 0,
            $dirsStats['directories_count'] ?? 0,
            $now
        ]);
        
        // Vypočítat statistiky podle kategorií
        $io->note('Calculating statistics by category...');
        
        // Získat všechny kategorie
        $categories = $this->mainPdo->query("SELECT id, name FROM file_category")->fetchAll(\PDO::FETCH_ASSOC);
        
        foreach ($categories as $category) {
            // Vypočítat statistiky pro kategorii
            $stmt = $this->mainPdo->prepare("
                SELECT COUNT(f.id) as files_count, SUM(f.file_size) as total_size
                FROM $filesTable f
                JOIN file_extension fe ON LOWER(f.extension) = LOWER(fe.name)
                WHERE f.media_id = ? AND fe.category_id = ?
            ");
            $stmt->execute([$mediaId, $category['id']]);
            $categoryStats = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            // Uložit statistiky kategorie pouze pokud existují soubory v této kategorii
            if (($categoryStats['files_count'] ?? 0) > 0) {
                $stmt = $this->mainPdo->prepare("
                    INSERT INTO media_category_stats (media_id, category_id, files_count, total_size)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([
                    $mediaId,
                    $category['id'],
                    $categoryStats['files_count'] ?? 0,
                    $categoryStats['total_size'] ?? 0
                ]);
            }
        }
        
        // Statistika pro soubory, které nemají kategorizovanou příponu
        $stmt = $this->mainPdo->prepare("
            SELECT COUNT(f.id) as files_count, SUM(f.file_size) as total_size
            FROM $filesTable f
            LEFT JOIN file_extension fe ON LOWER(f.extension) = LOWER(fe.name)
            WHERE f.media_id = ? AND fe.id IS NULL
        ");
        $stmt->execute([$mediaId]);
        $uncategorizedStats = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (($uncategorizedStats['files_count'] ?? 0) > 0) {
            // Najít kategorii "Other"
            $stmt = $this->mainPdo->prepare("SELECT id FROM file_category WHERE name = 'Other'");
            $stmt->execute();
            $otherCategoryId = $stmt->fetchColumn();
            
            if ($otherCategoryId) {
                $stmt = $this->mainPdo->prepare("
                    INSERT INTO media_category_stats (media_id, category_id, files_count, total_size)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([
                    $mediaId,
                    $otherCategoryId,
                    $uncategorizedStats['files_count'] ?? 0,
                    $uncategorizedStats['total_size'] ?? 0
                ]);
            }
        }
        
        // Vypočítat statistiky podle přípon
        $io->note('Calculating statistics by file extension...');
        
        $stmt = $this->mainPdo->prepare("
            SELECT extension, COUNT(*) as files_count, SUM(file_size) as total_size
            FROM $filesTable
            WHERE media_id = ?
            GROUP BY extension
            HAVING files_count > 0
        ");
        $stmt->execute([$mediaId]);
        $extensionStats = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        foreach ($extensionStats as $extStat) {
            $stmt = $this->mainPdo->prepare("
                INSERT INTO media_extension_stats (media_id, extension, files_count, total_size)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                $mediaId,
                $extStat['extension'] ?? '',
                $extStat['files_count'] ?? 0,
                $extStat['total_size'] ?? 0
            ]);
        }
        
        $io->success('Media statistics have been calculated and stored successfully');
    }

    /**
     * Odstraní existující statistiky pro médium
     */
    private function removeExistingStatistics(int $mediaId, SymfonyStyle $io): void
    {
        $io->note('Removing existing statistics...');
        
        // Odstranit statistiky podle přípon
        $stmt = $this->mainPdo->prepare("DELETE FROM media_extension_stats WHERE media_id = ?");
        $stmt->execute([$mediaId]);
        
        // Odstranit statistiky podle kategorií
        $stmt = $this->mainPdo->prepare("DELETE FROM media_category_stats WHERE media_id = ?");
        $stmt->execute([$mediaId]);
        
        // Odstranit celkové statistiky
        $stmt = $this->mainPdo->prepare("DELETE FROM media_stats WHERE media_id = ?");
        $stmt->execute([$mediaId]);
    }

}
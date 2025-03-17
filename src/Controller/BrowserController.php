<?php
// src/Controller/BrowserController.php

namespace App\Controller;

use App\Entity\Media;
use App\Entity\FileCategory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/browser', name: 'app_browser')]
class BrowserController extends BaseController
{
    // Počet souborů na stránku
    private const PAGE_SIZE = 100;

    #[Route('/', name: '')]
    public function browserHome(Request $request): Response
    {
        // Nastavit vyšší limit paměti pro náročné operace
        ini_set('memory_limit', '512M');
        
        // Načíst všechna média pro levé menu
        $mediaList = $this->entityManager->getRepository(Media::class)->findAll();
        
        // Načíst kategorie souborů pro filtrování
        $fileCategories = $this->entityManager->getRepository(FileCategory::class)->findAll();
        
        // Získat seznam médií z query parametrů
        $mediaIds = $request->query->all('media') ?: [];
        
        if (!empty($mediaIds)) {
            return $this->redirectToRoute('app_browser_browse', ['media' => $mediaIds]);
        }
        
        return $this->render('browser/index.html.twig', [
            'media_list' => $mediaList,
            'file_categories' => $fileCategories,
            'files' => [],
            'selected_media' => [],
            'count' => 0,
            'current_page' => 1,
            'total_pages' => 1
        ]);
    }

    #[Route('/media/{id}', name: '_media')]
    public function showMedia(int $id, Request $request): Response
    {
        // Nastavit vyšší limit paměti pro náročné operace
        ini_set('memory_limit', '512M');
        
        // Načíst všechna média pro levé menu
        $mediaList = $this->entityManager->getRepository(Media::class)->findAll();
        
        // Načíst kategorie souborů pro filtrování
        $fileCategories = $this->entityManager->getRepository(FileCategory::class)->findAll();
        
        // Najít médium podle ID
        $media = $this->entityManager->getRepository(Media::class)->find($id);
        
        if (!$media) {
            throw $this->createNotFoundException('Médium nebylo nalezeno');
        }
        
        // Získat parametry filtru a zobrazení
        $options = [
            'viewMode' => $request->query->get('view', 'flat'),
            'directoryId' => $request->query->getInt('directory', 0),
            'page' => max(1, $request->query->getInt('page', 1)),
            'pageSize' => $request->query->getInt('pageSize', self::PAGE_SIZE),
            'filterType' => $request->query->get('filterType', ''),
            'filterValue' => $request->query->get('filterValue', ''),
            'search' => $request->query->get('search', ''),
            'sortBy' => $request->query->get('sortBy', 'original_filename'),
            'sortDir' => $request->query->get('sortDir', 'asc')
        ];
        
        // Získat soubory a adresáře
        $result = $this->getFilesAndDirectories($media, $options);
        
        return $this->render('browser/index.html.twig', [
            'media_list' => $mediaList,
            'file_categories' => $fileCategories,
            'files' => $result['files'],
            'directories' => $result['directories'],
            'selected_media' => [$media],
            'count' => $result['totalFiles'],
            'current_page' => $options['page'],
            'total_pages' => ceil($result['totalFiles'] / $options['pageSize']),
            'page_size' => $options['pageSize'],
            'current_directory_id' => $options['directoryId'],
            'directory_path' => $result['path'],
            'filter_type' => $options['filterType'],
            'filter_value' => $options['filterValue'],
            'search' => $options['search'],
            'sort_by' => $options['sortBy'],
            'sort_dir' => $options['sortDir'],
            'view_mode' => $options['viewMode']
        ]);
    }



    #[Route('/browse', name: '_browse')]
    public function browse(Request $request): Response
    {
        // Nastavit vyšší limit paměti pro náročné operace
        ini_set('memory_limit', '512M');
        
        // Načíst všechna média pro levé menu
        $mediaList = $this->entityManager->getRepository(Media::class)->findAll();
        
        // Načíst kategorie souborů pro filtrování
        $fileCategories = $this->entityManager->getRepository(FileCategory::class)->findAll();
        
        // Získat vybraná média z požadavku
        $mediaIds = $request->query->all('media') ?: [];
        
        // Pokud nejsou vybrána žádná média, zobrazit prázdnou tabulku
        if (empty($mediaIds)) {
            return $this->render('browser/index.html.twig', [
                'media_list' => $mediaList,
                'file_categories' => $fileCategories,
                'files' => [],
                'directories' => [],
                'selected_media' => [],
                'count' => 0,
                'current_page' => 1,
                'total_pages' => 1,
                'page_size' => self::PAGE_SIZE,
                'filter_type' => '',
                'filter_value' => '',
                'search' => '',
                'sort_by' => 'original_filename',
                'sort_dir' => 'asc',
                'view_mode' => 'flat'
            ]);
        }
        
        // Získat vybraná média
        $selectedMedia = $this->entityManager->getRepository(Media::class)
            ->findBy(['id' => $mediaIds]);
        
        // Pokud je vybráno pouze jedno médium, přesměrovat na detail média
        if (count($selectedMedia) === 1) {
            return $this->redirectToRoute('app_browser_media', [
                'id' => $selectedMedia[0]->getId(),
                'view' => $request->query->get('view', 'flat'),
                'page' => $request->query->getInt('page', 1),
                'pageSize' => $request->query->getInt('pageSize', self::PAGE_SIZE),
                'filterType' => $request->query->get('filterType', ''),
                'filterValue' => $request->query->get('filterValue', ''),
                'search' => $request->query->get('search', ''),
                'sortBy' => $request->query->get('sortBy', 'original_filename'),
                'sortDir' => $request->query->get('sortDir', 'asc')
            ]);
        }
        
        // Získat parametry filtru a zobrazení
        $options = [
            'viewMode' => 'flat', // Pro více médií vždy plochý režim
            'page' => max(1, $request->query->getInt('page', 1)),
            'pageSize' => $request->query->getInt('pageSize', self::PAGE_SIZE),
            'filterType' => $request->query->get('filterType', ''),
            'filterValue' => $request->query->get('filterValue', ''),
            'search' => $request->query->get('search', ''),
            'sortBy' => $request->query->get('sortBy', 'original_filename'),
            'sortDir' => $request->query->get('sortDir', 'asc')
        ];
        
        // Získat soubory a adresáře
        $result = $this->getFilesAndDirectories($selectedMedia, $options);
        
        return $this->render('browser/index.html.twig', [
            'media_list' => $mediaList,
            'file_categories' => $fileCategories,
            'files' => $result['files'],
            'directories' => [], // Pro více médií nejsou adresáře relevantní
            'selected_media' => $selectedMedia,
            'count' => $result['totalFiles'],
            'current_page' => $options['page'],
            'total_pages' => ceil($result['totalFiles'] / $options['pageSize']),
            'page_size' => $options['pageSize'],
            'filter_type' => $options['filterType'],
            'filter_value' => $options['filterValue'],
            'search' => $options['search'],
            'sort_by' => $options['sortBy'],
            'sort_dir' => $options['sortDir'],
            'view_mode' => 'flat'
        ]);
    }

    
    /**
     * API endpoint pro získání detailů souboru
     */
    #[Route('/api/file/{mediaId}/{fileId}', name: '_api_file_details')]
    public function getFileDetails(int $mediaId, int $fileId): Response
    {
        $media = $this->entityManager->getRepository(Media::class)->find($mediaId);
        
        if (!$media) {
            return new JsonResponse(['error' => 'Médium nebylo nalezeno'], 404);
        }
        
        $identifier = strtolower($media->getIdentifier());
        $filesTable = 'files_' . $identifier;
        
        try {
            $connection = $this->entityManager->getConnection();
            
            // Kontrola existence tabulky
            $tableExists = $connection->executeQuery(
                "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=:name",
                ['name' => $filesTable]
            )->fetchOne();
            
            if (!$tableExists) {
                return new JsonResponse(['error' => 'Tabulka souborů neexistuje'], 404);
            }
            
            // Získat soubor
            $stmt = $connection->prepare("SELECT * FROM $filesTable WHERE id = :id");
            $stmt->bindValue('id', $fileId);
            $file = $stmt->executeQuery()->fetchAssociative();
            
            if (!$file) {
                return new JsonResponse(['error' => 'Soubor nebyl nalezen'], 404);
            }
            
            // Získat metadata souboru (pokud existují)
            try {
                $metaData = $connection->executeQuery(
                    "SELECT mt.name, mt.label, mv.value 
                     FROM meta_values mv
                     JOIN meta_types mt ON mv.meta_type_id = mt.id
                     WHERE mv.file_hash = :hash",
                    ['hash' => $file['content_hash']]
                )->fetchAllAssociative();
            } catch (\Exception $e) {
                $metaData = [];
            }
            
            // Formátovat velikost souboru
            $file['formatted_size'] = $this->formatSize($file['file_size']);
            
            // Přidat metadata k souboru
            $file['metadata'] = $metaData;
            
            // Zjistit, zda soubor existuje fyzicky
            $file['exists'] = file_exists($file['full_path']);
            
            return new JsonResponse($file);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Chyba při získávání detailů souboru: ' . $e->getMessage()], 500);
        }
    }

    /**
     * API endpoint pro resetování filtrů
     */
    #[Route('/reset-filters', name: '_reset_filters')]
    public function resetFilters(Request $request): Response
    {
        // Získáme pouze ID médií z původního požadavku
        $mediaIds = $request->query->all('media');
        
        if (count($mediaIds) === 1) {
            // Pokud je vybráno pouze jedno médium
            return $this->redirectToRoute('app_browser_media', ['id' => $mediaIds[0]]);
        }
        
        // Jinak přesměrujeme na browse pouze s ID médií
        return $this->redirectToRoute('app_browser_browse', ['media' => $mediaIds]);
    }




    /**
     * Univerzální metoda pro získání souborů a adresářů
     * 
     * @param array|Media $mediaSource Jedno médium nebo pole médií
     * @param array $options Parametry pro filtrování a stránkování
     *        - string viewMode: 'flat' nebo 'hierarchical'
     *        - int directoryId: ID adresáře pro hierarchický režim
     *        - int page: číslo stránky
     *        - int pageSize: počet položek na stránku
     *        - string filterType: typ filtru ('extension', 'category' atd.)
     *        - string filterValue: hodnota filtru
     *        - string search: vyhledávací dotaz
     *        - string sortBy: sloupec pro řazení
     *        - string sortDir: směr řazení ('asc' nebo 'desc')
     * @return array Asociativní pole s klíči 'files', 'directories', 'totalFiles', 'path'
     */
    private function getFilesAndDirectories($mediaSource, array $options = []): array
    {
        // Výchozí hodnoty parametrů
        $defaults = [
            'viewMode' => 'flat',
            'directoryId' => 0,
            'page' => 1,
            'pageSize' => 100,
            'filterType' => '',
            'filterValue' => '',
            'search' => '',
            'sortBy' => 'original_filename',
            'sortDir' => 'asc'
        ];
        
        // Sloučení výchozích hodnot a zadaných parametrů
        $options = array_merge($defaults, $options);
        
        // Kontrola a příprava mediaSources (buď pole médií nebo jedno médium)
        $mediaList = is_array($mediaSource) ? $mediaSource : [$mediaSource];
        
        // Kontrola zda jde o objekty Media
        $mediaList = array_filter($mediaList, function($item) {
            return $item instanceof Media;
        });
        
        if (empty($mediaList)) {
            return [
                'files' => [],
                'directories' => [],
                'totalFiles' => 0,
                'path' => []
            ];
        }
        
        // Validace parametrů
        $options['page'] = max(1, (int)$options['page']);
        $options['pageSize'] = in_array((int)$options['pageSize'], [50, 100, 200, 500]) ? (int)$options['pageSize'] : 100;
        $options['sortDir'] = in_array($options['sortDir'], ['asc', 'desc']) ? $options['sortDir'] : 'asc';
        
        // Povolené sloupce pro řazení
        $allowedSortColumns = [
            'original_filename', 'extension', 'file_size', 
            'file_modified_at', 'directory_path', 'media_identifier'
        ];
        
        if (!in_array($options['sortBy'], $allowedSortColumns)) {
            $options['sortBy'] = 'original_filename';
        }
        
        // Inicializace výstupních proměnných
        $files = [];
        $directories = [];
        $totalFiles = 0;
        $path = [];
        
        // Získat spojení k databázi
        $connection = $this->entityManager->getConnection();
        
        // Hierarchický režim s jedním médiem - získáme adresáře a cestu
        if ($options['viewMode'] === 'hierarchical' && count($mediaList) === 1) {
            $media = reset($mediaList);
            $identifier = strtolower($media->getIdentifier());
            $dirsTable = 'directories_' . $identifier;
            
            try {
                // Kontrola existence tabulky adresářů
                $tableExists = $connection->executeQuery(
                    "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=:name",
                    ['name' => $dirsTable]
                )->fetchOne();
                
                if ($tableExists) {
                    // Získat adresáře z aktuálního adresáře
                    $directories = $this->fetchDirectories($connection, $dirsTable, $options['directoryId']);
                    
                    // Získat cestu k aktuálnímu adresáři
                    if ($options['directoryId'] > 0) {
                        $path = $this->fetchDirectoryPath($connection, $dirsTable, $options['directoryId']);
                    }
                }
            } catch (\Exception $e) {
                // Ignorovat chyby
            }
        }
        
        // Získat soubory z médií
        if (count($mediaList) === 1 && $options['viewMode'] === 'hierarchical') {
            // Jeden médium, hierarchický režim - pouze soubory z aktuálního adresáře
            $media = reset($mediaList);
            $result = $this->fetchFilesFromSingleDirectory(
                $connection, 
                $media, 
                $options['directoryId'], 
                $options
            );
            
            $files = $result['data'];
            $totalFiles = $result['total'];
        } else {
            // Více médií nebo plochý režim - všechny soubory
            $result = $this->fetchFilesFromMultipleMedias(
                $connection, 
                $mediaList, 
                $options
            );
            
            $files = $result['data'];
            $totalFiles = $result['total'];
        }
        
        return [
            'files' => $files,
            'directories' => $directories,
            'totalFiles' => $totalFiles,
            'path' => $path
        ];
    }

    /**
     * Pomocná metoda pro získání adresářů z tabulky
     */
    private function fetchDirectories($connection, string $dirsTable, int $parentDirectoryId): array
    {
        try {
            // Sestavení SQL dotazu
            $sql = "SELECT * FROM $dirsTable WHERE ";
            
            if ($parentDirectoryId === 0) {
                $sql .= "parent_id IS NULL OR parent_id = 0";
                $params = [];
            } else {
                $sql .= "parent_id = :parent_id";
                $params = ['parent_id' => $parentDirectoryId];
            }
            
            $sql .= " ORDER BY name ASC";
            
            // Získat adresáře
            $stmt = $connection->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            
            return $stmt->executeQuery()->fetchAllAssociative();
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Pomocná metoda pro získání cesty k adresáři
     */
    private function fetchDirectoryPath($connection, string $dirsTable, int $directoryId): array
    {
        try {
            $path = [];
            $currentDirId = $directoryId;
            
            while ($currentDirId != 0) {
                $stmt = $connection->prepare("SELECT id, parent_id, name FROM $dirsTable WHERE id = :id");
                $stmt->bindValue('id', $currentDirId);
                $directory = $stmt->executeQuery()->fetchAssociative();
                
                if (!$directory) {
                    break;
                }
                
                array_unshift($path, $directory);
                $currentDirId = $directory['parent_id'] ?? 0;
            }
            
            return $path;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Pomocná metoda pro získání souborů z jednoho adresáře
     */
    private function fetchFilesFromSingleDirectory(
        $connection, 
        Media $media, 
        int $directoryId, 
        array $options
    ): array {
        $identifier = strtolower($media->getIdentifier());
        $filesTable = 'files_' . $identifier;
        $dirsTable = 'directories_' . $identifier;
        
        try {
            // Kontrola existence tabulek
            $fileTableExists = $connection->executeQuery(
                "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=:name",
                ['name' => $filesTable]
            )->fetchOne();
            
            $dirTableExists = $connection->executeQuery(
                "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=:name",
                ['name' => $dirsTable]
            )->fetchOne();
            
            if (!$fileTableExists || !$dirTableExists) {
                return ['data' => [], 'total' => 0];
            }
            
            // Sestavení SQL dotazu - sjednocení souborů a adresářů pro hierarchický režim
            $sql = "SELECT 
                    f.id, 
                    f.original_filename, 
                    f.full_path, 
                    f.content_hash, 
                    f.extension,
                    f.file_size,
                    f.file_modified_at,
                    d.path as directory_path,
                    d.name as directory_name,
                    '$identifier' as media_identifier,
                    {$media->getId()} as media_id
                FROM $filesTable f
                JOIN $dirsTable d ON f.directory_id = d.id
                WHERE f.directory_id = :directory_id";
            
            $countSql = "SELECT COUNT(*) FROM $filesTable f WHERE f.directory_id = :directory_id";
            $params = ['directory_id' => $directoryId];
            $types = ['directory_id' => \PDO::PARAM_INT];
            
            // Přidání filtrů a vyhledávání
            list($sql, $countSql, $params, $types) = $this->addFiltersToQuery(
                $sql, $countSql, $params, $types, $options, $connection
            );
            
            // Získání celkového počtu záznamů
            $stmt = $connection->prepare($countSql);
            foreach ($params as $key => $value) {
                $paramType = $types[$key] ?? null;
                if ($paramType !== null) {
                    $stmt->bindValue($key, $value, $paramType);
                } else {
                    $stmt->bindValue($key, $value);
                }
            }
            $totalFiles = $stmt->executeQuery()->fetchOne();
            
            // Přidání řazení
            $sql .= " ORDER BY {$options['sortBy']} {$options['sortDir']}";
            
            // Přidání stránkování
            $sql .= " LIMIT {$options['pageSize']} OFFSET " . (($options['page'] - 1) * $options['pageSize']);
            
            // dump($sql);
            // Získání souborů
            $stmt = $connection->prepare($sql);
            foreach ($params as $key => $value) {
                $paramType = $types[$key] ?? null;
                if ($paramType !== null) {
                    $stmt->bindValue($key, $value, $paramType);
                } else {
                    $stmt->bindValue($key, $value);
                }
            }
            $files = $stmt->executeQuery()->fetchAllAssociative();
            
            return [
                'data' => $files,
                'total' => (int)$totalFiles
            ];
        } catch (\Exception $e) {
            return ['data' => [], 'total' => 0];
        }
    }

    /**
     * Pomocná metoda pro získání souborů z více médií nebo plochý výpis
     */
    private function fetchFilesFromMultipleMedias(
        $connection, 
        array $mediaList, 
        array $options
    ): array {
        $queries = [];
        $countResults = [];
        
        // Sestavit dotazy pro každé médium
        foreach ($mediaList as $media) {
            $identifier = strtolower($media->getIdentifier());
            $filesTable = 'files_' . $identifier;
            $dirsTable = 'directories_' . $identifier;
            
            try {
                // ... kontroly tabulek ...
                
                // Sestavení SQL dotazu
                $sql = "SELECT 
                        f.id, 
                        f.original_filename, 
                        f.full_path, 
                        f.content_hash, 
                        f.extension,
                        f.file_size,
                        f.file_modified_at,
                        d.path as directory_path,
                        d.name as directory_name,
                        '$identifier' as media_identifier,
                        {$media->getId()} as media_id
                    FROM $filesTable f
                    JOIN $dirsTable d ON f.directory_id = d.id";
                
                $countSql = "SELECT COUNT(*) FROM $filesTable f JOIN $dirsTable d ON f.directory_id = d.id";
                $params = [];
                $types = [];
                
                // Přidání WHERE klauzule pro directory_id v hierarchickém režimu
                if ($options['viewMode'] === 'hierarchical' && $options['directoryId'] > 0) {
                    $sql .= " WHERE f.directory_id = :directory_id";
                    $countSql .= " WHERE f.directory_id = :directory_id";
                    $params['directory_id'] = $options['directoryId'];
                    $types['directory_id'] = \PDO::PARAM_INT;
                }
                
                if (!empty($options['search'])) {
                    $searchTerm = $connection->quote('%' . $options['search'] . '%');
                    $searchClause = "f.original_filename LIKE $searchTerm OR f.full_path LIKE $searchTerm OR d.path LIKE $searchTerm";
                    
                    if (empty($where)) {
                        $sql .= " WHERE ($searchClause)";
                    } else {
                        $sql .= " AND ($searchClause)";
                    }
                }

                // Přidání filtrů a vyhledávání
                list($sql, $countSql, $params, $types) = $this->addFiltersToQuery(
                    $sql, $countSql, $params, $types, $options, $connection
                );
                
                // Získání celkového počtu záznamů pro toto médium
                $stmt = $connection->prepare($countSql);
                foreach ($params as $key => $value) {
                    $paramType = $types[$key] ?? null;
                    if ($paramType !== null) {
                        $stmt->bindValue($key, $value, $paramType);
                    } else {
                        $stmt->bindValue($key, $value);
                    }
                }
                $count = (int)$stmt->executeQuery()->fetchOne();
                $countResults[] = $count;
                
                // Přidání SQL dotazu do pole dotazů
                $queries[] = "$sql";
            } catch (\Exception $e) {
                // Ignorovat chyby
                continue;
            }
        }
        
        // Pokud nemáme žádné dotazy, vrátit prázdné pole
        if (empty($queries)) {
            return ['data' => [], 'total' => 0];
        }
        
        // Spojit všechny dotazy pomocí UNION ALL
        $unionQuery = implode(" UNION ALL ", $queries);
        
        try {
            // Celkový počet záznamů je součet počtů ze všech dotazů
            $totalFiles = array_sum($countResults);
            
            // Přidat řazení a stránkování
            $finalQuery = "SELECT * FROM ($unionQuery) AS combined_results ORDER BY {$options['sortBy']} {$options['sortDir']} LIMIT {$options['pageSize']} OFFSET " . (($options['page'] - 1) * $options['pageSize']);
            
            // Získání souborů
            $files = $connection->executeQuery($finalQuery)->fetchAllAssociative();
            
            return [
                'data' => $files,
                'total' => $totalFiles
            ];
        } catch (\Exception $e) {
            return ['data' => [], 'total' => 0];
        }
    }

    /**
     * Pomocná metoda pro přidání filtrů do SQL dotazu
     */
    private function addFiltersToQuery(
        string $sql, 
        string $countSql, 
        array $params, 
        array $types, 
        array $options,
        $connection
    ): array {
        $whereClauses = [];
        
        // Existující WHERE klauzule
        $hasWhere = stripos($sql, ' WHERE ') !== false;
        
        // Filtr podle přípony
        if (!empty($options['filterType']) && $options['filterType'] === 'extension' && !empty($options['filterValue'])) {
            $whereClauses[] = "f.extension = :extension";
            $params['extension'] = $options['filterValue'];
            $types['extension'] = \PDO::PARAM_STR;
        }
        
        // Filtr podle kategorie
        if (!empty($options['filterType']) && $options['filterType'] === 'category' && !empty($options['filterValue'])) {
            $categoryId = (int)$options['filterValue'];
            
            // Ověření, že kategorie existuje
            $category = $this->entityManager->getRepository(FileCategory::class)->find($categoryId);
            
            if ($category) {
                // Použití poddotazu místo vypisování všech přípon
                $whereClauses[] = "f.extension IN (SELECT e.name FROM file_extension e WHERE e.category_id = ".$connection->quote($categoryId).")";
            }
        }


        
        // Přidání WHERE klauzulí, pokud existují
        if (!empty($whereClauses)) {
            if ($hasWhere) {
                $sql .= " AND " . implode(" AND ", $whereClauses);
                $countSql .= " AND " . implode(" AND ", $whereClauses);
            } else {
                $sql .= " WHERE " . implode(" AND ", $whereClauses);
                $countSql .= " WHERE " . implode(" AND ", $whereClauses);
            }
        }
        

        
        return [$sql, $countSql, $params, $types];
    }

}
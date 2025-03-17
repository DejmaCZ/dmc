<?php

namespace App\Controller;

use App\Entity\FileCategory;
use App\Entity\Media;
use App\Entity\MediaType;
use App\Entity\MetaType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class BrowserController extends BaseController
{
    // Odstraníme konstruktor a inicializaci session, protože je již v BaseControlleru
    
    #[Route('/browser', name: 'app_browser')]
    public function browserMedia(Request $request): Response
    {
        // Získat ID médií z požadavku (GET parameter)
        $mediaIds = $request->query->all('media');
        
        // Pokud není vybráno žádné médium, přesměrovat na domovskou stránku
        if (empty($mediaIds)) {
            return $this->redirectToRoute('app_home');
        }
        
        // Získat média
        $mediaList = $this->entityManager->getRepository(Media::class)->findBy(['id' => $mediaIds]);
        
        if (count($mediaList) == 0) {
            throw $this->createNotFoundException('Médium nebylo nalezeno');
        }
        
        // Seznam všech médií pro menu
        $allMedia = $this->entityManager->getRepository(Media::class)->findAll();
        
        // Získat kategorie souborů pro filtrování
        $fileCategories = $this->entityManager->getRepository(FileCategory::class)->findAll();
        
        // Zpracování filtrů a vyhledávání
        $filters = $this->processFilters($request);
        
        // Pokud je vybráno pouze jedno médium, zobrazíme průzkumník pro toto médium
        if (count($mediaList) == 1) {
            $media = $mediaList[0];
            $directoryId = $request->query->getInt('directory', 0);
            
            // Uložit aktuální adresář do session
            if ($directoryId > 0) {
                $this->session->set('last_directory_' . $media->getId(), $directoryId);
            }
            
            // Získat soubory a adresáře pro média s aplikovanými filtry
            $files = $this->getFilesForMedia($media, $directoryId, $filters);
            $directories = $this->getDirectoriesForMedia($media, $directoryId, $filters);
            
            // Získat cestu k aktuálnímu adresáři pro navigaci
            $directoryPath = $this->getDirectoryPath($media, $directoryId);
            
            // Získat metadata types pro rozšířené filtry
            // $metaTypes = $this->entityManager->getRepository(MetaType::class)->findAll();
            $metaTypes = $this->getMetaTypes();
            
            return $this->render('browser/index.html.twig', [
                'media' => $media,
                'files' => $files['data'],
                'total_files' => $files['total'],
                'directories' => $directories['data'],
                'total_directories' => $directories['total'],
                'media_list' => $allMedia,
                'current_directory_id' => $directoryId,
                'directory_path' => $directoryPath,
                'filters' => $filters,
                'file_categories' => $fileCategories,
                'meta_types' => $metaTypes,
                'pagination' => $this->getPaginationData($files['total'], $filters['page'], $filters['limit']),
                'active_filters' => $this->getActiveFilters($filters)
            ]);
        } 
        // Jinak zobrazíme soubory ze všech vybraných médií
        else {
            $allFiles = [];
            $totalFiles = 0;
            
            foreach ($mediaList as $media) {
                $files = $this->getFilesForMedia($media, 0, $filters, false);
                $totalFiles += $files['total'];
                
                // Přidáme k souborům informaci o médiu pro zobrazení
                foreach ($files['data'] as &$file) {
                    $file['media_identifier'] = $media->getIdentifier();
                    $file['media_description'] = $media->getDescription();
                    $file['media_id'] = $media->getId();
                }
                
                $allFiles = array_merge($allFiles, $files['data']);
            }
            
            // Seřadíme soubory podle zvoleného kritéria
            $allFiles = $this->sortFiles($allFiles, $filters['sort']);
            
            // Stránkování - omezíme počet položek
            $paginatedFiles = array_slice($allFiles, ($filters['page'] - 1) * $filters['limit'], $filters['limit']);
            
            // Získat metadata types pro rozšířené filtry
            // $metaTypes = $this->entityManager->getRepository(MetaType::class)->findAll();
            $metaTypes = $this->getMetaTypes();
            
            return $this->render('browser/multi.html.twig', [
                'media_list' => $allMedia,
                'selected_media' => $mediaList,
                'files' => $paginatedFiles,
                'total_files' => $totalFiles,
                'filters' => $filters,
                'file_categories' => $fileCategories,
                'meta_types' => $metaTypes,
                'pagination' => $this->getPaginationData($totalFiles, $filters['page'], $filters['limit']),
                'active_filters' => $this->getActiveFilters($filters)
            ]);
        }
    }
    
    // Zbytek metod zůstává stejný
    
    /**
     * Zpracuje vstupní filtry z požadavku
     */
    private function processFilters(Request $request): array
    {
        $filters = [
            'search' => $request->query->get('search', ''),
            'category' => $request->query->get('category', ''),
            'extension' => $request->query->get('extension', ''),
            'sort' => $request->query->get('sort', 'name_asc'),
            'page' => $request->query->getInt('page', 1),
            'limit' => $request->query->getInt('limit', 50),
            'meta' => []
        ];
        
        // Zpracování meta filtrů
        $metaFilters = $request->query->all('meta');
        if (!empty($metaFilters) && is_array($metaFilters)) {
            foreach ($metaFilters as $typeId => $value) {
                if (!empty($value)) {
                    $filters['meta'][$typeId] = $value;
                }
            }
        }
        
        // Zpracování min/max velikosti souboru
        $filters['size_min'] = $request->query->get('size_min');
        $filters['size_max'] = $request->query->get('size_max');
        
        // Zpracování filtru data
        $filters['date_from'] = $request->query->get('date_from');
        $filters['date_to'] = $request->query->get('date_to');
        
        return $filters;
    }
    
    /**
     * Vrátí seznam aktivních filtrů pro UI
     */
    private function getActiveFilters(array $filters): array
    {
        $activeFilters = [];
        
        if (!empty($filters['search'])) {
            $activeFilters['search'] = $filters['search'];
        }
        
        if (!empty($filters['category'])) {
            $category = $this->entityManager->getRepository(FileCategory::class)->find($filters['category']);
            if ($category) {
                $activeFilters['category'] = [
                    'id' => $category->getId(),
                    'name' => $category->getName()
                ];
            }
        }
        
        if (!empty($filters['extension'])) {
            $activeFilters['extension'] = $filters['extension'];
        }
        
        if (!empty($filters['size_min']) || !empty($filters['size_max'])) {
            $activeFilters['size'] = [
                'min' => $filters['size_min'],
                'max' => $filters['size_max']
            ];
        }
        
        if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
            $activeFilters['date'] = [
                'from' => $filters['date_from'],
                'to' => $filters['date_to']
            ];
        }
        
        // Meta filtry
        if (!empty($filters['meta'])) {
            $activeFilters['meta'] = [];
            foreach ($filters['meta'] as $typeId => $value) {
                $metaType = $this->entityManager->getRepository(MetaType::class)->find($typeId);
                if ($metaType) {
                    $activeFilters['meta'][] = [
                        'id' => $typeId,
                        'name' => $metaType->getName(),
                        'value' => $value
                    ];
                }
            }
        }
        
        return $activeFilters;
    }
    
    /**
     * Vrátí data pro stránkování
     */
    private function getPaginationData(int $total, int $currentPage, int $limit): array
    {
        $totalPages = ceil($total / $limit);
        
        return [
            'total' => $total,
            'total_pages' => $totalPages,
            'current_page' => $currentPage,
            'limit' => $limit,
            'has_previous' => $currentPage > 1,
            'has_next' => $currentPage < $totalPages,
            'previous_page' => max(1, $currentPage - 1),
            'next_page' => min($totalPages, $currentPage + 1)
        ];
    }
    
    /**
     * Přidáme i samostatnou routu pro jedno médium, která využívá stejnou metodu
     */
    #[Route('/browser/{mediaId}', name: 'app_browser_media')]
    public function browserSingleMedia(int $mediaId, Request $request): Response
    {
        // Přidáme ID média do query parametrů
        $query = $request->query->all();
        $query['media'] = [$mediaId];
        
        // Vytvoříme nový request s upravenými parametry
        $request = $request->duplicate($query);
        
        return $this->browserMedia($request);
    }
    
    /**
     * Vrátí soubory pro médium s aplikovanými filtry
     */
    private function getFilesForMedia(Media $media, int $directoryId = 0, array $filters = [], bool $paginate = true): array
    {
        $identifier = strtolower($media->getIdentifier());
        $filesTable = 'files_' . $identifier;
        
        $connection = $this->entityManager->getConnection();
        
        // Kontrola, zda tabulka existuje
        $tableExists = $connection->executeQuery(
            "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=:name",
            ['name' => $filesTable]
        )->fetchOne();
        
        if (!$tableExists) {
            return ['data' => [], 'total' => 0];
        }
        
        // Základní SELECT
        $sql = "SELECT f.* FROM $filesTable f";
        $countSql = "SELECT COUNT(*) FROM $filesTable f";
        $params = [];
        $types = [];
        
        // JOIN pro meta filtry
        if (!empty($filters['meta'])) {
            $metaJoins = [];
            $metaCounter = 0;
            
            foreach ($filters['meta'] as $typeId => $value) {
                $metaCounter++;
                $alias = "m$metaCounter";
                
                $sql .= " INNER JOIN meta_values $alias ON f.content_hash = $alias.file_hash AND $alias.meta_type_id = :meta_type_id$metaCounter";
                $countSql .= " INNER JOIN meta_values $alias ON f.content_hash = $alias.file_hash AND $alias.meta_type_id = :meta_type_id$metaCounter";
                
                $params["meta_type_id$metaCounter"] = $typeId;
                $types["meta_type_id$metaCounter"] = \PDO::PARAM_INT;
                
                if (!empty($value)) {
                    $sql .= " AND $alias.value LIKE :meta_value$metaCounter";
                    $countSql .= " AND $alias.value LIKE :meta_value$metaCounter";
                    
                    $params["meta_value$metaCounter"] = '%' . $value . '%';
                    $types["meta_value$metaCounter"] = \PDO::PARAM_STR;
                }
            }
        }
        
        // JOIN pro kategorii souborů
        if (!empty($filters['category'])) {
            $sql .= " LEFT JOIN file_extension fe ON LOWER(f.extension) = LOWER(fe.name)";
            $countSql .= " LEFT JOIN file_extension fe ON LOWER(f.extension) = LOWER(fe.name)";
        }
        
        // WHERE podmínky
        $whereClauses = ["f.directory_id = :directory_id"];
        $params['directory_id'] = $directoryId;
        $types['directory_id'] = \PDO::PARAM_INT;
        
        // Filtr podle názvu souboru
        if (!empty($filters['search'])) {
            $whereClauses[] = "(f.original_filename LIKE :search OR f.full_path LIKE :search)";
            $params['search'] = '%' . $filters['search'] . '%';
            $types['search'] = \PDO::PARAM_STR;
        }
        
        // Filtr podle kategorie souborů
        if (!empty($filters['category'])) {
            $whereClauses[] = "fe.category_id = :category_id";
            $params['category_id'] = $filters['category'];
            $types['category_id'] = \PDO::PARAM_INT;
        }
        
        // Filtr podle přípony
        if (!empty($filters['extension'])) {
            $whereClauses[] = "LOWER(f.extension) = LOWER(:extension)";
            $params['extension'] = $filters['extension'];
            $types['extension'] = \PDO::PARAM_STR;
        }
        
        // Filtr podle velikosti souboru
        if (!empty($filters['size_min'])) {
            $whereClauses[] = "f.file_size >= :size_min";
            $params['size_min'] = $this->convertSizeToBytes($filters['size_min']);
            $types['size_min'] = \PDO::PARAM_INT;
        }
        
        if (!empty($filters['size_max'])) {
            $whereClauses[] = "f.file_size <= :size_max";
            $params['size_max'] = $this->convertSizeToBytes($filters['size_max']);
            $types['size_max'] = \PDO::PARAM_INT;
        }
        
        // Filtr podle data modifikace
        if (!empty($filters['date_from'])) {
            $whereClauses[] = "f.file_modified_at >= :date_from";
            $params['date_from'] = $filters['date_from'] . ' 00:00:00';
            $types['date_from'] = \PDO::PARAM_STR;
        }
        
        if (!empty($filters['date_to'])) {
            $whereClauses[] = "f.file_modified_at <= :date_to";
            $params['date_to'] = $filters['date_to'] . ' 23:59:59';
            $types['date_to'] = \PDO::PARAM_STR;
        }
        
        // Sestavení WHERE klauzule
        if (!empty($whereClauses)) {
            $sql .= " WHERE " . implode(" AND ", $whereClauses);
            $countSql .= " WHERE " . implode(" AND ", $whereClauses);
        }
        
        // Nejprve získáme celkový počet záznamů
        $totalFiles = $connection->executeQuery($countSql, $params, $types)->fetchOne();
        
        // Přidání řazení
        $sql .= $this->getSortClause($filters['sort']);
        
        // Přidání limitu pro stránkování (pouze pro single médium)
        if ($paginate) {
            $sql .= " LIMIT " . (($filters['page'] - 1) * $filters['limit']) . ", " . $filters['limit'];
        }
        
        // Získat soubory
        $files = $connection->executeQuery($sql, $params, $types)->fetchAllAssociative();
        
        return [
            'data' => $files,
            'total' => (int)$totalFiles
        ];
    }
    
    /**
     * Vrátí adresáře pro médium s aplikovanými filtry pro název
     */
    private function getDirectoriesForMedia(Media $media, int $parentDirectoryId = 0, array $filters = []): array
    {
        $identifier = strtolower($media->getIdentifier());
        $directoriesTable = 'directories_' . $identifier;
        
        $connection = $this->entityManager->getConnection();
        
        // Kontrola, zda tabulka existuje
        $tableExists = $connection->executeQuery(
            "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=:name",
            ['name' => $directoriesTable]
        )->fetchOne();
        
        if (!$tableExists) {
            return ['data' => [], 'total' => 0];
        }
        
        // Vytvoření SQL dotazu s filtry
        $sql = "SELECT * FROM $directoriesTable WHERE ";
        $countSql = "SELECT COUNT(*) FROM $directoriesTable WHERE ";
        
        if ($parentDirectoryId == 0) {
            $sql .= "parent_id IS NULL OR parent_id = 0";
            $countSql .= "parent_id IS NULL OR parent_id = 0";
            $params = [];
            $types = [];
        } else {
            $sql .= "parent_id = :parent_id";
            $countSql .= "parent_id = :parent_id";
            $params = ['parent_id' => $parentDirectoryId];
            $types = ['parent_id' => \PDO::PARAM_INT];
        }
        
        // Přidání vyhledávání pro adresáře
        if (!empty($filters['search'])) {
            $sql .= " AND (name LIKE :search OR path LIKE :search)";
            $countSql .= " AND (name LIKE :search OR path LIKE :search)";
            $params['search'] = '%' . $filters['search'] . '%';
            $types['search'] = \PDO::PARAM_STR;
        }
        
        // Nejprve získáme celkový počet záznamů
        $totalDirectories = $connection->executeQuery($countSql, $params, $types)->fetchOne();
        
        // Přidání řazení pro adresáře (jen podle názvu)
        $direction = (strpos($filters['sort'], 'desc') !== false) ? 'DESC' : 'ASC';
        $sql .= " ORDER BY name $direction";
        
        // Přidání limitu pro stránkování
        $sql .= " LIMIT " . (($filters['page'] - 1) * $filters['limit']) . ", " . $filters['limit'];
        
        // Získat adresáře
        $directories = $connection->executeQuery($sql, $params, $types)->fetchAllAssociative();
        
        return [
            'data' => $directories,
            'total' => (int)$totalDirectories
        ];
    }
    
    /**
     * Vrátí SQL klauzuli pro řazení podle zvoleného kritéria
     */
    private function getSortClause(string $sort): string
    {
        switch ($sort) {
            case 'name_asc':
                return " ORDER BY f.original_filename ASC";
            case 'name_desc':
                return " ORDER BY f.original_filename DESC";
            case 'size_asc':
                return " ORDER BY f.file_size ASC";
            case 'size_desc':
                return " ORDER BY f.file_size DESC";
            case 'date_asc':
                return " ORDER BY f.file_modified_at ASC";
            case 'date_desc':
                return " ORDER BY f.file_modified_at DESC";
            case 'extension_asc':
                return " ORDER BY f.extension ASC, f.original_filename ASC";
            case 'extension_desc':
                return " ORDER BY f.extension DESC, f.original_filename ASC";
            case 'path_asc':
                return " ORDER BY f.full_path ASC";
            case 'path_desc':
                return " ORDER BY f.full_path DESC";
            default:
                return " ORDER BY f.original_filename ASC";
        }
    }
    
    /**
     * Seřadí pole souborů podle zvoleného kritéria (pro multi média)
     */
    private function sortFiles(array $files, string $sort): array
    {
        switch ($sort) {
            case 'name_asc':
                usort($files, function($a, $b) {
                    return strcmp($a['original_filename'], $b['original_filename']);
                });
                break;
            case 'name_desc':
                usort($files, function($a, $b) {
                    return strcmp($b['original_filename'], $a['original_filename']);
                });
                break;
            case 'size_asc':
                usort($files, function($a, $b) {
                    return $a['file_size'] - $b['file_size'];
                });
                break;
            case 'size_desc':
                usort($files, function($a, $b) {
                    return $b['file_size'] - $a['file_size'];
                });
                break;
            case 'date_asc':
                usort($files, function($a, $b) {
                    return strtotime($a['file_modified_at']) - strtotime($b['file_modified_at']);
                });
                break;
            case 'date_desc':
                usort($files, function($a, $b) {
                    return strtotime($b['file_modified_at']) - strtotime($a['file_modified_at']);
                });
                break;
            case 'extension_asc':
                usort($files, function($a, $b) {
                    $cmp = strcmp($a['extension'] ?? '', $b['extension'] ?? '');
                    return $cmp === 0 ? strcmp($a['original_filename'], $b['original_filename']) : $cmp;
                });
                break;
            case 'extension_desc':
                usort($files, function($a, $b) {
                    $cmp = strcmp($b['extension'] ?? '', $a['extension'] ?? '');
                    return $cmp === 0 ? strcmp($a['original_filename'], $b['original_filename']) : $cmp;
                });
                break;
            case 'path_asc':
                usort($files, function($a, $b) {
                    return strcmp($a['full_path'], $b['full_path']);
                });
                break;
            case 'path_desc':
                usort($files, function($a, $b) {
                    return strcmp($b['full_path'], $a['full_path']);
                });
                break;
        }
        
        return $files;
    }
    

    


    /**
     * API endpoint pro uložení aktuálního pohledu
     */
    #[Route('/browser/save-view', name: 'app_browser_save_view', methods: ['POST'])]
    public function saveView(Request $request): Response
    {
        $viewName = $request->request->get('view_name');
        $currentUrl = $request->request->get('current_url');
        
        if (empty($viewName) || empty($currentUrl)) {
            return $this->json(['success' => false, 'error' => 'Chybí název pohledu nebo URL']);
        }
        
        // Získat aktuální uložené pohledy ze session
        $savedViews = $this->session->get('saved_views', []);
        
        // Přidat nový pohled
        $savedViews[$viewName] = $currentUrl;
        
        // Uložit zpět do session
        $this->session->set('saved_views', $savedViews);
        
        return $this->json(['success' => true]);
    }
    
    /**
     * API endpoint pro získání uložených pohledů
     */
    #[Route('/api/browser/saved-views', name: 'app_api_browser_saved_views')]
    public function getSavedViews(): Response
    {
        // Získat uložené pohledy ze session
        $savedViews = $this->session->get('saved_views', []);
        
        return $this->json(['views' => $savedViews]);
    }
    
    /**
     * API endpoint pro smazání uloženého pohledu
     */
    #[Route('/api/browser/delete-view/{name}', name: 'app_api_browser_delete_view')]
    public function deleteView(string $name): Response
    {
        // Získat aktuální uložené pohledy ze session
        $savedViews = $this->session->get('saved_views', []);
        
        // Odstranit pohled
        if (isset($savedViews[$name])) {
            unset($savedViews[$name]);
            
            // Uložit zpět do session
            $this->session->set('saved_views', $savedViews);
            
            return $this->json(['success' => true]);
        }
        
        return $this->json(['success' => false, 'error' => 'Pohled nebyl nalezen']);
    }
    
    /**
     * API endpoint pro autocomplete (našeptávač) názvů souborů
     */
    #[Route('/api/browser/autocomplete', name: 'app_api_browser_autocomplete')]
    public function autocomplete(Request $request): Response
    {
        $term = $request->query->get('term', '');
        $mediaIds = $request->query->all('media');
        
        if (empty($term) || empty($mediaIds) || strlen($term) < 2) {
            return $this->json(['suggestions' => []]);
        }
        
        $suggestions = [];
        $mediaList = $this->entityManager->getRepository(Media::class)->findBy(['id' => $mediaIds]);
        
        foreach ($mediaList as $media) {
            $identifier = strtolower($media->getIdentifier());
            $filesTable = 'files_' . $identifier;
            
            $connection = $this->entityManager->getConnection();
            
            // Kontrola, zda tabulka existuje
            $tableExists = $connection->executeQuery(
                "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=:name",
                ['name' => $filesTable]
            )->fetchOne();
            
            if (!$tableExists) {
                continue;
            }
            
            // Hledat v názvech souborů
            $fileResults = $connection->executeQuery(
                "SELECT DISTINCT original_filename FROM $filesTable 
                WHERE original_filename LIKE :term 
                ORDER BY original_filename ASC LIMIT 10",
                ['term' => '%' . $term . '%']
            )->fetchAllAssociative();
            
            foreach ($fileResults as $result) {
                $suggestions[] = [
                    'value' => $result['original_filename'],
                    'label' => $result['original_filename'],
                    'source' => 'filename',
                    'media' => $media->getIdentifier()
                ];
            }
            
            // Hledat v metadatech - ošetřeno proti chybám
            try {
                $metaResults = $connection->executeQuery(
                    "SELECT DISTINCT mt.name, mv.value 
                    FROM meta_values mv
                    JOIN meta_types mt ON mv.meta_type_id = mt.id
                    JOIN $filesTable f ON f.content_hash = mv.file_hash
                    WHERE mv.value LIKE :term
                    ORDER BY mv.value ASC LIMIT 10",
                    ['term' => '%' . $term . '%']
                )->fetchAllAssociative();
                
                foreach ($metaResults as $result) {
                    $suggestions[] = [
                        'value' => $result['value'],
                        'label' => $result['value'] . ' (' . $result['name'] . ')',
                        'source' => 'metadata',
                        'type' => $result['name'],
                        'media' => $media->getIdentifier()
                    ];
                }
            } catch (\Exception $e) {
                // Ignorujeme chyby, které mohou nastat pokud tabulky neexistují
            }
        }
        
        // Odstranit duplicity a omezit výsledky
        $suggestions = array_slice(array_unique($suggestions, SORT_REGULAR), 0, 15);
        
        return $this->json(['suggestions' => $suggestions]);
    }
    
    /**
     * Získá cestu ke složce (breadcrumb)
     */
    private function getDirectoryPath(Media $media, int $directoryId): array
    {
        if ($directoryId == 0) {
            return [];
        }
        
        $identifier = strtolower($media->getIdentifier());
        $directoriesTable = 'directories_' . $identifier;
        
        $connection = $this->entityManager->getConnection();
        
        // Kontrola, zda tabulka existuje
        $tableExists = $connection->executeQuery(
            "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=:name",
            ['name' => $directoriesTable]
        )->fetchOne();
        
        if (!$tableExists) {
            return [];
        }
        
        $path = [];
        $currentDirId = $directoryId;
        
        while ($currentDirId != 0) {
            $directory = $connection->executeQuery(
                "SELECT id, parent_id, name FROM $directoriesTable WHERE id = :id",
                ['id' => $currentDirId]
            )->fetchAssociative();
            
            if (!$directory) {
                break;
            }
            
            array_unshift($path, $directory);
            $currentDirId = $directory['parent_id'] ?? 0;
        }
        
        return $path;
    }
    
    /**
     * Převede velikost v lidsky čitelném formátu na byty
     * Např. "1.5 MB" => 1572864
     */
    private function convertSizeToBytes(string $size): int
    {
        $size = strtolower(trim($size));
        $bytes = (float) $size;
        
        if (preg_match('/([kmgt]?b)$/', $size, $matches)) {
            switch ($matches[1]) {
                case 'kb':
                    $bytes *= 1024;
                    break;
                case 'mb':
                    $bytes *= 1024 * 1024;
                    break;
                case 'gb':
                    $bytes *= 1024 * 1024 * 1024;
                    break;
                case 'tb':
                    $bytes *= 1024 * 1024 * 1024 * 1024;
                    break;
            }
        }
        
        return (int) $bytes;
    }
    
    /**
     * API endpoint pro získání detailů souboru
     */
    #[Route('/api/file/{mediaId}/{fileId}', name: 'app_api_file_details')]
    public function getFileDetails(int $mediaId, int $fileId): Response
    {
        $media = $this->entityManager->getRepository(Media::class)->find($mediaId);
        
        if (!$media) {
            return $this->json(['error' => 'Médium nebylo nalezeno'], 404);
        }
        
        $identifier = strtolower($media->getIdentifier());
        $filesTable = 'files_' . $identifier;
        
        $connection = $this->entityManager->getConnection();
        
        // Kontrola, zda tabulka existuje
        $tableExists = $connection->executeQuery(
            "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=:name",
            ['name' => $filesTable]
        )->fetchOne();
        
        if (!$tableExists) {
            return $this->json(['error' => 'Tabulka souborů neexistuje'], 404);
        }
        
        // Získat soubor
        $file = $connection->executeQuery(
            "SELECT * FROM $filesTable WHERE id = :id",
            ['id' => $fileId]
        )->fetchAssociative();
        
        if (!$file) {
            return $this->json(['error' => 'Soubor nebyl nalezen'], 404);
        }
        
        // Získat metadata souboru
        try {
            $metaData = $connection->executeQuery(
                "SELECT mt.name, mt.label, mv.value 
                 FROM meta_values mv
                 JOIN meta_types mt ON mv.meta_type_id = mt.id
                 WHERE mv.file_hash = :hash",
                ['hash' => $file['content_hash']]
            )->fetchAllAssociative();
        } catch (\Exception $e) {
            // Pokud tabulky neexistují nebo nastane jiná chyba, nastavíme prázdné pole
            $metaData = [];
        }
        
        // Formátovat velikost souboru
        $file['formatted_size'] = $this->formatSize($file['file_size']);
        
        // Přidat metadata k souboru
        $file['metadata'] = $metaData;
        
        // Zjistit, zda soubor existuje fyzicky
        $file['exists'] = file_exists($file['full_path']);
        
        return $this->json($file);
    }
    
    /**
     * API endpoint pro resetování filtrů
     */
    #[Route('/browser/reset-filters', name: 'app_browser_reset_filters')]
    public function resetFilters(Request $request): Response
    {
        // Získáme pouze ID médií z původního požadavku
        $mediaIds = $request->query->all('media');
        
        // Přesměrujeme na browser pouze s ID médií
        $redirectUrl = $this->generateUrl('app_browser', ['media' => $mediaIds]);
        
        return $this->redirect($redirectUrl);
    }

    private function getMetaTypes(): array
    {
        try {
            return $this->entityManager->getRepository(MetaType::class)->findAll();
        } catch (\Exception $e) {
            // Pokud entita nebo tabulka neexistuje, vrátíme prázdné pole
            return [];
        }
    }

}
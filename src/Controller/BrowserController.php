<?php

namespace App\Controller;

use App\Entity\Media;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class BrowserController extends BaseController
{
    #[Route('/browser', name: 'app_browser')]
    public function browserMedia(Request $request): Response
    {
        // Získat ID médií z požadavku
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
        
        // Zpracování filtrů a vyhledávání
        $search = $request->query->get('search', '');
        $filter = $request->query->get('filter', '');
        $sort = $request->query->get('sort', 'name_asc');
        $page = $request->query->getInt('page', 1);
        $limit = 100; // Počet položek na stránku
        
        // Pokud je vybráno pouze jedno médium, zobrazíme průzkumník pro toto médium
        if (count($mediaList) == 1) {
            $media = $mediaList[0];
            $directoryId = $request->query->getInt('directory', 0);
            
            $files = $this->getFilesForMedia($media, $directoryId, $search, $filter, $sort, $page, $limit);
            $directories = $this->getDirectoriesForMedia($media, $directoryId, $search, $sort, $page, $limit);
            
            // Získat cestu k aktuálnímu adresáři pro navigaci
            $directoryPath = $this->getDirectoryPath($media, $directoryId);
            
            return $this->render('browser/index.html.twig', [
                'media' => $media,
                'files' => $files,
                'directories' => $directories,
//                'media_list' => $allMedia,
                'current_directory_id' => $directoryId,
                'directory_path' => $directoryPath
            ]);
        } 
        // Jinak zobrazíme soubory ze všech vybraných médií
        else {
            $allFiles = [];
            foreach ($mediaList as $media) {
                $files = $this->getFilesForMedia($media, 0, $search, $filter, $sort, $page, $limit);
                
                // Přidáme k souborům informaci o médiu pro zobrazení
                foreach ($files as &$file) {
                    $file['media_identifier'] = $media->getIdentifier();
                    $file['media_description'] = $media->getDescription();
                }
                
                $allFiles = array_merge($allFiles, $files);
            }
            
            // Seřadíme soubory podle zvoleného kritéria
            if ($sort == 'name_asc') {
                usort($allFiles, function($a, $b) {
                    return strcmp($a['original_filename'], $b['original_filename']);
                });
            } elseif ($sort == 'name_desc') {
                usort($allFiles, function($a, $b) {
                    return strcmp($b['original_filename'], $a['original_filename']);
                });
            } elseif ($sort == 'size_asc') {
                usort($allFiles, function($a, $b) {
                    return $a['file_size'] - $b['file_size'];
                });
            } elseif ($sort == 'size_desc') {
                usort($allFiles, function($a, $b) {
                    return $b['file_size'] - $a['file_size'];
                });
            } elseif ($sort == 'date_asc') {
                usort($allFiles, function($a, $b) {
                    return strtotime($a['file_modified_at']) - strtotime($b['file_modified_at']);
                });
            } elseif ($sort == 'date_desc') {
                usort($allFiles, function($a, $b) {
                    return strtotime($b['file_modified_at']) - strtotime($a['file_modified_at']);
                });
            }
            
            // Stránkování - omezíme počet položek
            $allFiles = array_slice($allFiles, ($page - 1) * $limit, $limit);
            
            return $this->render('browser/multi.html.twig', [
                'media_list' => $allMedia,
                'selected_media' => $mediaList,
                'files' => $allFiles
            ]);
        }
    }
    
    // Přidáme i samostatnou routu pro jedno médium, která využívá stejnou metodu
    #[Route('/browser/{mediaId}', name: 'app_browser_media')]
    public function browserSingleMedia(int $mediaId, Request $request): Response
    {
        $request->query->set('media', [$mediaId]);
        return $this->browserMedia($request);
    }
    
    private function getFilesForMedia(Media $media, int $directoryId = 0, string $search = '', string $filter = '', string $sort = 'name_asc', int $page = 1, int $limit = 100): array
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
            return [];
        }
        
        // Vytvoření SQL dotazu s filtry
        $sql = "SELECT * FROM $filesTable WHERE directory_id = :directory_id";
        $params = ['directory_id' => $directoryId];
        
        // Přidání vyhledávání
        if (!empty($search)) {
            $sql .= " AND (original_filename LIKE :search OR full_path LIKE :search)";
            $params['search'] = '%' . $search . '%';
        }
        
        // Přidání filtru podle typu souboru
        if (!empty($filter)) {
            if ($filter == 'document') {
                $sql .= " AND extension IN ('pdf', 'doc', 'docx', 'txt', 'rtf', 'odt', 'xls', 'xlsx', 'csv', 'ppt', 'pptx')";
            } elseif ($filter == 'image') {
                $sql .= " AND extension IN ('jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg', 'webp', 'tiff')";
            } elseif ($filter == 'video') {
                $sql .= " AND extension IN ('mp4', 'avi', 'mov', 'wmv', 'flv', 'mkv', 'webm')";
            } elseif ($filter == 'audio') {
                $sql .= " AND extension IN ('mp3', 'wav', 'ogg', 'flac', 'aac', 'wma')";
            }
        }
        
        // Přidání řazení
        if ($sort == 'name_asc') {
            $sql .= " ORDER BY original_filename ASC";
        } elseif ($sort == 'name_desc') {
            $sql .= " ORDER BY original_filename DESC";
        } elseif ($sort == 'size_asc') {
            $sql .= " ORDER BY file_size ASC";
        } elseif ($sort == 'size_desc') {
            $sql .= " ORDER BY file_size DESC";
        } elseif ($sort == 'date_asc') {
            $sql .= " ORDER BY file_modified_at ASC";
        } elseif ($sort == 'date_desc') {
            $sql .= " ORDER BY file_modified_at DESC";
        } else {
            $sql .= " ORDER BY original_filename ASC";
        }
        
        // Přidání limitu pro stránkování
        $sql .= " LIMIT " . (($page - 1) * $limit) . ", " . $limit;
        
        // Získat soubory
        $files = $connection->executeQuery($sql, $params)->fetchAllAssociative();
        
        return $files;
    }
    
    private function getDirectoriesForMedia(Media $media, int $parentDirectoryId = 0, string $search = '', string $sort = 'name_asc', int $page = 1, int $limit = 100): array
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
            return [];
        }
        
        // Vytvoření SQL dotazu s filtry
        $sql = "SELECT * FROM $directoriesTable WHERE parent_id ";
        
        if ($parentDirectoryId == 0) {
            $sql .= "IS NULL OR parent_id = 0";
            $params = [];
        } else {
            $sql .= "= :parent_id";
            $params = ['parent_id' => $parentDirectoryId];
        }
        
        // Přidání vyhledávání
        if (!empty($search)) {
            $sql .= " AND (name LIKE :search OR path LIKE :search)";
            $params['search'] = '%' . $search . '%';
        }
        
        // Přidání řazení
        if ($sort == 'name_asc' || $sort == 'name_desc') {
            $direction = $sort == 'name_asc' ? 'ASC' : 'DESC';
            $sql .= " ORDER BY name $direction";
        } else {
            $sql .= " ORDER BY name ASC";
        }
        
        // Přidání limitu pro stránkování
        $sql .= " LIMIT " . (($page - 1) * $limit) . ", " . $limit;
        
        // Získat adresáře
        $directories = $connection->executeQuery($sql, $params)->fetchAllAssociative();
        
        return $directories;
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
        
        // Formátovat velikost souboru
        $file['formatted_size'] = $this->formatSize($file['file_size']);
        
        return $this->json($file);
    }
}
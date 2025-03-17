<?php
// src/Controller/MediaController.php
namespace App\Controller;

use App\Entity\Media;
use App\Entity\MediaType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/media', name: 'app_media_')]
class MediaController extends BaseController
{
//     private $entityManager;

//     public function __construct(EntityManagerInterface $entityManager)
//     {
//         $this->entityManager = $entityManager;
//     }

    #[Route('/', name: 'list')]
    public function list(): Response
    {
        // Získáme všechna média včetně statistik jedním SQL dotazem
        $connection = $this->entityManager->getConnection();
        $sql = "
            SELECT 
                m.*,
                mt.name as media_type_name,
                mt.icon as media_type_icon,
                ms.files_count,
                ms.total_size,
                ms.directories_count
            FROM 
                media AS m
            LEFT JOIN 
                media_type AS mt ON m.media_type_id = mt.id
            LEFT JOIN 
                media_stats AS ms ON ms.media_id = m.id
        ";
        
        $stmt = $connection->prepare($sql);
        $result = $stmt->executeQuery()->fetchAllAssociative();
        
        // Transformujeme výsledek do entity objektů
        $mediaList = [];
        $mediaTypes = $this->entityManager->getRepository(MediaType::class)->findAll();
        
        foreach ($result as $row) {
            $media = $this->entityManager->getRepository(Media::class)->find($row['id']);
            $media->filesCount = $row['files_count'] ?? 0;
            $media->totalSize = $this->formatSize($row['total_size'] ?? 0);
            $media->directoriesCount = $row['directories_count'] ?? 0;
            
            $mediaList[] = $media;
        }
        
        return $this->render('media/list.html.twig', [
            'mediaList' => $mediaList,
            'mediaTypes' => $mediaTypes
        ]);
    }


    // #[Route('/', name: 'list')]
    // public function list(): Response
    // {
    //     // Použijeme QueryBuilder pro vytvoření JOIN dotazu
    //     $queryBuilder = $this->entityManager->createQueryBuilder();
    //     $queryBuilder
    //         ->select('m', 'mt', 'ms')
    //         ->from(Media::class, 'm')
    //         ->leftJoin(MediaType::class, 'mt', 'WITH', 'm.mediaType = mt.id')
    //         ->leftJoin('media_stats', 'ms', 'WITH', 'ms.media_id = m.id');
        
    //     $result = $queryBuilder->getQuery()->getResult();
        
    //     // Transformujeme výsledek do potřebných proměnných
    //     $mediaList = [];
    //     foreach ($result as $row) {
    //         $media = $row[0]; // Media entita
            
    //         // Přidáme statistiky jako vlastnosti entity
    //         if (isset($row['ms_files_count'])) {
    //             $media->filesCount = $row['ms_files_count'];
    //             $media->totalSize = $this->formatSize($row['ms_total_size'] ?? 0);
    //             $media->directoriesCount = $row['ms_directories_count'] ?? 0;
    //         } else {
    //             $media->filesCount = 0;
    //             $media->totalSize = $this->formatSize(0);
    //             $media->directoriesCount = 0;
    //         }
            
    //         $mediaList[] = $media;
    //     }
        
    //     // Získáme typy médií pro případný filtr/formulář
    //     $mediaTypes = $this->entityManager->getRepository(MediaType::class)->findAll();
        
    //     return $this->render('media/list.html.twig', [
    //         'mediaList' => $mediaList,
    //         'mediaTypes' => $mediaTypes
    //     ]);
    // }
    #[Route('/new', name: 'new')]
    public function new(): Response
    {
        // Získat seznam typů médií pro dropdown
        $mediaTypes = $this->entityManager->getRepository(MediaType::class)->findAll();
        
        return $this->render('media/edit.html.twig', [
            'mediaTypes' => $mediaTypes
        ]);
    }


    #[Route('/create', name: 'create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $description = $request->request->get('description');
        $typeId = $request->request->get('type');
        $path = $request->request->get('path');
        
        // Najdeme MediaType podle ID místo podle názvu
        $mediaType = $this->entityManager->getRepository(MediaType::class)->find($typeId);
            
        if (!$mediaType) {
            $this->addFlash('error', 'Neplatný typ média');
            return $this->redirectToRoute('app_media_list');
        }
        
        $identifier = $this->generateIdentifier();
        while ($this->identifierExists($identifier)) {
            $identifier = $this->generateIdentifier();
        }
        
        $media = new Media();
        $media->setIdentifier($identifier);
        $media->setDescription($description);
        $media->setPath(trim($path)); // Ořezání mezer kolem cesty
        $media->setMediaType($mediaType);
        $media->setCreatedAt(new \DateTimeImmutable());
        
        $this->entityManager->persist($media);
        $this->entityManager->flush();
        
        $this->addFlash('success', 'Médium úspěšně vytvořeno. Identifikátor: ' . $identifier);
        
        return $this->redirectToRoute('app_media_list');
    }

    private function generateIdentifier(): string
    {
        $letters = chr(rand(65, 90)) . chr(rand(65, 90)) . chr(rand(65, 90));
        $numbers = str_pad(rand(0, 999), 3, '0', STR_PAD_LEFT);
        return $letters . $numbers;
    }

    private function identifierExists(string $identifier): bool
    {
        return $this->entityManager->getRepository(Media::class)
            ->count(['identifier' => $identifier]) > 0;
    }

    // private function getFilesCount(Media $media): int
    // {
    //     $identifier = strtolower($media->getIdentifier());
    //     $filesTable = 'files_' . $identifier;
        
    //     try {
    //         $pdo = new \PDO('sqlite:' . $this->getParameter('kernel.project_dir') . '/var/data.db');
    //         $query = "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=:table";
    //         $stmt = $pdo->prepare($query);
    //         $stmt->execute(['table' => $filesTable]);
            
    //         if ($stmt->fetchColumn() === 0) {
    //             return 0;
    //         }
            
    //         $query = "SELECT COUNT(*) FROM $filesTable";
    //         return (int)$pdo->query($query)->fetchColumn();
    //     } catch (\Exception $e) {
    //         return 0;
    //     }
    // }

    // private function getTotalSize(Media $media): int
    // {
    //     $identifier = strtolower($media->getIdentifier());
    //     $filesTable = 'files_' . $identifier;
        
    //     try {
    //         $pdo = new \PDO('sqlite:' . $this->getParameter('kernel.project_dir') . '/var/data.db');
    //         $query = "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=:table";
    //         $stmt = $pdo->prepare($query);
    //         $stmt->execute(['table' => $filesTable]);
            
    //         if ($stmt->fetchColumn() === 0) {
    //             return 0;
    //         }
            
    //         $query = "SELECT SUM(file_size) FROM $filesTable";
    //         return (int)$pdo->query($query)->fetchColumn() ?: 0;
    //     } catch (\Exception $e) {
    //         return 0;
    //     }
    // }

    // src/Controller/MediaController.php

    #[Route('/edit/{id}', name: 'edit')]
    public function edit(Request $request, int $id): Response
    {
        $media = $this->entityManager->getRepository(Media::class)->find($id);

        if (!$media) {
            $this->addFlash('error', 'Médium nebylo nalezeno');
            return $this->redirectToRoute('app_media_list');
        }
        
        if ($request->isMethod('POST')) {
            $description = $request->request->get('description');
            $path = $request->request->get('path');
            $typeId = $request->request->get('type');
            
            $mediaType = $this->entityManager->getRepository(MediaType::class)->find($typeId);
            
            if (!$mediaType) {
                $this->addFlash('error', 'Neplatný typ média');
                return $this->redirectToRoute('app_media_edit', ['id' => $id]);
            }
            
            $media->setDescription($description);
            $media->setPath(trim($path)); // Odstraní mezery kolem cesty
            $media->setMediaType($mediaType);
            
            $this->entityManager->flush();
            
            $this->addFlash('success', 'Médium bylo úspěšně aktualizováno');
            return $this->redirectToRoute('app_media_list');
        }
        
        $mediaTypes = $this->entityManager->getRepository(MediaType::class)->findAll();
        
        return $this->render('media/edit.html.twig', [
            'media' => $media,
            'mediaTypes' => $mediaTypes
        ]);
    }

    
    #[Route('/delete/{id}', name: 'delete')]
    public function delete(Request $request, int $id): Response
    {

        $media = $this->entityManager->getRepository(Media::class)->find($id);

        
        if (!$media) {
            $this->addFlash('error', 'Médium nebylo nalezeno');
            return $this->redirectToRoute('app_media_list');
        }
        
        // Získat identifikátor pro nalezení tabulek
        $identifier = strtolower($media->getIdentifier());
        $filesTable = 'files_' . $identifier;
        $dirsTable = 'directories_' . $identifier;
        
        try {
            // Připojení k databázi
            $pdo = new \PDO('sqlite:' . $this->getParameter('kernel.project_dir') . '/var/data.db');
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            
            // Kontrola existence tabulek
            $tableCheck = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND (name='$filesTable' OR name='$dirsTable')")
                ->fetchAll(\PDO::FETCH_COLUMN);
            
            // Smazání tabulek pokud existují
            $pdo->beginTransaction();
            
            if (in_array($filesTable, $tableCheck)) {
                $pdo->exec("DROP TABLE $filesTable");
            }
            
            if (in_array($dirsTable, $tableCheck)) {
                $pdo->exec("DROP TABLE $dirsTable");
            }
            
            $pdo->commit();
            
            // Smazání média z databáze
            $this->entityManager->remove($media);
            $this->entityManager->flush();
            
            $this->addFlash('success', 'Médium bylo úspěšně smazáno včetně souborových záznamů');
            
        } catch (\Exception $e) {
            $this->addFlash('error', 'Chyba při mazání média: ' . $e->getMessage());
        }
        
        return $this->redirectToRoute('app_media_list');
    }

    // private function formatSize(int $bytes): string
    // {
    //     $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    //     $size = $bytes;
    //     $unitIndex = 0;
        
    //     while ($size >= 1024 && $unitIndex < count($units) - 1) {
    //         $size /= 1024;
    //         $unitIndex++;
    //     }
        
    //     return round($size, 2) . ' ' . $units[$unitIndex];
    // }
}
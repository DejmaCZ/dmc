<?php

namespace App\Controller;

use App\Entity\Media;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class BaseController extends AbstractController
{
    protected $entityManager;
    protected $requestStack;

    public function __construct(EntityManagerInterface $entityManager, RequestStack $requestStack)
    {
        $this->entityManager = $entityManager;
        $this->requestStack = $requestStack;
    }


    protected function getSession()
    {
        return $this->requestStack->getSession();
    }
    
    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        // Získat seznam médií pro levé menu
        $mediaList = $this->entityManager->getRepository(Media::class)->findAll();
        
        // Získat základní statistiky
        $mediaCount = count($mediaList);
        
        // Celkový počet souborů a velikost
        $filesCount = 0;
        $totalSize = 0;
        
        foreach ($mediaList as $media) {
            $identifier = strtolower($media->getIdentifier());
            $filesTable = 'files_' . $identifier;
            
            try {
                $connection = $this->entityManager->getConnection();
                
                // Kontrola, zda tabulka existuje
                $tableExists = $connection->executeQuery(
                    "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=:name",
                    ['name' => $filesTable]
                )->fetchOne();
                
                if ($tableExists) {
                    // Počet souborů
                    $count = $connection->executeQuery("SELECT COUNT(*) FROM $filesTable")->fetchOne();
                    $filesCount += (int)$count;
                    
                    // Celková velikost
                    $size = $connection->executeQuery("SELECT SUM(file_size) FROM $filesTable")->fetchOne();
                    $totalSize += (int)$size;
                }
            } catch (\Exception $e) {
                // Ignorovat chyby, pokračovat s dalším médiem
            }
        }
        
        // Formátování velikosti
        $formattedSize = $this->formatSize($totalSize);
        
        // Získat poslední naskenovaná média
        $recentMedia = $this->entityManager->getRepository(Media::class)
            ->findBy([], ['lastScannedAt' => 'DESC'], 5);
        
        // Doplnit počty souborů pro zobrazená média
        foreach ($recentMedia as $media) {
            $identifier = strtolower($media->getIdentifier());
            $filesTable = 'files_' . $identifier;
            
            try {
                $connection = $this->entityManager->getConnection();
                
                $tableExists = $connection->executeQuery(
                    "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=:name",
                    ['name' => $filesTable]
                )->fetchOne();
                
                if ($tableExists) {
                    $count = $connection->executeQuery("SELECT COUNT(*) FROM $filesTable")->fetchOne();
                    $media->filesCount = (int)$count;
                } else {
                    $media->filesCount = 0;
                }
            } catch (\Exception $e) {
                $media->filesCount = 0;
            }
        }

        return $this->render('base/index.html.twig', [
            'media_list' => $mediaList,
            'mediaCount' => $mediaCount,
            'filesCount' => $filesCount,
            'totalSize' => $formattedSize,
            'recentMedia' => $recentMedia
        ]);
    }
    
    protected function formatSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = $bytes;
        $unitIndex = 0;
        
        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }
        
        return round($size, 2) . ' ' . $units[$unitIndex];
    }

    // Přepsání původní metody render z AbstractController
    public function render(string $view, array $parameters = [], Response $response = null): Response
    {
        // Přidat společné proměnné
        $commonParams = [
            'media_list' => $this->getMediaList(),
            // další společné proměnné...
        ];
        
        // Sloučit s parametry specifickými pro konkrétní view
        $parameters = array_merge($commonParams, $parameters);
        
        // Zavolat původní implementaci render
        return parent::render($view, $parameters, $response);
    }

    private function getMediaList()
    {
        return $this->entityManager->getRepository(Media::class)->findAll();
    }
}
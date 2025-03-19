<?php

namespace App\Controller;

use App\Service\DashboardService;
use App\Service\BrowserSessionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DashboardController extends BaseController
{
    private $dashboardService;

    public function __construct(
        EntityManagerInterface $entityManager, 
        BrowserSessionService $sessionService,
        DashboardService $dashboardService
    ) {
        parent::__construct($entityManager, $sessionService);
        $this->dashboardService = $dashboardService;
    }

    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        // Získat základní statistiky
        $basicStats = $this->dashboardService->getBasicStats();
        
        // Získat statistiky podle typů médií
        $mediaTypeStats = $this->dashboardService->getMediaTypeStats();
        
        // Získat top 10 přípon podle počtu souborů
        $topExtensionsByCount = $this->dashboardService->getTopExtensionsByCount(10);
        
        // Získat top 10 přípon podle velikosti
        $topExtensionsBySize = $this->dashboardService->getTopExtensionsBySize(10);
        
        // Získat statistiky kategorií souborů
        $categoryStats = $this->dashboardService->getCategoryStats();
        
        // Získat poslední skenovaná média
        $recentlyScannedMedia = $this->dashboardService->getRecentlyScannedMedia(5);
        
        // Získat časovou osu přidání médií
        $mediaTimeline = $this->dashboardService->getMediaTimeline();
        
        // Předat data do šablony
        return $this->render('dashboard/index.html.twig', [
            'basic_stats' => $basicStats,
            'media_type_stats' => $mediaTypeStats,
            'top_extensions_by_count' => $topExtensionsByCount,
            'top_extensions_by_size' => $topExtensionsBySize,
            'category_stats' => $categoryStats,
            'recently_scanned_media' => $recentlyScannedMedia,
            'media_timeline' => $mediaTimeline
        ]);
    }
}
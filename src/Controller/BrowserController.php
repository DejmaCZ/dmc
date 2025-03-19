<?php
namespace App\Controller;

use App\Entity\Media;
use App\Entity\FileCategory;
use App\Form\SmartFilterType;
use App\Service\FileQueryService;
use App\Service\BrowserSessionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/browser', name: 'app_browser')]
class BrowserController extends BaseController
{
    private $fileQueryService;
    
    public function __construct(
        EntityManagerInterface $entityManager, 
        BrowserSessionService $sessionService,
        FileQueryService $fileQueryService
    ) {
        parent::__construct($entityManager, $sessionService);
        $this->fileQueryService = $fileQueryService;
    }

    #[Route('/', name: '', methods: ['GET', 'POST'])]
    public function browse(Request $request): Response
    {
        // Načíst data filtru ze session
        $filterData = $this->sessionService->getFilterData();
        
        // Načíst vybraná média
        $selectedMediaIds = $filterData['selected_media_ids'] ?? [];
        $selectedMedia = $this->entityManager->getRepository(Media::class)
            ->findBy(['id' => $selectedMediaIds]);
        
        // Pokud nejsou vybrána žádná média, zobrazit prázdnou tabulku
        if (empty($selectedMedia)) {
            return $this->renderEmptyView();
        }
        
        // Připravit parametry pro dotaz
        $options = [
            'page' => max(1, $filterData['page'] ?? 1),
            'pageSize' => $filterData['page_size'] ?? 100,
            'extension' => $filterData['filter_extension'] ?? '',
            'category' => $filterData['filter_category'] ? $filterData['filter_category']->getId() : '',
            'search' => $filterData['filter_search'] ?? '',
            'sortBy' => $filterData['sort_by'] ?? 'original_filename',
            'sortDir' => $filterData['sort_dir'] ?? 'asc'
        ];
        
        // Získat soubory pomocí FileQueryService
        $result = $this->fileQueryService->getFiles($selectedMedia, $options);
        
        // Renderovat view
        return $this->render('browser/index.html.twig', [
            'files' => $result['files'],
            'count' => $result['totalFilteredFiles'],
            'total_count' => $result['totalFiles'],
            'media_stats' => $result['mediaStats'],
            'current_page' => $options['page'],
            'total_pages' => ceil($result['totalFilteredFiles'] / $options['pageSize']),
            'page_size' => $options['pageSize'],
            'view_mode' => 'flat',
            // Další parametry budou automaticky přidány BaseControllerem
        ]);
    }

    /**
     * Renderování prázdného pohledu
     */
    private function renderEmptyView(): Response
    {
        return $this->render('browser/index.html.twig', [
            'files' => [],
            'count' => 0,
            'total_count' => 0,
            'media_stats' => [],
            'current_page' => 1,
            'total_pages' => 1,
            'page_size' => 100,
            'view_mode' => 'flat'
        ]);
    }
}
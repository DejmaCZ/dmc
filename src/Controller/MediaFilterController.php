<?php
namespace App\Controller;

use App\Entity\Media;
use App\Form\SmartFilterType;
use App\Service\BrowserSessionService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route('/media-filter', name: 'app_media_filter')]
class MediaFilterController extends AbstractController
{
    private $sessionService;

    public function __construct(BrowserSessionService $sessionService)
    {
        $this->sessionService = $sessionService;
    }

    #[Route('/', name: '', methods: ['POST'])]
    public function processFilter(Request $request): Response
    {
        // Vytvořit formulář s aktuálními daty ze session
        $filterData = $this->sessionService->getFilterData();
        
        $form = $this->createForm(SmartFilterType::class, $filterData);
        
        $form->handleRequest($request);
        
        if ($form->isSubmitted() && $form->isValid()) {
            $submittedData = $form->getData();
            
            // Explicitní zpracování selected_media_ids
            $selectedMediaIds = $submittedData['selected_media_ids'] 
                ? array_map(fn($media) => $media->getId(), $submittedData['selected_media_ids']) 
                : [];
            
            $filterData = [
                'filter_search' => $submittedData['filter_search'] ?? '',
                'filter_extension' => $submittedData['filter_extension'] ?? '',
                'filter_category' => $submittedData['filter_category'] ?? null,
                'sort_by' => $submittedData['sort_by'] ?? 'original_filename',
                'sort_dir' => $submittedData['sort_dir'] ?? 'asc',
                'page_size' => $submittedData['page_size'] ?? 100,
                'page' => $submittedData['page'] ?? 1,
                'selected_media_ids' => $selectedMediaIds
            ];
            
            $this->sessionService->saveFilterData($filterData);
            
            // Vrátit se na stránku, odkud formulář přišel
            $referer = $request->headers->get('referer');
            
            return $referer 
                ? $this->redirect($referer) 
                : $this->redirectToRoute('app_browser');
        }
        
        // Pokud formulář není validní, můžete přesměrovat zpět s chybou
        return $this->redirectToRoute('app_browser');
    }

        /**
     * Reset filtrů
     */
    #[Route('/reset-filters', name: '_reset_filters')]
    public function resetFilters(Request $request): Response
    {
        // Resetovat filtr v session
        $this->sessionService->resetFilterData();
        
        // Získat ID médií ze session
        $mediaIds = $this->sessionService->getSelectedMediaIds();
        
        $referer = $request->headers->get('referer');
            
        return $referer 
            ? $this->redirect($referer) 
            : $this->redirectToRoute('app_browser');
    }

}
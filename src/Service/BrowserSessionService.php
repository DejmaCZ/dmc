<?php

namespace App\Service;

use App\Entity\Media;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class BrowserSessionService
{
    private const DEFAULT_PAGE_SIZE = 100;
    
    private $requestStack;
    private $entityManager;

    public function __construct(
        RequestStack $requestStack, 
        EntityManagerInterface $entityManager
    ) {
        $this->requestStack = $requestStack;
        $this->entityManager = $entityManager;
    }
    
    /**
     * Get session
     */
    private function getSession()
    {
        return $this->requestStack->getSession();
    }
    
    /**
     * Get selected media IDs from session
     */
    public function getSelectedMediaIds(): array
    {
        return $this->getSession()->get('selected_media_ids', []);
    }
    
    /**
     * Save selected media IDs to session
     */
    public function saveSelectedMediaIds(array $mediaIds): void
    {
        $this->getSession()->set('selected_media_ids', $mediaIds);
    }
    
    /**
     * Get default filter data
     */
    public function getDefaultFilterData(): array
    {
        return [
            'filter_search' => '',
            'filter_extension' => '',
            'filter_category' => null, // nebo prázdný řetězec
            'sort_by' => 'original_filename',
            'sort_dir' => 'asc',
            'page_size' => self::DEFAULT_PAGE_SIZE,
            'page' => 1,
            // 'selected_media_ids' => [] // přidejte, pokud je potřeba
        ];
    }
    
    /**
     * Get filter data from session
     */
    public function getFilterData(): array
    {
        $filterData = $this->getSession()->get('filter_data', $this->getDefaultFilterData());
        
        // Pokud jsou v datech ID médií, načti je jako entity
        if (!empty($filterData['selected_media_ids'])) {
            $mediaRepository = $this->entityManager->getRepository(Media::class);
            $filterData['selected_media_ids'] = $mediaRepository->findBy(['id' => $filterData['selected_media_ids']]);
        }
        
        return $filterData;
    }
    /**
     * Save filter data to session
     */
    public function saveFilterData(array $filterData): void
    {
        $this->getSession()->set('filter_data', $filterData);
    }
    
    /**
     * Update filter data from request and save to session
     */
    public function updateFilterDataFromRequest(array $requestData): array
    {
        $filterData = $this->getFilterData();
        
        // Update filter data from request
        foreach ($this->getDefaultFilterData() as $key => $defaultValue) {
            if (isset($requestData[$key])) {
                $filterData[$key] = $requestData[$key];
            }
        }
        
        $this->saveFilterData($filterData);
        
        return $filterData;
    }

    /**
     * Reset filter data in session
     */
    public function resetFilterData(): void
    {
        $this->saveFilterData($this->getDefaultFilterData());
    }
}
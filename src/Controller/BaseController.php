<?php
namespace App\Controller;

use App\Entity\Media;
use App\Form\SmartFilterType;
use App\Service\BrowserSessionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class BaseController extends AbstractController
{
    protected $entityManager;
    protected $sessionService;

    public function __construct(
        EntityManagerInterface $entityManager, 
        BrowserSessionService $sessionService
    ) {
        $this->entityManager = $entityManager;
        $this->sessionService = $sessionService;
    }

    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        // Základní statistiky médií
        $mediaList = $this->entityManager->getRepository(Media::class)->findAll();
        $mediaCount = count($mediaList);
        
        return $this->render('base/index.html.twig', [
            'media_list' => $mediaList,
            'mediaCount' => $mediaCount
        ]);
    }

    public function render(string $view, array $parameters = [], Response $response = null): Response
    {
        // Načíst data filtru ze session
        $filterData = $this->sessionService->getFilterData();
        
        // Načíst média podle ID v session
        $selectedMediaIds = $filterData['selected_media_ids'] ?? [];
        $selectedMedia = $selectedMediaIds 
            ? $this->entityManager->getRepository(Media::class)->findBy(['id' => $selectedMediaIds]) 
            : [];
        
        // Přidání společných parametrů pro všechny pohledy
        $commonParams = [
            'media_list' => $this->getMediaList(),
            'smart_filter_form' => $this->createForm(SmartFilterType::class, 
                $filterData, 
                [
                    'method' => 'POST',
                    'selected_media_ids' => $selectedMedia
                ]
            )->createView()
        ];
        
        // Sloučení společných a specifických parametrů
        $parameters = array_merge($commonParams, $parameters);
        
        return parent::render($view, $parameters, $response);
    }

    private function getMediaList()
    {
        return $this->entityManager->getRepository(Media::class)->findAll();
    }
}
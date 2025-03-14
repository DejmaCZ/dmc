<?php

namespace App\Controller;

use App\Entity\FileCategory;
use App\Entity\FileExtension;
use App\Entity\MediaType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/settings', name: 'app_settings')]
class SettingsController extends BaseController
{
    #[Route('/', name: '')]
    public function index(): Response
    {
        return $this->render('settings/index.html.twig');
    }

    #[Route('/file-categories', name: '_file_categories')]
    public function fileCategories(): Response
    {
        // Použijeme přímo entity manager místo repozitáře
        $categories = $this->entityManager->getRepository(FileCategory::class)->findAllWithExtensions();
        
        return $this->render('settings/file_categories.html.twig', [
            'categories' => $categories
        ]);
    }

    #[Route('/file-categories/new', name: '_file_categories_new')]
    public function newFileCategory(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $name = $request->request->get('name');
            $description = $request->request->get('description');
            $icon = $request->request->get('icon');
            $extensions = $request->request->get('extensions');
            
            if (empty($name)) {
                $this->addFlash('error', 'Jméno kategorie je povinné');
                return $this->redirectToRoute('app_settings_file_categories_new');
            }
            
            // Kontrola, zda kategorie již existuje
            $existingCategory = $this->entityManager->getRepository(FileCategory::class)->findOneByName($name);
            if ($existingCategory) {
                $this->addFlash('error', 'Kategorie s tímto jménem již existuje');
                return $this->redirectToRoute('app_settings_file_categories_new');
            }
            
            // Vytvoření nové kategorie
            $category = new FileCategory();
            $category->setName($name);
            $category->setDescription($description);
            $category->setIcon($icon);
            
            // Přidání přípon
            if (!empty($extensions)) {
                $category->addExtensionsFromString($extensions);
            }
            
            $this->entityManager->persist($category);
            $this->entityManager->flush();
            
            $this->addFlash('success', 'Kategorie souborů byla úspěšně vytvořena');
            return $this->redirectToRoute('app_settings_file_categories');
        }
        
        return $this->render('settings/file_category_form.html.twig', [
            'mode' => 'new',
            'category' => null
        ]);
    }

    #[Route('/file-categories/{id}/edit', name: '_file_categories_edit')]
    public function editFileCategory(int $id, Request $request): Response
    {
        $category = $this->entityManager->getRepository(FileCategory::class)->find($id);
        
        if (!$category) {
            throw $this->createNotFoundException('Kategorie nebyla nalezena');
        }
        
        if ($request->isMethod('POST')) {
            $name = $request->request->get('name');
            $description = $request->request->get('description');
            $icon = $request->request->get('icon');
            $extensionsString = $request->request->get('extensions');
            
            if (empty($name)) {
                $this->addFlash('error', 'Jméno kategorie je povinné');
                return $this->redirectToRoute('app_settings_file_categories_edit', ['id' => $id]);
            }
            
            // Kontrola, zda jiná kategorie již nepoužívá toto jméno
            $existingCategory = $this->entityManager->getRepository(FileCategory::class)->findOneByName($name);
            if ($existingCategory && $existingCategory->getId() !== $category->getId()) {
                $this->addFlash('error', 'Kategorie s tímto jménem již existuje');
                return $this->redirectToRoute('app_settings_file_categories_edit', ['id' => $id]);
            }
            
            // Aktualizace kategorie
            $category->setName($name);
            $category->setDescription($description);
            $category->setIcon($icon);
            $category->setUpdatedAt(new \DateTimeImmutable());
            
            // Zpracování přípon - nejprve je převedeme na unikátní pole
            $extensionNames = [];
            if (!empty($extensionsString)) {
                $extensionNames = array_map('trim', explode(',', $extensionsString));
                $extensionNames = array_map('strtolower', $extensionNames); // Převod na malá písmena
                $extensionNames = array_unique($extensionNames); // Odstranění duplicit
                $extensionNames = array_filter($extensionNames); // Odstranění prázdných hodnot
            }

            // Získáme aktuální přípony kategorie
            $currentExtensions = [];
            foreach ($category->getExtensions() as $extension) {
                $currentExtensions[$extension->getName()] = $extension;
            }
            
            // Odstraníme přípony, které již nejsou v seznamu
            foreach ($currentExtensions as $name => $extension) {
                if (!in_array($name, $extensionNames)) {
                    $category->removeExtension($extension);
                    $this->entityManager->remove($extension);
                }
            }
            
            // Přidáme nové přípony
            foreach ($extensionNames as $name) {
                if (!isset($currentExtensions[$name])) {
                    // Nejprve zkontrolujeme, zda přípona už existuje v jiné kategorii
                    $existingExtension = $this->entityManager->getRepository(FileExtension::class)
                        ->findOneBy(['name' => $name]);
                    
                    if ($existingExtension) {
                        // Pokud přípona existuje, přiřadíme ji k nové kategorii
                        $category->addExtension($existingExtension);
                        $existingExtension->setCategory($category);
                    } else {
                        // Vytvoříme novou příponu
                        $extension = new FileExtension();
                        $extension->setName($name);
                        $category->addExtension($extension);
                    }
                }
            }
            
            // Uložíme změny
            $this->entityManager->persist($category);
            $this->entityManager->flush();
            
            $this->addFlash('success', 'Kategorie souborů byla úspěšně aktualizována');
            return $this->redirectToRoute('app_settings_file_categories');
        }
        
        return $this->render('settings/file_category_form.html.twig', [
            'mode' => 'edit',
            'category' => $category
        ]);
    }

    #[Route('/file-categories/{id}/delete', name: '_file_categories_delete', methods: ['POST'])]
    public function deleteFileCategory(int $id, Request $request): Response
    {
        $category = $this->entityManager->getRepository(FileCategory::class)->find($id);
        
        if (!$category) {
            throw $this->createNotFoundException('Kategorie nebyla nalezena');
        }
        
        // Odstranění kategorie
        $this->entityManager->remove($category);
        $this->entityManager->flush();
        
        $this->addFlash('success', 'Kategorie souborů byla úspěšně odstraněna');
        return $this->redirectToRoute('app_settings_file_categories');
    }
    
    // SPRÁVA TYPŮ MÉDIÍ
    
    #[Route('/media-types', name: '_media_types')]
    public function mediaTypes(): Response
    {
        $types = $this->entityManager->getRepository(MediaType::class)->findAll();
        
        return $this->render('settings/media_types.html.twig', [
            'types' => $types
        ]);
    }
    
    #[Route('/media-types/new', name: '_media_types_new')]
    public function newMediaType(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $name = $request->request->get('name');
            $description = $request->request->get('description');
            $icon = $request->request->get('icon');
            
            if (empty($name)) {
                $this->addFlash('error', 'Název typu média je povinný');
                return $this->redirectToRoute('app_settings_media_types_new');
            }
            
            // Kontrola, zda typ média již existuje
            $existingType = $this->entityManager->getRepository(MediaType::class)->findOneBy(['name' => $name]);
            if ($existingType) {
                $this->addFlash('error', 'Typ média s tímto názvem již existuje');
                return $this->redirectToRoute('app_settings_media_types_new');
            }
            
            // Vytvoření nového typu média
            $mediaType = new MediaType();
            $mediaType->setName($name);
            $mediaType->setDescription($description);
            $mediaType->setIcon($icon);
            $mediaType->setCreatedAt(new \DateTimeImmutable());
            
            $this->entityManager->persist($mediaType);
            $this->entityManager->flush();
            
            $this->addFlash('success', 'Typ média byl úspěšně vytvořen');
            return $this->redirectToRoute('app_settings_media_types');
        }
        
        return $this->render('settings/media_type_form.html.twig', [
            'mode' => 'new',
            'type' => null
        ]);
    }
    
    #[Route('/media-types/{id}/edit', name: '_media_types_edit')]
    public function editMediaType(int $id, Request $request): Response
    {
        $mediaType = $this->entityManager->getRepository(MediaType::class)->find($id);
        
        if (!$mediaType) {
            throw $this->createNotFoundException('Typ média nebyl nalezen');
        }
        
        if ($request->isMethod('POST')) {
            $name = $request->request->get('name');
            $description = $request->request->get('description');
            $icon = $request->request->get('icon');
            
            if (empty($name)) {
                $this->addFlash('error', 'Název typu média je povinný');
                return $this->redirectToRoute('app_settings_media_types_edit', ['id' => $id]);
            }
            
            // Kontrola, zda jiný typ média již nepoužívá toto jméno
            $existingType = $this->entityManager->getRepository(MediaType::class)->findOneBy(['name' => $name]);
            if ($existingType && $existingType->getId() !== $mediaType->getId()) {
                $this->addFlash('error', 'Typ média s tímto názvem již existuje');
                return $this->redirectToRoute('app_settings_media_types_edit', ['id' => $id]);
            }
            
            // Aktualizace typu média
            $mediaType->setName($name);
            $mediaType->setDescription($description);
            $mediaType->setIcon($icon);
            $mediaType->setUpdatedAt(new \DateTimeImmutable());
            
            $this->entityManager->flush();
            
            $this->addFlash('success', 'Typ média byl úspěšně aktualizován');
            return $this->redirectToRoute('app_settings_media_types');
        }
        
        return $this->render('settings/media_type_form.html.twig', [
            'mode' => 'edit',
            'type' => $mediaType
        ]);
    }
    
    #[Route('/media-types/{id}/delete', name: '_media_types_delete', methods: ['POST'])]
    public function deleteMediaType(int $id, Request $request): Response
    {
        $mediaType = $this->entityManager->getRepository(MediaType::class)->find($id);
        
        if (!$mediaType) {
            throw $this->createNotFoundException('Typ média nebyl nalezen');
        }
        
        // Kontrola, zda typ média není používán
        if (!$mediaType->getMedia()->isEmpty()) {
            $this->addFlash('error', 'Nelze smazat typ média, který je používán. Nejprve smažte nebo upravte všechna média tohoto typu.');
            return $this->redirectToRoute('app_settings_media_types');
        }
        
        // Odstranění typu média
        $this->entityManager->remove($mediaType);
        $this->entityManager->flush();
        
        $this->addFlash('success', 'Typ média byl úspěšně odstraněn');
        return $this->redirectToRoute('app_settings_media_types');
    }
}
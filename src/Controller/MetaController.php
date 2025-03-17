<?php

namespace App\Controller;

use App\Entity\MetaType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/settings/meta', name: 'app_settings_meta')]
class MetaController extends BaseController
{
    #[Route('/', name: '_types')]
    public function index(): Response
    {
        try {
            $metaTypes = $this->entityManager->getRepository(MetaType::class)->findAll();
            
            return $this->render('settings/meta_types.html.twig', [
                'meta_types' => $metaTypes,
            ]);
        } catch (\Exception $e) {
            // Pokud entita nebo tabulka neexistuje, zobrazíme informaci o chybějící tabulce
            return $this->render('settings/meta_types_missing.html.twig', [
                'error' => $e->getMessage()
            ]);
        }
    }

    #[Route('/new', name: '_type_new')]
    public function new(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            try {
                $name = $request->request->get('name');
                $label = $request->request->get('label');
                $description = $request->request->get('description');
                $dataType = $request->request->get('data_type');
                $isMultiple = $request->request->getBoolean('is_multiple');
                
                if (empty($name) || empty($label) || empty($dataType)) {
                    $this->addFlash('error', 'Všechna povinná pole musí být vyplněna');
                    return $this->redirectToRoute('app_settings_meta_type_new');
                }
                
                // Kontrola, zda typ již existuje
                $existing = $this->entityManager->getRepository(MetaType::class)->findOneByName($name);
                if ($existing) {
                    $this->addFlash('error', 'Typ metadat s tímto názvem již existuje');
                    return $this->redirectToRoute('app_settings_meta_type_new');
                }
                
                // Vytvoření nového typu metadat
                $metaType = new MetaType();
                $metaType->setName($name);
                $metaType->setLabel($label);
                $metaType->setDescription($description);
                $metaType->setDataType($dataType);
                $metaType->setIsMultiple($isMultiple);
                
                $this->entityManager->persist($metaType);
                $this->entityManager->flush();
                
                $this->addFlash('success', 'Typ metadat byl úspěšně vytvořen');
                return $this->redirectToRoute('app_settings_meta_types');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Chyba při vytváření typu metadat: ' . $e->getMessage());
                return $this->redirectToRoute('app_settings_meta_type_new');
            }
        }
        
        return $this->render('settings/meta_type_form.html.twig', [
            'mode' => 'new'
        ]);
    }

    #[Route('/{id}/edit', name: '_type_edit')]
    public function edit(int $id, Request $request): Response
    {
        try {
            $metaType = $this->entityManager->getRepository(MetaType::class)->find($id);
            
            if (!$metaType) {
                $this->addFlash('error', 'Typ metadat nebyl nalezen');
                return $this->redirectToRoute('app_settings_meta_types');
            }
            
            if ($request->isMethod('POST')) {
                $name = $request->request->get('name');
                $label = $request->request->get('label');
                $description = $request->request->get('description');
                $dataType = $request->request->get('data_type');
                $isMultiple = $request->request->getBoolean('is_multiple');
                
                if (empty($name) || empty($label) || empty($dataType)) {
                    $this->addFlash('error', 'Všechna povinná pole musí být vyplněna');
                    return $this->redirectToRoute('app_settings_meta_type_edit', ['id' => $id]);
                }
                
                // Kontrola, zda již existuje jiný typ se stejným názvem
                $existing = $this->entityManager->getRepository(MetaType::class)->findOneByName($name);
                if ($existing && $existing->getId() !== $metaType->getId()) {
                    $this->addFlash('error', 'Typ metadat s tímto názvem již existuje');
                    return $this->redirectToRoute('app_settings_meta_type_edit', ['id' => $id]);
                }
                
                // Aktualizace typu metadat
                $metaType->setName($name);
                $metaType->setLabel($label);
                $metaType->setDescription($description);
                $metaType->setDataType($dataType);
                $metaType->setIsMultiple($isMultiple);
                
                $this->entityManager->flush();
                
                $this->addFlash('success', 'Typ metadat byl úspěšně aktualizován');
                return $this->redirectToRoute('app_settings_meta_types');
            }
            
            return $this->render('settings/meta_type_form.html.twig', [
                'mode' => 'edit',
                'metaType' => $metaType
            ]);
        } catch (\Exception $e) {
            $this->addFlash('error', 'Chyba při úpravě typu metadat: ' . $e->getMessage());
            return $this->redirectToRoute('app_settings_meta_types');
        }
    }

    #[Route('/{id}/delete', name: '_type_delete', methods: ['POST'])]
    public function delete(int $id): Response
    {
        try {
            $metaType = $this->entityManager->getRepository(MetaType::class)->find($id);
            
            if (!$metaType) {
                $this->addFlash('error', 'Typ metadat nebyl nalezen');
                return $this->redirectToRoute('app_settings_meta_types');
            }
            
            // TODO: Přidat kontrolu, zda nejsou používána metadata tohoto typu
            
            $this->entityManager->remove($metaType);
            $this->entityManager->flush();
            
            $this->addFlash('success', 'Typ metadat byl úspěšně odstraněn');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Chyba při mazání typu metadat: ' . $e->getMessage());
        }
        
        return $this->redirectToRoute('app_settings_meta_types');
    }
}
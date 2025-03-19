<?php

namespace App\Form;

use App\Entity\FileCategory;
use App\Entity\Media;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SmartFilterType extends AbstractType
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Výběr médií - přidáno
        $builder->add('selected_media_ids', EntityType::class, [
            'class' => Media::class,
            'choice_label' => function (Media $media) {
                return $media->getDescription() . ' (' . $media->getIdentifier() . ')';
            },
            'choice_value' => 'id',
            'multiple' => true,
            'expanded' => false,
            'required' => false,
            'label' => 'Vybraná média',
            'attr' => [
                'class' => 'form-select',
                'size' => '5'
            ],
            'data' => $options['selected_media_ids']  // Klíčový řádek
        ]);

        // Vyhledávání
        $builder->add('filter_search', TextType::class, [
            'label' => 'Vyhledávat',
            'required' => false,
            'attr' => [
                'placeholder' => 'Hledat v názvech...',
                'class' => 'form-control'
            ]
        ]);

        // Filtr dle přípony
        $builder->add('filter_extension', TextType::class, [
            'label' => 'Filtrovat dle přípony',
            'required' => false,
            'attr' => [
                'placeholder' => 'např. jpg, pdf',
                'class' => 'form-control'
            ],
            'help' => 'Zadejte příponu bez tečky'
        ]);

        // Filtr dle kategorie
        $builder->add('filter_category', EntityType::class, [
            'class' => FileCategory::class,
            'choice_label' => 'name',
            'choice_value' => 'id',
            'placeholder' => '-- Všechny kategorie --',
            'required' => false,
            'label' => 'Filtrovat dle kategorie',
            'attr' => [
                'class' => 'form-select'
            ]
        ]);

        // Řazení podle
        $builder->add('sort_by', ChoiceType::class, [
            'label' => 'Řadit podle',
            'choices' => [
                'Názvu' => 'original_filename',
                'Přípony' => 'extension',
                'Velikosti' => 'file_size',
                'Data modifikace' => 'file_modified_at',
                'Cesty' => 'directory_path',
                'Média' => 'media_identifier'
            ],
            'attr' => [
                'class' => 'form-select'
            ]
        ]);

        // Směr řazení
        $builder->add('sort_dir', ChoiceType::class, [
            'label' => 'Směr řazení',
            'choices' => [
                'Vzestupně (A-Z, 0-9)' => 'asc',
                'Sestupně (Z-A, 9-0)' => 'desc'
            ],
            'attr' => [
                'class' => 'form-select'
            ]
        ]);

        // Položek na stránku
        $builder->add('page_size', ChoiceType::class, [
            'label' => 'Položek na stránku',
            'choices' => [
                '50' => 50,
                '100' => 100,
                '200' => 200,
                '500' => 500
            ],
            'attr' => [
                'class' => 'form-select'
            ]
        ]);

        // Aktuální stránka (skrytá)
        $builder->add('page', TextType::class, [
            'data' => $options['current_page'] ?? 1,
            'attr' => [
                'hidden' => true
            ],
            'label' => false
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'csrf_protection' => true,
            'method' => 'POST',
            'selected_media_ids' => [],
            'current_page' => 1
        ]);
    }
}
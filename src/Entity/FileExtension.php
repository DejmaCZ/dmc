<?php

namespace App\Entity;

use App\Repository\FileExtensionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FileExtensionRepository::class)]
#[ORM\Table(name: 'file_extension')]
#[ORM\UniqueConstraint(name: 'UNIQ_FILE_EXTENSION_NAME', columns: ['name'])]
class FileExtension
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    private ?string $name = null;

    #[ORM\ManyToOne(inversedBy: 'extensions')]
    #[ORM\JoinColumn(nullable: false)]
    private ?FileCategory $category = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = strtolower($name);

        return $this;
    }

    public function getCategory(): ?FileCategory
    {
        return $this->category;
    }

    public function setCategory(?FileCategory $category): static
    {
        $this->category = $category;

        return $this;
    }
}
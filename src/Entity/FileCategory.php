<?php

namespace App\Entity;

use App\Repository\FileCategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FileCategoryRepository::class)]
class FileCategory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $icon = null;

    #[ORM\OneToMany(mappedBy: 'category', targetEntity: FileExtension::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $extensions;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->extensions = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

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
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(?string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    /**
     * @return Collection<int, FileExtension>
     */
    public function getExtensions(): Collection
    {
        return $this->extensions;
    }

    public function addExtension(FileExtension $extension): static
    {
        if (!$this->extensions->contains($extension)) {
            $this->extensions->add($extension);
            $extension->setCategory($this);
        }

        return $this;
    }

    public function removeExtension(FileExtension $extension): static
    {
        if ($this->extensions->removeElement($extension)) {
            // set the owning side to null (unless already changed)
            if ($extension->getCategory() === $this) {
                $extension->setCategory(null);
            }
        }

        return $this;
    }

    /**
     * Helper method to add multiple extensions at once
     */
    public function addExtensionsFromString(string $extensionsString): static
    {
        $extensionNames = array_map('trim', explode(',', $extensionsString));
        
        foreach ($extensionNames as $extensionName) {
            if (!empty($extensionName)) {
                $extension = new FileExtension();
                $extension->setName(strtolower($extensionName));
                $this->addExtension($extension);
            }
        }

        return $this;
    }

    /**
     * Helper method to get extensions as a comma-separated string
     */
    public function getExtensionsAsString(): string
    {
        $extensionNames = [];
        
        foreach ($this->extensions as $extension) {
            $extensionNames[] = $extension->getName();
        }
        
        return implode(', ', $extensionNames);
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
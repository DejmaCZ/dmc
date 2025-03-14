<?php

namespace App\Entity;

use App\Repository\MediaRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MediaRepository::class)]
class Media
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $path = null;

    #[ORM\Column(length: 6, unique: true)]
    private ?string $identifier = null;

    #[ORM\Column(length: 255)]
    private ?string $description = null;

    #[ORM\ManyToOne(inversedBy: 'media')]
    #[ORM\JoinColumn(nullable: false)]
    private ?MediaType $mediaType = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastScannedAt = null;

    // #[ORM\OneToOne(mappedBy: 'media', targetEntity: MediaStats::class, cascade: ['persist', 'remove'])]
    // private ?MediaStats $stats = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPath(): ?string
    {
        return $this->path;
    }

    public function setPath(string $path): static
    {
        $this->path = $path;

        return $this;
    }

    public function getIdentifier(): ?string
    {
        return $this->identifier;
    }

    public function setIdentifier(string $identifier): static
    {
        $this->identifier = $identifier;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getMediaType(): ?MediaType
    {
        return $this->mediaType;
    }

    public function setMediaType(?MediaType $mediaType): static
    {
        $this->mediaType = $mediaType;

        return $this;
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

    public function getLastScannedAt(): ?\DateTimeImmutable
    {
        return $this->lastScannedAt;
    }

    public function setLastScannedAt(?\DateTimeImmutable $lastScannedAt): static
    {
        $this->lastScannedAt = $lastScannedAt;

        return $this;
    }

    // public function getStats(): ?MediaStats
    // {
    //     return $this->stats;
    // }

    // public function setStats(?MediaStats $stats): static
    // {
    //     // Nastavení obou stran relace
    //     if ($stats === null && $this->stats !== null) {
    //         $this->stats->setMedia(null);
    //     }

    //     if ($stats !== null && $stats->getMedia() !== $this) {
    //         $stats->setMedia($this);
    //     }

    //     $this->stats = $stats;

    //     return $this;
    // }
    
    /**
     * Vrací počet souborů v médiu.
     * Pokud existují statistiky, použije je, jinak vrátí 0.
     */
    // public function getFilesCount(): int
    // {
    //     return $this->stats ? $this->stats->getFilesCount() : 0;
    // }
    
    /**
     * Vrací celkovou velikost souborů v médiu.
     * Pokud existují statistiky, použije je, jinak vrátí 0.
     */
    // public function getTotalSize(): int
    // {
    //     return $this->stats ? $this->stats->getTotalSize() : 0;
    // }
    
    /**
     * Vrací počet adresářů v médiu.
     * Pokud existují statistiky, použije je, jinak vrátí 0.
     */
    // public function getDirectoriesCount(): int
    // {
    //     return $this->stats ? $this->stats->getDirectoriesCount() : 0;
    // }
    
    /**
     * Vrací datum posledního výpočtu statistik.
     */
    // public function getLastStatsCalculatedAt(): ?\DateTimeImmutable
    // {
    //     return $this->stats ? $this->stats->getLastCalculatedAt() : null;
    // }
    
    /**
     * Metoda pro nastavení dynamické vlastnosti filesCount
     * Používá se při dotazování před zavedením statistik
     */
    // public function setFilesCount(int $count): static
    // {
    //     // Pouze pro zpětnou kompatibilitu
    //     return $this;
    // }
}
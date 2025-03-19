<?php

namespace App\Service;

use App\Entity\Media;
use App\Entity\MediaType;
use App\Entity\FileCategory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

class DashboardService
{
    private $entityManager;
    private $connection;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
    }

    /**
     * Získá základní statistiky pro dashboard
     */
    public function getBasicStats(): array
    {
        // Zjistíme počet médií
        $mediaCount = $this->entityManager->getRepository(Media::class)->count([]);
        
        // Celkový počet souborů a celková velikost z media_stats
        $totalStatsQuery = "
            SELECT 
                SUM(files_count) as total_files, 
                SUM(total_size) as total_size,
                SUM(directories_count) as total_directories
            FROM media_stats
        ";
        
        $totalStats = $this->connection->executeQuery($totalStatsQuery)->fetchAssociative();
        
        // Pokud nejsou dostupné statistiky, nastavíme výchozí hodnoty
        if (!$totalStats || !$totalStats['total_files']) {
            $totalStats = [
                'total_files' => 0,
                'total_size' => 0,
                'total_directories' => 0
            ];
        }
        
        return [
            'media_count' => $mediaCount,
            'total_files' => $totalStats['total_files'],
            'total_size' => $totalStats['total_size'],
            'total_directories' => $totalStats['total_directories'],
            'formatted_total_size' => $this->formatSize($totalStats['total_size'])
        ];
    }

    /**
     * Získá statistiky podle typů médií
     */
    public function getMediaTypeStats(): array
    {
        $query = "
            SELECT 
                mt.id,
                mt.name,
                mt.icon,
                COUNT(m.id) as media_count,
                COALESCE(SUM(ms.files_count), 0) as files_count,
                COALESCE(SUM(ms.total_size), 0) as total_size
            FROM 
                media_type mt
            LEFT JOIN 
                media m ON mt.id = m.media_type_id
            LEFT JOIN 
                media_stats ms ON m.id = ms.media_id
            GROUP BY 
                mt.id
            ORDER BY 
                total_size DESC
        ";
        
        $results = $this->connection->executeQuery($query)->fetchAllAssociative();
        
        // Formátování velikosti
        foreach ($results as &$row) {
            $row['formatted_size'] = $this->formatSize($row['total_size']);
        }
        
        return $results;
    }

    /**
     * Získá top N přípon podle počtu souborů
     */
    public function getTopExtensionsByCount(int $limit = 10): array
    {
        $query = "
            SELECT 
                extension,
                SUM(files_count) as total_count,
                SUM(total_size) as total_size
            FROM 
                media_extension_stats
            WHERE 
                extension IS NOT NULL AND extension != ''
            GROUP BY 
                extension
            ORDER BY 
                total_count DESC
            LIMIT " . $limit;
        
        $results = $this->connection->executeQuery($query)->fetchAllAssociative();
        
        // Formátování velikosti
        foreach ($results as &$row) {
            $row['formatted_size'] = $this->formatSize($row['total_size']);
            
            // Přidat informaci o kategorii
            $categoryQuery = "
                SELECT fc.name, fc.icon 
                FROM file_extension fe 
                JOIN file_category fc ON fe.category_id = fc.id 
                WHERE fe.name = ?
                LIMIT 1
            ";
            $category = $this->connection->executeQuery($categoryQuery, [$row['extension']])->fetchAssociative();
            
            if ($category) {
                $row['category_name'] = $category['name'];
                $row['category_icon'] = $category['icon'];
            } else {
                $row['category_name'] = 'Ostatní';
                $row['category_icon'] = 'file';
            }
        }
        
        return $results;
    }

    /**
     * Získá top N přípon podle velikosti
     */
    public function getTopExtensionsBySize(int $limit = 10): array
    {
        $query = "
            SELECT 
                extension,
                SUM(files_count) as total_count,
                SUM(total_size) as total_size
            FROM 
                media_extension_stats
            WHERE 
                extension IS NOT NULL AND extension != ''
            GROUP BY 
                extension
            ORDER BY 
                total_size DESC
            LIMIT " . $limit;
        
        $results = $this->connection->executeQuery($query)->fetchAllAssociative();
        
        // Formátování velikosti
        foreach ($results as &$row) {
            $row['formatted_size'] = $this->formatSize($row['total_size']);
            
            // Přidat informaci o kategorii
            $categoryQuery = "
                SELECT fc.name, fc.icon 
                FROM file_extension fe 
                JOIN file_category fc ON fe.category_id = fc.id 
                WHERE fe.name = ?
                LIMIT 1
            ";
            $category = $this->connection->executeQuery($categoryQuery, [$row['extension']])->fetchAssociative();
            
            if ($category) {
                $row['category_name'] = $category['name'];
                $row['category_icon'] = $category['icon'];
            } else {
                $row['category_name'] = 'Ostatní';
                $row['category_icon'] = 'file';
            }
        }
        
        return $results;
    }

    /**
     * Získá statistiky kategorií souborů
     */
    public function getCategoryStats(): array
    {
        $query = "
            SELECT 
                fc.id,
                fc.name,
                fc.icon,
                SUM(mcs.files_count) as total_count,
                SUM(mcs.total_size) as total_size
            FROM 
                file_category fc
            LEFT JOIN 
                media_category_stats mcs ON fc.id = mcs.category_id
            GROUP BY 
                fc.id
            ORDER BY 
                total_size DESC
        ";
        
        $results = $this->connection->executeQuery($query)->fetchAllAssociative();
        
        // Formátování velikosti
        foreach ($results as &$row) {
            $row['formatted_size'] = $this->formatSize($row['total_size']);
        }
        
        return $results;
    }

    /**
     * Získá poslední skenovaná média
     */
    public function getRecentlyScannedMedia(int $limit = 5): array
    {
        $query = "
            SELECT 
                m.id,
                m.identifier,
                m.description,
                m.last_scanned_at,
                mt.name as type_name,
                mt.icon as type_icon,
                ms.files_count,
                ms.total_size,
                ms.directories_count
            FROM 
                media m
            JOIN 
                media_type mt ON m.media_type_id = mt.id
            LEFT JOIN 
                media_stats ms ON m.id = ms.media_id
            WHERE 
                m.last_scanned_at IS NOT NULL
            ORDER BY 
                m.last_scanned_at DESC
            LIMIT " . $limit;
        
        $results = $this->connection->executeQuery($query)->fetchAllAssociative();
        
        // Formátování velikosti a data
        foreach ($results as &$row) {
            $row['formatted_size'] = $this->formatSize($row['total_size']);
            $row['scanned_date'] = $row['last_scanned_at'] ? (new \DateTime($row['last_scanned_at']))->format('d.m.Y H:i') : 'Nikdy';
        }
        
        return $results;
    }

    /**
     * Získá časovou osu přidání médií (agregovanou po měsících)
     */
    public function getMediaTimeline(): array
    {
        $query = "
            SELECT 
                strftime('%Y-%m', created_at) as month,
                COUNT(*) as count
            FROM 
                media
            GROUP BY 
                month
            ORDER BY 
                month ASC
        ";
        
        return $this->connection->executeQuery($query)->fetchAllAssociative();
    }

    /**
     * Formátuje velikost souboru do čitelné podoby
     */
    private function formatSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = $bytes;
        $unitIndex = 0;
        
        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }
        
        return round($size, 2) . ' ' . $units[$unitIndex];
    }
}
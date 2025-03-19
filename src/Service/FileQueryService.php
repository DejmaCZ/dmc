<?php

namespace App\Service;

use App\Entity\FileCategory;
use App\Entity\Media;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

class FileQueryService
{
    private $entityManager;
    private $connection;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
    }

    /**
     * Get files based on filter parameters
     * 
     * @param array|Media $mediaSource One or more media objects
     * @param array $options Filter and pagination options
     * @return array Result data with files, total count, and path information
     */
    public function getFiles($mediaSource, array $options = []): array
    {
        // Default options
        $defaults = [
            'page' => 1,
            'pageSize' => 100,
            'extension' => '',
            'category' => '',
            'search' => '',
            'sortBy' => 'original_filename',
            'sortDir' => 'asc'
        ];
        
        // Merge provided options with defaults
        $options = array_merge($defaults, $options);
        
        // Normalize media source to array
        $mediaList = is_array($mediaSource) ? $mediaSource : [$mediaSource];
        
        // Filter only Media objects
        $mediaList = array_filter($mediaList, function($item) {
            return $item instanceof Media;
        });
        
        if (empty($mediaList)) {
            return [
                'files' => [],
                'totalFiles' => 0,
                'totalFilteredFiles' => 0,
                'mediaStats' => [],
                'path' => []
            ];
        }

        // Get total counts first (unfiltered)
        $totalCounts = $this->getTotalFileCounts($mediaList);
        
        // Build and execute query for files with filters
        $result = $this->fetchFiles($mediaList, $options);
        
        // Add total counts to the result
        $result['totalFiles'] = array_sum(array_column($totalCounts, 'count'));
        
        return $result;
    }

    /**
     * Get total file counts for each media
     */
    private function getTotalFileCounts(array $mediaList): array
    {
        $result = [];
        
        foreach ($mediaList as $media) {
            $identifier = strtolower($media->getIdentifier());
            $filesTable = 'files_' . $identifier;
            
            try {
                // Check if table exists
                $tableExists = $this->tableExists($filesTable);
                
                if (!$tableExists) {
                    $result[] = [
                        'media_id' => $media->getId(),
                        'media_identifier' => $media->getIdentifier(),
                        'count' => 0
                    ];
                    continue;
                }
                
                // Count total files
                $sql = "SELECT COUNT(*) FROM $filesTable";
                $count = (int)$this->connection->executeQuery($sql)->fetchOne();
                
                $result[] = [
                    'media_id' => $media->getId(),
                    'media_identifier' => $media->getIdentifier(),
                    'count' => $count
                ];
            } catch (\Exception $e) {
                error_log('Error getting total count for media ' . $media->getId() . ': ' . $e->getMessage());
                $result[] = [
                    'media_id' => $media->getId(),
                    'media_identifier' => $media->getIdentifier(),
                    'count' => 0
                ];
            }
        }
        
        return $result;
    }

    /**
     * Fetch files from one or multiple media with filtering and pagination
     */
    private function fetchFiles(array $mediaList, array $options): array
    {
        $queries = [];
        $mediaStats = [];

        // Build queries for each media
        foreach ($mediaList as $media) {
            $identifier = strtolower($media->getIdentifier());
            $filesTable = 'files_' . $identifier;
            
            try {
                // Check if table exists
                if (!$this->tableExists($filesTable)) {
                    $mediaStats[] = [
                        'media_id' => $media->getId(),
                        'media_identifier' => $media->getIdentifier(),
                        'description' => $media->getDescription(),
                        'filtered_count' => 0
                    ];
                    continue;
                }
                
                // Build SQL query
                $sql = $this->buildFileQuery($filesTable, $media, $options);
                
                // Count filtered results for this media
                $countSql = "SELECT COUNT(*) FROM $filesTable f WHERE " . $sql['where'];
                $filteredCount = (int)$this->connection->executeQuery($countSql)->fetchOne();
                
                // Add to media stats
                $mediaStats[] = [
                    'media_id' => $media->getId(),
                    'media_identifier' => $media->getIdentifier(),
                    'description' => $media->getDescription(),
                    'filtered_count' => $filteredCount
                ];
                
                // Skip if no results
                if ($filteredCount === 0) {
                    continue;
                }
                
                // Build full query with selected fields
                $fullSql = "SELECT 
                    f.id, 
                    f.original_filename, 
                    f.full_path, 
                    f.content_hash, 
                    f.extension,
                    f.file_size,
                    f.file_modified_at,
                    '{$media->getIdentifier()}' as media_identifier,
                    {$media->getId()} as media_id
                FROM $filesTable f
                WHERE " . $sql['where'];
                
                // Add to queries array
                $queries[] = $fullSql;
                
            } catch (\Exception $e) {
                // Log error and continue
                error_log('Error building query for media ' . $media->getId() . ': ' . $e->getMessage());
                $mediaStats[] = [
                    'media_id' => $media->getId(),
                    'media_identifier' => $media->getIdentifier(),
                    'description' => $media->getDescription(),
                    'filtered_count' => 0
                ];
                continue;
            }
        }
        
        // If no valid queries, return empty result
        if (empty($queries)) {
            return [
                'files' => [],
                'totalFilteredFiles' => 0,
                'mediaStats' => $mediaStats,
                'path' => []
            ];
        }
        
        // Calculate total filtered files
        $totalFilteredFiles = array_sum(array_column($mediaStats, 'filtered_count'));
        
        // Prepare final query with pagination
        $unionQuery = implode(" UNION ALL ", $queries);
        $finalQuery = "SELECT * FROM ($unionQuery) AS combined_results ORDER BY {$options['sortBy']} {$options['sortDir']} LIMIT {$options['pageSize']} OFFSET " . (($options['page'] - 1) * $options['pageSize']);
        
        try {
            // Execute final query
            $files = $this->connection->executeQuery($finalQuery)->fetchAllAssociative();
            
            return [
                'files' => $files,
                'totalFilteredFiles' => $totalFilteredFiles,
                'mediaStats' => $mediaStats,
                'path' => [] // Empty path for flat view
            ];
        } catch (\Exception $e) {
            error_log('Error executing combined query: ' . $e->getMessage());
            return [
                'files' => [],
                'totalFilteredFiles' => 0,
                'mediaStats' => $mediaStats,
                'path' => []
            ];
        }
    }

    /**
     * Build WHERE clause for SQL query based on filter options
     */
    private function buildFileQuery(string $filesTable, Media $media, array $options): array
    {
        $whereConditions = ['1=1']; // Start with a condition that's always true
        
        // Filter by extension
        if (!empty($options['extension'])) {
            $whereConditions[] = "LOWER(f.extension) = LOWER(" . $this->connection->quote($options['extension']) . ")";
        }
        
        // Filter by category
        if (!empty($options['category'])) {
            $categoryId = (int)$options['category'];
            
            // Verify category exists
            $category = $this->entityManager->getRepository(FileCategory::class)->find($categoryId);
            
            if ($category) {
                $whereConditions[] = "f.extension IN (SELECT e.name FROM file_extension e WHERE e.category_id = " . $this->connection->quote($categoryId) . ")";
            }
        }
        
        // Search in filenames and paths
        if (!empty($options['search'])) {
            $searchTerm = '%' . $options['search'] . '%';
            $quotedSearchTerm = $this->connection->quote($searchTerm);
            $whereConditions[] = "(f.original_filename LIKE $quotedSearchTerm OR f.full_path LIKE $quotedSearchTerm)";
        }
        
        // Combine conditions with AND
        $whereClause = implode(" AND ", $whereConditions);
        
        return [
            'where' => $whereClause
        ];
    }
    
    /**
     * Check if a table exists in the database
     */
    private function tableExists(string $tableName): bool
    {
        try {
            $result = $this->connection->executeQuery(
                "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=:name",
                ['name' => $tableName]
            )->fetchOne();
            
            return (bool)$result;
        } catch (\Exception $e) {
            error_log('Error checking if table exists: ' . $e->getMessage());
            return false;
        }
    }
}
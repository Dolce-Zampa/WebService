<?php
declare(strict_types=1);

namespace PS\Webservice\Traits;

trait PaginationTrait
{
    /**
     * Extract and validate pagination parameters from request
     *
     * @param array $queryParams Query parameters from request
     * @return array ['page' => int, 'per_page' => int]
     */
    protected function getPaginationParams(array $queryParams): array
    {
        $page = (int) ($queryParams['page'] ?? 1);
        $perPage = (int) ($queryParams['per_page'] ?? 10);

        // Validate
        if ($page < 1) {
            $page = 1;
        }
        if ($perPage < 1 || $perPage > 100) {
            $perPage = 10;
        }

        return [
            'page' => $page,
            'per_page' => $perPage
        ];
    }

    /**
     * Build pagination metadata for response
     *
     * @param int $currentPage Current page number
     * @param int $perPage Items per page
     * @param int $totalItems Total number of items
     * @return array Pagination metadata
     */
    protected function buildPaginationMeta(int $currentPage, int $perPage, int $totalItems): array
    {
        $totalPages = (int) ceil($totalItems / $perPage);

        return [
            'current_page' => $currentPage,
            'per_page' => $perPage,
            'total_items' => $totalItems,
            'total_pages' => $totalPages,
            'has_next_page' => $currentPage < $totalPages,
            'has_previous_page' => $currentPage > 1
        ];
    }

    /**
     * Build paginated response with data and metadata
     *
     * @param array $data Response data
     * @param int $currentPage Current page number
     * @param int $perPage Items per page
     * @param int $totalItems Total number of items
     * @param array $additionalData Additional data to include in response
     * @return array Complete response with pagination
     */
    protected function paginatedResponse(
        array $data,
        int $currentPage,
        int $perPage,
        int $totalItems,
        array $additionalData = []
    ): array {
        return array_merge([
            'data' => $data,
            'pagination' => $this->buildPaginationMeta($currentPage, $perPage, $totalItems)
        ], $additionalData);
    }
}

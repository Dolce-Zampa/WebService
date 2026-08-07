<?php

namespace PS\Webservice\Domain\Entities;

class EntityExceptions extends \Exception
{
    public const INVALID_ENTITY_DATA = 'Invalid entity data provided.';
    public const MISSING_ENTITY_FIELD = 'Missing required field in entity data.';
    public const ENTITY_CREATION_FAILED = 'Failed to create entity.';
    public const ENTITY_UPDATE_FAILED = 'Failed to update entity.';
    public const ENTITY_DELETION_FAILED = 'Failed to delete entity.';
    public const ENTITY_NOT_FOUND = 'Entity not found.';

    public function __construct(string $message, int $productId)
    {
        parent::__construct(sprintf('%s Product ID: %d', $message, $productId), 500);
    }

    public static function invalidEntityData(int $productId): self
    {
        return new self(self::INVALID_ENTITY_DATA, $productId);
    }

    public static function missingEntityField(int $productId): self
    {
        return new self(self::MISSING_ENTITY_FIELD, $productId);
    }

    public static function entityCreationFailed(int $productId): self
    {
        return new self(self::ENTITY_CREATION_FAILED, $productId);
    }

    public static function entityUpdateFailed(int $productId): self
    {
        return new self(self::ENTITY_UPDATE_FAILED, $productId);
    }

    public static function entityDeletionFailed(int $productId): self
    {
        return new self(self::ENTITY_DELETION_FAILED, $productId);
    }

    public static function entityNotFound(int $productId): self
    {
        return new self(self::ENTITY_NOT_FOUND, $productId);
    }

}
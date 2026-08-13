<?php
namespace PS\Webservice\Repositories;

interface RepositoryInterface
{
    public function __construct(\Illuminate\Database\Capsule\Manager $db);
    public function checkConnection(): bool;
}
<?php

declare(strict_types=1);

namespace NeuronAI\RAG\GraphStore;

interface GraphStoreInterface
{
    // Core triplet operations (subject-relation-object)
    public function upsert(string $subject, string $relation, string $object): void;
    public function delete(string $subject, string $relation, string $object): void;

    /**
     * Most RAG queries start from entities (subjects) and explore relationships
     */
    public function get(string $subject): array;

    /**
     * For RAG context gathering (multi-hop relationships)
     */
    public function getRelationshipMap(array $subjects = [], int $depth = 2, int $limit = 30): array;

    /**
     * Helps agents understand available entity types and relationships
     */
    public function getSchema(bool $refresh = false): string;

    /**
     * Query the graph store with statement and parameters.
     */
    public function query(string $query, array $parameters = []): mixed;
}

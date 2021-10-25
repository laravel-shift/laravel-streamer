<?php

namespace Tests\Stubs;

use Illuminate\Support\Collection;
use Prwnr\Streamer\Contracts\ArchiveStorage;
use Prwnr\Streamer\EventDispatcher\Message;

class MemoryArchiveStorage implements ArchiveStorage
{
    private $items = [];

    /**
     * @inheritDoc
     */
    public function create(Message $message): void
    {
        $this->items[$message->getEventName()][$message->getId()] = $message;
    }

    /**
     * @inheritDoc
     */
    public function find(string $event, string $id): ?Message
    {
        return $this->items[$event][$id] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function findMany(string $event): Collection
    {
        return collect($this->items[$event] ?? []);
    }

    /**
     * @inheritDoc
     */
    public function all(): Collection
    {
        $collection = collect();
        foreach ($this->items as $message) {
            $collection->push($message);
        }

        return $collection;
    }

    /**
     * @inheritDoc
     */
    public function delete(string $event, string $id): void
    {
        unset($this->items[$event][$id]);
    }
}
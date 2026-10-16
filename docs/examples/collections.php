<?php

// Any object with all() or toArray() works, such as a Laravel Collection
// of arrays or Eloquent models. This stand-in avoids a Laravel dependency.
$collection = new class
{
    public function all(): array
    {
        return [
            ['id' => 1, 'name' => 'Ana'],
            (object) ['id' => 2, 'name' => 'Ben'],
        ];
    }
};

echo Frame::fromCollection($collection);

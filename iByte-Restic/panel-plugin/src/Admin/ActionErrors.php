<?php

namespace IByte\Restic\Admin;

use Illuminate\Validation\ValidationException;
use Livewire\Component;

final class ActionErrors
{
    public static function validation(Component $livewire, ValidationException $exception): never
    {
        // Service validation uses field names; Filament modal fields are nested.
        $path = $livewire->getSchema($livewire->getMountedActionSchemaName())->getStatePath();
        throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn ($messages, $field) => [$path.'.'.$field => $messages])->all());
    }
}

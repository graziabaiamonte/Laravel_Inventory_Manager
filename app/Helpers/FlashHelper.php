<?php

namespace App\Helpers;

class FlashHelper
{
    /**
     * Add a flash message, combining it with existing messages if they exist
     */
    public static function addMessage(string $type, string $message): void
    {
        $existingMessage = session()->get($type);

        if ($existingMessage) {
            $finalMessage = $existingMessage.' - '.$message;
        } else {
            $finalMessage = $message;
        }

        session()->flash($type, $finalMessage);
    }

    /**
     * Add a success message
     */
    public static function success(string $message): void
    {
        self::addMessage('success', $message);
    }

    /**
     * Add an error message
     */
    public static function error(string $message): void
    {
        self::addMessage('error', $message);
    }

    /**
     * Add a warning message
     */
    public static function warning(string $message): void
    {
        self::addMessage('warning', $message);
    }
}

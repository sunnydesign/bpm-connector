<?php

namespace Quancy\Logger;

/**
 * Logger
 */
class Logger
{
    /**
     * @var array message levels
     */
    static $levels = [
        0 => 'info',
        1 => 'error',
        2 => 'success',
        3 => 'warning',
    ];

    /**
     * Clean class name
     *
     * @param string $class_name
     * @return string
     */
    static function cleanClassName(string $class_name): string
    {
        $class_name_as_array = explode('\\', $class_name);

        return array_pop($class_name_as_array);
    }

    /**
     * Get message level name
     *
     * @param string $level
     * @return string
     */
    static function getMessageLevelName($level): string
    {
        // if level has set as integer
        if(is_int($level))
            return self::$levels[$level];
        // if level has set as string
        else
            return $level;
    }


    /**
     * Is JSON
     *
     * @param $string
     * @return bool
     */
    static function isJson(string $string): bool
    {
        json_decode($string);

        return (json_last_error() === JSON_ERROR_NONE);
    }

    /**
     * Log
     *
     * @param mixed $message object, array, string or json string
     * @param string $owner
     * @param mixed $level (0 = 'info' | 1 = 'error' | 2 = 'success' | 3 = 'warning')
     * @return void
     */
    static function log($message = '', string $type = '', string $queue = '', string $owner = __CLASS__, $level = 0): void
    {
        $level_name = self::getMessageLevelName($level);
        $owner_name = self::cleanClassName($owner);

        // if object or array
        // convert to JSON
        if(is_object($message) || is_array($message))
            $message = json_encode($message);

        // if message not pure string
        // convert his to array
        if(self::isJson($message))
            $message = json_decode($message, true);

        $response = [
            '@timestamp' => date('c'),
            'type'       => $type,
            'queue'      => $queue,
            'level'      => $level_name,
            'owner'      => $owner_name,
            'message'    => $message,
        ];

        echo json_encode($response), PHP_EOL;
    }
}
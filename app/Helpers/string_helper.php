<?php

if (! function_exists('str_slug'))
{
    /**
     * Convert string to slug.
     *
     * @param string $string
     * @return mixed
     */
    function str_slug(string $string)
    {
        return str_replace(' ', '-', strtolower($string));
    }
}

if (! function_exists('str_snake'))
{
    /**
     * Convert string to friendly file name.
     *
     * @param string $string
     * @return mixed
     */
    function str_snake(string $string)
    {
        return str_replace(' ', '_', strtolower($string));
    }
}

if (! function_exists('class_basename')) {
    /**
     * Get the class "basename" of the given object / class.
     *
     * @param  string|object $class
     * @return string
     */
    function class_basename($class)
    {
        $class = is_object($class) ? get_class($class) : $class;
        return basename(str_replace('\\', '/', $class));
    }
}

if (! function_exists('str_starts_with')) {
    /**
     * Determine if a given string starts with a given substring.
     *
     * @param  string  $haystack
     * @param  string|array  $needles
     * @return bool
     */
    function str_starts_with($haystack, $needles)
    {
        foreach ((array) $needles as $needle) {
            if ($needle !== '' && substr($haystack, 0, strlen($needle)) === (string) $needle) {
                return true;
            }
        }
        return false;
    }
}

if (! function_exists('str_studly')) {
    /**
     * Convert a value to studly caps case.
     *
     * @param  string  $value
     * @return string
     */
    function str_studly($value)
    {
        $value = ucwords(str_replace(['-', '_'], ' ', $value));
        return str_replace(' ', '', $value);
    }
}

if (! function_exists('str_contains'))
{
    /**
     * Determine if a given string contains a given substring.
     *
     * @param  string  $haystack
     * @param  string|array  $needles
     * @return bool
     */
    function str_contains(string $haystack, $needles)
    {
        foreach ((array) $needles as $needle) {
            if ($needle !== '' && mb_strpos($haystack, $needle) !== false) {
                return true;
            }
        }
        return false;
    }
}

if (! function_exists('str_camel'))
{
    /**
     * Convert a value to camel case.
     *
     * @param  string  $value
     * @return string
     */
    function str_camel($value)
    {
        return lcfirst(str_studly($value));
    }
}

if (! function_exists('str_plural'))
{
    /**
     * Get the plural form of an English word.
     *
     * @param  string  $value
     * @return string
     */
    function str_plural($value)
    {
        return plural($value);
    }
}

if (! function_exists('str_plural_studly'))
{
    /**
     * Pluralize the last word of an English, studly caps case string.
     *
     * @param  string  $value
     * @return string
     */
    function str_plural_studly($value)
    {
        $parts = preg_split('/(.)(?=[A-Z])/u', $value, -1, PREG_SPLIT_DELIM_CAPTURE);

        $lastWord = array_pop($parts);

        return implode('', $parts).str_plural($lastWord);
    }
}
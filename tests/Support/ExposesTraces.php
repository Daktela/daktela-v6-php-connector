<?php

declare(strict_types=1);

namespace Daktela\Tests\Support;

/**
 * Checks what loggers, error trackers, and debug output can see of an exception chain, with the arguments in
 * exception traces kept, as in PHP's default configuration.
 */
trait ExposesTraces
{
    /** @var string|false setting restored after the test */
    private $ignoreArgs = false;

    protected function setUp(): void
    {
        parent::setUp();
        // Production configurations hide arguments in traces; the tests check the default, which keeps them
        $this->ignoreArgs = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
    }

    protected function tearDown(): void
    {
        ini_set('zend.exception_ignore_args', (string)$this->ignoreArgs);
        parent::tearDown();
    }

    /**
     * Returns what loggers and error trackers can see of an exception: its string form and the arguments
     * of the connector's frames in the traces of the whole exception chain.
     */
    private static function exposed(\Throwable $ex): string
    {
        $text = (string)$ex;
        $seen = [];
        for ($current = $ex; $current !== null; $current = $current->getPrevious()) {
            foreach ($current->getTrace() as $frame) {
                if (str_starts_with($frame['class'] ?? '', 'Daktela\\DaktelaV6\\')) {
                    $text .= self::strings($frame['args'] ?? [], 0, $seen);
                }
            }
        }

        return $text;
    }

    /**
     * Collects every string and key in a value, including private properties of objects, as print_r() shows
     * them. Objects are visited once and the depth is bounded, so a leaking value, e.g. an HTTP client exception
     * with its own trace, fails the test instead of exhausting memory.
     * @param array<int, true> $seen objects already visited
     */
    private static function strings(mixed $value, int $depth, array &$seen): string
    {
        if (is_string($value)) {
            return $value . "\n";
        }
        if (is_object($value)) {
            if (isset($seen[spl_object_id($value)])) {
                return '';
            }
            $seen[spl_object_id($value)] = true;
            $isThrowable = $value instanceof \Throwable;
            $value = (array)$value;
            if ($isThrowable) {
                // Of its own trace only the connector's frames: the others reach the test framework's objects
                foreach (["\0Exception\0trace", "\0Error\0trace"] as $property) {
                    if (is_array($value[$property] ?? null)) {
                        $value[$property] = array_filter(
                            $value[$property],
                            static fn ($frame): bool => str_starts_with($frame['class'] ?? '', 'Daktela\\DaktelaV6\\')
                        );
                    }
                }
            }
        }
        if (!is_array($value) || $depth > 12) {
            return '';
        }
        $text = '';
        foreach ($value as $key => $item) {
            $text .= (is_string($key) ? $key . "\n" : '') . self::strings($item, $depth + 1, $seen);
        }

        return $text;
    }
}

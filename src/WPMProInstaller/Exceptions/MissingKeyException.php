<?php namespace IgniteOnline\WPMProInstaller\Exceptions;

/**
 * Exception thrown if the ACF PRO key is not available in the environment
 */
class MissingKeyException extends \Exception
{
    public function __construct(
        $message = '',
        $code = 0,
        \Exception $previous = null
    ) {
        parent::__construct(
            'Could not find a key for WPM PRO. ' .
            'Please make it available via the environment variable ' .
            $message,
            $code,
            $previous
        );
    }
}

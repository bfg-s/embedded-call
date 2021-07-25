<?php

namespace Bfg\EmbeddedCall;

/**
 * Class EmbeddedCallExtend
 * @package Bfg\EmbeddedCall
 */
class EmbeddedCallExtend {

    /**
     * @var array
     */
    protected $extends = [];

    /**
     * @param  string  $name
     * @param mixed $value
     * @return $this
     */
    public function set(string $name, $value)
    {
        $this->{$name} = $value;

        return $this;
    }

    /**
     * @param  object|string  $class
     * @return static
     */
    public function extend($class)
    {
        if (is_string($class)) {

            $this->extends[] = new $class;

        } else if (is_object($class)) {

            $this->extends[] = $class;
        }

        return $this;
    }

    /**
     * @param $name
     * @param $arguments
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        foreach ($this->extends as $extend) {

            if (method_exists($extend, $name)) {

                return $extend->{$name}(...$arguments);
            }
        }
    }

    /**
     * @param  mixed  ...$params
     * @return static
     */
    public static function create(...$params)
    {
        return new static(...$params);
    }
}

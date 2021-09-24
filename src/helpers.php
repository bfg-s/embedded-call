<?php

if (! function_exists('is_functional_call')) {

    /**
     * @param mixed $subject
     * @return bool
     */
    function is_functional_call($subject)
    {
        return is_array($subject) && is_callable($subject) || $subject instanceof Closure;
    }
}

if (! function_exists('embedded_call')) {

    /**
     * @param  callable|array  $subject
     * @param  array  $arguments
     * @param  null  $throw_event
     * @return \Illuminate\Http\Resources\Json\JsonResource|mixed|null
     * @throws Throwable
     */
    function embedded_call(callable | array $subject, array $arguments = [], $throw_event = null)
    {
        return (new \Bfg\EmbeddedCall\EmbeddedCall($subject, $arguments, $throw_event))->call();
    }
}

if (! function_exists('resulted_event')) {

    /**
     * Dispatch an event with save a last true result of listener.
     * @param  object  $event
     * @return object
     */
    function resulted_event(object $event)
    {
        $event->result = event($event);

        if (count($event->result)) {
            $event->result = array_filter($event->result, fn ($i) => (bool) $i);
            $event->result = $event->result[array_key_last($event->result)] ?? null;
            $event->result = is_object($event->result) ? $event->result : (object) \Arr::wrap($event->result);
        }

        return $event->result;
    }
}

<?php

namespace Bfg\EmbeddedCall;

use Bfg\EmbeddedCall\EmbeddedAttributes\Action;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Class EmbeddedCall.
 * @package Bfg\EmbeddedCall
 */
class EmbeddedCall
{
    /**
     * @var \Closure|array
     */
    protected $subject;

    /**
     * @var array
     */
    protected $arguments;

    /**
     * 0 - Closure,
     * 1 - Object.
     *
     * @var string
     */
    protected $mode;

    /**
     * @var \ReflectionMethod|\ReflectionFunction
     */
    protected $ref;

    /**
     * @var array
     */
    protected $parameters = [];

    /**
     * @var array
     */
    protected $send_parameters = [];

    /**
     * @var array
     */
    protected $route_params = [];

    /**
     * @var \Closure
     */
    protected $throw_event;

    /**
     * @var mixed
     */
    protected $event_result = [];

    /**
     * @var JsonResource
     */
    protected $resource;

    /**
     * EmbeddedCall constructor.
     * @param  \Closure|array|object|string  $subject
     * @param  array  $arguments
     * @param  \Closure|array|null  $throw_event
     * @throws \Throwable
     */
    public function __construct($subject, array $arguments = [], $throw_event = null)
    {
        $this->arguments = $arguments;

        $this->throw_event = $throw_event;

        if (request()->route()) {
            $this->route_params = request()->route()->parameters();
        }

        if ($subject instanceof \Closure) {
            $this->subject = $subject;

            $this->mode = 0;
        } elseif (is_array($subject) && isset($subject[0]) && isset($subject[1])) {
            $this->subject = [
                is_string($subject[0]) ? app($subject[0]) : $subject[0],
                $subject[1],
            ];

            $this->mode = 1;
        } elseif (is_object($subject)) {
            $this->subject = [
                $subject,
                '__invoke',
            ];

            $this->mode = 1;
        } elseif (is_string($subject)) {
            $this->subject = [
                app($subject),
                '__invoke',
            ];

            $this->mode = 1;
        } else {
            $this->throw(new \Exception('Invalid subject of call'));
        }

        $this->makeRef();

        $this->makeParameters();
    }

    /**
     * Make reflection of the call data.
     * @throws \Throwable
     */
    protected function makeRef()
    {
        if ($this->mode === 0) {
            try {
                $this->ref = new \ReflectionFunction($this->subject);
            } catch (\Throwable $throwable) {
                $this->throw($throwable);
            }
        } elseif ($this->mode === 1) {
            try {
                $this->ref = (new \ReflectionClass($this->subject[0]))->getMethod($this->subject[1]);
            } catch (\Throwable $throwable) {
                $this->throw($throwable);
            }
        } else {
            $this->throw(new \Exception('Wrong mode for reflection'));
        }

        $this->catchAttributes();
    }

    protected function catchAttributes()
    {
        $attributes = $this->ref->getAttributes(Action::class);

        foreach ($attributes as $attribute) {
            try {
                $attributeClass = $attribute->newInstance();
            } catch (\Throwable) {
                continue;
            }

            if (! $attributeClass instanceof Action) {
                continue;
            }

            if ($attributeClass->request) {
                $this->arguments[$attributeClass->request] = app($attributeClass->request);
            }

            if ($attributeClass->event && app('events')->hasListeners($attributeClass->event)) {
                $this->arguments[$attributeClass->request] = app($attributeClass->event);

                $this->event_result = resulted_event($this->arguments[$attributeClass->request]);
            }

            $make_params = (bool) $this->event_result ? ['resource' => $this->event_result] : [];

            if ($attributeClass->resource) {
                $this->arguments[$attributeClass->resource] = app($attributeClass->resource, $make_params);

                if ($this->arguments[$attributeClass->resource] instanceof JsonResource) {
                    $this->resource = $this->arguments[$attributeClass->resource];
                }
            }
        }
    }

    /**
     * Make reflection parameters.
     */
    protected function makeParameters()
    {
        //if ($this->ref->getParameters()) dd($this->ref->getParameters());
        foreach ($this->ref->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof \ReflectionUnionType) {
                $type = $type->getTypes()[0];
            }

            list($class, $type, $nullable) = $parameter->hasType() ? (
            ! $type->isBuiltin() ?
                [$type->getName(), false, $type->allowsNull()] :
                [false, $type->getName(), $type->allowsNull()]
            ) : [false, false, false];

            $param = [
                'class' => $class,
                'type' => $type,
                'name' => $parameter->getName(),
                'nullable' => $nullable,
                'value' => null,
                'isVariadic' => $parameter->isVariadic()
            ];

            if ($parameter->isDefaultValueAvailable()) {
                $param['default'] = $parameter->getDefaultValue();
            }

            $this->parameters[] = $param;
        }

        $this->toPrepareParameters();
    }

    /**
     * To prepare parameters before call.
     */
    protected function toPrepareParameters()
    {
        foreach ($this->parameters as $key => $parameter) {
            if ($parameter['class'] && isset($this->arguments[$parameter['class']])) {

                $this->send_parameters[] = $this->arguments[$parameter['class']];
                unset($this->arguments[$parameter['class']]);

            } elseif (isset($this->arguments[$key])) {

                $this->send_parameters[] = $this->arguments[$key];
                unset($this->arguments[$key]);

            } elseif (isset($this->arguments[$parameter['name']])) {

                $this->send_parameters[] = $this->arguments[$parameter['name']];
                unset($this->arguments[$parameter['name']]);

            } elseif ($parameter['class']) {
                $this->send_parameters[] = $this->makeByClass($parameter, $key);
            } elseif ($parameter['isVariadic']) {
                $m = $this->makeByName($parameter, $key);
                if (is_array($m)) $this->send_parameters = array_merge($this->send_parameters, array_values($m));
            } else {
                $this->send_parameters[] = $this->makeByName($parameter, $key);
            }
        }
        foreach ($this->arguments as $k => $argument) {
            if (is_numeric($k)) $this->send_parameters[] = $argument;
        }
    }

    /**
     * @param  EmbeddedCallExtend  $class
     */
    protected function setGeneratorProps(EmbeddedCallExtend $class)
    {
        foreach (get_object_vars($class) as $key => $get_object_var) {
            if ($key == 'ARGS') {
                $class->{$key} = $this->arguments;
            } elseif (is_string($get_object_var) && isset($this->arguments[$get_object_var])) {
                //$class->{$key} = $this->arguments[$get_object_var];
                $class->set($key, $this->arguments[$get_object_var]);
            }
        }
    }

    /**
     * @param  array  $params
     * @param  int  $key
     * @return string
     */
    protected function makeByClass(array $params, int $key)
    {
        if (app()->has($params['class'])) {
            $class = app($params['class']);

            if ($class instanceof EmbeddedCallExtend) {
                $this->setGeneratorProps($class);
            }

            return $class;
        } elseif (class_exists($params['class'])) {
            if (request()->hasFile($params['name'])) {
                $r_data = request()->file($params['name']);
            } elseif (request()->has($params['name'])) {
                $r_data = request()->get($params['name']);
            } elseif (isset($this->route_params[$params['name']])) {
                $r_data = $this->route_params[$params['name']];
            }

            $make_params = (bool) $this->event_result ? ['resource' => $this->event_result] : [];

            $class = isset($r_data) && is_object($r_data) ? $r_data : app($params['class'], $make_params);

            if ($class instanceof EmbeddedCallExtend) {
                $this->setGeneratorProps($class);
            }

            $this->parameters[$key]['class'] = $class;

            if ($class instanceof JsonResource) {
                $this->resource = $class;
            }

            if (app('events')->hasListeners($params['class'])) {
                $this->event_result = resulted_event($class);
            }

            if ($class instanceof Model && isset($r_data) && is_numeric($r_data)) {
                $find_class = $class->find($r_data);
                if ($params['nullable']) {
                    $class = $find_class;
                } elseif ($find_class) {
                    $class = $find_class;
                }
            }

            return $class;
        }

        return null;
    }

    /**
     * @param  array  $params
     * @param  int  $key
     * @return mixed
     */
    protected function makeByName(array $params, int $key)
    {
        if (app()->has($params['name'])) {
            return app($params['name']);
        } elseif (isset($this->route_params[$params['name']])) {
            return $this->route_params[$params['name']];
        } elseif (request()->has($params['name'])) {
            return request($params['name']);
        } elseif (isset($params['default'])) {
            return $params['default'];
        }

        return null;
    }

    /**
     * @param  \Throwable  $throwable
     * @return false|mixed
     * @throws \Throwable
     */
    protected function throw(\Throwable $throwable)
    {
        if (is_array($this->throw_event) && isset($this->throw_event[0]) && isset($this->throw_event[1])) {
            return call_user_func($this->throw_event, $throwable);
        } elseif ($this->throw_event instanceof \Closure) {
            return ($this->throw_event)($throwable);
        } else {
            throw $throwable;
        }
    }

    /**
     * @return $this|false|JsonResource|mixed|null
     * @throws \Throwable
     */
    public function call()
    {
        try {
            $result = call_user_func_array($this->subject, $this->send_parameters);
        } catch (\Throwable $throwable) {
            return $this->throw($throwable);
        }

        return $result ?: ($this->resource ?: null);
    }

    /**
     * EmbeddedCall maker.
     * @param $subject
     * @param  array  $arguments
     * @param  null  $throw_event
     * @return EmbeddedCall|false|JsonResource|mixed|null
     * @throws \Throwable
     */
    public static function make($subject, array $arguments = [], $throw_event = null)
    {
        return (new static($subject, $arguments, $throw_event))->call();
    }
}

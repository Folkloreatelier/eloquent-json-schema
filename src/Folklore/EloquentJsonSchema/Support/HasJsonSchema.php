<?php

namespace Folklore\EloquentJsonSchema\Support;

use Folklore\EloquentJsonSchema\JsonSchemaObserver;
use Folklore\EloquentJsonSchema\Contracts\JsonSchemaValidator;
use Folklore\EloquentJsonSchema\ValidationException;
use Folklore\EloquentJsonSchema\Contracts\ReducerGetter;
use Folklore\EloquentJsonSchema\Contracts\ReducerSetter;
use Folklore\EloquentJsonSchema\Contracts\ReducerSaver;

trait HasJsonSchema
{
    protected $jsonSchemas = [];

    protected $jsonSchemasReducers = [];

    public static function bootHasJsonSchema()
    {
        static::observe(JsonSchemaObserver::class);
    }

    public static function getGlobalJsonSchemaReducers()
    {
        return config('json-schema.reducers', []);
    }

    public function validateJsonSchemaAttributes()
    {
        $validator = app(JsonSchemaValidator::class);
        $attributes = $this->getJsonSchemaAttributes();
        foreach ($attributes as $key) {
            $schema = $this->getAttributeJsonSchema($key);
            $value = $this->getAttributeValue($key);
            if (!$validator->validateSchema($value, $schema)) {
                throw new ValidationException($validator->getMessages(), $key);
            }
        }
    }

    public function saveJsonSchemaAttributes()
    {
        $attributes = $this->getJsonSchemaAttributes();
        foreach ($attributes as $key) {
            $schema = $this->getAttributeJsonSchema($key);
            $value = $this->getAttributeValue($key);
            $this->callJsonSchemaReducers($schema, 'save', $value);
        }
    }

    /**
     * Call the reducers for a given method
     *
     * @param  \Folklore\EloquentJsonSchema\Contracts\JsonSchema $schema
     * @param  string $method
     * @param  mixed $value
     * @return mixed
     */
    protected function callJsonSchemaReducers($schema, $method, $value)
    {
        $interfaces = [
            'get' => ReducerGetter::class,
            'set' => ReducerSetter::class,
            'save' => ReducerSaver::class
        ];
        if (!isset($interfaces[$method])) {
            throw new \Exception("Unknown method $method");
        }
        $interface = $interfaces[$method];

        $reducers = array_merge(
            static::getGlobalJsonSchemaReducers(),
            array_where(array_values($this->getJsonSchemaReducers()), function ($reducer) {
                return !is_array($reducer);
            }),
            $schema->getReducers()
        );

        $nodesCollection = $schema->getNodesFromData($value);
        return $nodesCollection->reduce(function ($value, $node) use ($reducers, $interface, $method) {
            foreach ($reducers as $reducer) {
                $reducer = is_string($reducer) ? app($reducer) : $reducer;
                if ($reducer instanceof $interface) {
                    $value = $reducer->{$method}($this, $node, $value);
                } elseif (is_callable($reducer)) {
                    $value = call_user_func_array($reducer, [$this, $node, $value]);
                }
            }
            return $value;
        }, $value);
    }

    /**
     * Cast the given attribute to JSON Schema.
     *
     * @param  mixed  $value
     * @param  \Folklore\EloquentJsonSchema\Contracts\JsonSchema  $schema
     * @return string
     */
    protected function castAttributeAsJsonSchema($key, $value)
    {
        $schema = $this->getAttributeJsonSchema($key);
        return $this->callJsonSchemaReducers($schema, 'set', $value);
    }

    /**
     * Decode the given JSON Schema back into an array or object.
     *
     * @param  string  $value
     * @param  \Folklore\EloquentJsonSchema\Contracts\JsonSchema  $schema
     * @return mixed
     */
    public function fromJsonSchema($value, $schema)
    {
        $value = $this->fromJson($value);
        return $this->callJsonSchemaReducers($schema, 'get', $value);
    }

    /**
     * Cast an attribute to a native PHP type.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return mixed
     */
    protected function castAttribute($key, $value)
    {
        $value = parent::castAttribute($key, $value);
        switch ($this->getCastType($key)) {
            case 'json_schema':
                $schema = $this->getAttributeJsonSchema($key);
                return !is_null($schema) ?
                    $this->fromJsonSchema($value, $schema) : $this->fromJson($value);
            default:
                return $value;
        }
    }

    /**
     * Set a given attribute on the model.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return $this
     */
    public function setAttribute($key, $value)
    {
        if ($this->hasJsonSchema($key)) {
            $value = $this->castAttributeAsJsonSchema($key, $value);
            if (method_exists($this, 'castAttributeAsJson')) {
                $value = $this->castAttributeAsJson($key, $value);
            } else {
                $value = $this->asJson($value);
            }
        }
        return parent::setAttribute($key, $value);
    }

    /**
     * Get the JSON Schema for an attribute
     *
     * @param  string  $key
     * @return \Folklore\EloquentJsonSchema\Contracts\JsonSchema|null
     */
    public function getAttributeJsonSchema($key)
    {
        $schemas = $this->getJsonSchemas();
        if (!array_key_exists($key, $schemas)) {
            return null;
        }

        $schema = is_string($schemas[$key]) ? app($schemas[$key]) : $schemas[$key];

        // Add the attributes reducers to the schema
        $reducers = $this->getAttributeJsonSchemaReducers($key);
        foreach ($reducers as $reducer) {
            $schema->addReducer($reducer);
        }

        return $schema;
    }

    /**
     * Get the JSON schemas reducers for an attribute.
     *
     * @param  string  $key
     * @return array
     */
    public function getAttributeJsonSchemaReducers($key)
    {
        $reducers = $this->getJsonSchemaReducers();
        return array_key_exists($key, $reducers) ? (array)$reducers[$key] : [];
    }

    /**
     * Get the JSON schemas attributes
     *
     * @return array
     */
    public function getJsonSchemaAttributes()
    {
        return array_reduce(array_keys($this->casts), function($attributes, $key) {
            if ($this->getCastType($key) === 'json_schema') {
                $attributes[] = $key;
            }
            return $attributes;
        }, []);
    }

    /**
     * Determine whether an attribute has a JSON Schema
     *
     * @param  string  $key
     * @return bool
     */
    public function hasJsonSchema($key)
    {
        return array_key_exists($key, $this->getJsonSchemas());
    }

    /**
     * Get the JSON schemas array.
     *
     * @return array
     */
    public function getJsonSchemas()
    {
        return $this->jsonSchemas;
    }

    /**
     * Set the JSON schemas.
     *
     * @param  array  $schemas
     * @return $this
     */
    public function setJsonSchemas($schemas)
    {
        $this->jsonSchemas = $schemas;
        return $this;
    }

    /**
     * Get the JSON schemas reducers.
     *
     * @return array
     */
    public function getJsonSchemaReducers()
    {
        return $this->jsonSchemasReducers;
    }

    /**
     * Set the JSON schemas reducers.
     *
     * @param  array|string  $key
     * @param  array|null  $reducers
     * @return $this
     */
    public function setJsonSchemaReducers($key, $reducers = null)
    {
        if (is_null($reducers)) {
            $this->jsonSchemasReducers = $reducers;
        } else {
            $this->jsonSchemasReducers[$key] = $reducers;
        }
        return $this;
    }
}

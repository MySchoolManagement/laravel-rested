<?php
namespace Rested\Laravel\Transforms;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\Facades\Validator;
use Rested\Definition\Embed;
use Rested\Definition\SetterField;
use Rested\Transforms\CompiledTransformMappingInterface;
use Rested\Transforms\DefaultTransform;
use Rested\Transforms\TransformInterface;

class LaravelTransform extends DefaultTransform
{

    /**
     * {@inheritdoc}
     */
    protected function getEmbedValue(
        TransformInterface $transform,
        CompiledTransformMappingInterface $transformMapping,
        Embed $embed,
        $instance)
    {
        if ($this->isEloquentModel($instance) === true) {
            $userData = $embed->getUserData();

            if (array_key_exists('rel', $userData) === true) {
                return $instance->getAttribute($embed->getUserData()['rel']);
            }
        }

        return parent::getEmbedValue($transform, $transformMapping, $embed, $instance);
    }

    /**
     * {@inheritdoc}
     */
    protected function getFieldValue($instance, $callback)
    {
        if ($this->isEloquentModel($instance) === true) {
            return $instance->getAttribute($callback);
        }

        return parent::getFieldValue($instance, $callback);
    }

    /**
     * {@inheritdoc}
     */
    protected function isEloquentModel($instance)
    {
        return is_subclass_of($instance, 'Illuminate\Database\Eloquent\Model');
    }

    /**
     * {@inheritdoc}
     */
    protected function setFieldValue($instance, $callback, $value)
    {
        if ($this->isEloquentModel($instance) === true) {
            $instance->setAttribute($callback, $value);
        } else {
            parent::setFieldValue($instance, $callback, $value);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function validate(CompiledTransformMappingInterface $transformMapping, array $input)
    {
        $rules = [];
        $messages = [];

        foreach ($transformMapping->getFields(SetterField::OPERATION) as $field) {
            $parameters = $field->getValidationParameters();

            // add a validator for the data type of this field
            $parameters .= '|' . $field->getTypeValidatorName();

            $rules[$field->getName()] = $parameters;
        }

        $validator = Validator::make($input, $rules);

        if ($validator->fails() === true) {
            $failed = $validator->failed();
            $validationMessages = $validator->messages();
            $messages = [];

            foreach ($failed as $field => $rules) {
                $messages[$field] = [];

                foreach ($rules as $rule => $parameters) {
                    $messages[$field][$rule] = $validationMessages->first($field);
                }
            }
        }

        return $messages;
    }
}

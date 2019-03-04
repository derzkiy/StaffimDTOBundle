<?php

namespace Staffim\DTOBundle\DTO;

use Staffim\DTOBundle\Collection\EmbeddedModelCollection;
use Staffim\DTOBundle\DTO\Model\DTOInterface;
use Staffim\DTOBundle\Exception\MappingException;
use Staffim\DTOBundle\Request\MappingConfigurator;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\PropertyAccess\Exception\NoSuchPropertyException;

use Staffim\DTOBundle\Collection\ModelIteratorInterface;
use Staffim\DTOBundle\Model\ModelInterface;
use Staffim\DTOBundle\Model\EmbeddedModelInterface;
use Staffim\DTOBundle\Event\PostMapEvent;

class Mapper
{
    /**
     * @var \Symfony\Component\PropertyAccess\PropertyAccessorInterface
     */
    private $propertyAccessor;

    /**
     * @var \Staffim\DTOBundle\Request\MappingConfigurator
     */
    private $mappingConfigurator;

    /**
     * @var \Staffim\DTOBundle\DTO\Factory
     */
    private $factory;

    /**
     * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * @param \Symfony\Component\PropertyAccess\PropertyAccessorInterface $propertyAccessor
     * @param \Staffim\DTOBundle\Request\MappingConfigurator $mappingConfigurator
     * @param \Staffim\DTOBundle\DTO\Factory $factory
     * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
     */
    public function __construct(
        PropertyAccessorInterface $propertyAccessor,
        Factory $factory,
        MappingConfigurator $mappingConfigurator = null,
        EventDispatcherInterface $eventDispatcher = null
    ) {
        $this->propertyAccessor = $propertyAccessor;
        $this->factory = $factory;
        $this->mappingConfigurator = $mappingConfigurator;
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * Map domain model to DTO.
     *
     * @param \Staffim\DTOBundle\Model\ModelInterface $model
     * @return object $dto
     */
    public function map(ModelInterface $model)
    {
        return $this->doMap($model);
    }

    /**
     * Map iterator of models to array with DTO.
     *
     * @param \Staffim\DTOBundle\Collection\ModelIteratorInterface $collection
     * @return array
     */
    public function mapCollection(ModelIteratorInterface $collection)
    {
        return $this->doMapCollection($collection);
    }

    /**
     * @param \Staffim\DTOBundle\Collection\ModelIteratorInterface $collection
     * @param array $parentPropertyPath
     * @return array
     * @throws \Staffim\DTOBundle\Exception\MappingException
     */
    private function doMapCollection(ModelIteratorInterface $collection, array $parentPropertyPath = [])
    {
        $result = [];
        foreach ($collection as $model) {
            if (!($model instanceof ModelInterface)) {
                throw new MappingException('Class \''. get_class($model) .'\' should implement \\Staffim\\DTOBundle\\Model\\ModelInterface');
            }

            $result[] = $this->doMap($model, $parentPropertyPath);
        }

        return $result;
    }

    /**
     * @param \Staffim\DTOBundle\Model\ModelInterface $model
     * @param array $parentPropertyPath
     * @return \Staffim\DTOBundle\DTO\Model\DTOInterface
     */
    private function doMap(ModelInterface $model, array $parentPropertyPath = [])
    {
        $dto = $this->factory->create($model);
        $properties = get_object_vars($dto);

        // @todo trigger pre event

        foreach ($properties as $propertyName => $property) {
            $this->updateProperty($model, $dto, $propertyName, $parentPropertyPath);
        }

        if ($this->eventDispatcher) {
            $event = new PostMapEvent($model, $dto);
            $modelClassParts = explode('\\', get_class($model));
            $modelName = \Doctrine\Common\Inflector\Inflector::tableize(end($modelClassParts));
            $this->eventDispatcher->dispatch('dto.' . $modelName . '.post_map', $event);
        }

        return $dto;
    }

    /**
     * @param mixed $model
     * @param \Staffim\DTOBundle\DTO\Model\DTOInterface $dto
     * @param string $propertyName
     * @param array $fullPropertyPath
     */
    private function updateProperty($model, DTOInterface $dto, $propertyName, array $fullPropertyPath = [])
    {
        $fullPropertyPath[] = $propertyName;

        if (!$this->isPropertyVisible($fullPropertyPath)) {
            $modelValue = UnknownValue::create();
        } else {
            try {
                $modelValue = $this->propertyAccessor->getValue($model, $propertyName);
            } catch (NoSuchPropertyException $e) {
                return;
            }
        }

        $this->propertyAccessor->setValue(
            $dto,
            $propertyName,
            $this->convertValue($modelValue, $fullPropertyPath)
        );
    }

    /**
     * @param array $path
     * @return bool
     */
    private function isPropertyVisible(array $path)
    {
        return !$this->mappingConfigurator || $this->mappingConfigurator->isPropertyVisible($path);
    }

    /**
     * @param array $path
     * @return bool
     */
    private function hasRelation(array $path)
    {
        return $this->mappingConfigurator && $this->mappingConfigurator->hasRelation($path);
    }

    /**
     * @param mixed $modelValue
     * @param array $propertyPath
     * @return array|object
     */
    private function convertValue($modelValue, array $propertyPath)
    {
        if ($modelValue instanceof EmbeddedModelCollection) {
            $value = $this->doMapCollection($modelValue, $propertyPath);
        } elseif (is_array($modelValue) || (is_object($modelValue) && ($modelValue instanceof \Traversable))) {
            $value = [];
            foreach ($modelValue as $key => $modelValueItem) {
                $value[$key] = $this->convertValue($modelValueItem, $propertyPath);
            }
        } elseif ($modelValue instanceof ModelInterface) {
            if ($this->hasRelation($propertyPath) || $modelValue instanceof EmbeddedModelInterface) {
                $value = $this->doMap($modelValue, $propertyPath);
            } else {
                $value = $modelValue->getId();
            }
        } else {
            $value = $modelValue;
        }

        return $value;
    }
}

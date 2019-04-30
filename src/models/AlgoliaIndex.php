<?php
/**
 * Scout plugin for Craft CMS 3.x.
 *
 * Craft Scout provides a simple solution for adding full-text search to your entries. Scout will automatically keep your search indexes in sync with your entries.
 *
 * @link      https://rias.be
 *
 * @copyright Copyright (c) 2017 Rias
 */

namespace rias\scout\models;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\Model;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use League\Fractal\Manager;
use League\Fractal\Resource\Item;
use League\Fractal\Serializer\ArraySerializer;
use League\Fractal\TransformerAbstract;
use rias\scout\ElementTransformer;
use rias\scout\jobs\DeIndexElement;
use rias\scout\jobs\IndexElement;

/**
 * @author    Rias
 *
 * @since     0.1.0
 */
class AlgoliaIndex extends Model
{
    /* @var string */
    public $indexName;

    /* @var string */
    public $elementType;

    /* @var mixed */
    public $criteria;

    /* @var array */
    public $splitElementIndex = [];

    /**
     * @var callable|string|array|TransformerAbstract The transformer config, or an actual transformer object
     */
    public $transformer = ElementTransformer::class;

    /**
     * Determines if the supplied element can be indexed in this index.
     *
     * @param $element Element
     *
     * @return bool
     */
    public function canIndexElement(Element $element)
    {
        if (isset($this->criteria['site']) && $element->site->handle !== $this->criteria['site']) {
            return false;
        }

        if (isset($this->criteria['siteId']) && (int) $element->site->id !== (int) $this->criteria['siteId']) {
            return false;
        }

        return $this->getElementQuery($element)->count();
    }

    /**
     * Determines if the supplied element can be deindexed in this index.
     *
     * @param $element Element
     *
     * @return bool
     */
    public function canDeindexElement(Element $element)
    {
        if (isset($this->criteria['siteId']) && (int) $element->site->id !== (int) $this->criteria['siteId']) {
            return false;
        }

        return $this->getElementQuery($element)->count() === 0 || $this->getElementQuery($element)->status(null)->count() > 0;
    }

    /**
     * Transforms the supplied element using the transformer method in config.
     *
     * @param $element Element
     *
     * @throws \yii\base\InvalidConfigException
     *
     * @return mixed
     */
    public function transformElement(Element $element)
    {
        $transformer = $this->getTransformer();
        $resource = new Item($element, $transformer);

        $fractal = new Manager();
        $fractal->setSerializer(new ArraySerializer());

        $data = $fractal->createData($resource)->toArray();
        // Make sure the objectID is set (and unique) for Algolia
        $data['objectID'] = $this->getSiteElementId($element);

        return $data;
    }

    /**
     * Adds or removes the supplied element from the index.
     *
     * @param $elements array
     *
     * @throws \yii\base\InvalidConfigException
     */
    public function indexElements($elements)
    {
        foreach ($elements as $element) {
            if ($this->elementType === get_class($element)) {
                if ($this->canIndexElement($element)) {
                    $this->indexElement($element);
                } elseif ($this->canDeindexElement($element)) {
                    $this->deindexElement($element);
                }
            }
        }
    }

    /**
     * Adds or removes the supplied element from the index.
     *
     * @param $elements array
     *
     * @throws \yii\base\InvalidConfigException
     */
    public function deindexElements($elements)
    {
        foreach ($elements as $element) {
            if ($this->elementType === get_class($element) && $this->canDeindexElement($element)) {
                $this->deindexElement($element);
            }
        }
    }

    protected function indexElement($element)
    {
        $elementConfigs = [];
        if (count($this->splitElementIndex) > 0) {
            $elementConfigs = $this->splitElementConfig($element);
        } else {
            $elementConfigs[] = [
                'indexName' => $this->indexName,
                'element'   => $this->transformElement($element),
            ];
        }

        foreach ($elementConfigs as $elementConfig) {
            Craft::$app->queue->push(new IndexElement($elementConfig));
        }
    }

    /**
     * @param $element
     */
    public function deindexElement($element)
    {
        $config = [
            'indexName' => $this->indexName,
        ];

        $config['objectID'] = $this->getSiteElementId($element);
        if (count($this->splitElementIndex) > 0) {
            $config['distinctId'] = $this->getSiteElementId($element);
        }

        Craft::$app->queue->push(new DeIndexElement($config));
    }

    /**
     * @param $element
     */
    protected function splitElementConfig($element)
    {
        $transformedElement = $this->transformElement($element);
        $transformedElement['distinctId'] = $this->getSiteElementId($element);

        $elementConfigs = [];
        $i = 1;
        foreach ($this->splitElementIndex as $indexElement) {
            $transformedElement['objectID'] = $this->getSiteElementId($element).'_'.$i;

            if ($transformedElement[$indexElement] !== null) {
                if (is_array($transformedElement[$indexElement])) {
                    foreach ($transformedElement[$indexElement] as $key => $value) {
                        if ((is_array($value) && count($value) > 0) || (!is_array($value) && $value !== null)) {
                            $transformedElement['objectID'] = $this->getSiteElementId($element).'_'.$i;

                            $splitElement = array_filter($transformedElement, function ($item) {
                                return !in_array($item, $this->splitElementIndex, true);
                            }, ARRAY_FILTER_USE_KEY);
                            $splitElement[$indexElement] = $value;

                            $elementConfigs[] = [
                                'indexName' => $this->indexName,
                                'element'   => $splitElement,
                            ];

                            $i++;
                        }
                    }
                } else {
                    $elementConfigs[] = [
                        'indexName' => $this->indexName,
                        'element'   => array_filter($transformedElement, function ($item) use ($indexElement) {
                            return !(in_array($item, $this->splitElementIndex, true) && $item !== $indexElement);
                        }, ARRAY_FILTER_USE_KEY),
                    ];
                }
            }

            $i++;
        }

        return $elementConfigs;
    }

    /**
     * Returns the transformer.
     *
     * @throws \yii\base\InvalidConfigException
     *
     * @return callable|TransformerAbstract|object
     */
    protected function getTransformer()
    {
        if (is_callable($this->transformer) || $this->transformer instanceof TransformerAbstract) {
            return $this->transformer;
        }

        return Craft::createObject($this->transformer);
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            'indexName'   => 'string',
            'elementType' => [
                'string',
                'default' => Entry::class,
            ],
        ];
    }

    /**
     * Returns the element query based on [[elementType]] and [[criteria]].
     *
     * @param Element $element
     *
     * @return ElementQueryInterface
     */
    public function getElementQuery(Element $element = null): ElementQueryInterface
    {
        /** @var string|ElementInterface $elementType */
        $elementType = $this->elementType;
        $query = $elementType::find();
        if (!is_null($element)) {
            $query->id($element->id);
        }
        Craft::configure($query, $this->criteria);

        return $query;
    }

    protected function getSiteElementId(Element $element)
    {
        return $element->siteId.'_'.$element->id;
    }
}

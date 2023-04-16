<?php
namespace Rails\ActiveRecord\Base\Methods;

use Rails;
use Rails\ActiveRecord\Exception;

/**
 * Attributes are properties that correspond to a column in the table.
 * However, these "properties" are actually stored in the actual instance
 * property $attributes.
 *
 * Models *should* define getters and setters for each attribute, but they
 * can be called overloadingly (see Rails\ActiveRecord\Base::__call()).
 * I say *should* because it is said that overloading is bad for performance.
 *
 * For convenience (I'd say), in the case of getter methods, the "get" prefix is
 * omitted (except for methods that require a parameter, like getAttribute($attrName)),
 * so the expected name of the getter methods is the camel-cased name of the corresponding attribute,
 * for example createdAt(). This method can either check itself if the index for the attribute exists
 * in the $attributes array and return it, or simply return getAttribute($attrName).
 *
 * Setter methods have the "set" prefix, and they should set the new value in the $attributes array.
 */
trait AttributeMethods
{
    /**
     * Calling attributes throgh magic methods would be like:
     * $post->createdAt()
     * The corresponding column for this attribute would be "created_at",
     * therefore, the attribute name will be converted.
     * For some cases, to disable the camel to lower conversion,
     * this property can be set to false.
     */
    static protected $convertAttributeNames = true;

    /**
     * Expected to hold only the model's attributes.
     */
    protected $attributes = [];

    /**
     * Holds data grabbed from the database for models
     * without a primary key, to be able to update them.
     * Hoever, models should always have a primary key.
     */
    private $storedAttributes = array();

    private $changedAttributes = array();

    static public function convertAttributeNames($value = null)
    {
        if (null !== $value) {
            static::$convertAttributeNames = (bool) $value;
        } else {
            return static::$convertAttributeNames;
        }
    }

    static public function isAttribute($name)
    {
        // if (!Rails::config()->ar2) {
        // return static::table()->columnExists(static::properAttrName($name));
        // } else {
        return static::table()->columnExists($name);
        // }
    }

    /**
     * This method allows to "overloadingly" get attributes this way:
     * $model->parentId; instead of $model->parent_id.
     */
    static public function properAttrName($name)
    {
        if (static::convertAttributeNames()) {
            $name = \Rails::services()->get('inflector')->underscore($name);
        }
        return $name;
    }

    /**
     * This method is similar to setAttribute, but goes beyond attributes.
     * Cheks if the property named $propName is either an attribute,
     * or a setter exists for it, or it's a public property.
     *
     * @return void
     * @throw Rails\ActiveRecord\Exception\RuntimeException
     */
    public function setProperty($propName, $value)
    {
        if (self::isAttribute($propName)) {
            $this->setAttribute($propName, $value);
        } else {
            if ($setterName = $this->setterExists($propName)) {
                $this->$setterName($value);
            } elseif (self::hasPublicProperty($propName)) {
                $this->$propName = $value;
            } else {
                throw new Exception\RuntimeException(
                    sprintf("Can't write unknown property '%s' for model %s", $propName, get_called_class())
                );
            }
        }
    }

    /**
     * This method is similar to getAttribute, but goes beyond attributes.
     * Cheks if the property named $propName is either an attribute,
     * or a getter exists for it, or it's a public property.
     *
     * @return mixed
     * @throw Rails\ActiveRecord\Exception\RuntimeException
     */
    public function getProperty($propName)
    {
        if (self::isAttribute($propName)) {
            return $this->getAttribute($propName);
        } else {
            if ($getterName = $this->getterExists($propName)) {
                return $this->$getterName();
            } elseif (self::hasPublicProperty($propName)) {
                return $this->$propName;
            } else {
                throw new Exception\RuntimeException(
                    sprintf("Can't read unknown attribute '%s' for model %s", $propName, get_called_class())
                );
            }
        }
    }

    /**
     * @throw Exception\InvalidArgumentException
     */
    public function getAttribute($name)
    {
        if (array_key_exists($name, $this->attributes)) {
            return $this->attributes[$name];
        } elseif (static::table()->columnExists($name)) {
            return null;
        }

        throw new Exception\InvalidArgumentException(
            sprintf("Trying to get non-attribute '%s' from model %s", $name, get_called_class())
        );
    }

    public function setAttribute($name, $value)
    {
        if (!self::isAttribute($name)) {
            throw new Exception\InvalidArgumentException(
                sprintf("Trying to set non-attribute '%s' for model %s", $name, get_called_class())
            );
        }

        $this->setChangedAttribute($name, $value);

        # If setter exists for this attribute, it will have to set the attribute itself.
        if ($setter = $this->setterExists($name)) {
            $this->$setter($value);
        } else {
            $this->attributes[$name] = $value;
        }

        return $this;
    }

    public function issetAttribute($name)
    {
        if (!self::isAttribute($name)) {
            throw new Exception\InvalidArgumentException(
                sprintf("'%s' isn't an attribute for model %s", $name, get_called_class())
            );
        }

        return isset($this->attributes[$name]);
    }

    public function attributes()
    {
        return $this->attributes;
    }

    /**
     * Add/change attributes to model
     *
     * Filters protected attributes of the model.
     * Also calls the "getAttribute()" method, if exists, of the model,
     * in case extra operation is needed when changing certain attribute.
     * It's intended to be an equivalent to "def attribute=(val)" in rails.
     * E.g. "is_held" for post model.
     *
     * @see _run_setter()
     */
    public function assignAttributes(array $attrs, array $options = [])
    {
        if (!$attrs) {
            return;
        }

        if (empty($options['without_protection'])) {
            $this->filterProtectedAttributes($attrs);
        }

        foreach ($attrs as $attrName => $value) {
            $this->setProperty($attrName, $value);
        }
    }

    public function updateAttributes(array $attrs)
    {
        $this->assignAttributes($attrs);

        if (!$this->_validate_data('save')) {
            return false;
        }

        return $this->runCallbacks('save', function () {
            return $this->runCallbacks('update', function () {
                return $this->_save_do();
            });
        });
    }

    /**
     * The changedAttributes array is filled upon updating a record.
     * When updating, the stored data of the model is retrieved and checked
     * against the data that will be saved. If an attribute changed, the old value
     * is stored in this array.
     *
     * Calling a method that isn't defined, ending in Changed, for example nameChanged() or
     * categoryIdChanged(), is the same as calling attributeChanged('name') or
     * attributeChanged('category_id').
     *
     * @return bool
     * @see attributeWas()
     */
    public function attributeChanged($attr)
    {
        return array_key_exists($attr, $this->changedAttributes);
    }

    /**
     * This method returns the previous value of an attribute before updating a record. If
     * it was not changed, returns null.
     */
    public function attributeWas($attr)
    {
        return $this->attributeChanged($attr) ?
            $this->changedAttributes[$attr] :
            $this->getAttribute($attr);
    }

    public function changedAttributes()
    {
        return $this->changedAttributes;
    }

    public function clearChangedAttributes()
    {
        $this->changedAttributes = [];
    }

    /**
     * List of the attributes that can't be changed in the model through
     * assignAttributes().
     * If both attrAccessible() and attrProtected() are present in the model,
     * only attrAccessible() will be used.
     *
     * Return an empty array so no attributes are protected (except the default ones).
     */
    protected function attrProtected()
    {
        return null;
    }

    /**
     * List of the only attributes that can be changed in the model through
     * assignAttributes().
     * If both attrAccessible() and attrProtected() are present in the model,
     * only attrAccessible() will be used.
     *
     * Return an empty array so no attributes are accessible.
     */
    protected function attrAccessible()
    {
        return null;
    }

    /**
     * Store changed attribute value.
     * $attributes array starts empty. When registering an attribute's $newValue,
     * if the attribute isn't found in the $attributes array (which means it doesn't
     * have any value yet), nothing happens; the $newValue will be considered as the
     * "initial" value. If a the value of this attribute is changed again, then the
     * "initial" value will be stored as the original value in $changedAttributes.
     * Then, if another new value is set, and this value equals to the original value,
     * it's considered as the attribute hasn't changed, so it's removed from the
     * changedAttributes array.
     */
    protected function setChangedAttribute($attrName, $newValue)
    {
        if (!$this->attributeChanged($attrName)) {
            if (array_key_exists($attrName, $this->attributes)) {
                $oldValue = $this->getAttribute($attrName);
                if ((string) $newValue != (string) $oldValue) {
                    $this->changedAttributes[$attrName] = $oldValue;
                }
            }
        } elseif ((string) $newValue == (string) $this->changedAttributes[$attrName]) {
            unset($this->changedAttributes[$attrName]);
        }
    }

    private function filterProtectedAttributes(&$attributes)
    {
        # Default protected attributes
        $default_columns = ['created_at', 'updated_at', 'created_on', 'updated_on'];

        if ($pk = static::table()->primaryKey()) {
            $default_columns[] = $pk;
        }

        $default_protected = array_fill_keys(array_merge($default_columns, $this->_associations_names()), true);
        $attributes = array_diff_key($attributes, $default_protected);

        if (is_array($attrs = $this->attrAccessible())) {
            $attributes = array_intersect_key($attributes, array_fill_keys($attrs, true));
        } elseif (is_array($attrs = $this->attrProtected())) {
            $attributes = array_diff_key($attributes, array_fill_keys($attrs, true));
        }
    }

    private function setDefaultAttributes()
    {
        $this->attributes = self::table()->columnDefaults();
    }
}
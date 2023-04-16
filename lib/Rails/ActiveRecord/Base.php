<?php
namespace Rails\ActiveRecord;

use PDO;
use Rails;
use Rails\ActiveRecord\ActiveRecord;
use Rails\ActiveRecord\Base\Methods;
use Rails\ActiveRecord\Validation\Validator;
use Rails\ActiveModel;

abstract class Base
{
    use Methods\CounterMethods, Methods\RelationMethods, Methods\ScopingMethods,
        Methods\AttributeMethods, Methods\ModelSchemaMethods, Methods\AssociationMethods;

    /**
     * ActiveRecord_Registry instance.
     */
    static private $registry;

    /**
     * Instance of Table for each model.
     */
    static private $tables = [];

    static private $cachedReflections = [];

    /**
     * Flag to prevent calling init() for some cases.
     */
    static private $preventInit = false;

    /**
     * Flag to prevent setting default attributes.
     */
    static private $skipDefaultAttributes = false;

    /**
     * ActiveModel\Errors instance.
     */
    private $errors;

    /**
     * @var bool
     */
    private $isNewRecord = true;

    static public function __callStatic($method, $params)
    {
        if ($rel = static::scope($method, $params))
            return $rel;

        throw new Exception\BadMethodCallException(
            sprintf("Call to undefined static method %s::%s", get_called_class(), $method)
        );
    }

    static public function create(array $attrs, array $options = [])
    {
        $new_model = new static();
        $new_model->assignAttributes($attrs, $options);
        $new_model->_create_do();
        return $new_model;
    }

    /**
     * Can receive parameters that will be directly passed to where();
     */
    static public function destroyAll()
    {
        $models = call_user_func_array(get_called_class() . '::where', func_get_args())->take();
        foreach ($models as $m) {
            $m->destroy();
        }
        return $models;
    }

    # TODO
    static public function deleteAll(array $conds)
    {

    }

    /**
     * Finds model by id and updates it.
     *
     * @param string|array $id
     * @param array        $attrs
     */
    static public function update($id, array $attrs)
    {
        if (is_array($id)) {
            foreach ($id as $k => $i) {
                static::update($i, $attrs[$k]);
            }
        } else {
            $object = static::find($id);
            $object->updateAttributes($attrs);
            return $object;
        }
    }

    /**
     * Searches if record exists by id.
     *
     * How to use:
     * Model::exists(1);
     * Model::exists(1, 2, 3);
     * Model::exists([1, 2, 3]);
     */
    static public function exists($params)
    {
        $query = self::none();

        if (ctype_digit((string) $params)) {
            $query->where(['id' => $params]);
        } else {
            if (is_array($params))
                $query->where('id IN (?)', $params);
            else
                $query->where('id IN (?)', func_get_args());
        }

        return $query->exists();
    }

    static public function countBySql()
    {
        $stmt = call_user_func_array([static::connection(), 'executeSql'], func_get_args());
        if (ActiveRecord::lastError())
            return false;

        $rows = $stmt->fetchAll(PDO::FETCH_NUM);

        if (isset($rows[0][0]))
            return (int) $rows[0][0];
        else
            return false;
    }

    static public function maximum($attr)
    {
        return self::connection()->selectValue('SELECT MAX(' . $attr . ') FROM ' . static::tableName());
    }

    static public function count()
    {
        return self::connection()->selectValue('SELECT COUNT(*) FROM `' . self::tableName() . '`');
    }

    static public function I18n()
    {
        return Rails::application()->I18n();
    }

    static public function transaction($params = [], \Closure $block = null)
    {
        self::connection()->transaction($params, $block);
    }

    static public function connectionName()
    {
        return null;
    }

    /**
     * If called class specifies a connection, such connection will be returned.
     */
    static public function connection()
    {
        $name = static::connectionName();
        return ActiveRecord::connection($name);
    }

    /**
     * Public properties declared in a model class can be used to avoid defining
     * setter and getter methods; the value will be set/get directly to/from it.
     */
    static public function hasPublicProperty($propName)
    {
        $reflection = self::getReflection();
        return $reflection->hasProperty($propName) && $reflection->getProperty($propName)->isPublic();
    }

    /**
     * It is sometimes required an empty collection.
     */
    static public function emptyCollection()
    {
        return new Collection();
    }

    static protected function cn()
    {
        return get_called_class();
    }

    static protected function _registry()
    {
        if (!self::$registry)
            self::$registry = new Registry();
        return self::$registry->model(get_called_class());
    }

    /**
     * Creates and returns a non-empty model.
     * This function is useful to centralize the creation of non-empty models,
     * since isNewRecord must set to null by passing non_empty.
     */
    static private function _create_model(array $data)
    {
        self::$preventInit = true;
        self::$skipDefaultAttributes = true;
        $model = new static();
        $model->attributes = $data;

        # Check for primary_key and set init_attrs.
        if (!static::table()->primaryKey()) {
            $model->storedAttributes = $data;
        }
        $model->isNewRecord = false;
        $model->_register();
        $model->init();

        return $model;
    }

    static private function _create_collection(array $data, $query = null)
    {
        $models = array();

        foreach ($data as $d)
            $models[] = self::_create_model($d);

        $coll = new Collection($models, $query);
        return $coll;
    }

    static public function getReflection($class = null)
    {
        if (!$class) {
            $class = get_called_class();
        }
        if (!isset(self::$cachedReflections[$class])) {
            self::$cachedReflections[$class] = new \ReflectionClass($class);
        }
        return self::$cachedReflections[$class];
    }

    public function __construct(array $attrs = [])
    {
        if (!self::$skipDefaultAttributes) {
            $this->setDefaultAttributes();
        } else {
            self::$skipDefaultAttributes = true;
        }
        $this->assignAttributes(
            array_merge(
                array_fill_keys(
                    static::table()->columnNames(),
                    null
                ),
                static::table()->columnDefaults(),
                $attrs
            )
        );
        if (!self::$preventInit) {
            $this->init();
        } else {
            self::$preventInit = false;
        }
    }

    /**
     * Checks for the called property in this order
     *   1. Checks if property is an attribute.
     *   2. Checks if called property is an already loaded association.
     *   3. Checks if called property is an association.
     * If none if these validations is true, the property is actually called
     * in order to generate an error.
     */
    public function __get($prop)
    {
        if (static::isAttribute($prop)) {
            return $this->getAttribute($prop);
        } elseif ($this->getAssociation($prop) !== null) {
            return $this->loadedAssociations[$prop];
        } elseif ($getter = $this->getterExists($prop)) {
            return $this->$getter();
        }

        throw new Exception\RuntimeException(
            sprintf("Tried to get unknown property %s::$%s", get_called_class(), $prop)
        );
    }

    public function __set($prop, $val)
    {
        $this->setProperty($prop, $val);
    }

    public function __isset($prop)
    {
        return $this->issetAttribute($prop);
    }

    /**
     * Some model's features can be overloaded:
     *  1. Check if an attribute changed with attributeNameChanged();
     *     Calling this normally would be: attributeChanged('attribute_name');
     *  2. Get the previous value of an attribute with attributeNameWas();
     *     Normally: attributeWas('attribute_name');
     *  3. Overload attribute setters. Models *should* define setter
     *     methods for each attribute, but they can be called overloadingly. The correct
     *     or expected name of the setter method is camel-cased, like setAttributeName();
     *  4. Overload scopes.
     *  5. Overload associations.
     *  6. Overload attribute getters. The expected method name is the camel-cased name of
     *     the attribute, like attributeName(), because the "get" prefix is omitted for getters.
     *     Models *should* define getter methods for each attribute.
     */
    public function __call($method, $params)
    {
        # Overload attributeChange()
        if (strlen($method) > 7 && substr($method, -7) == 'Changed') {
            $attribute = static::properAttrName(substr($method, 0, -7));
            return $this->attributeChanged($attribute);
        }

        # Overload attributeWas()
        if (strlen($method) > 3 && substr($method, -3) == 'Was') {
            $attribute = static::properAttrName(substr($method, 0, -3));
            return $this->attributeWas($attribute);
        }

        # Overload attribute setter.
        if (substr($method, 0, 3) == 'set') {
            $attrName = substr($method, 3);
            $underscored = Rails::services()->get('inflector')->underscore($method);
            if (static::isAttribute($underscored)) {
                $this->setAttribute($underscored, array_shift($params));
                return;
            }
        }

        # Overload scopes.
        if ($rel = static::scope($method, $params)) {
            return $rel;
        }

        // # Overload associations.
        // if ($this->getAssociation($method) !== null) {
        // return $this->loadedAssociations[$method];
        // }

        // # Overload attributes.
        // $underscored = Rails::services()->get('inflector')->underscore($method);
        // if (static::isAttribute($underscored)) {
        // return $this->getAttribute($underscored);
        // }

        throw new Exception\BadMethodCallException(
            sprintf("Call to undefined method %s::%s", get_called_class(), $method)
        );
    }

    public function isNewRecord()
    {
        return $this->isNewRecord;
    }

    /**
     * Update single attribute.
     * Sets a new value for the attribute and saves the record.
     * Validations are skipped but callbacks are still ran.
     *
     * @param string $attrName  The name of the attribute.
     * @param mixed $value      The value for the attribute,
     */
    public function updateAttribute($attrName, $value)
    {
        $this->setAttribute($attrName, $value);
        return $this->save(['skip_validation' => true, 'action' => 'update']);
    }

    /**
     * Save record
     *
     * Saves in the database the properties of the object that match
     * the columns of the corresponding table in the database.
     *
     * Is the model is new, create() will be called instead.
     *
     * @array $values: If present, object will be updated according
     * to this array, otherwise, according to its properties.
     */
    public function save(array $opts = array())
    {
        if ($this->isNewRecord()) {
            return $this->_create_do($opts);
        } else {
            if (!isset($opts['skip_validation']) || empty($opts['skip_validation'])) {
                if (!$this->_validate_data('save', $opts))
                    return false;
            }

            return $this->runCallbacks('save', function () {
                return $this->_save_do();
            });
        }
    }

    /**
     * Delete
     *
     * Deletes row from database based on Primary keys.
     */
    static public function delete()
    {
        // if ($this->_delete_from_db('delete')) {
        // foreach (array_keys(get_object_vars($this)) as $p)
        // unset($this->$p);
        // return true;
        // }
        // return false;
    }

    # Deletes current model from database but keeps model's properties.
    public function destroy()
    {
        return $this->runCallbacks('destroy', function () {
            return $this->_delete_from_db('destroy');
        });
    }

    public function errors()
    {
        if (!$this->errors)
            $this->errors = new ActiveModel\Errors();
        return $this->errors;
    }

    public function reload()
    {
        try {
            $data = $this->_get_stored_data();
        } catch (Exception\ExceptionInterface $e) {
            return false;
        }
        $cn = get_called_class();
        $refl = new \ReflectionClass($cn);
        $reflProps = $refl->getProperties(\ReflectionProperty::IS_PUBLIC | \ReflectionProperty::IS_PROTECTED);
        $defProps = $refl->getDefaultProperties();

        foreach ($reflProps as $reflProp) {
            if ($reflProp->getDeclaringClass() == $cn && !$reflProp->isStatic()) {
                $this->{$reflProp->name} = $defProps[$reflProp->name];
            }
        }

        $this->isNewRecord = false;

        $this->attributes = $data->attributes();
        $this->loadedAssociations = [];

        return true;
    }

    public function asJson()
    {
        return $this->attributes();
    }

    public function toJson()
    {
        return json_encode($this->asJson());
    }

    # TODO:
    public function toXml(array $params = [])
    {
        if (!isset($params['attributes'])) {
            $attrs = $this->attributes();
        } else {
            $attrs = $params['attributes'];
        }

        !isset($params['root']) && $params['root'] = strtolower(str_replace('_', '-', self::cn()));

        if (!isset($params['builder'])) {
            $xml = new \Rails\Xml\Xml($attrs, $params);
            return $xml->output();
        } else {
            $builder = $params['builder'];
            unset($params['builder']);
            $builder->build($attrs, $params);
        }
    }

    public function updateColumn($columnName, $value)
    {
        return $this->updateColumns([$columnName => $value]);
    }

    public function updateColumns(array $colsValsPairs)
    {
        if (!$colsValsPairs) {
            return null;
        }

        $colQuery = [];
        foreach (array_keys($colsValsPairs) as $colName) {
            $colQuery[] = '`' . $colName . '` = ?';
        }

        $pk = static::table()->primaryKey();
        $sql = sprintf(
            "UPDATE `%s` SET %s WHERE `%s` = ?",
            static::tableName(),
            implode(', ', $colQuery),
            $pk
        );

        return static::connection()->executeSql(
            array_merge(
                [$sql],
                array_values($colsValsPairs),
                [$this->getAttribute($pk)]
            )
        );
    }

    /**
     * ***************************
     * Default protected methods {
     * ***************************
     * attrAccessible and attrProtected can be found in Base\Methods\AttributeMethods.
     * associations can be found in Base\Methods\AssociationMethods
     */

    /**
     * Code executed when initializing the object.
     * Called by _create_model() and _create_do().
     */
    protected function init()
    {
    }

    /**
     * Example:
     *
     * return [
     *    // Validate attributes
     *    'attribute_name' => [
     *       'property' => rules...
     *    ],
     *
     *    // Passing a value or an index that isn't recognized as
     *    // attribute will be considered as custom validation method.
     *    'methodName',
     *    'method2Name' => [ 'on' => [ actions... ] ],
     * ];
     *
     * Custom validation methods must set the error manually. They aren't expected to
     * return anything.
     */
    protected function validations()
    {
        return [];
    }

    protected function callbacks()
    {
        return [];
    }
    /* } */

    /**
     * Returns model's current data in the database or using storedAttributes.
     */
    protected function _get_stored_data()
    {
        if (!($prim_key = static::table()->primaryKey()) && !$this->storedAttributes) {
            throw new Exception\RuntimeException(
                "Can't find data without primary key nor storedAttributes"
            );
        }

        $query = static::none();

        if ($prim_key) {
            $query->where('`' . $prim_key . '` = ?', $this->$prim_key);
        } else {
            $model = new static();
            $cols_names = static::table()->columnNames();
            foreach ($this->storedAttributes as $name => $value) {
                if (in_array($name, $cols_names)) {
                    $model->attributes[$name] = $value;
                }
            }

            if (!$model->attributes)
                throw new Exception\RuntimeException(
                    "Model rebuilt from storedAttributes failed"
                );
            else
                return $model;
        }

        $current = $query->first();

        if (!$current)
            throw new Exception\RuntimeException(
                "Row not found in database (searched with storedAttributes)"
            );
        else
            return $current;
    }

    public function runCallbacks($callbackName, \Closure $block = null)
    {
        if ($callbacks = $this->getCallbacks($callbackName, 'before')) {
            foreach ($callbacks as $method => $params) {
                if (is_int($method)) {
                    $method = $params;
                    $params = [];
                }

                # "If" blocks (Closure) must return true in order for the method to be executed.
                if (isset($params['if'])) {
                    if (true !== $params['if']()) {
                        continue;
                    }
                }

                if (false === $this->$method()) {
                    return false;
                }

            }
        }
        $this->notifyObservers('before_' . $callbackName);

        if ($block) {
            $result = (bool) $block();
        } else {
            $result = true;
        }

        if ($callbacks = $this->getCallbacks($callbackName, 'after')) {
            foreach ($callbacks as $method) {
                if (false === $this->$method()) {
                    break;
                }
            }
        }
        $this->notifyObservers('after_' . $callbackName);

        return $result;
    }

    protected function notifyObservers($callbackName)
    {
        if (Rails::config()->active_record->observers->any()) {
            Observer\Notifier::instance()->notify($callbackName, $this);
        }
    }

    public function getCallbacks($name, $kind)
    {
        $callbacks = $this->allCallbacks();

        $key = $kind . '_' . $name;

        if (isset($callbacks[$key])) {
            return $callbacks[$key];
        }
        return [];
    }

    /**
     * Merges model's callbacks and "plugged" callbacks.
     * If under production environment, the callbacks will be cached.
     */
    protected function allCallbacks()
    {
        if (Rails::env() == 'production') {
            return Rails::cache()->fetch('rails.models.' . get_called_class() . '.callbacks', function () {
                return array_merge_recursive(
                    $this->callbacks(),
                    $this->pluggedCallbacks()
                );
            });
        } else {
            return array_merge_recursive(
                $this->callbacks(),
                $this->pluggedCallbacks()
            );
        }
    }

    /**
     * Any method that ends with "Callbacks" can return an array of
     * callbacks. This is useful for traits that require to add some callbacks,
     * so they don't have to be manually called by the class implementing the trait.
     */
    private function pluggedCallbacks()
    {
        $callbacks = [];

        foreach (self::getReflection()->getMethods() as $method) {
            $methodName = $method->getName();
            if (
                strpos($methodName, 'Callbacks') === strlen($methodName) - 9
                && strpos($method->getDeclaringClass()->getName(), 'Rails') !== 0
            ) {
                $callbacks = array_merge($callbacks, $this->$methodName());
            }
        }

        return $callbacks;
    }

    /**
     * @return bool
     * @see validations()
     */
    private function _validate_data($action, array $opts = [])
    {
        return $this->runCallbacks('validation_on_' . $action, function () use ($action) {
            return $this->runCallbacks('validation', function () use ($action) {
                $validation_success = true;
                $modelClass = get_called_class();
                $classProps = get_class_vars($modelClass);

                foreach ($this->validations() as $attrName => $validations) {
                    /**
                     * This should only happen when passing a custom validation method with
                     * no validation options.
                     */
                    if (is_int($attrName)) {
                        $attrName = $validations;
                        $validations = [];
                    }

                    if (static::isAttribute($attrName) || array_key_exists($attrName, $classProps)) {
                        foreach ($validations as $type => $params) {
                            if (!is_array($params)) {
                                $params = [$params];
                            }

                            if ($modelClass::isAttribute($attrName)) {
                                $value = $this->getAttribute($attrName);
                            } else {
                                $value = $this->$attrName;
                            }

                            $validation = new Validator($type, $value, $params);
                            $validation->set_params($action, $this, $attrName);

                            if (!$validation->validate()->success()) {
                                $validation->set_error_message();
                                $validation_success = false;
                            }
                        }
                    } else {
                        /**
                         * The attrName passed isn't an attribute nor a property, so we assume it's a method.
                         *
                         * $attrName becomes the name of the method.
                         * $validations becomes validation options.
                         */
                        if (!empty($validations['on']) && !in_array($action, $validations['on'])) {
                            continue;
                        }

                        $this->$attrName();
                        if ($this->errors()->any()) {
                            $validation_success = false;
                        }
                    }
                }
                return $validation_success;
            });
        });
    }

    private function _create_do()
    {
        if (!$this->_validate_data('create')) {
            return false;
        }

        return $this->runCallbacks('save', function () {
            return $this->runCallbacks('create', function () {
                $this->_check_time_column('created_at');
                $this->_check_time_column('updated_at');
                $this->_check_time_column('created_on');
                $this->_check_time_column('updated_on');

                $cols_values = $cols_names = array();

                foreach ($this->attributes() as $attr => $val) {
                    if (
                        $val === null &&
                        !$this->attributeChanged($attr) &&
                        !array_key_exists($attr, static::table()->columnDefaults())
                    ) {
                        /**
                         * This attribute's value (null) has been set automatically
                         * when the model was constructed, and it doesn't have a default
                         * value (i.e. it's not null). Skip it.
                         */
                        continue;
                    }

                    $proper = static::properAttrName($attr);

                    if (!static::table()->columnExists($proper)) {
                        continue;
                    }

                    $cols_names[] = '`' . $attr . '`';
                    $cols_values[] = $val;
                    $init_attrs[$attr] = $val;
                }

                if (!$cols_values)
                    return false;

                $binding_marks = implode(', ', array_fill(0, (count($cols_names)), '?'));
                $cols_names = implode(', ', $cols_names);

                $sql = 'INSERT INTO `' . static::tableName() . '` (' . $cols_names . ') VALUES (' . $binding_marks . ')';

                array_unshift($cols_values, $sql);

                static::connection()->executeSql($cols_values);

                $id = static::connection()->lastInsertId();

                $primary_key = static::table()->primaryKey();

                if ($primary_key && !empty($primary_key)) {
                    if (!$id) {
                        $this->errors()->addToBase('Couldn\'t retrieve new primary key.');
                        return false;
                    }

                    if ($pri_key = static::table()->primaryKey()) {
                        $this->setAttribute($pri_key, $id);
                    }
                } else {
                    $this->storedAttributes = $init_attrs;
                }

                $this->isNewRecord = false;

                return true;
            });
        });
    }

    private function _save_do()
    {
        $w = $wd = $q = $d = array();

        $dt = static::table()->columnNames();
        $indexes = static::table()->indexes() ?: [];

        foreach (array_keys($this->changedAttributes()) as $attrName) {
            # Can't update properties that don't have a column in DB, or
            # PRImary keys, or time columns.
            if (
                !in_array($attrName, $dt) || $attrName == 'created_at' || $attrName == 'updated_at' ||
                $attrName == 'created_on' || $attrName == 'updated_on' || in_array($attrName, $indexes)
            ) {
                continue;
            } else {
                $q[] = '`' . $attrName . '` = ?';
                $d[] = $this->getAttribute($attrName);
            }
        }

        if (!$q) {
            # Nothing to update
            return true;
        }

        if ($indexes) {
            foreach ($indexes as $idx) {
                $w[] = '`' . $idx . '` = ?';
                if ($this->attributeChanged($idx)) {
                    $wd[] = $this->attributeWas($idx);
                } else {
                    $wd[] = $this->getAttribute($idx);
                }
            }
        } else {
            foreach ($this->attributes() as $attrName => $value) {
                $w[] = '`' . $attrName . '` = ?';

                if ($this->attributeChanged($attrName)) {
                    $wd[] = $this->attributeWas($attrName);
                } elseif (($value = $this->getAttribute($attrName)) !== null) {
                    $wd[] = $value;
                }
            }
        }

        # Update `updated_at|on` if exists.
        if ($this->_check_time_column('updated_at')) {
            $q[] = "`updated_at` = ?";
            $d[] = $this->updated_at;
        } elseif ($this->_check_time_column('updated_on')) {
            $q[] = "`updated_on` = ?";
            $d[] = $this->updated_on;
        }

        if ($q) {
            $q = "UPDATE `" . static::tableName() . "` SET " . implode(', ', $q);

            $w && $q .= ' WHERE ' . implode(' AND ', $w);

            $q .= ' LIMIT 1';

            $d = array_merge($d, $wd);
            array_unshift($d, $q);

            static::connection()->executeSql($d);

            if (ActiveRecord::lastError()) {
                # The error is logged by Connection.
                return false;
            }
        }

        $this->_update_init_attrs();
        return true;
    }

    private function _delete_from_db($type)
    {
        $w = $wd = [];

        if ($keys = self::table()->indexes()) {
            foreach ($keys as $k) {
                $w[] = '`' . static::tableName() . '`.`' . $k . '` = ?';
                $wd[] = $this->$k;
            }
        } elseif ($this->storedAttributes) {
            foreach ($this->storedAttributes as $attr => $val) {
                $w[] = '`' . $attr . '` = ?';
                $wd[] = $val;
            }
        } else {
            throw new Exception\LogicException(
                "Can't delete model without attributes"
            );
        }

        $w = implode(' AND ', $w);

        $query = 'DELETE FROM `' . static::tableName() . '` WHERE ' . $w;
        array_unshift($wd, $query);

        static::connection()->executeSql($wd);

        return true;
    }

    private function _register()
    {
        self::_registry()->register($this);
    }

    /**
     * Check time column
     *
     * Called by save() and create(), checks if $column
     * exists and automatically sets a value to it.
     */
    private function _check_time_column($column)
    {
        if (!static::table()->columnExists($column))
            return false;

        $type = static::table()->columnType($column);

        if ($type == 'datetime' || $type == 'timestamp')
            $time = date('Y-m-d H:i:s');
        elseif ($type == 'year')
            $time = date('Y');
        elseif ($type == 'date')
            $time = date('Y-m-d');
        elseif ($type == 'time')
            $time = date('H:i:s');
        else
            return false;

        $this->attributes[$column] = $time;

        return true;
    }

    private function _update_init_attrs()
    {
        foreach (array_keys($this->storedAttributes) as $name) {
            if (isset($this->attributes[$name]))
                $this->storedAttributes[$name] = $this->attributes[$name];
        }
    }

    protected function getterExists($attrName)
    {
        if (is_int(strpos($attrName, '_'))) {
            $inflector = Rails::services()->get('inflector');
            $getter = 'get' . $inflector->camelize($attrName);
        } else {
            $getter = 'get' . ucfirst($attrName);
        }

        $reflection = self::getReflection();

        if ($reflection->hasMethod($getter) && $reflection->getMethod($getter)->isPublic()) {
            return $getter;
        } else {
            return false;
        }
    }

    protected function setterExists($attrName)
    {
        if (is_int(strpos($attrName, '_'))) {
            $inflector = Rails::services()->get('inflector');
            $setter = 'set' . $inflector->camelize($attrName);
        } else {
            $setter = 'set' . ucfirst($attrName);
        }
        $reflection = self::getReflection();

        if ($reflection->hasMethod($setter)) {
            $method = $reflection->getMethod($setter);
            if ($method->isPublic() && !$method->isStatic()) {
                return $setter;
            }
        }
        return false;
    }
}
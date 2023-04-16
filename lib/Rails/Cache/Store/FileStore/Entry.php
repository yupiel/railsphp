<?php
namespace Rails\Cache\Store\FileStore;

use Rails;
use Rails\Cache\Store\FileStore;

/**
 * This class is intended to be used only by Rails.
 */
class Entry
{
    const DATA_SEPARATOR = ';';

    const KEY_VALUE_SEPARATOR = '=';

    private
    $_hash,
    $_key,
    $_path,
    $_value,
    $_expires_in,
    $_file_exists,
    $_file_contents,
    $params = [],
    /**
     * Subdirs inside the tmp/cache directory.
     * Names cannot contain dots.
     */
    $_dir = 'app';

    protected $store;

    public function __construct($key, $store, array $params = [])
    {
        $this->store = $store;

        $this->_key = $key;
        $this->_hash = md5($key);

        if (isset($params['path'])) {
            $this->_dir = $params['path'];
            unset($params['path']);
        }

        $this->params = $params;
    }

    public function read()
    {
        if ($this->fileExists())
            $this->_read_file();

        return $this->_value;
    }

    public function write($val)
    {
        if (is_string($val)) {
            $this->params['type'] = 'string';
            $this->_value = $val;
        } else {
            $this->_value = serialize($val);
        }

        if (isset($this->params['expires_in'])) {
            if (!ctype_digit((string) $this->params['expires_in']))
                $this->params['expires_in'] = strtotime('+' . $this->params['expires_in']);
        }
        if (isset($this->params['path'])) {
            $this->_dir = $this->params['path'];
            unset($this->params['path']);
        }

        $header = [];
        foreach ($this->params as $k => $v)
            $header[] = $k . self::KEY_VALUE_SEPARATOR . $v;
        $header = implode(self::DATA_SEPARATOR, $header);

        if (!is_dir($this->_path()))
            mkdir($this->_path(), 0777, true);

        return (bool) file_put_contents($this->_file_name(), $header . "\n" . $this->_value);
    }

    public function delete()
    {
        $this->_value = null;
        return $this->_delete_file();
    }

    public function value()
    {
        return $this->_value;
    }

    public function fileExists()
    {
        if ($this->_file_exists === null) {
            $this->_file_exists = is_file($this->_file_name());
        }
        return $this->_file_exists;
    }

    public function expired()
    {
        if (!isset($this->params['expires_in']) || $this->params['expires_in'] > time()) {
            return false;
        }
        return true;
    }

    public function unserialize_e_handler()
    {
        $this->_value = false;
    }

    private function _read_file()
    {
        $this->_file_contents = file_get_contents($this->_file_name());
        $this->_parse_contents();
        if ($this->expired()) {
            $this->delete();
        } else {

        }
    }

    private function _parse_contents()
    {
        $regex = '/^(\V+)/';
        preg_match($regex, $this->_file_contents, $m);

        if (!empty($m[1])) {
            foreach (explode(self::DATA_SEPARATOR, $m[1]) as $data) {
                list($key, $val) = explode(self::KEY_VALUE_SEPARATOR, $data);
                $this->params[$key] = $val;
            }
        } else
            $m[1] = '';

        # For some reason, try/catch Exception didn't work.
        $err_handler = set_error_handler([$this, "unserialize_e_handler"]);

        if (isset($this->params['type']) && $this->params['type'] == 'string') {
            $this->_value = str_replace($m[1] . "\n", '', $this->_file_contents);
        } else {
            $this->_value = unserialize(str_replace($m[1] . "\n", '', $this->_file_contents));
        }

        $this->_file_contents = null;

        set_error_handler($err_handler);
    }

    private function _delete_file()
    {
        if (is_file($this->_file_name())) {
            /**
             * Even though this happens only if is_file is true,
             * sometimes the file is already gone by the time unlink
             * is executed.
             */
            try {
                unlink($this->_file_name());
            } catch (Rails\Exception\PHPError\Warning $e) {
                return false;
            }
        }
        return true;
    }

    private function _file_name()
    {
        return $this->_path() . '/' . urlencode($this->_key);
    }

    private function _path()
    {
        if (!$this->_path) {
            $this->_path = $this->_generate_path($this->_key);
        }
        return $this->_path;
    }

    private function _generate_path($key)
    {
        $md5 = md5($key);
        $ab = substr($md5, 0, 2);
        $cd = substr($md5, 2, 2);
        return $this->_base_path() . '/' . $ab . '/' . $cd;
    }

    private function _base_path()
    {
        $subdir = $this->_dir ? '/' . str_replace('.', '', $this->_dir) : '';
        return $this->store->basePath() . $subdir;
    }
}
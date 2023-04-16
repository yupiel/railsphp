<?php
namespace Rails\Log;

use Laminas\Log\Logger as LaminasLogger;

class Logger extends LaminasLogger
{
    const NONE = 8;

    protected $priorities = array(
        self::EMERG => 'EMERGENCY',
        self::ALERT => 'ALERT',
        self::CRIT => 'CRITICAL',
        self::ERR => 'ERROR',
        self::WARN => 'WARNING',
        self::NOTICE => 'NOTICE',
        self::INFO => 'INFO',
        self::DEBUG => 'DEBUG',
        self::NONE => 'NONE',
    );

    protected $name = '';

    public function __construct(array $options = [])
    {
        if (isset($options['name'])) {
            $this->name = $options['name'];
            unset($optiona['name']);
        }
        parent::__construct();
    }

    public function emergency($message, $extra = [])
    {
        return $this->emerg($message, $extra);
    }

    public function critical($message, $extra = [])
    {
        return $this->crit($message, $extra);
    }

    public function error($message, $extra = [])
    {
        return $this->err($message, $extra);
    }

    public function warning($message, $extra = [])
    {
        return $this->warn($message, $extra);
    }

    public function none($message)
    {
        return $this->log(self::NONE, $message);
    }

    public function vars(array $vars)
    {
        ob_start();
        foreach ($vars as $var) {
            var_dump($var);
        }
        $message = ob_get_clean();
        $message .= "\n";
        return $this->none($message);
    }

    /**
     * Additional text can be passed through $options['extraMessages'].
     */
    public function exception(\Exception $e, array $options = [])
    {
        $message = \Rails\Exception\Reporting\Reporter::create_report($e, $options);
        $message = \Rails\Exception\Reporting\Reporter::cleanup_report($message);

        return $this->message($message);
    }

    /**
     * Adds date-time and request data, if any.
     */
    public function message($err)
    {
        return $this->none($this->buildErrorMessage($err));
    }

    private function buildErrorMessage($err, $requestInfo = true)
    {
        if ($requestInfo) {
            $request = ' ' . $this->buildRequestInfo();
        } else {
            $request = '';
        }

        $message = date('[d-M-Y H:i:s T]') . $request . "\n";
        $message .= $err;
        $message = trim($message);
        $message .= "\n";
        return $message;
    }

    private function buildRequestInfo()
    {
        if (\Rails::application()->dispatcher() && ($request = \Rails::application()->dispatcher()->request())) {
            $info = '[' . $request->remoteIp() . '] ' . $request->method() . ' ' . $request->fullPath();
        } elseif (\Rails::cli()) {
            $info = '[cli]';
        } else {
            $info = '';
        }
        return $info;
    }
}
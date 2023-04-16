<?php
namespace Rails\Console;

class Console
{
    public function interpret($method, $params)
    {
        call_user_func_array([$this, $method], $params);
    }

    public function terminate($text = "", int $color = null, int $bgColor = null)
    {
        $this->writeLine($text, $color, $bgColor);
        exit;
    }

    /**
     * Ask the user to confirm something.
     */
    public function confirm(string $prompt)
    {
        while (true) {
            $response = readline($prompt);

            if ($response == 'y') {
                return true;
            } else if ($response == 'n') {
                return false;
            }
        }
    }

    /**
     * Ask the user to hit a key.
     */
    public function key()
    {
        return call_user_func_array('Laminas\Console\Prompt\Char::prompt', func_get_args());
    }

    /**
     * Ask the user for a text.
     */
    public function input(string $prompt)
    {
        while (true) {
            $response = trim(readline($prompt));
            if (empty($response)) {
                continue;
            }

            return $response;
        }
    }

    public function number(string $prompt)
    {
        while (true) {
            $response = trim(readline($prompt));

            if (is_numeric($response)) {
                return (int) $response;
            }
        }
    }

    public function select()
    {
        return call_user_func_array('Laminas\Console\Prompt\Select::prompt', func_get_args());
    }

    public function write(string $text, int $color = null, int $bgColor = null)
    {
        echo $this->construct_colored_string($text, $color, $bgColor);
    }

    public function writeLine(string $text, int $color = null, int $bgColor = null)
    {
        echo $this->construct_colored_string($text, $color, $bgColor) . "\n";
    }

    private function construct_colored_string($text, $color, $bgColor)
    {
        if ($bgColor) {
            $bgColor = ";" . ($bgColor + 10);
        }
        if (!$color) {
            $color = ColorInterface::NORMAL;
        }

        return "\033[" . $color . $bgColor . "m" . $text . "\e[" . ColorInterface::RESET . "m";
    }
}

interface ColorInterface
{
    const NORMAL = 39;
    const RESET = 0;

    const BLACK = 30;
    const RED = 31;
    const GREEN = 32;
    const YELLOW = 33;
    const BLUE = 34;
    const MAGENTA = 35;
    const CYAN = 36;

    const LIGHT_GRAY = 37;
    const DARK_GRAY = 90;
    const LIGHT_RED = 91;
    const LIGHT_GREEN = 92;
    const LIGHT_YELLOW = 93;
    const LIGHT_BLUE = 94;
    const LIGHT_MAGENTA = 95;
    const LIGHT_CYAN = 96;

    const WHITE = 97;
}
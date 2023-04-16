<?php
namespace Rails\Console\Generators;

use Rails\Console\Console;
use Rails\Console\Getopt;
use Rails\Toolbox;
use Rails\Toolbox\FileGenerators;

class Generator
{
    protected $console;

    protected $opts;

    public function __construct(Console $console)
    {
        $this->console = $console;

        $rules = [
            'generator' => '',
            'model' => '',
            'f|force' => '',
        ];

        $this->opts = new Getopt($rules);
    }

    public function parseCmd()
    {
        $argv = $this->opts->getArguments();

        if (!$argv[1]) {
            $this->console->terminate('Missing generator');
        }

        try {
            switch ($argv[1]) {
                case 'model':
                    $this->generateModel();
                    break;

                case 'controller':
                    $this->generateController();
                    break;

                case 'db:schema':
                    Toolbox\DbTools::generateSchemaFiles();
                    break;

                case 'migration':
                    $this->generateMigration();
                    break;

                default:
                    $this->console->terminate(
                        sprintf("Unknown generator for %s", $argv[1])
                    );
            }
        } catch (FileGenerators\Exception\ExceptionInterface $e) {
            $this->console->terminate(
                "Error: " . $e->getMessage()
            );
        }
    }

    protected function generateMigration()
    {
        $rules = [
            'name' => '',
        ];

        $opts = $this->opts->addRules($rules);
        $argv = $opts->getArguments();

        if (empty($argv[2])) {
            $this->console->terminate("Missing migration name");
        }

        \Rails\Generators\ActiveRecord\Migration\MigrationGenerator::generate($argv[2]);
    }

    protected function generateModel()
    {
        $rules = [
            'name' => '',
        ];

        $opts = $this->opts->addRules($rules);
        $argv = $opts->getArguments();

        if (empty($argv[2])) {
            $this->console->terminate("Missing name for model");
        }

        $name = $argv[2];
        $options = $opts->getOptions();

        FileGenerators\ModelGenerator::generate($name, $options, $this->console);

        # Create migration
        $migrName = 'create_' . $name::tableName();
        \Rails\Generators\ActiveRecord\Migration\MigrationGenerator::generate($migrName);
    }

    protected function generateController()
    {
        $rules = [
            'name' => '',
        ];

        $opts = $this->opts->addRules($rules);
        $argv = $opts->getArguments();

        if (empty($argv[2])) {
            $this->console->terminate("Missing name for controller");
        }

        $name = $argv[2];
        $options = $opts->getOptions();

        FileGenerators\ControllerGenerator::generate($name, $options, $this->console);
    }
}
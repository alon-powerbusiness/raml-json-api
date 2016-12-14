<?php
namespace rjapi\controllers;

use rjapi\blocks\BaseModels;
use rjapi\blocks\Containers;
use rjapi\blocks\Controllers;
use rjapi\blocks\CustomsInterface;
use rjapi\blocks\FileManager;
use rjapi\blocks\Mappers;
use rjapi\blocks\Module;
use rjapi\exception\MethodNotFoundException;
use Symfony\Component\Yaml\Yaml;

trait ControllersTrait
{
    // paths
    public $rootDir = '';
    public $appDir = '';
    public $modulesDir = '';
    public $controllersDir = '';
    public $modelsFormDir = '';
    public $formsDir = '';
    public $mappersDir = '';
    public $containersDir = '';

    public $version;
    public $objectName = '';
    public $defaultController = 'Default';
    public $uriNamedParams = null;
    public $ramlFile = '';
    public $force = null;
    public $customTypes = [
        CustomsInterface::CUSTOM_TYPES_ID,
        CustomsInterface::CUSTOM_TYPES_TYPE,
        CustomsInterface::CUSTOM_TYPES_RELATIONSHIPS,
        CustomsInterface::CUSTOM_TYPES_SINGLE_DATA_RELATIONSHIPS,
        CustomsInterface::CUSTOM_TYPES_MULTIPLE_DATA_RELATIONSHIPS,
    ];
    public $types = [];
    public $frameWork = '';
    public $objectProps = [];

    /**
     *  Generates api Controllers + Models to support RAML validation
     */
    public function actionIndex($ramlFile)
    {
        $data = Yaml::parse(file_get_contents($ramlFile));
        $this->version = str_replace('/', '', $data['version']);
        $this->frameWork = $data['uses']['FrameWork'];

        $this->appDir = constant('self::' . strtoupper($this->frameWork) . '_APPLICATION_DIR');
        $this->controllersDir = constant('self::' . strtoupper($this->frameWork) . '_CONTROLLERS_DIR');
        $this->formsDir = constant('self::' . strtoupper($this->frameWork) . '_FORMS_DIR');
        $this->mappersDir = constant('self::' . strtoupper($this->frameWork) . '_MAPPERS_DIR');
        $this->modelsFormDir = constant('self::' . strtoupper($this->frameWork) . '_MODELS_DIR');
        $this->modulesDir = constant('self::' . strtoupper($this->frameWork) . '_MODULES_DIR');
        $this->containersDir = constant('self::' . strtoupper($this->frameWork) . '_CONTAINERS_DIR');
        $this->createDirs();

        $this->types = $data['types'];
        $this->runGenerator();
    }

    private function runGenerator()
    {
        foreach ($this->types as $objName => $objData) {
            if (!in_array($objName, $this->customTypes)) { // if this is not a custom type generate resources
                $excluded = false;
                foreach ($this->excludedSubtypes as $type) {
                    if (strpos($objName, $type) !== false) {
                        $excluded = true;
                    }
                }
                // if the type is among excluded - continue
                if ($excluded === true) {
                    continue;
                }

                foreach ($objData as $k => $v) {
                    if ($k === self::RAML_PROPS) { // process props
                        $this->setObjectName($objName);
                        $this->setObjectProps($v);
                        $generator = self::GENERATOR_METHOD . ucfirst($this->frameWork);
                        if (method_exists($this, $generator) === false) {
                            throw new MethodNotFoundException('The method ' . $generator . ' has not been found.');
                        }
                        $this->$generator();
                    }
                }
            }
        }
    }

    private function createDirs()
    {
        // create modules dir
        FileManager::createPath(FileManager::getModulePath($this));
        // create controllers dir
        FileManager::createPath($this->formatControllersPath());
        // create forms dir
        FileManager::createPath($this->formatFormsPath());
        // create mapper dir
        FileManager::createPath($this->formatMappersPath());
        // create containers dir
        FileManager::createPath($this->formatContainersPath());
    }

    public function formatControllersPath()
    {
        return FileManager::getModulePath($this) . $this->controllersDir;
    }

    public function formatModelsPath()
    {
        return FileManager::getModulePath($this) . $this->modelsFormDir;
    }

    public function formatFormsPath() : string
    {
        return FileManager::getModulePath($this, true) . $this->formsDir;
    }

    public function formatMappersPath() : string
    {
        return FileManager::getModulePath($this, true) . $this->mappersDir;
    }

    public function formatContainersPath() : string
    {
        return FileManager::getModulePath($this, true) . $this->containersDir;
    }

    private function setObjectName($name)
    {
        $this->objectName = $name;
    }

    private function setObjectProps($props)
    {
        $this->objectProps = $props;
    }

    private function generateResourcesYii()
    {
        // create controller
        $this->controllers = new Controllers($this);
        $this->controllers->createDefault();
        $this->controllers->create();

        // create module
        $this->moduleObject = new Module($this);
        $this->moduleObject->createModule();

        // create model
        $this->forms = new BaseModels($this);
        $this->forms->create();

        // create mappers
        $this->mappers = new Mappers($this);
        $this->mappers->create();

        // create db containers
        $this->containers = new Containers($this);
        $this->containers->create();
    }

    private function generateResourcesLaravel()
    {
        // create controller
        $this->controllers = new Controllers($this);
        $this->controllers->createDefault();
        $this->controllers->create();

        // create module
        $this->moduleObject = new Module($this);
        $this->moduleObject->createModule();

        // create model
        $this->forms = new BaseModels($this);
        $this->forms->create();

        // create mappers
        $this->mappers = new Mappers($this);
        $this->mappers->create();

        // create db containers
        $this->containers = new Containers($this);
        $this->containers->create();
    }
}
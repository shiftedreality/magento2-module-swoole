<?php

namespace Swoolegento\Cli\App;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\State;
use Magento\Framework\Filesystem\DriverPool;
use Magento\Framework\Interception\ObjectManager\ConfigInterface;
use Magento\Framework\App\ObjectManager\Environment;
use Magento\Framework\Config\File\ConfigFilePool;
use Magento\Framework\Code\GeneratedFiles;

class ObjectManagerFactory extends \Magento\Framework\App\ObjectManagerFactory
{
    /**
     * @var \Magento\Framework\ObjectManager\DefinitionFactory
     */
    private $definitionFactory;

    /**
     * @var \Magento\Framework\ObjectManager\Relations\Runtime
     */
    private $relations;

    /**
     * @var \Magento\Framework\ObjectManager\Definition\Runtime
     */
    private $definitions;

    /**
     * @var \Magento\Framework\App\ObjectManager\Environment\Developer
     */
    private $env;

    /**
     * @var \Magento\Framework\Interception\ObjectManager\Config\Developer
     */
    private $diConfig;

    /**
     * Constructor
     *
     * @param DirectoryList $directoryList
     * @param DriverPool $driverPool
     * @param ConfigFilePool $configFilePool
     */
    public function __construct(DirectoryList $directoryList, DriverPool $driverPool, ConfigFilePool $configFilePool)
    {
        parent::__construct($directoryList, $driverPool, $configFilePool);

        $this->definitionFactory = new \Magento\Framework\ObjectManager\DefinitionFactory(
            $this->driverPool->getDriver(DriverPool::FILE),
            $this->directoryList->getPath(DirectoryList::GENERATED_CODE)
        );

        $this->definitions = $this->definitionFactory->createClassDefinition();
        $this->relations = $this->definitionFactory->createRelations();

        /** @var EnvironmentFactory $envFactory */
        $envFactory = new $this->envFactoryClassName($this->relations, $this->definitions);
        /** @var EnvironmentInterface $env */
        $this->env = $envFactory->createEnvironment();

        /** @var ConfigInterface $diConfig */
        $this->diConfig = $this->env->getDiConfig();
    }

    /**
     * Create ObjectManager
     *
     * @param array $arguments
     * @return \Magento\Framework\ObjectManagerInterface
     *
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function create(array $arguments)
    {
        $writeFactory = new \Magento\Framework\Filesystem\Directory\WriteFactory($this->driverPool);
        /** @var \Magento\Framework\Filesystem\Driver\File $fileDriver */
        $fileDriver = $this->driverPool->getDriver(DriverPool::FILE);
        $lockManager = new \Magento\Framework\Lock\Backend\FileLock($fileDriver, BP);
        $generatedFiles = new GeneratedFiles($this->directoryList, $writeFactory, $lockManager);
        $generatedFiles->cleanGeneratedFiles();

        $deploymentConfig = $this->createDeploymentConfig($this->directoryList, $this->configFilePool, $arguments);
        $arguments = array_merge($deploymentConfig->get(), $arguments);

        $appMode = isset($arguments[State::PARAM_MODE]) ? $arguments[State::PARAM_MODE] : State::MODE_DEFAULT;
        $booleanUtils = new \Magento\Framework\Stdlib\BooleanUtils();
        $argInterpreter = $this->createArgumentInterpreter($booleanUtils);
        $argumentMapper = new \Magento\Framework\ObjectManager\Config\Mapper\Dom($argInterpreter);

        if ($this->env->getMode() != Environment\Compiled::MODE) {
            $configData = $this->_loadPrimaryConfig($this->directoryList, $this->driverPool, $argumentMapper, $appMode);
            if ($configData) {
                $this->diConfig->extend($configData);
            }
        }

        // set cache profiler decorator if enabled
        if (\Magento\Framework\Profiler::isEnabled()) {
            $cacheFactoryArguments = $this->diConfig->getArguments(\Magento\Framework\App\Cache\Frontend\Factory::class);
            $cacheFactoryArguments['decorators'][] = [
                'class' => \Magento\Framework\Cache\Frontend\Decorator\Profiler::class,
                'parameters' => ['backendPrefixes' => ['Zend_Cache_Backend_', 'Cm_Cache_Backend_']],
            ];
            $cacheFactoryConfig = [
                \Magento\Framework\App\Cache\Frontend\Factory::class => ['arguments' => $cacheFactoryArguments]
            ];
            $this->diConfig->extend($cacheFactoryConfig);
        }

        $sharedInstances = [
            \Magento\Framework\App\DeploymentConfig::class => $deploymentConfig,
            \Magento\Framework\App\Filesystem\DirectoryList::class => $this->directoryList,
            \Magento\Framework\Filesystem\DirectoryList::class => $this->directoryList,
            \Magento\Framework\Filesystem\DriverPool::class => $this->driverPool,
            \Magento\Framework\ObjectManager\RelationsInterface::class => $this->relations,
            \Magento\Framework\Interception\DefinitionInterface::class => $this->definitionFactory->createPluginDefinition(),
            \Magento\Framework\ObjectManager\ConfigInterface::class => $this->diConfig,
            \Magento\Framework\Interception\ObjectManager\ConfigInterface::class => $this->diConfig,
            \Magento\Framework\ObjectManager\DefinitionInterface::class => $this->definitions,
            \Magento\Framework\Stdlib\BooleanUtils::class => $booleanUtils,
            \Magento\Framework\ObjectManager\Config\Mapper\Dom::class => $argumentMapper,
            \Magento\Framework\ObjectManager\ConfigLoaderInterface::class => $this->env->getObjectManagerConfigLoader(),
            $this->_configClassName => $this->diConfig,
        ];
        $arguments['shared_instances'] = &$sharedInstances;
        $this->factory = $this->env->getObjectManagerFactory($arguments);

        /** @var \Magento\Framework\ObjectManagerInterface $objectManager */
        $objectManager = new $this->_locatorClassName($this->factory, $this->diConfig, $sharedInstances);

        $this->factory->setObjectManager($objectManager);

        $generatorParams = $this->diConfig->getArguments(\Magento\Framework\Code\Generator::class);
        /** Arguments are stored in different format when DI config is compiled, thus require custom processing */
        $generatedEntities = isset($generatorParams['generatedEntities']['_v_'])
            ? $generatorParams['generatedEntities']['_v_']
            : (isset($generatorParams['generatedEntities']) ? $generatorParams['generatedEntities'] : []);
        $this->definitionFactory->getCodeGenerator()
            ->setObjectManager($objectManager)
            ->setGeneratedEntities($generatedEntities);

        $this->env->configureObjectManager($this->diConfig, $sharedInstances);

        return $objectManager;
    }
}

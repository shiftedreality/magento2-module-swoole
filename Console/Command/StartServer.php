<?php

declare(strict_types=1);

namespace Swoolegento\Cli\Console\Command;

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class StartServer extends Command
{
    /**
     * @var \Magento\Framework\Filesystem\DirectoryList $_dir
     */
    protected $_dir;

    /**
     * @param string|null $name
     * @param \Magento\Framework\Filesystem\DirectoryList $dir
     */
    public function __construct(
        \Magento\Framework\Filesystem\DirectoryList $dir,
        string $name = null
    ) {
        $this->_dir = $dir;

        parent::__construct($name);
    }

    protected function configure()
    {
        $this->setName('swoolegento:server:start');
        $this->setDescription('Start the Swoole server.');

        parent::configure();
    }

    /**
     * Execute the command
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $http = new Server("0.0.0.0", 3000);
        $http->set([
            'log_level' => 5,
            'log_file' => $this->_dir->getPath('log') . '/swoole.log',
        ]);

        $http->on(
            "request",
            function (Request $request, Response $response) {
                $info = pathinfo($request->server['request_uri']);
                if (!empty($info['extension'])) {
                    $staticFile = $this->_dir->getPath('pub') . preg_replace('/version[0-9]+\//i', '', $request->server['request_uri']);
                    if (file_exists($staticFile)) {
                        $response->sendfile($staticFile);
                        return true;
                    }
                }

                $sessionName = session_name();
                if (isset($request->cookie[$sessionName])) {
                    $sessionId = $request->cookie[$sessionName];
                } else {
                    $sessionId = call_user_func('session_create_id');
                }
                ob_start();
                session_id($sessionId);
                ob_clean();
                session_start();
                $response->cookie(
                    $sessionName,
                    $sessionId,
                    0,
                    '/',
                    '',
                    true,
                    true
                );

                try {
                    $_SERVER['HTTP_HOST'] = $request->header['host'];
                    $_SERVER['SERVER_PORT'] = 443;
                    $_SERVER['REQUEST_URI'] = $request->server['request_uri'];
                    $_SERVER['REQUEST_METHOD'] = $request->server['request_method'];
                    if (!empty($request->server['query_string'])) {
                        $_SERVER['QUERY_STRING'] = $request->server['query_string'];
                    }
                    if (!empty($request->header['content-type'])) {
                        $_SERVER['REDIRECT_HTTP_CONTENT_TYPE'] = $request->header['content-type'];
                    }
                    if (!empty($request->header['x-requested-with'])) {
                        $_SERVER['HTTP_X_REQUESTED_WITH'] = $request->header['x-requested-with'];
                    }

                    $_GET = $request->get;
                    $_POST = $request->post;
                    $_COOKIE = $request->cookie;
                    $GLOBALS['HTTP_RAW_POST_DATA'] = $request->getContent();

                    if (str_starts_with($request->server['request_uri'], '/static/')) {
                        $_GET['resource'] = preg_replace('/static\/version[0-9]+\//i', '', $request->server['request_uri']);
                        $bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
                        $application = $bootstrap->createApplication(\Magento\Framework\App\StaticResource::class);
                        $application->launch();
                        $response->sendfile($staticFile);
                        return true;
                    } else if (str_starts_with($request->server['request_uri'], '/media/')) {
                        $mediaDirectory = null;
                        $allowedResources = [];
                        $configCacheFile = BP . '/var/resource_config.json';

                        $isAllowed = function ($resource, array $allowedResources) {
                            foreach ($allowedResources as $allowedResource) {
                                if (0 === stripos($resource, $allowedResource)) {
                                    return true;
                                }
                            }
                            return false;
                        };

                        $request = new \Magento\MediaStorage\Model\File\Storage\Request(
                            new \Magento\Framework\HTTP\PhpEnvironment\Request(
                                new \Magento\Framework\Stdlib\Cookie\PhpCookieReader(),
                                new \Magento\Framework\Stdlib\StringUtils()
                            )
                        );
                        $relativePath = $request->getPathInfo();
                        if (file_exists($configCacheFile) && is_readable($configCacheFile)) {
                            $config = json_decode(file_get_contents($configCacheFile), true);

                            //checking update time
                            if (filemtime($configCacheFile) + $config['update_time'] > time()) {
                                $mediaDirectory = $config['media_directory'];
                                $allowedResources = $config['allowed_resources'];

                                // Serve file if it's materialized
                                if ($mediaDirectory) {
                                    $fileAbsolutePath = $this->_dir->getPath('pub') . '/' . $relativePath;
                                    $fileRelativePath = str_replace(rtrim($mediaDirectory, '/') . '/', '', $fileAbsolutePath);

                                    if (!$isAllowed($fileRelativePath, $allowedResources)) {
                                        $response->status(404);
                                        $response->end();
                                    }

                                    if (is_readable($fileAbsolutePath)) {
                                        if (is_dir($fileAbsolutePath)) {
                                            $response->status(404);
                                            $response->end();
                                        }
                                        $response->sendfile($fileAbsolutePath);
                                        return true;
                                    }
                                }
                            }
                        }

                        // Materialize file in application
                        $params = $_SERVER;
                        if (empty($mediaDirectory)) {
                            $params[\Magento\Framework\App\ObjectManagerFactory::INIT_PARAM_DEPLOYMENT_CONFIG] = [];
                            $params[\Magento\Framework\App\Cache\Frontend\Factory::PARAM_CACHE_FORCED_OPTIONS] = ['frontend_options' => ['disable_save' => true]];
                        }
                        $bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $params);
                        /** @var \Magento\MediaStorage\App\Media $app */
                        $application = $bootstrap->createApplication(
                            \Magento\MediaStorage\App\Media::class,
                            [
                                'mediaDirectory' => $mediaDirectory,
                                'configCacheFile' => $configCacheFile,
                                'isAllowed' => $isAllowed,
                                'relativeFileName' => $relativePath,
                            ]
                        );
                        $application->launch();
                        $response->sendfile($fileAbsolutePath);
                        return true;
                    } else {
                        $bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
                        $application = $bootstrap->createApplication(\Magento\Framework\App\Http::class);
                        $m2Response = $application->launch();
                        foreach ($m2Response->getHeaders()->toArray() as $key => $value) {
                            $response->header($key, $value);
                        }
                        $response->status($m2Response->getStatusCode());
                        $response->end($m2Response->getContent() . "\n");
                    }
                } catch (\Magento\Framework\View\Asset\File\NotFoundException $e) {
                    $response->status(404);
                    $response->end();
                } finally {
                    session_write_close();
                    session_id('');
                    $_SESSION = [];
                    unset($_SESSION);
                }
            }
        );

        $http->start();
    }
}

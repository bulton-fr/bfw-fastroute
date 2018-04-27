<?php

namespace BfwFastRoute\test\unit;

use \atoum;

$vendorPath = realpath(__DIR__.'/../../../../vendor');
require_once($vendorPath.'/autoload.php');
require_once($vendorPath.'/bulton-fr/bfw/test/unit/helpers/Application.php');
require_once($vendorPath.'/bulton-fr/bfw/test/unit/mocks/src/class/Module.php');
require_once($vendorPath.'/bulton-fr/bfw/test/unit/mocks/src/class/Subject.php');

class Router extends Atoum
{
    use \BFW\Test\Helpers\Application;
    
    protected $mock;
    protected $module;
    
    public function beforeTestMethod($testMethod)
    {
        //Define PHP_SAPI on namespace BFW (mock) to have the methode
        //BFW\Application::initCtrlRouterLink executed
        eval('namespace BFW {const PHP_SAPI = \'www\';}');
        
        $this->setRootDir(__DIR__.'/../../../..');
        $this->createApp();
        $this->app->setRunSteps([
            [$this->app, 'initCtrlRouterLink'],
            [$this->app, 'runCtrlRouterLink']
        ]);
        $this->initApp();
        $this->createModule();
        $this->app->run();
        
        if ($testMethod === 'testConstructAndGetters') {
            return;
        }
        
        $this->mockGenerator
            ->makeVisible('obtainCtrlRouterInfos')
            ->makeVisible('searchRoute')
            ->makeVisible('checkStatus')
            ->makeVisible('addInfosToCtrlRouter')
            ->makeVisible('addTargetToCtrlRouter')
            ->makeVisible('addToSuperglobalVar')
            ->makeVisible('addDatasToGetVar')
            ->generate('BfwFastRoute\Router')
        ;
        $this->mock = new \mock\BfwFastRoute\Router($this->module);
    }
    
    protected function createModule()
    {
        $this->module = new \BFW\Test\Mock\Module('bfw-fastroute');
        $config = new \BFW\Config('bfw-fastroute');
        $this->module->setConfig($config);
        $this->module->setStatus(true, true);
        
        $config->setConfigForFile(
            'routes.php',
            (object) [
                'routes' =>  [
                    '/' => [
                        'target' => 'index.php'
                    ],
                    '/login' => [
                        'target' => 'login.php',
                        'httpMethod' => ['GET', 'POST']
                    ],
                    '/no-target' => [
                        'httpMethod' => ['GET', 'POST']
                    ],
                    '/article-{id:\d+}' => [
                        'target' => 'article.php',
                        'get' => ['action' => 'read']
                    ]
                ]
            ]
        );
    }
    
    public function testConstructAndGetters()
    {
        $this->assert('test Router::__construct')
            ->object($bfwFastRoute = new \BfwFastRoute\Router($this->module))
                ->isInstanceOf('\SplObserver')
        ;
        
        $this->assert('test Router::getters')
            ->object($bfwFastRoute->getModule())
                ->isIdenticalTo($this->module)
            ->object($bfwFastRoute->getConfig())
                ->isIdenticalto($this->module->getConfig())
            ->object($bfwFastRoute->getDispatcher())
                //It's in the dependency, so I can't check the class name.
                //->isInstanceOf('\FastRoute\\Dispatcher\\GroupCountBased')
        ;
    }
    
    public function testAddRoutesToCollector()
    {
        $staticOnlyIndex = [
            '/' => [
                'target' => 'index.php'
            ]
        ];
        
        $staticIndexLoginAndNoTarget = [
            '/' => [
                'target' => 'index.php'
            ],
            '/login' => [
                'target' => 'login.php'
            ],
            '/no-target' => []
        ];
        
        $variableArticle = [
            0 => [
                'regex' => '~^(?|/article\-(\d+))$~',
                'routeMap' => [
                    2 => [
                        0 => [
                            'target' => 'article.php',
                            'get' => [
                                'action' => 'read'
                            ]
                        ],
                        1 => [
                            'id' => 'id'
                        ]
                    ]
                ]
            ]
        ];
        
        $this->assert('test Router::addRoutesToCollector')
            ->given($routeCollector = new \FastRoute\RouteCollector(
                new \FastRoute\RouteParser\Std,
                new \FastRoute\DataGenerator\GroupCountBased
            ))
            ->then
            ->variable($this->mock->addRoutesToCollector($routeCollector))
                ->isNull()
            ->array($routeCollector->getData())
                ->isEqualTo([
                    0 => [ //static routes
                        'GET'    => $staticIndexLoginAndNoTarget,
                        'HEAD'   => $staticOnlyIndex,
                        'POST'   => $staticIndexLoginAndNoTarget,
                        'PUT'    => $staticOnlyIndex,
                        'PATCH'  => $staticOnlyIndex,
                        'DELETE' => $staticOnlyIndex
                    ],
                    1 => [ //variable routes
                        'GET'    => $variableArticle,
                        'HEAD'   => $variableArticle,
                        'POST'   => $variableArticle,
                        'PUT'    => $variableArticle,
                        'PATCH'  => $variableArticle,
                        'DELETE' => $variableArticle
                    ]
                ])
        ;
    }
    
    public function testUpdate()
    {
        $this->assert('test Router::update for adding to linker subject')
            ->given($subject = new \BFW\Test\Mock\Subject)
            ->and($subject->setAction('bfw_ctrlRouterLink_subject_added'))
            ->then
            ->variable($this->mock->update($subject))
                ->isNull()
            ->given($subjectList = \BFW\Application::getInstance()->getSubjectList())
            ->array($observers = $subjectList->getSubjectForName('ctrlRouterLink')->getObservers())
                ->contains($this->mock)
        ;
        
        $this->assert('test Router::update for searchRoute system')
            ->given($subject = new \BFW\Test\Mock\Subject)
            ->and($subject->setAction('searchRoute'))
            ->and($subject->setContext($this->app->getCtrlRouterInfos()))
            ->then
            ->if($this->calling($this->mock)->searchRoute = null)
            ->then
            ->variable($this->mock->update($subject))
                ->isNull()
            ->object($this->mock->getCtrlRouterInfos())
                ->isIdenticalTo($this->app->getCtrlRouterInfos())
            ->mock($this->mock)
                ->call('searchRoute')
                    ->once()
        ;
    }
    
    public function testSearchRoute()
    {
        $this->assert('test Router::searchRoute - prepare')
            ->if($this->function->http_response_code = null)
            ->and($_SERVER['REQUEST_URI'] = '')
            ->and($_SERVER['REQUEST_METHOD'] = 'GET')
            ->and(\BFW\Request::getInstance()->runDetect())
            ->then
            ->given($ctrlRouterInfos = $this->app->getCtrlRouterInfos())
            ->given($subject = new \BFW\Test\Mock\Subject)
            ->if($subject->setContext($ctrlRouterInfos))
            ->and($this->mock->obtainCtrlRouterInfos($subject))
        ;
        
        $this->assert('test Router::searchRoute with a 404 route')
            ->if($this->calling($this->mock)->checkStatus = 404)
            ->then
            ->variable($this->mock->searchRoute())
                ->isNull()
            ->function('http_response_code')
                ->never()
        ;
        
        $this->assert('test Router::searchRoute with a 405 route')
            ->if($this->calling($this->mock)->checkStatus = 405)
            ->and($this->calling($this->mock)->addInfosToCtrlRouter = null)
            ->and($this->calling($this->mock)->addTargetToCtrlRouter = null)
            ->then
            ->variable($this->mock->searchRoute())
                ->isNull()
            ->function('http_response_code')
                ->wasCalledWithArguments(405)
                    ->once()
            ->mock($this->mock)
                ->call('addTargetToCtrlRouter')
                    ->never()
        ;
        
        $this->assert('test Router::searchRoute with a 200 route')
            ->if($this->calling($this->mock)->checkStatus = 200)
            ->and($this->calling($this->mock)->addInfosToCtrlRouter = null)
            ->and($this->calling($this->mock)->addTargetToCtrlRouter = null)
            ->and($this->calling($this->mock)->addDatasToGetVar = null)
            ->and($this->calling($this->mock)->addToSuperglobalVar = null)
            ->then
            ->variable($this->mock->searchRoute())
                ->isNull()
            ->function('http_response_code')
                ->wasCalledWithArguments(200)
                    ->atLeastOnce()
            ->mock($this->mock)
                ->call('addTargetToCtrlRouter')
                    ->withArguments([ //Test first if of the method
                        'target' => 'index.php'
                    ])
                        ->once()
                ->call('addDatasToGetVar')
                    ->withArguments([
                        'target' => 'index.php'
                    ])
                        ->once()
                ->call('addToSuperglobalVar')
                    ->withArguments('GET', [])
                        ->once()
        ;
    }
    
    public function testCheckStatus()
    {
        $this->assert('test Router::checkStatus with default value')
            ->integer($this->mock->checkStatus('atoum'))
                ->isEqualTo(200)
        ;
        
        $this->assert('test Router::checkStatus with no existing route')
            ->integer($this->mock->checkStatus(\FastRoute\Dispatcher::NOT_FOUND))
                ->isEqualTo(404)
        ;
        
        $this->assert('test Router::checkStatus with method not allowed for the route')
            ->integer($this->mock->checkStatus(\FastRoute\Dispatcher::METHOD_NOT_ALLOWED))
                ->isEqualTo(405)
        ;
    }
    
    public function testAddInfosToCtrlRouter()
    {
        $this->assert('test Router::addInfosToCtrlRouter - prepare')
            ->given($ctrlRouterInfos = $this->app->getCtrlRouterInfos())
            ->given($subject = new \BFW\Test\Mock\Subject)
            ->if($subject->setContext($ctrlRouterInfos))
            ->and($this->mock->obtainCtrlRouterInfos($subject))
        ;
        
        $this->assert('test Router::addInfosToCtrlRouter')
            ->given($app = \BFW\Application::getInstance())
            ->given($modulesInfos = $app->getConfig()->getValue('modules'))
            ->if($modulesInfos['controller']['name'] = 'bfw-controller')
            ->and($app->getConfig()->setConfigKeyForFile('config.php', 'modules', $modulesInfos))
            ->then
            ->variable($this->mock->addInfosToCtrlRouter())
                ->isNull()
            ->boolean($ctrlRouterInfos->isFound)
                ->isTrue()
            ->string($ctrlRouterInfos->forWho)
                ->isEqualTo('bfw-controller')
        ;
    }
    
    public function testAddTargetToCtrlRouter()
    {
        $this->assert('test Router::addTargetToCtrlRouter - prepare')
            ->given($ctrlRouterInfos = $this->app->getCtrlRouterInfos())
            ->given($subject = new \BFW\Test\Mock\Subject)
            ->if($subject->setContext($ctrlRouterInfos))
            ->and($this->mock->obtainCtrlRouterInfos($subject))
        ;
        
        $this->assert('test Router::addTargetToCtrlRouter without target')
            ->exception(function() {
                $this->mock->addTargetToCtrlRouter([]);
            })
                ->hasCode(\BfwFastRoute\Router::ERR_TARGET_NOT_DECLARED)
        ;
        
        $this->assert('test Router::addTargetToCtrlRouter with a target')
            ->variable($this->mock->addTargetToCtrlRouter([
                'target' => 'index.php'
            ]))
                ->isNull()
            ->string($ctrlRouterInfos->target)
                ->isEqualTo('index.php')
        ;
    }
    
    public function testAddToSuperglobalVar()
    {
        $this->assert('test Router::addToSuperglobalVar')
            ->given($_GET = ['id' => 123])
            ->then
            ->variable($this->mock->addToSuperglobalVar('GET', ['action' => 'read']))
                ->isNull()
            ->array($_GET)
                ->isEqualTo([
                    'id'     => 123,
                    'action' => 'read'
                ])
        ;
    }
    
    public function testAddDatasToGetVar()
    {
        $this->assert('test Router::addDatasToGetVar')
            ->given($_GET = ['id' => 456])
            ->then
            ->variable($this->mock->addDatasToGetVar([
                'get' => [
                    'action' => 'read'
                ]
            ]))
                ->isNull()
            ->array($_GET)
                ->isEqualTo([
                    'id'     => 456,
                    'action' => 'read'
                ])
        ;
    }
}
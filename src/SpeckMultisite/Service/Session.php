<?php

namespace SpeckMultisite\Service;

use Zend\Session\Container as SessionContainer,
    Zend\Http\PhpEnvironment\Response as HttpResponse,
    Zend\EventManager\StaticEventManager;

class Session
{

    protected $hostname;
    protected $app;
    protected $container;
    protected $domainMap;

    public function setDomainMap(\Zend\Config\Config $domainMap) {
        $this->domainMap = $domainMap;
    }

    public function initializeSession(\Zend\Mvc\MvcEvent $e)
    {
        $this->app = $e->getApplication();
        $request = $this->app->getRequest();
        $this->hostname = $request->uri()->getHost();

        $sessionManager = $e->getApplication()->getServiceManager()->get('SpeckMultisite/SessionManager');
        SessionContainer::setDefaultManager($sessionManager);

        if ($request->query()->{$sessionManager->getName()} !== null) {
            $this->newSession($request->query()->{$sessionManager->getName()});

            // when ($_COOKIE) contains session_name then it's save to redirect
            if (isset($_COOKIE[$sessionManager->getName()])) {
                $uri   = new \Zend\Uri\Http($request->uri());
                $query = $uri->getQueryAsArray();
                unset($query[$sessionManager->getName()]);
                $uri->setQuery($query);
                $this->app->events()->attach('dispatch', function($e) use ($uri) {
                            $response = new HttpResponse();
                            $response->headers()->addHeaderLine('Location', rawurldecode((string) $uri));
                            $response->setStatusCode(302);

                            return $response;
                        }, 9999);
            }
        } else {
            $this->newSession();
        }

        if ($this->isMasterHost() && isset($request->query()->requestMasterSessUri)) {
            $slaveUri                    = new \Zend\Uri\Http($request->query()->requestMasterSessUri);
            $query                       = $slaveUri->getQueryAsArray();
            $query[$sessionManager->getName()] = $sessionManager->getId();
            unset($query['requestMasterSessUri']);
            $slaveUri->setQuery($query);

            $this->app->events()->attach('dispatch', function($e) use ($slaveUri) {
                        $response = new HttpResponse();
                        $response->headers()->addHeaderLine('Location', rawurldecode((string) $slaveUri));
                        $response->setStatusCode(302);

                        return $response;
                    }, 9999);

            return;
        }
    }

    public function newSession($sid = null)
    {
        $sessionManager = SessionContainer::getDefaultManager();
        if ($sid !== null) {
            $sessionManager->setId($sid);

            $this->container = new SessionContainer(__CLASS__);

            if ($this->container->valid !== true) {
                $sessionManager->destroy();

                return $this->newSession();
            }
        } else {
            // $sessionManager->regenerateId(); ???
            $this->container = new SessionContainer(__CLASS__);

            if ($this->isMasterHost()) {
                $this->container->masterOriginated = true;
            }

            $this->container->valid = true;

            if ($this->isMasterHost() === false && $this->container->masterOriginated !== true) {
                $this->fetchMasterSession();
            }
        }
    }

    public function fetchMasterSession()
    {
            $slaveUri = $this->app->getRequest()->uri();

            $masterUri                     = new \Zend\Uri\Http($slaveUri);
            $masterUri->setHost($this->getMasterHost());
            $query                         = $masterUri->getQueryAsArray();
            $query['requestMasterSessUri'] = rawurldecode((string) $slaveUri);
            $masterUri->setQuery($query);

            $this->app->events()->attach('dispatch', function($e) use ($masterUri) {
                        $response = new HttpResponse();
                        $response->headers()->addHeaderLine('Location', rawurldecode((string) $masterUri));
                        $response->setStatusCode(302);
                        $response->send();
                        return $response;
                    }, 9999);
    }

    public function isMasterHost()
    {
        return ($this->hostname === $this->getMasterHost());
    }

    public function getMasterHost()
    {
        $groupName = $this->domainMap->hosts->{$this->hostname};
        return $this->domainMap->groups->{$groupName}->master;
    }

}

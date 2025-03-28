<?php

namespace UncannyOwl\AutomatorDevTools;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\ScriptEvents;

class DevToolsPlugin implements PluginInterface, EventSubscriberInterface {
    private $composer;
    private $io;

    public function activate(Composer $composer, IOInterface $io) {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io) {
    }

    public function uninstall(Composer $composer, IOInterface $io) {
    }

    public static function getSubscribedEvents() {
        return [
            ScriptEvents::POST_INSTALL_CMD => 'registerScripts',
            ScriptEvents::POST_UPDATE_CMD => 'registerScripts',
        ];
    }

    public function registerScripts() {
        $scripts = $this->composer->getPackage()->getScripts();
        $rootPackage = $this->composer->getPackage();
        
        // Get the vendor directory path
        $vendorDir = $this->composer->getConfig()->get('vendor-dir');
        $devToolsPath = $vendorDir . DIRECTORY_SEPARATOR . 'uncanny-owl' . DIRECTORY_SEPARATOR . 'automator-dev-tools';
        
        // Register each script from dev tools
        foreach ($scripts as $name => $script) {
            if (is_string($script)) {
                $scriptPath = $devToolsPath . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'script.php';
                $rootPackage->setScripts([
                    $name => 'php "' . $scriptPath . '" ' . $script
                ]);
            }
        }
    }
} 
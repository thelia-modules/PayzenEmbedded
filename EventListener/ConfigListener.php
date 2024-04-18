<?php

namespace PayzenEmbedded\EventListener;

use PayzenEmbedded\PayzenEmbedded;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\EventDispatcher\GenericEvent;
use Thelia\Model\Base\ModuleConfigQuery;

class ConfigListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            'module.config' => [
                'onModuleConfig', 128
                ],
        ];
    }

    public function onModuleConfig(GenericEvent $event): void
    {
        $subject = $event->getSubject();

        if ($subject !== "HealthStatus") {
            throw new \RuntimeException('Event subject does not match expected value');
        }

        $configModule = ModuleConfigQuery::create()
            ->filterByModuleId(PayzenEmbedded::getModuleId())
            ->filterByName(['site_id', 'test_password', 'production_password', 'javascript_test_key', 'javascript_production_key', 'signature_test_key', 'signature_production_key', 'webservice_endpoint', 'mode', 'capture_delay', 'minimum_amount', 'maximum_amount'])
            ->find();

        $moduleConfig = [];
        $moduleConfig['module'] = PayzenEmbedded::getModuleCode();
        $configsCompleted = true;

        if ($configModule->count() === 0) {
            $configsCompleted = false;
        }

        foreach ($configModule as $config) {
            $moduleConfig[$config->getName()] = $config->getValue();
            if ($config->getValue() === null) {
                $configsCompleted = false;
            }
        }

        $moduleConfig['completed'] = $configsCompleted;

        $event->setArgument('payzen_embedded.module.config', $moduleConfig);

    }
}
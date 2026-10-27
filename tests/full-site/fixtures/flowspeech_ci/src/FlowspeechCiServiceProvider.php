<?php
declare(strict_types=1);

namespace Drupal\flowspeech_ci;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Symfony\Component\DependencyInjection\Reference;

/** Installs the explicit offline transport only inside the disposable site. */
final class FlowspeechCiServiceProvider extends ServiceProviderBase {
    public function alter(ContainerBuilder $container): void {
        if ($container->hasDefinition('flowspeech.api')) {
            $definition = $container->getDefinition('flowspeech.api');
            $constructor = (new \ReflectionClass($definition->getClass()))->getConstructor();
            if ($constructor && $constructor->getNumberOfParameters() >= 5) {
                $definition->setArgument(4, new Reference('http_client'));
            }
        }
    }
}

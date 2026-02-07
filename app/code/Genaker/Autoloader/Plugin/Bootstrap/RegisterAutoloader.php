<?php
/**
 * @category Genaker
 * @copyright Copyright (c) Genaker
 */

namespace Genaker\Autoloader\Plugin\Bootstrap;

use Genaker\Autoloader\Model\ComposerAutoloader;
use Magento\Framework\App\Bootstrap;

/**
 * Plugin to register autoloader early in bootstrap
 */
class RegisterAutoloader
{
    public function __construct(
        private readonly ComposerAutoloader $autoloader
    ) {
    }

    /**
     * Register autoloader before application starts
     */
    public function beforeCreate(Bootstrap $subject, $application, array $initParams): array
    {
        try {
            $this->autoloader->register();
        } catch (\Exception $e) {
            // Silently fail if autoloader registration fails
        }
        return [$application, $initParams];
    }
}

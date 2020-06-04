<?php
/**
 * Valitor Module for Magento 2.x.
 *
 * Copyright © 2020 Valitor. All rights reserved.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SDM\Valitor\Model\Config\Source;

use Valitor\Api\Test\TestAuthentication;
use Magento\Framework\Option\ArrayInterface;
use SDM\Valitor\Model\SystemConfig;

class Authentication implements ArrayInterface
{

    /**
     * @var SystemConfig
     */
    private $systemConfig;

    /**
     * Authentication constructor.
     *
     * @param SystemConfig $systemConfig
     */
    public function __construct(SystemConfig $systemConfig)
    {
        $this->systemConfig = $systemConfig;
    }

    /**
     * Return array of options as value-label pairs
     *
     * @return array Format: array(array('value' => '<value>', 'label' => '<label>'), ...)
     */
    public function toOptionArray()
    {
        try {
            $response = new TestAuthentication($this->systemConfig->getAuth());
            $result   = $response->call();
        } catch (\Exception $e) {
            $result = false;
        }

        return [
            ['value' => '', 'label' => $result ? 'Authentication successful' : 'Could not authenticate']
        ];
    }
}

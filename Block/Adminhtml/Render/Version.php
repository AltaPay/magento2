<?php
/**
 * Altapay Module for Magento 2.x.
 *
 * Copyright © 2020 Altapay. All rights reserved.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SDM\Altapay\Block\Adminhtml\Render;

use Altapay\Api\Others\Terminals;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Psr\Log\LoggerInterface;
use Magento\Framework\Module\ModuleListInterface;
use SDM\Altapay\Model\SystemConfig;
use SDM\Altapay\Response\TerminalsResponse;

class Version extends Field
{
    const MODULE_CODE = 'SDM_Altapay';
    /**
     * @var ModuleListInterface
     */
    private $moduleList;

    /**
     * @var SystemConfig
     */
    private $systemConfig;
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * Version constructor.
     *
     * @param Context             $context
     * @param ModuleListInterface $moduleList
     * @param SystemConfig        $systemConfig
     * @param LoggerInterface     $logger
     */
    public function __construct(
        Context $context,
        ModuleListInterface $moduleList,
        SystemConfig $systemConfig,
        LoggerInterface $logger
    ) {
        $this->moduleList = $moduleList;
        parent::__construct($context);
        $this->systemConfig = $systemConfig;
        $this->logger       = $logger;
    }

    /**
     * Render module version
     *
     * @param AbstractElement $element
     *
     * @return string
     */
    public function render(AbstractElement $element)
    {
        $html       = '';
        $moduleInfo = $this->moduleList->getOne(self::MODULE_CODE);
        try {
            $call = new Terminals($this->systemConfig->getAuth());
            /** @var TerminalsResponse $response */
            $response  = $call->call();
            $terminals = [];

            foreach ($response->Terminals as $terminal) {
                $creditCard = false;
                foreach ($terminal->Natures as $nature) {
                    if ($nature->Nature == "CreditCard") {
                        $creditCard = true;
                    }
                }
                $terminals[] = [
                    'title'      => $terminal->Title,
                    'creditCard' => $creditCard,
                ];
            }

            $html .= "<tr id='row_terminals_data'>";
            $html .= "<td class='label'><input type='hidden' id='terminal_data_obj' value='" . json_encode($terminals)
                     . "'></td>";
            $html .= " <td></td>";
            $html .= " <td></td>";
            $html .= "</tr>";

        } catch (\Exception $e) {
            $this->logger->critical('Exception :'. $e->getMessage());
        }

        $html .= '<tr id="row_' . $element->getHtmlId() . '">';
        $html .= '  <td class="label">' . $element->getData('label') . '</td>';
        $html .= '  <td class="value">' . $moduleInfo['setup_version'] . '</td>';
        $html .= '  <td></td>';
        $html .= '</tr>';

        return $html;
    }
}

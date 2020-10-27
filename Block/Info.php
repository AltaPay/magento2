<?php
/**
 * Altapay Module for Magento 2.x.
 *
 * Copyright © 2020 Altapay. All rights reserved.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SDM\Altapay\Block;

use Magento\Payment\Block\Info as BaseInfo;
use Magento\Framework\DataObject;

class Info extends BaseInfo
{
    /**
     * Prepare credit card related payment info
     *
     * @param DataObject|array|null $transport
     *
     * @return DataObject
     */
    protected function _prepareSpecificInformation($transport = null)
    {
        if (null !== $this->_paymentSpecificInformation) {
            return $this->_paymentSpecificInformation;
        }

        $transport = parent::_prepareSpecificInformation($transport);
        $data      = [];
        if ($transId = $this->getInfo()->getLastTransId()) {
            $data['Transaction Id'] = $transId;
        }
        if ($ccTransId = $this->getInfo()->getCcTransId()) {
            $data['Credit card token'] = $ccTransId;
        }
        if ($paymentId = $this->getInfo()->getPaymentId()) {
            $data['Payment ID'] = $paymentId;
        }

        return $transport->setData(array_merge($data, $transport->getData()));
    }
}
